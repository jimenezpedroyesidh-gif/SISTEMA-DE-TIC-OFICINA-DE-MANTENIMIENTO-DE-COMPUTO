<?php
/**
 * SolucionDespliegueService — dónde debe aplicarse cada solución técnica, dónde está aplicada, con qué versión y
 * en qué estado real, y su integración con el módulo de mantenimiento.
 *
 *  - Objetivos: toda una sala (sus equipos de cómputo operativos) o equipos puntuales. Cada equipo objetivo tiene un
 *    despliegue (st_despliegues) que nace PENDIENTE.
 *  - Programar: el técnico crea un mantenimiento de sala normal (mantenimientos_programados con alcance «ciertos
 *    equipos» y su checklist), opcionalmente con los equipos ya asignados a una beca o técnico. Se ejecuta, se revisa y
 *    se cierra en «Mantenimientos», con sus mismas reglas (checklist completa y foto para Realizado).
 *  - Sincronizar: al cerrarse ese mantenimiento, su checklist pasa a los despliegues (Realizado → implementado,
 *    Incompleto → con incidencia, lo demás vuelve a pendiente). No se modifica el módulo de mantenimiento: cada
 *    pantalla de este módulo revisa primero los mantenimientos cerrados que faltan por pasar (sincronizar()).
 *  - Verificación manual por equipo (responsable, fecha, resultado, observaciones y evidencias), siempre como fila
 *    nueva. Cada cambio de estado deja su rastro en st_historial con el estado anterior.
 * Permisos: gestiona el técnico; el admin supervisa (ve el panel); la beca solo ve y documenta sus tareas.
 */
final class SolucionDespliegueService
{
    /** Estado que se muestra (incluye DESACTUALIZADO: aplicada con una versión que ya no es la vigente). */
    public const ESTADOS = [
        'PENDIENTE' => 'Pendiente', 'PROGRAMADO' => 'Programado', 'IMPLEMENTADO' => 'Sin verificar', 'VERIFICADO' => 'Verificado',
        'CON_INCIDENCIA' => 'Con incidencia', 'REVERTIDO' => 'Revertido', 'DESACTUALIZADO' => 'Versión desactualizada',
    ];
    public const CHIP = [
        'PENDIENTE' => 'espera', 'PROGRAMADO' => 'proceso', 'IMPLEMENTADO' => 'proceso', 'VERIFICADO' => 'solucionado',
        'CON_INCIDENCIA' => 'danio', 'REVERTIDO' => 'espera', 'DESACTUALIZADO' => 'incompleto',
    ];
    public const RESULTADOS = ['CORRECTO' => 'Funciona correctamente', 'CON_OBSERVACIONES' => 'Funciona con observaciones', 'FALLIDO' => 'No funciona'];
    public const ACCIONES = [
        'VERIFICAR' => 'Registrar verificación', 'IMPLEMENTAR' => 'Registrar implementación (versión vigente)',
        'REVERTIR' => 'Marcar como revertida', 'PENDIENTE' => 'Volver a pendiente',
    ];
    /** Desde qué estados se permite cada acción manual (lo que está en un mantenimiento se actualiza al cerrarlo). */
    private const DESDE = [
        'IMPLEMENTAR' => ['PENDIENTE', 'IMPLEMENTADO', 'VERIFICADO', 'CON_INCIDENCIA', 'REVERTIDO'],
        'VERIFICAR'   => ['IMPLEMENTADO', 'VERIFICADO', 'CON_INCIDENCIA'],
        'REVERTIR'    => ['IMPLEMENTADO', 'VERIFICADO', 'CON_INCIDENCIA'],
        'PENDIENTE'   => ['IMPLEMENTADO', 'VERIFICADO', 'CON_INCIDENCIA', 'REVERTIDO'],
    ];
    private const MSG_SIN_PERMISO = 'Tu rol no tiene permiso para esta acción.';

    private static function r(string $error = '', string $exito = '', int $id = 0): array
    {
        return ['error' => $error, 'exito' => $exito, 'id' => $id];
    }

    /** Estado para mostrar: DESACTUALIZADO si está aplicada con una versión distinta de la vigente. */
    public static function estadoVisible(?string $estado, $idVersion, $idVigente): string
    {
        $estado = $estado ?: 'PENDIENTE';
        if (in_array($estado, ['IMPLEMENTADO', 'VERIFICADO'], true) && (int)$idVersion !== (int)$idVigente) {
            return 'DESACTUALIZADO';
        }
        return $estado;
    }

    /** ¿Se puede incluir en un mantenimiento? (no aplicada, con incidencia, revertida o desactualizada) */
    private static function programable(array $d, $idVigente): bool
    {
        return !empty($d['en_objetivo']) && in_array(self::estadoVisible($d['estado'], $d['id_version'], $idVigente), ['PENDIENTE', 'CON_INCIDENCIA', 'REVERTIDO', 'DESACTUALIZADO'], true);
    }

    // ───────────────────────── Sincronización con el módulo de mantenimiento

    /**
     * Crea los despliegues PENDIENTES de los equipos objetivo nuevos y pasa a los despliegues la checklist de los
     * mantenimientos ya cerrados. Barata e idempotente; nunca tumba la página (un error va al log).
     */
    public static function sincronizar(PDO $pdo): void
    {
        try {
            SolucionDespliegueRepository::crearPendientes($pdo);
            foreach (SolucionDespliegueRepository::detallesPorSincronizar($pdo) as $d) {
                Database::transaccion(fn(PDO $pdo) => self::sincronizarMantenimiento($pdo, $d), $pdo, 'st_sincronizar');
            }
        } catch (Throwable $e) {
            RegistroErrores::registrar('Soluciones técnicas: sincronización con mantenimientos', $e);
        }
    }

    private static function sincronizarMantenimiento(PDO $pdo, array $d): void
    {
        if (SolucionDespliegueRepository::marcarSincronizado($pdo, (int)$d['id_detalle']) === 0) {
            return; // otra petición ya lo pasó
        }
        $idProg = (int)$d['id_programado'];
        $idSol = (int)$d['id_solucion'];
        $implementados = $incidencias = 0;
        foreach (SolucionDespliegueRepository::checklist($pdo, $idProg) as $me) {
            if (!in_array($me['estado'], ['REALIZADO', 'INCOMPLETO'], true)) {
                continue;
            }
            $hecho = $me['estado'] === 'REALIZADO';
            $obs = ($hecho ? "Aplicada en el mantenimiento #$idProg" : "Mantenimiento #$idProg: quedó incompleta") . (trim((string)$me['observaciones']) !== '' ? ': ' . trim((string)$me['observaciones']) : '');
            SolucionDespliegueRepository::aplicarResultadoChecklist($pdo, $idSol, (int)$me['id_equipo'], (int)$d['id_version'], $hecho ? 'IMPLEMENTADO' : 'CON_INCIDENCIA',
                $idProg, $me['fecha_actualizacion'], $me['id_usuario_ultimo'] !== null ? (int)$me['id_usuario_ultimo'] : null, $obs);
            $hecho ? $implementados++ : $incidencias++;
        }
        $pendientes = SolucionDespliegueRepository::devolverAPendiente($pdo, $idSol, $idProg, "El mantenimiento #$idProg se cerró sin aplicarla en este equipo.");
        $cierre = $d['estado_mantenimiento'] === 'REALIZADO' ? 'realizado' : 'cancelado';
        SolucionService::historial($pdo, $idSol, 'MANTENIMIENTO', $idProg, 'MANTENIMIENTO_CERRADO',
            "Mantenimiento #$idProg $cierre: $implementados equipo(s) implementados (falta verificarlos), $incidencias con incidencia y $pendientes vuelven a pendiente.", null);
    }

    // ───────────────────────── Objetivos

    /** Agrega salas completas (salas[]) y/o equipos puntuales (códigos separados por coma, espacio o línea). */
    public static function agregarObjetivos(PDO $pdo, int $userId, int $idSolucion, array $in): array
    {
        $s = SolucionRepository::buscar($pdo, $idSolucion);
        if (!SolucionService::puede($pdo, $userId, 'gestionar') || !$s) {
            return self::r(self::MSG_SIN_PERMISO);
        }
        if ($s['estado'] === 'RETIRADA') {
            return self::r('La solución está retirada: ya no se aplica en nuevos equipos.');
        }
        $salas = array_values(array_unique(array_filter(array_map('intval', (array)($in['salas'] ?? [])))));
        $codigos = array_values(array_unique(preg_split('/[\s,;]+/', mb_strtoupper(trim((string)($in['equipos'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        if (!$salas && !$codigos) {
            return self::r('Elige al menos una sala o escribe el código de un equipo.');
        }
        $elegibles = array_column(MantenimientoCampanaRepository::salasElegibles($pdo), null, 'id_sala');
        foreach ($salas as $idSala) {
            if (!isset($elegibles[$idSala])) {
                return self::r('Una de las salas elegidas no existe o es una oficina de mantenimiento.');
            }
        }
        $equipos = SolucionDespliegueRepository::equiposPorCodigo($pdo, $codigos);
        $faltan = array_diff($codigos, array_keys($equipos));
        if ($faltan) {
            return self::r('No hay equipos con estos códigos: ' . implode(', ', array_slice($faltan, 0, 10)) . '.');
        }
        [$nuevos, $repetidos] = Database::transaccion(function (PDO $pdo) use ($idSolucion, $salas, $equipos, $elegibles, $userId) {
            $nuevos = [];
            $repetidos = 0;
            foreach ($salas as $idSala) {
                if (SolucionDespliegueRepository::objetivoActivoExiste($pdo, $idSolucion, $idSala, null)) {
                    $repetidos++;
                    continue;
                }
                SolucionDespliegueRepository::insertarObjetivo($pdo, $idSolucion, $idSala, null, $userId);
                $nuevos[] = 'Sala ' . $elegibles[$idSala]['nombre_sala'] . ' (sus equipos de cómputo)';
            }
            foreach ($equipos as $e) {
                if (SolucionDespliegueRepository::objetivoActivoExiste($pdo, $idSolucion, (int)$e['id_sala'], (int)$e['id_equipo'])) {
                    $repetidos++;
                    continue;
                }
                SolucionDespliegueRepository::insertarObjetivo($pdo, $idSolucion, (int)$e['id_sala'], (int)$e['id_equipo'], $userId);
                $nuevos[] = 'Equipo ' . $e['codigo'];
            }
            if ($nuevos) {
                SolucionService::historial($pdo, $idSolucion, 'OBJETIVO', null, 'OBJETIVO_AGREGADO', implode("\n", $nuevos), $userId);
            }
            return [$nuevos, $repetidos];
        }, $pdo, 'st_objetivos');
        self::sincronizar($pdo);
        if (!$nuevos) {
            return self::r('Lo que elegiste ya estaba entre los lugares donde se aplica esta solución.');
        }
        return self::r('', 'Se agregó: ' . implode(' · ', $nuevos) . '.' . ($repetidos ? " ($repetidos ya estaban.)" : ''));
    }

    /** Quita un objetivo (queda de baja con su fecha; los equipos donde ya está aplicada siguen a la vista). */
    public static function quitarObjetivo(PDO $pdo, int $userId, int $idSolucion, int $idObjetivo): array
    {
        $o = SolucionDespliegueRepository::objetivo($pdo, $idObjetivo, $idSolucion);
        if (!SolucionService::puede($pdo, $userId, 'gestionar') || !$o) {
            return self::r(self::MSG_SIN_PERMISO);
        }
        $texto = $o['equipo_codigo'] ? 'Equipo ' . $o['equipo_codigo'] : 'Sala ' . $o['nombre_sala'];
        Database::transaccion(function (PDO $pdo) use ($idSolucion, $idObjetivo, $texto, $userId) {
            SolucionDespliegueRepository::desactivarObjetivo($pdo, $idObjetivo, $idSolucion);
            SolucionService::historial($pdo, $idSolucion, 'OBJETIVO', $idObjetivo, 'OBJETIVO_QUITADO', $texto, $userId);
        }, $pdo, 'st_objetivo_baja');
        return self::r('', "$texto ya no es objetivo de esta solución. Los equipos donde ya estaba aplicada siguen en la lista.");
    }

    // ───────────────────────── Acciones manuales sobre los equipos marcados

    public static function aplicarAccion(PDO $pdo, int $userId, int $idSolucion, array $in, ?array $files): array
    {
        $accion = (string)($in['accion'] ?? '');
        $s = SolucionRepository::buscar($pdo, $idSolucion);
        if (!SolucionService::puede($pdo, $userId, 'gestionar') || !$s || !isset(self::DESDE[$accion])) {
            return self::r(self::MSG_SIN_PERMISO);
        }
        $marcados = SolucionDespliegueRepository::desplieguesPorIds($pdo, $idSolucion, (array)($in['despliegues'] ?? []));
        $obs = trim((string)($in['observaciones'] ?? ''));
        $resultado = (string)($in['resultado'] ?? '');
        $vigente = SolucionRepository::vigente($pdo, $idSolucion);
        if (!$marcados) {
            return self::r('Marca al menos un equipo de la lista.');
        }
        if (in_array($accion, ['VERIFICAR', 'REVERTIR'], true) && mb_strlen($obs) < 5) {
            return self::r($accion === 'VERIFICAR' ? 'Escribe qué revisaste y qué encontraste (observaciones).' : 'Escribe por qué se revierte.');
        }
        if ($accion === 'VERIFICAR' && !isset(self::RESULTADOS[$resultado])) {
            return self::r('Elige el resultado de la verificación.');
        }
        if ($accion === 'IMPLEMENTAR' && (!$vigente || $s['estado'] !== 'ACTIVA')) {
            return self::r('Solo se registra la implementación de una solución activa con una versión aprobada.');
        }
        $aplicables = array_values(array_filter($marcados, fn($d) => in_array($d['estado'], self::DESDE[$accion], true)));
        $omitidos = count($marcados) - count($aplicables);
        if (!$aplicables) {
            return self::r('Ninguno de los equipos marcados admite esa acción en su estado actual (lo programado en un mantenimiento se actualiza al cerrarlo).');
        }
        $evid = $accion === 'VERIFICAR' ? SolucionArchivos::guardarEvidencias($files) : ['guardadas' => [], 'rechazadas' => 0];
        $nuevo = ['IMPLEMENTAR' => 'IMPLEMENTADO', 'REVERTIR' => 'REVERTIDO', 'PENDIENTE' => 'PENDIENTE', 'VERIFICAR' => $resultado === 'FALLIDO' ? 'CON_INCIDENCIA' : 'VERIFICADO'][$accion];
        try {
            Database::transaccion(function (PDO $pdo) use ($accion, $aplicables, $vigente, $obs, $resultado, $evid, $nuevo, $idSolucion, $userId) {
                $lineas = [];
                foreach ($aplicables as $d) {
                    $idD = (int)$d['id_despliegue'];
                    if ($accion === 'IMPLEMENTAR') {
                        SolucionDespliegueRepository::registrarImplementacion($pdo, $idD, (int)$vigente['id_version'], $userId, $obs !== '' ? mb_substr($obs, 0, 500) : null);
                    } else {
                        if ($accion === 'VERIFICAR') {
                            $idVf = SolucionDespliegueRepository::insertarVerificacion($pdo, $idD, $d['id_version'] !== null ? (int)$d['id_version'] : null, $resultado, $obs, $userId);
                            foreach ($evid['guardadas'] as $e) {
                                SolucionRepository::insertarEvidencia($pdo, $idSolucion, $e, ['id_verificacion' => $idVf], $userId);
                            }
                        }
                        SolucionDespliegueRepository::cambiarEstado($pdo, $idD, $nuevo, $userId, $obs !== '' ? mb_substr($obs, 0, 500) : null);
                    }
                    $lineas[] = $d['equipo_codigo'] . ' (antes: ' . self::ESTADOS[$d['estado']] . ')';
                }
                $detalle = self::ACCIONES[$accion] . ($accion === 'VERIFICAR' ? ' — ' . self::RESULTADOS[$resultado] : '') . ($accion === 'IMPLEMENTAR' ? " — versión {$vigente['numero']}" : '')
                    . ' → ' . self::ESTADOS[$nuevo] . "\nEquipos: " . implode(', ', $lineas) . ($obs !== '' ? "\nObservaciones: $obs" : '') . (count($evid['guardadas']) ? "\nEvidencias: " . count($evid['guardadas']) : '');
                SolucionService::historial($pdo, $idSolucion, $accion === 'VERIFICAR' ? 'VERIFICACION' : 'DESPLIEGUE', count($aplicables) === 1 ? (int)$aplicables[0]['id_despliegue'] : null, $accion, $detalle, $userId);
            }, $pdo, 'st_accion');
        } catch (Throwable $e) {
            SolucionArchivos::borrar(array_column($evid['guardadas'], 'nombre_almacenado'));
            throw $e;
        }
        if ($nuevo === 'CON_INCIDENCIA' && !empty($s['id_responsable']) && (int)$s['id_responsable'] !== $userId) {
            NotificationService::crear($pdo, (int)$s['id_responsable'], 'MANTENIMIENTO', "Verificación fallida de {$s['codigo']} en " . count($aplicables) . ' equipo(s).', 'soluciones_tecnicas.php?id=' . $idSolucion . '&sec=despliegue');
        }
        $msg = self::ACCIONES[$accion] . ': ' . count($aplicables) . ' equipo(s).' . ($omitidos ? " Se omitieron $omitidos porque su estado no lo permite." : '');
        return self::r('', $msg . ($evid['rechazadas'] ? " {$evid['rechazadas']} archivo(s) no se guardaron (solo fotos o PDF de hasta 8 MB)." : ''));
    }

    // ───────────────────────── Programar en el módulo de mantenimiento

    /**
     * Crea un mantenimiento de sala con los equipos marcados (alcance «ciertos equipos») y lo liga a la solución.
     * @param array $in claves: id_sala, despliegues[], fecha_programada, duracion_minutos, id_responsable, id_asignado, confirmar_choque
     */
    public static function programarMantenimiento(PDO $pdo, int $userId, int $idSolucion, array $in): array
    {
        $s = SolucionRepository::buscar($pdo, $idSolucion);
        if (!SolucionService::puede($pdo, $userId, 'gestionar') || !$s) {
            return self::r(self::MSG_SIN_PERMISO);
        }
        $vigente = SolucionRepository::vigente($pdo, $idSolucion);
        if (!$vigente || $s['estado'] !== 'ACTIVA') {
            return self::r('Solo se programa una solución activa con una versión aprobada.');
        }
        $idSala = (int)($in['id_sala'] ?? 0);
        $pedidos = array_map('intval', (array)($in['despliegues'] ?? []));
        $elegidos = array_values(array_filter(SolucionDespliegueRepository::despliegues($pdo, $idSolucion),
            fn($d) => (int)$d['id_sala'] === $idSala && self::programable($d, $vigente['id_version']) && in_array((int)$d['id_despliegue'], $pedidos, true)));
        $ts = strtotime((string)($in['fecha_programada'] ?? ''));
        $duracion = MantenimientoAgenda::duracion($in['duracion_minutos'] ?? 60);
        $idResponsable = !empty($in['id_responsable']) ? (int)$in['id_responsable'] : null;
        $idAsignado = (int)($in['id_asignado'] ?? 0);
        if (!$elegidos) {
            return self::r('Marca al menos un equipo pendiente, con incidencia, revertido o desactualizado de esa sala.');
        }
        if (!$ts) {
            return self::r('La fecha programada no es válida.');
        }
        $idsEquipo = array_map(fn($d) => (int)$d['id_equipo'], $elegidos);
        if (count(MantenimientoRepository::equiposValidosDeSala($pdo, $idSala, $idsEquipo, true)) !== count($idsEquipo)) {
            return self::r('Alguno de los equipos ya no está en la sala o no es de cómputo (sin torre, portátil ni todo en uno).');
        }
        if ($idResponsable !== null && MantenimientoRepository::rolDeUsuario($pdo, $idResponsable) !== Rol::TECNICO) {
            $idResponsable = null; // el coordinador solo puede ser técnico (igual que en Mantenimientos)
        }
        $asignadoNombre = $idAsignado ? MantenimientoRepository::nombreStaff($pdo, $idAsignado) : '';
        if ($idAsignado && !$asignadoNombre) {
            return self::r('Elige una beca o técnico válido para asignarle los equipos.');
        }
        $choques = MantenimientoAgenda::choques($pdo, $idSala, new DateTime(date('Y-m-d H:i:s', $ts)), $duracion, null, $idResponsable);
        if ($choques && ($in['confirmar_choque'] ?? '') !== '1') {
            return self::r('Esa sala tiene ' . MantenimientoAgenda::texto($choques[0]) . '. Marca «Programar igual» si deseas continuar.');
        }
        $actividades = [];
        foreach (SolucionRepository::procedimientos($pdo, $idSolucion, true) as $p) {
            if ($p['fase'] !== 'REVERSION') {
                $actividades[] = SolucionService::FASES[$p['fase']] . ': ' . $p['titulo'];
            }
        }
        $descripcion = "Solución técnica {$s['codigo']} · {$s['nombre']} (versión {$vigente['numero']}). Instrucciones, archivos y evidencias en «Soluciones técnicas».";
        $sala = MantenimientoRepository::nombreSala($pdo, $idSala);
        $fecha = date('Y-m-d H:i:s', $ts);

        $idProg = (int)Database::transaccion(function (PDO $pdo) use ($idSala, $idsEquipo, $elegidos, $descripcion, $actividades, $fecha, $duracion, $idResponsable, $idAsignado, $userId, $idSolucion, $vigente, $sala) {
            $idProg = MantenimientoRepository::insertar($pdo, $idSala, count($idsEquipo) === 1 ? $idsEquipo[0] : null, 'INSTALACION_SOFTWARE', 'SELECCION', $descripcion,
                null, $fecha, $idResponsable, $userId, null, null, $duracion, $actividades ? implode("\n", $actividades) : null);
            MantenimientoRepository::generarChecklist($pdo, $idProg, $idSala, 'SELECCION', $idsEquipo);
            SolucionDespliegueRepository::insertarDetalle($pdo, $idProg, $idSolucion, (int)$vigente['id_version'], $userId);
            SolucionDespliegueRepository::marcarProgramados($pdo, array_column($elegidos, 'id_despliegue'), $idProg);
            if ($idAsignado) {
                foreach (SolucionDespliegueRepository::checklist($pdo, $idProg) as $me) {
                    MantenimientoRepository::asignarEquipo($pdo, (int)$me['id_mant_equipo'], $idProg, $idAsignado, $userId);
                }
            }
            SolucionService::historial($pdo, $idSolucion, 'MANTENIMIENTO', $idProg, 'MANTENIMIENTO_PROGRAMADO',
                "Mantenimiento #$idProg en $sala para el " . date('d/m/Y H:i', strtotime($fecha)) . " con la versión {$vigente['numero']}. Equipos: " . implode(', ', array_column($elegidos, 'equipo_codigo')), $userId);
            return $idProg;
        }, $pdo, 'st_programar');
        MantenimientoAgenda::limpiarCache();

        AuditoriaService::registrar($pdo, $userId, 'mantenimientos_programados', $idProg, 'CREAR', "desde la solución técnica {$s['codigo']}");
        if ($idResponsable && $idResponsable !== $userId) {
            NotificationService::crear($pdo, $idResponsable, 'MANTENIMIENTO', "Te asignaron como coordinador del mantenimiento #$idProg (solución técnica {$s['codigo']}, sala $sala).", 'mantenimientos_programados.php');
        }
        if ($idAsignado && $idAsignado !== $userId) {
            NotificationService::crear($pdo, $idAsignado, 'MANTENIMIENTO', 'Te asignaron ' . count($idsEquipo) . " equipo(s) para aplicar la solución técnica {$s['codigo']} en $sala (" . date('d/m/Y H:i', $ts) . ').', 'soluciones_tecnicas.php');
        }
        return self::r('', "Mantenimiento #$idProg programado en $sala con " . count($idsEquipo) . ' equipo(s)' . ($asignadoNombre ? ", asignados a $asignadoNombre" : '') . '. Se ejecuta y se cierra en «Mantenimientos»; al cerrarlo, cada equipo queda implementado o con incidencia según su checklist.', $idProg);
    }

    // ───────────────────────── Evidencia de ejecución de la beca

    public static function subirEvidenciaBeca(PDO $pdo, int $userId, array $in, ?array $files): array
    {
        $d = MantenimientoRepository::rolDeUsuario($pdo, $userId) === Rol::BECA ? SolucionDespliegueRepository::despliegueDeBeca($pdo, (int)($in['id_despliegue'] ?? 0), $userId) : false;
        $nota = trim((string)($in['nota'] ?? ''));
        if (!$d) {
            return self::r('Ese equipo no está en tus tareas activas.');
        }
        if (mb_strlen($nota) < 5) {
            return self::r('Describe en pocas palabras qué hiciste o qué muestra la evidencia.');
        }
        $evid = SolucionArchivos::guardarEvidencias($files);
        if (!$evid['guardadas']) {
            return self::r('Adjunta al menos una foto o captura (JPG, PNG, WEBP o PDF de hasta 8 MB).');
        }
        $idSolucion = (int)$d['id_solucion'];
        try {
            Database::transaccion(function (PDO $pdo) use ($d, $evid, $nota, $idSolucion, $userId) {
                foreach ($evid['guardadas'] as $e) {
                    SolucionRepository::insertarEvidencia($pdo, $idSolucion, $e, ['id_despliegue' => (int)$d['id_despliegue'], 'descripcion' => mb_substr($nota, 0, 255)], $userId);
                }
                SolucionService::historial($pdo, $idSolucion, 'EVIDENCIA', (int)$d['id_despliegue'], 'EVIDENCIA_EJECUCION', "{$d['equipo_codigo']}: $nota (" . count($evid['guardadas']) . ' archivo(s))', $userId);
            }, $pdo, 'st_evidencia_beca');
        } catch (Throwable $e) {
            SolucionArchivos::borrar(array_column($evid['guardadas'], 'nombre_almacenado'));
            throw $e;
        }
        return self::r('', 'Evidencia guardada para el equipo ' . $d['equipo_codigo'] . '.' . ($evid['rechazadas'] ? " {$evid['rechazadas']} archivo(s) no se guardaron." : ''));
    }

    // ───────────────────────── Datos de las pantallas

    /** Sección «Despliegue» de la ficha: equipos agrupados por sala, mantenimientos y opciones para programar. */
    public static function datosDespliegue(PDO $pdo, array $s): array
    {
        $porSala = [];
        foreach (SolucionDespliegueRepository::despliegues($pdo, (int)$s['id_solucion']) as $d) {
            $d['estado_visible'] = self::estadoVisible($d['estado'], $d['id_version'], $s['id_vigente']);
            $d['programable'] = $s['id_vigente'] && self::programable($d, $s['id_vigente']);
            $idSala = (int)$d['id_sala'];
            $porSala[$idSala] ??= ['nombre' => $d['abreviatura'] ? $d['abreviatura'] . ' — ' . $d['nombre_sala'] : $d['nombre_sala'], 'filas' => [], 'programables' => 0];
            $porSala[$idSala]['filas'][] = $d;
            $porSala[$idSala]['programables'] += $d['programable'] ? 1 : 0;
        }
        $ids = [];
        foreach ($porSala as $g) {
            $ids = array_merge($ids, array_column($g['filas'], 'id_despliegue'));
        }
        return [
            'porSala'                 => $porSala,
            'mantenimientos'          => SolucionDespliegueRepository::mantenimientos($pdo, (int)$s['id_solucion']),
            'evidenciasPorDespliegue' => SolucionRepository::evidenciasPor($pdo, 'id_despliegue', $ids),
            'personal'                => MantenimientoRepository::tecnicosYBecas($pdo),
            'duraciones'              => MantenimientoService::DURACIONES,
        ];
    }

    /** Panel de seguimiento: totales, cobertura por solución y por sala, equipos de una sala y lo que pide atención. */
    public static function datosPanel(PDO $pdo, int $idSala): array
    {
        $porSolucion = SolucionDespliegueRepository::cobertura($pdo, 'solucion');
        $totales = array_fill_keys(['objetivo', 'al_dia', 'verificados', 'por_verificar', 'pendientes', 'programados', 'incidencias', 'desactualizados'], 0);
        foreach ($porSolucion as $c) {
            foreach ($totales as $k => $v) {
                $totales[$k] = $v + $c[$k];
            }
        }
        $activas = SolucionRepository::listar($pdo, ['estado' => 'ACTIVA', 'id_tipo' => 0, 'texto' => '']);
        $salas = array_column(MantenimientoRepository::salas($pdo), null, 'id_sala');
        $matriz = ['soluciones' => [], 'equipos' => []];
        if ($idSala && isset($salas[$idSala])) {
            foreach (SolucionDespliegueRepository::matrizSala($pdo, $idSala) as $f) {
                $matriz['soluciones'][(int)$f['id_solucion']] = ['codigo' => $f['solucion_codigo'], 'nombre' => $f['solucion_nombre']];
                $matriz['equipos'][(int)$f['id_equipo']]['codigo'] = $f['equipo_codigo'];
                $matriz['equipos'][(int)$f['id_equipo']]['celdas'][(int)$f['id_solucion']] = [
                    'estado'  => self::estadoVisible($f['estado'], $f['id_version'], $f['id_vigente']),
                    'version' => $f['version_numero'],
                ];
            }
        }
        return [
            'totales'          => $totales,
            'activas'          => $activas,
            'porSolucion'      => $porSolucion,
            'porSala'          => SolucionDespliegueRepository::cobertura($pdo, 'sala'),
            'salas'            => $salas,
            'idSala'           => isset($salas[$idSala]) ? $idSala : 0,
            'matriz'           => $matriz,
            'atencion'         => SolucionDespliegueRepository::atencion($pdo),
            'mantenimientos'   => SolucionDespliegueRepository::mantenimientos($pdo, null),
            'enRevision'       => SolucionRepository::versionesEnRevision($pdo),
        ];
    }

    /** Tareas de la beca: cada mantenimiento activo con una solución donde tiene equipos asignados o tomados. */
    public static function datosTareas(PDO $pdo, int $userId): array
    {
        $tareas = SolucionDespliegueRepository::tareasDeBeca($pdo, $userId);
        $procs = [];
        foreach ($tareas as &$t) {
            $idSol = (int)$t['id_solucion'];
            $procs[$idSol] ??= SolucionRepository::procedimientos($pdo, $idSol, true);
            $t['procedimientos'] = $procs[$idSol];
            $t['equipos'] = SolucionDespliegueRepository::equiposDeTarea($pdo, (int)$t['id_programado'], $idSol, $userId);
            $t['archivos'] = SolucionRepository::archivosDeVersiones($pdo, [(int)$t['id_version']])[(int)$t['id_version']] ?? [];
        }
        unset($t);
        $idsProc = [];
        $idsDesp = [];
        foreach ($tareas as $t) {
            $idsProc = array_merge($idsProc, array_column($t['procedimientos'], 'id_procedimiento'));
            $idsDesp = array_merge($idsDesp, array_filter(array_column($t['equipos'], 'id_despliegue')));
        }
        $propias = [];
        foreach (SolucionRepository::evidenciasPor($pdo, 'id_despliegue', $idsDesp) as $idD => $lista) {
            $propias[$idD] = array_values(array_filter($lista, fn($e) => (int)$e['id_usuario_subio'] === $userId));
        }
        return ['tareas' => $tareas, 'capturas' => SolucionRepository::evidenciasPor($pdo, 'id_procedimiento', $idsProc), 'evidenciasPropias' => $propias];
    }
}
