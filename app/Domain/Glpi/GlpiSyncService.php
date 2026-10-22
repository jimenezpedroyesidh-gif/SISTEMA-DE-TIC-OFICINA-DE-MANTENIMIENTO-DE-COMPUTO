<?php
/**
 * Sincronización con GLPI.
 *
 * LECTURA (base de datos de GLPI, solo SELECT): inventario agrupado por tipo y por ubicación, comparado con
 * los componentes del sistema por número de serie.
 *
 * ESCRITURA (API REST de GLPI) por medio de una cola (`glpi_cola`):
 *  - CREAR_COMPONENTE: cada componente de Equipos TI que se registra en el sistema.
 *  - CAMBIO_UBICACION: cada movimiento de equipo/componente cuando el técnico lo APRUEBA
 *    (TicketActivos::registrarMovimientoActivo con id_aprobo).
 * Se encola dentro de la misma transacción del cambio (si el cambio se revierte, no queda nada en la cola) y se
 * intenta enviar al terminar la petición. Si GLPI no responde o falta configurar la API, queda PENDIENTE/ERROR y
 * el admin lo reintenta desde «Inventario GLPI».
 */
final class GlpiSyncService
{
    /** tipo de componente del sistema => itemtype de GLPI (los de infraestructura no se envían). */
    public const ITEMTYPE_POR_TIPO = [
        'torre' => 'Computer', 'todo_en_uno' => 'Computer', 'portatil' => 'Computer',
        'monitor' => 'Monitor',
        'teclado' => 'Peripheral', 'mouse' => 'Peripheral', 'parlante' => 'Peripheral', 'videobeam' => 'Peripheral', 'otro' => 'Peripheral',
    ];

    private static bool $despachoRegistrado = false;

    public static function itemtypeDe(string $tipo): ?string
    {
        return self::ITEMTYPE_POR_TIPO[$tipo] ?? null;
    }

    // ───────────────────────────── Cola ─────────────────────────────

    /** Encola el alta en GLPI de un componente recién creado (si su tipo existe en GLPI). */
    public static function encolarCreacion(PDO $pdo, int $idComponente, ?int $idUsuario): void
    {
        $tipo = GlpiColaRepository::tipoComponente($pdo, $idComponente);
        if ($tipo === null || self::itemtypeDe($tipo) === null) return;
        if (!self::colaDisponible($pdo)) return;
        GlpiColaRepository::insertar($pdo, 'CREAR_COMPONENTE', $idComponente, null, null, $idUsuario);
        self::despacharAlFinal($pdo);
    }

    /**
     * Encola un cambio de ubicación aprobado. Si se movió un equipo completo, se encola cada componente suyo.
     * $idSalaDestino null = bodega / oficina de mantenimiento.
     */
    public static function encolarMovimiento(PDO $pdo, ?int $idEquipo, ?int $idComponente, ?int $idSalaDestino, ?int $idMovimiento, ?int $idUsuario): void
    {
        if (!self::colaDisponible($pdo)) return;
        $lista = [];
        if ($idComponente !== null) {
            $tipo = GlpiColaRepository::tipoComponente($pdo, $idComponente);
            if ($tipo !== null) $lista[] = ['id_componente' => $idComponente, 'tipo' => $tipo];
        } elseif ($idEquipo !== null) {
            $lista = GlpiColaRepository::componentesDeEquipo($pdo, $idEquipo);
        }
        $n = 0;
        foreach ($lista as $c) {
            if (self::itemtypeDe((string)$c['tipo']) === null) continue;
            GlpiColaRepository::insertar($pdo, 'CAMBIO_UBICACION', (int)$c['id_componente'], $idSalaDestino, $idMovimiento, $idUsuario);
            $n++;
        }
        if ($n > 0) self::despacharAlFinal($pdo);
    }

    /**
     * Un componente cambió de equipo (o quedó suelto en bodega). Solo cuenta si lo hizo o aprobó un técnico
     * o el admin; la sala destino es la del equipo nuevo (null = bodega / oficina de mantenimiento).
     */
    public static function encolarCambioDeComponente(PDO $pdo, int $idComponente, ?int $idEquipoNuevo, int $idUsuario): void
    {
        if (!self::colaDisponible($pdo)) return;
        $st = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $st->execute([$idUsuario]);
        if (!in_array($st->fetchColumn(), [Rol::TECNICO, Rol::ADMIN], true)) return;
        $idSala = null;
        if ($idEquipoNuevo !== null) {
            $st = $pdo->prepare("SELECT id_sala FROM equipos WHERE id_equipo = ?");
            $st->execute([$idEquipoNuevo]);
            $v = $st->fetchColumn();
            $idSala = ($v === false || $v === null) ? null : (int)$v;
        }
        self::encolarMovimiento($pdo, null, $idComponente, $idSala, null, $idUsuario);
    }

    /** Intenta enviar lo pendiente cuando termina la petición (después del COMMIT del cambio). */
    private static function despacharAlFinal(PDO $pdo): void
    {
        if (self::$despachoRegistrado || getenv('GLPI_SIN_ENVIO_AUTOMATICO')) return;
        self::$despachoRegistrado = true;
        register_shutdown_function(static function () use ($pdo) {
            try {
                if ($pdo->inTransaction()) return; // la petición terminó con un error a mitad de camino
                self::procesar($pdo, false, 20);
            } catch (Throwable $e) {
                RegistroErrores::registrar('GLPI: envío automático de la cola', $e);
            }
        });
    }

    private static function colaDisponible(PDO $pdo): bool
    {
        static $hay = null;
        if ($hay === null) {
            try { $pdo->query("SELECT 1 FROM glpi_cola LIMIT 0"); $hay = true; } catch (PDOException $e) { $hay = false; }
        }
        return $hay;
    }

    /**
     * Envía la cola por la API. $conErrores = también reintenta los que fallaron (botón del admin).
     * @return array{procesados:int,enviados:int,errores:int,mensaje:string}
     */
    public static function procesar(PDO $pdo, bool $conErrores, int $limite = 50, ?GlpiApi $api = null, ?PDO $glpi = null): array
    {
        $inyectado = $api !== null; // pruebas: API y base de GLPI se pasan a mano
        $res = ['procesados' => 0, 'enviados' => 0, 'errores' => 0, 'mensaje' => ''];
        $cfg = ConexionesExternas::obtener($pdo, ConexionesExternas::GLPI_API);
        if ($api === null) {
            if ($cfg === null || empty($cfg['url']) || empty($cfg['app_token']) || empty($cfg['user_token'])) {
                $res['mensaje'] = 'La API de GLPI no está configurada: los envíos quedan pendientes.';
                return $res;
            }
            $api = new GlpiApi((string)$cfg['url'], (string)$cfg['app_token'], (string)$cfg['user_token']);
        }
        $ubicBodega = (int)($cfg['ubicacion_bodega'] ?? 0) ?: null;
        $glpiDb = $inyectado ? $glpi : Database::glpi();

        foreach (GlpiColaRepository::porProcesar($pdo, $conErrores, $limite) as $q) {
            $res['procesados']++;
            try {
                $comp = GlpiColaRepository::componente($pdo, (int)$q['id_componente']);
                if ($comp === null) throw new RuntimeException('El componente ya no existe.');
                $itemtype = self::itemtypeDe((string)$comp['tipo']);
                if ($itemtype === null) throw new RuntimeException('Este tipo de componente no se registra en GLPI.');

                // ¿Ya existe en GLPI? (vínculo guardado, o mismo número de serie en la base de GLPI)
                $vinculo = !empty($comp['glpi_id']) ? ['itemtype' => (string)$comp['glpi_itemtype'], 'id' => (int)$comp['glpi_id']] : null;
                if ($vinculo === null && $glpiDb !== null) {
                    $vinculo = GlpiDb::buscarPorSerial($glpiDb, (string)$comp['serial']);
                    if ($vinculo !== null) GlpiColaRepository::vincularComponente($pdo, (int)$comp['id_componente'], $vinculo['itemtype'], $vinculo['id']);
                }

                if ($q['tipo'] === 'CREAR_COMPONENTE') {
                    if ($vinculo !== null) {
                        GlpiColaRepository::marcarEnviado($pdo, (int)$q['id'], $vinculo['itemtype'], $vinculo['id'], 'Ya existía en GLPI: se vinculó por número de serie.');
                    } else {
                        $loc = self::ubicacionGlpi($pdo, $comp['id_sala_actual'] !== null ? (int)$comp['id_sala_actual'] : null, $ubicBodega, false);
                        $input = ['name' => $comp['codigo_inventario'] . ' - ' . ucfirst(str_replace('_', ' ', (string)$comp['tipo'])),
                                  'serial' => $comp['serial'], 'otherserial' => $comp['codigo_inventario'],
                                  'comment' => trim('Registrado desde el ' . institucion('sistema') . ' ' . institucion('sigla') . '. ' . (string)($comp['marca'] ?? '') . ' ' . (string)($comp['modelo'] ?? ''))];
                        if ($loc !== null) $input['locations_id'] = $loc;
                        $id = $api->crear($itemtype, $input);
                        GlpiColaRepository::vincularComponente($pdo, (int)$comp['id_componente'], $itemtype, $id);
                        GlpiColaRepository::marcarEnviado($pdo, (int)$q['id'], $itemtype, $id);
                    }
                } else { // CAMBIO_UBICACION
                    if ($vinculo === null) throw new RuntimeException('No existe en GLPI: primero debe enviarse su alta (o vincularse por serial).');
                    $loc = self::ubicacionGlpi($pdo, $q['id_sala_destino'] !== null ? (int)$q['id_sala_destino'] : null, $ubicBodega, true);
                    $api->actualizar($vinculo['itemtype'], $vinculo['id'], ['locations_id' => $loc]);
                    GlpiColaRepository::marcarEnviado($pdo, (int)$q['id'], $vinculo['itemtype'], $vinculo['id']);
                }
                $res['enviados']++;
            } catch (Throwable $e) {
                GlpiColaRepository::marcarError($pdo, (int)$q['id'], $e->getMessage());
                $res['errores']++;
            }
        }
        $api->cerrar();
        $res['mensaje'] = $res['procesados'] === 0 ? 'No había envíos pendientes.' : "Enviados {$res['enviados']} de {$res['procesados']}" . ($res['errores'] ? " ({$res['errores']} con error)." : '.');
        return $res;
    }

    /** locations_id de GLPI para una sala (null = bodega). Si $obligatoria y no hay vínculo, error explicativo. */
    private static function ubicacionGlpi(PDO $pdo, ?int $idSala, ?int $ubicBodega, bool $obligatoria): ?int
    {
        if ($idSala === null) {
            if ($ubicBodega === null && $obligatoria) throw new RuntimeException('Falta elegir la ubicación de GLPI para bodega / oficina de mantenimiento (Conexiones → API de GLPI).');
            return $ubicBodega;
        }
        $s = GlpiColaRepository::ubicacionGlpiDeSala($pdo, $idSala);
        if (empty($s['glpi_location_id'])) {
            if ($obligatoria) throw new RuntimeException("La sala {$s['nombre_sala']} no está vinculada a una ubicación de GLPI (Inventario GLPI → Salas).");
            return null;
        }
        return (int)$s['glpi_location_id'];
    }

    // ───────────────────────────── Lectura / agrupación ─────────────────────────────

    /**
     * Inventario de GLPI agrupado. $idUbicacion filtra el detalle (null = sin detalle, solo resúmenes).
     * @return array{conectado:bool,porTipo:array,porUbicacion:array,detalle:array,total:int,enSistema:int}
     */
    public static function inventario(PDO $pdo, ?PDO $glpi, ?int $idUbicacion, ?string $itemtype): array
    {
        $vacio = ['conectado' => false, 'porTipo' => [], 'porUbicacion' => [], 'detalle' => [], 'total' => 0, 'enSistema' => 0];
        if ($glpi === null) return $vacio;
        $activos = GlpiDb::activos($glpi);
        $locales = GlpiColaRepository::serialesLocales($pdo);
        $porTipo = []; $porUbic = []; $detalle = []; $enSistema = 0;
        foreach ($activos as $a) {
            $a['id_componente_local'] = $locales[strtoupper(trim($a['serial']))] ?? null;
            if ($a['serial'] === '') $a['id_componente_local'] = null;
            if ($a['id_componente_local'] !== null) $enSistema++;
            $porTipo[$a['itemtype']] = ($porTipo[$a['itemtype']] ?? 0) + 1;
            $u = $a['ubicacion'] !== '' ? $a['ubicacion'] : '(sin ubicación)';
            $porUbic[$u] ??= ['id' => $a['id_ubicacion'], 'total' => 0, 'tipos' => []];
            $porUbic[$u]['total']++;
            $porUbic[$u]['tipos'][$a['itemtype']] = ($porUbic[$u]['tipos'][$a['itemtype']] ?? 0) + 1;
            if (($idUbicacion === null || $a['id_ubicacion'] === $idUbicacion) && ($itemtype === null || $a['itemtype'] === $itemtype)
                && ($idUbicacion !== null || $itemtype !== null)) {
                $detalle[] = $a;
            }
        }
        ksort($porUbic);
        return ['conectado' => true, 'porTipo' => $porTipo, 'porUbicacion' => $porUbic, 'detalle' => $detalle, 'total' => count($activos), 'enSistema' => $enSistema];
    }

    /** Vincula por número de serie los componentes locales que ya existen en GLPI. Devuelve cuántos vinculó. */
    public static function vincularPorSerial(PDO $pdo, PDO $glpi): int
    {
        $porSerial = [];
        foreach (GlpiDb::activos($glpi) as $a) {
            if ($a['serial'] !== '') $porSerial[strtoupper(trim($a['serial']))] = $a;
        }
        $n = 0;
        foreach (GlpiColaRepository::sinVinculo($pdo) as $c) {
            $a = $porSerial[strtoupper(trim((string)$c['serial']))] ?? null;
            if ($a !== null) {
                GlpiColaRepository::vincularComponente($pdo, (int)$c['id_componente'], $a['itemtype'], $a['id']);
                $n++;
            }
        }
        return $n;
    }
}
