<?php
/**
 * Estado real de cada equipo para el plano de sala (mantenimientos, ticket de daño del profesor,
 * QR de sala con y sin sesión). Un mismo criterio para todos los roles:
 *
 *  - NO OPERATIVO si: la pieza principal instalada (torre / todo en uno / portátil / videobeam) está
 *    DEFECTUOSA o EN_REVISION; o no tiene pieza principal porque se la llevaron (traslado a bodega/
 *    oficina) o el puesto de cómputo solo tiene periféricos; o el inventario lo marca operativo=0.
 *    Si ya le instalaron una pieza principal de reemplazo activa, la que salió no lo deja «no operativo».
 *    Si el equipo no tiene ningún componente registrado no se puede saber qué le falta: se respeta
 *    solo la bandera operativo del inventario.
 *  - Traslados: los últimos movimientos (movimientos_activos) del equipo completo o de sus piezas
 *    (las piezas se ubican por historial_componente), con quién solicitó, aprobó y ejecutó.
 *    Así se ve, por ejemplo, «Torre INV-123 → Oficina de mantenimiento; aprobó Carlos Rojas»,
 *    mientras el monitor, teclado y mouse siguen en el puesto.
 *
 * $conNombres = false (QR sin sesión, página pública): no expone nombres de personas, solo el rol.
 */
final class PlanoSalaService
{
    /** Pieza sin la cual el equipo no funciona (en un videobeam, el propio proyector). */
    public const PIEZAS_PRINCIPALES = ['torre', 'todo_en_uno', 'portatil', 'videobeam'];
    /** Periféricos que delatan un puesto de cómputo (si los hay y no hay torre/todo en uno, no funciona). */
    private const PERIFERICOS_PC = ['monitor', 'teclado', 'mouse'];
    private const MAX_TRASLADOS = 3;
    /** Tamaño máximo de las listas IN (…) por consulta; ver estadoEquipos(). */
    private const BLOQUE_IN = 200;

    /** @return array<int, array{operativo: bool, motivo: string, piezas: array, faltan: array, traslados: array}> */
    public static function estadoEquipos(PDO $pdo, array $idsEquipo, bool $conNombres = true): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $idsEquipo))));
        if (!$ids) return [];
        // Con listas IN muy largas (p. ej. los 1.230 equipos de «Fichas de equipo») MySQL/MariaDB dejan de
        // mirar el índice valor por valor (eq_range_index_dive_limit = 200) y estiman con el promedio; como
        // casi todas las piezas tienen id_equipo_actual NULL, el promedio sale enorme y terminan recorriendo
        // los 2M de componentes. En bloques de 200 siempre usan el índice. El estado de cada equipo solo
        // depende de sus propias filas, así que el resultado es idéntico.
        if (count($ids) > self::BLOQUE_IN) {
            $out = [];
            foreach (array_chunk($ids, self::BLOQUE_IN) as $bloque) {
                $out += self::estadoEquipos($pdo, $bloque, $conNombres);
            }
            return $out;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));

        $out = [];
        $st = $pdo->prepare("SELECT id_equipo, operativo FROM equipos WHERE id_equipo IN ($in)");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id_equipo']] = ['operativo' => (int)$r['operativo'] === 1, 'motivo' => '', 'piezas' => [], 'faltan' => [], 'traslados' => []];
        }

        // Piezas instaladas hoy en cada equipo
        $st = $pdo->prepare("SELECT id_componente, id_equipo_actual, tipo, codigo_inventario, estado FROM componentes
                             WHERE id_equipo_actual IN ($in) AND estado <> 'DE_BAJA' ORDER BY tipo");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $out[(int)$c['id_equipo_actual']]['piezas'][] = $c;
        }

        // Mismo resultado: la desasignación de estos equipos que no tiene otra desasignación posterior del
        // mismo componente, resuelto con los índices idx_hist_equipo_anterior e idx_hist_comp_tipo.
        $st = $pdo->prepare("
            SELECT h.id_equipo_anterior AS id_equipo, c.id_componente, c.tipo, c.codigo_inventario, c.estado
            FROM historial_componente h
            JOIN componentes c ON c.id_componente = h.id_componente
            WHERE h.id_equipo_anterior IN ($in) AND h.tipo_evento = 'DESASIGNACION' AND c.id_equipo_actual IS NULL
              AND NOT EXISTS (SELECT 1 FROM historial_componente h2
                              WHERE h2.id_componente = h.id_componente AND h2.tipo_evento = 'DESASIGNACION'
                                AND h2.id_historial > h.id_historial)");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (isset($out[(int)$c['id_equipo']])) $out[(int)$c['id_equipo']]['faltan'][] = $c;
        }

        // Últimos traslados: del equipo completo o de piezas que estuvieron en él
        $st = $pdo->prepare("
            SELECT x.id_equipo_ref, m.*, so.nombre_sala AS sala_origen, sd.nombre_sala AS sala_destino,
                   c.tipo AS comp_tipo, c.codigo_inventario AS comp_codigo,
                   us.nombre_completo AS solicito, us.role AS solicito_rol,
                   ua.nombre_completo AS aprobo, ua.role AS aprobo_rol,
                   ue.nombre_completo AS ejecuto, ue.role AS ejecuto_rol
            FROM (
                SELECT id AS id_mov, id_equipo AS id_equipo_ref FROM movimientos_activos WHERE id_equipo IN ($in)
                UNION
                SELECT m2.id, h.id_equipo_ref FROM movimientos_activos m2
                JOIN (SELECT DISTINCT id_componente, COALESCE(id_equipo_anterior, id_equipo_nuevo) AS id_equipo_ref
                      FROM historial_componente
                      WHERE id_equipo_anterior IN ($in) OR id_equipo_nuevo IN ($in)) h ON h.id_componente = m2.id_componente
            ) x
            JOIN movimientos_activos m ON m.id = x.id_mov
            LEFT JOIN salas so ON so.id_sala = m.id_sala_origen
            LEFT JOIN salas sd ON sd.id_sala = m.id_sala_destino
            LEFT JOIN componentes c ON c.id_componente = m.id_componente
            LEFT JOIN users us ON us.id = m.id_solicito
            LEFT JOIN users ua ON ua.id = m.id_aprobo
            LEFT JOIN users ue ON ue.id = m.id_ejecuto
            ORDER BY m.fecha_solicitado DESC, m.id DESC");
        $st->execute(array_merge($ids, $ids, $ids));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $idEq = (int)$m['id_equipo_ref'];
            if (!isset($out[$idEq]) || count($out[$idEq]['traslados']) >= self::MAX_TRASLADOS) continue;
            $out[$idEq]['traslados'][] = self::traslado($m, $conNombres);
        }

        foreach ($out as &$e) {
            $e['motivo'] = self::motivoNoOperativo($e);
            if ($e['motivo'] !== '') $e['operativo'] = false;
            // Para la vista solo hace falta tipo/código/estado de cada pieza
            $e['piezas'] = array_map(fn($c) => ['tipo' => self::nombrePieza((string)$c['tipo']), 'codigo' => (string)$c['codigo_inventario'], 'estado' => (string)$c['estado']], $e['piezas']);
            $e['faltan'] = array_map(fn($c) => ['tipo' => self::nombrePieza((string)$c['tipo']), 'codigo' => (string)$c['codigo_inventario'], 'estado' => (string)$c['estado']], $e['faltan']);
        }
        return $out;
    }

    /** Tipos de componente que se ubican frente al tablero (no son equipos de cómputo). */
    public const TIPOS_FRENTE = ['videobeam', 'parlante'];

    /**
     * Videobeam y parlantes registrados como componentes de la sala (id_sala_directa), no como puesto
     * de cómputo: se dibujan frente al tablero con su estado y sus traslados.
     * @return array<int, array<int, array{id_componente:int, tipo:string, nombre:string, codigo:string, marca:string, modelo:string, estado:array}>>  id_sala => ítems
     */
    public static function frentePorSalas(PDO $pdo, array $idsSala, bool $conNombres = true): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $idsSala))));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $tipos = implode(',', array_fill(0, count(self::TIPOS_FRENTE), '?'));
        $st = $pdo->prepare("SELECT id_componente, id_sala_directa, tipo, codigo_inventario, marca, modelo, estado
                             FROM componentes
                             WHERE id_sala_directa IN ($in) AND tipo IN ($tipos) AND estado <> 'DE_BAJA'
                             ORDER BY tipo, codigo_inventario");
        $st->execute(array_merge($ids, self::TIPOS_FRENTE));
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$filas) return [];

        // Traslados de cada componente (quién solicitó, aprobó y ejecutó)
        $idsComp = array_map(fn($f) => (int)$f['id_componente'], $filas);
        $inC = implode(',', array_fill(0, count($idsComp), '?'));
        $tr = $pdo->prepare("
            SELECT m.*, so.nombre_sala AS sala_origen, sd.nombre_sala AS sala_destino,
                   c.tipo AS comp_tipo, c.codigo_inventario AS comp_codigo,
                   us.nombre_completo AS solicito, us.role AS solicito_rol,
                   ua.nombre_completo AS aprobo, ua.role AS aprobo_rol,
                   ue.nombre_completo AS ejecuto, ue.role AS ejecuto_rol
            FROM movimientos_activos m
            LEFT JOIN salas so ON so.id_sala = m.id_sala_origen
            LEFT JOIN salas sd ON sd.id_sala = m.id_sala_destino
            LEFT JOIN componentes c ON c.id_componente = m.id_componente
            LEFT JOIN users us ON us.id = m.id_solicito
            LEFT JOIN users ua ON ua.id = m.id_aprobo
            LEFT JOIN users ue ON ue.id = m.id_ejecuto
            WHERE m.id_componente IN ($inC)
            ORDER BY m.fecha_solicitado DESC, m.id DESC");
        $tr->execute($idsComp);
        $traslados = [];
        foreach ($tr->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $idC = (int)$m['id_componente'];
            if (count($traslados[$idC] ?? []) < self::MAX_TRASLADOS) $traslados[$idC][] = self::traslado($m, $conNombres);
        }

        $out = [];
        foreach ($filas as $f) {
            $estadoPieza = (string)$f['estado'];
            $operativo = $estadoPieza === 'ACTIVO';
            $nombre = ucfirst(self::nombrePieza((string)$f['tipo']));
            $out[(int)$f['id_sala_directa']][] = [
                'id_componente' => (int)$f['id_componente'],
                'tipo' => (string)$f['tipo'],
                'nombre' => $nombre,
                'codigo' => (string)$f['codigo_inventario'],
                'marca' => (string)($f['marca'] ?? ''),
                'modelo' => (string)($f['modelo'] ?? ''),
                'estado' => [
                    'operativo' => $operativo,
                    'motivo' => $operativo ? '' : $nombre . ' ' . self::estadoPieza($estadoPieza) . '.',
                    'piezas' => [], 'faltan' => [],
                    'traslados' => $traslados[(int)$f['id_componente']] ?? [],
                ],
            ];
        }
        return $out;
    }

    /** Ids de videobeam/parlantes que de verdad pertenecen a esa sala (nunca se confía en el POST). */
    public static function componentesFrenteValidos(PDO $pdo, int $idSala, array $idsPost): array
    {
        $idsPost = array_values(array_unique(array_filter(array_map('intval', $idsPost))));
        if (!$idSala || !$idsPost) return [];
        $validos = array_map(fn($c) => $c['id_componente'], self::frentePorSalas($pdo, [$idSala], false)[$idSala] ?? []);
        return array_values(array_intersect($idsPost, $validos));
    }

    /** Motivo legible de por qué no está operativo ('' = operativo). */
    private static function motivoNoOperativo(array $e): string
    {
        $esPrincipal = fn($c) => in_array($c['tipo'], self::PIEZAS_PRINCIPALES, true);
        $principales = array_values(array_filter($e['piezas'], $esPrincipal));
        $principalesOk = array_values(array_filter($principales, fn($c) => $c['estado'] === 'ACTIVO'));

        // 1) Hay pieza principal instalada pero está dañada o en revisión.
        if ($principales && !$principalesOk) {
            $p = $principales[0];
            return ucfirst(self::nombrePieza((string)$p['tipo'])) . ' ' . $p['codigo_inventario'] . ' ' . self::estadoPieza((string)$p['estado']) . '.';
        }
        // 2) No hay pieza principal: se la llevaron (traslado) o el puesto de cómputo solo tiene periféricos.
        if (!$principales) {
            $fuera = array_values(array_filter($e['faltan'], $esPrincipal));
            if ($fuera) {
                $p = $fuera[0];
                return 'Sin ' . self::nombrePieza((string)$p['tipo']) . ($p['codigo_inventario'] ? ' ' . $p['codigo_inventario'] : '') . ': salió de la sala (' . self::estadoPieza((string)$p['estado']) . ').';
            }
            $esPuestoDeComputo = (bool)array_filter($e['piezas'], fn($c) => in_array($c['tipo'], self::PERIFERICOS_PC, true));
            if ($esPuestoDeComputo) {
                return 'No tiene torre ni equipo todo en uno: solo quedan periféricos en el puesto.';
            }
        }
        // 3) La bandera del inventario manda si nada de lo anterior lo explica.
        if (!$e['operativo']) {
            return 'Marcado como no operativo en el inventario.';
        }
        return '';
    }

    private static function traslado(array $m, bool $conNombres): array
    {
        $persona = static function (?string $nombre, ?string $rol) use ($conNombres): string {
            if ($nombre === null || $nombre === '') return '';
            return $conNombres ? $nombre : (function_exists('etiquetaRol') ? etiquetaRol((string)$rol) : (string)$rol);
        };
        $que = $m['id_componente']
            ? ucfirst(self::nombrePieza((string)$m['comp_tipo'])) . ($m['comp_codigo'] ? ' ' . $m['comp_codigo'] : '')
            : 'Equipo completo';
        return [
            'que' => $que,
            'desde' => $m['sala_origen'] ?: 'Bodega / oficina de mantenimiento',
            'hacia' => $m['sala_destino'] ?: 'Bodega / oficina de mantenimiento',
            'motivo' => self::motivoLegible((string)$m['motivo']),
            'fecha' => (string)($m['fecha_ejecutado'] ?: $m['fecha_aprobado'] ?: $m['fecha_solicitado']),
            'solicito' => $persona($m['solicito'], $m['solicito_rol']),
            'aprobo' => $persona($m['aprobo'], $m['aprobo_rol']),
            'ejecuto' => $persona($m['ejecuto'], $m['ejecuto_rol']),
            'ticket' => $m['id_ticket_relacionado'] ? '#' . (int)$m['id_ticket_relacionado'] : '',
        ];
    }

    private static function motivoLegible(string $motivo): string
    {
        return match ($motivo) {
            'CAMBIO_PIEZA_SALIENTE' => 'Pieza retirada',
            'CAMBIO_PIEZA_ENTRANTE' => 'Pieza instalada',
            'CAMBIO_DE_POSICION' => 'Cambio de sala',
            default => ucfirst(strtolower(str_replace('_', ' ', $motivo))),
        };
    }

    private static function nombrePieza(string $tipo): string
    {
        return match ($tipo) {
            'todo_en_uno' => 'todo en uno',
            'portatil' => 'portátil',
            'otro_infraestructura' => 'infraestructura',
            default => str_replace('_', ' ', $tipo),
        };
    }

    private static function estadoPieza(string $estado): string
    {
        return match ($estado) {
            'EN_REVISION' => 'en revisión',
            'DEFECTUOSO' => 'defectuosa',
            'DE_BAJA' => 'dada de baja',
            default => 'activa',
        };
    }
}
