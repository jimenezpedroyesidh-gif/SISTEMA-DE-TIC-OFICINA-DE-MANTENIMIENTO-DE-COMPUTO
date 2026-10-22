<?php
/**
 * MantenimientoCampanaService — «Mantenimientos programados generales» (campañas en varias salas).
 *
 * Una campaña es una sola actividad (actualizar un programa, instalar una aplicación, revisión preventiva…)
 * que se ejecuta en las salas elegidas. Al crearla se genera una intervención por sala (una fila normal de
 * mantenimientos_programados con su checklist), en el primer espacio libre de cada sala según clases, turnos,
 * préstamos, otros mantenimientos y la agenda del coordinador (MantenimientoAgenda).
 *
 * «Finalizar una sala antes de continuar con la siguiente» (secuencial):
 *  - Activada: solo la primera sala queda PROGRAMADA; las demás quedan PENDIENTES (bloqueadas: no se puede
 *    tomar ni marcar equipos ni iniciarlas). Cuando una sala se cierra como REALIZADO —lo que exige la checklist
 *    completa y la evidencia, no solo cambiar un estado— o se cancela, se libera la siguiente; si su fecha ya
 *    pasó o ahora choca con algo, se mueve al primer espacio libre y el cambio queda en su historial.
 *  - Desactivada: todas las salas quedan PROGRAMADAS y se atienden de forma independiente.
 * Cuando ya no queda ninguna intervención en curso, la campaña queda FINALIZADA.
 *
 * Mismos permisos que programar: crea y cancela solo el técnico; el admin supervisa.
 */
final class MantenimientoCampanaService
{
    private static function r(string $error = '', string $exito = '', int $id = 0): array
    {
        return ['error' => $error, 'exito' => $exito, 'id' => $id];
    }

    /**
     * @param array $in claves: nombre, tipo, alcance_equipo, descripcion, actividades, enlace_referencia,
     *                  fecha_inicio, duracion_minutos, secuencial, id_responsable, salas[] (en el orden de ejecución)
     */
    public static function crear(PDO $pdo, int $userId, array $in): array
    {
        $nombre = trim((string)($in['nombre'] ?? ''));
        $tipo = (string)($in['tipo'] ?? '');
        $alcance = (string)($in['alcance_equipo'] ?? 'TODOS');
        $descripcion = trim((string)($in['descripcion'] ?? ''));
        $actividades = trim((string)($in['actividades'] ?? ''));
        $enlace = trim((string)($in['enlace_referencia'] ?? ''));
        $duracion = MantenimientoAgenda::duracion($in['duracion_minutos'] ?? 60);
        $secuencial = !empty($in['secuencial']);
        $idResponsable = !empty($in['id_responsable']) ? (int)$in['id_responsable'] : null;
        $ts = strtotime((string)($in['fecha_inicio'] ?? ''));
        $accion = !empty($in['id_accion_origen']) ? MantenimientoHistorialService::prefillDesdeAccion($pdo, (int)$in['id_accion_origen']) : null;

        $salasPedidas = [];
        foreach ((array)($in['salas'] ?? []) as $v) {
            $v = (int)$v;
            if ($v > 0 && !in_array($v, $salasPedidas, true)) {
                $salasPedidas[] = $v;
            }
        }

        if ($nombre === '' || $descripcion === '' || !in_array($tipo, MantenimientoService::TIPOS, true)) {
            return self::r('Completa el nombre, el tipo y la descripción de la campaña.');
        }
        if (!$ts) {
            return self::r('La fecha de inicio no es válida.');
        }
        if (count($salasPedidas) < 2) {
            return self::r('Una campaña es para varias salas: elige al menos dos (para una sola, programa un mantenimiento de sala).');
        }
        if ($enlace !== '' && !filter_var($enlace, FILTER_VALIDATE_URL)) {
            return self::r('El enlace de referencia no es una URL válida (debe empezar por http:// o https://).');
        }
        if ($idResponsable !== null && MantenimientoRepository::rolDeUsuario($pdo, $idResponsable) !== Rol::TECNICO) {
            $idResponsable = null;
        }
        if (!in_array($alcance, MantenimientoService::ALCANCES, true) || $alcance === 'SELECCION') { // la campaña no elige equipos
            $alcance = 'TODOS';
        }
        if ($tipo === 'INSTALACION_SOFTWARE') {
            $alcance = 'COMPUTO';
        }

        $elegibles = array_column(MantenimientoCampanaRepository::salasElegibles($pdo), null, 'id_sala');
        $salas = [];
        $omitidas = [];
        foreach ($salasPedidas as $idSala) {
            if (!isset($elegibles[$idSala])) {
                return self::r('Una de las salas elegidas no existe o es una oficina de mantenimiento.');
            }
            // Una sala sin equipos en el alcance no tendría checklist: no se puede cerrar con resultados.
            if (MantenimientoRepository::contarEquiposAlcance($pdo, $idSala, $alcance) === 0) {
                $omitidas[] = $elegibles[$idSala]['nombre_sala'];
                continue;
            }
            $salas[] = $idSala;
        }
        if (count($salas) === 0) {
            return self::r('Ninguna de las salas elegidas tiene equipos registrados para ese alcance; no hay nada que revisar.');
        }

        $inicio = new DateTime(date('Y-m-d H:i:00', $ts));
        try {
            $resultado = Database::transaccion(function (PDO $pdo) use ($nombre, $tipo, $alcance, $descripcion, $actividades, $enlace, $inicio, $duracion, $secuencial, $idResponsable, $userId, $salas, $elegibles, $accion) {
                $idCampana = MantenimientoCampanaRepository::insertar($pdo, [
                    'nombre' => $nombre, 'tipo' => $tipo, 'alcance' => $alcance, 'descripcion' => $descripcion,
                    'actividades' => $actividades !== '' ? $actividades : null, 'enlace' => $enlace !== '' ? $enlace : null,
                    'fecha_inicio' => $inicio->format('Y-m-d H:i:s'), 'duracion' => $duracion, 'secuencial' => $secuencial,
                    'id_responsable' => $idResponsable, 'id_creador' => $userId,
                ]);
                $cursor = clone $inicio;
                $cronograma = [];
                foreach ($salas as $i => $idSala) {
                    MantenimientoAgenda::limpiarCache(); // ve también lo que se acaba de insertar
                    // Secuencial: cada sala después de la anterior. Independiente: todas desde la fecha de inicio
                    // (si comparten coordinador, la agenda evita que le queden dos a la vez).
                    $desde = $secuencial ? $cursor : $inicio;
                    $hueco = MantenimientoAgenda::primerHueco($pdo, $idSala, $desde, $duracion, $idResponsable);
                    if (!$hueco) {
                        throw new DomainException('No hay un espacio libre para ' . $elegibles[$idSala]['nombre_sala'] . ' en los próximos ' . MantenimientoAgenda::DIAS_BUSQUEDA . ' días.');
                    }
                    $estado = ($secuencial && $i > 0) ? 'PENDIENTE' : 'PROGRAMADO';
                    $idProg = MantenimientoRepository::insertar($pdo, $idSala, null, $tipo, $alcance, $descripcion,
                        $enlace !== '' ? $enlace : null, $hueco->format('Y-m-d H:i:s'), $idResponsable, $userId, null, null,
                        $duracion, $actividades !== '' ? $actividades : null, $idCampana, $i + 1, $estado);
                    MantenimientoRepository::generarChecklist($pdo, $idProg, $idSala, $alcance);
                    $cursor = (clone $hueco)->modify("+$duracion minutes");
                    $cronograma[] = $elegibles[$idSala]['nombre_sala'] . ' ' . $hueco->format('d/m H:i');
                    if ($accion && $i === 0) {
                        // La acción queda ligada a la primera sala; se da por HECHA cuando termina la campaña completa.
                        MantenimientoHistorialRepository::enlazarAccion($pdo, $accion['id_accion'], $idProg);
                    }
                }
                return ['id' => $idCampana, 'cronograma' => $cronograma];
            }, $pdo, 'crear_campana');
        } catch (DomainException $e) {
            return self::r($e->getMessage());
        } finally {
            MantenimientoAgenda::limpiarCache();
        }

        $idCampana = (int)$resultado['id'];
        AuditoriaService::registrar($pdo, $userId, 'mantenimiento_campanas', $idCampana, 'CREAR', count($salas) . ' salas' . ($secuencial ? ', secuencial' : ''));
        if ($idResponsable && $idResponsable !== $userId) {
            NotificationService::crear($pdo, $idResponsable, 'MANTENIMIENTO', "Te asignaron como coordinador de la campaña «{$nombre}» (" . count($salas) . ' salas).', 'mantenimiento_campanas.php?id=' . $idCampana);
        }
        $msg = 'Campaña creada con ' . count($salas) . ' salas: ' . implode(' · ', $resultado['cronograma']) . '.';
        if ($omitidas) {
            $msg .= ' Se omitieron por no tener equipos en el alcance: ' . implode(', ', $omitidas) . '.';
        }
        return self::r('', $msg, $idCampana);
    }

    /**
     * Se llama cuando una intervención de la campaña se cierra (REALIZADO o CANCELADO).
     * Libera la siguiente sala si la campaña es secuencial y la finaliza si ya no queda nada.
     */
    public static function alCerrarIntervencion(PDO $pdo, int $idCampana, ?int $userId): void
    {
        $c = MantenimientoCampanaRepository::buscar($pdo, $idCampana);
        if (!$c || $c['estado'] !== 'ACTIVA') {
            return;
        }
        if ((int)$c['secuencial'] === 1 && !MantenimientoCampanaRepository::hayEnCurso($pdo, $idCampana)) {
            $sig = MantenimientoCampanaRepository::primeraBloqueada($pdo, $idCampana);
            if ($sig) {
                self::liberar($pdo, $c, $sig, $userId);
            }
        }
        if (MantenimientoCampanaRepository::contarActivas($pdo, $idCampana) === 0) {
            MantenimientoCampanaRepository::cerrarCampana($pdo, $idCampana, 'FINALIZADA');
            MantenimientoHistorialRepository::marcarHechasPorMantenimiento($pdo, array_column(MantenimientoCampanaRepository::intervenciones($pdo, $idCampana), 'id_programado'));
            NotificationService::crear($pdo, (int)$c['id_creador'], 'MANTENIMIENTO', "La campaña «{$c['nombre']}» terminó en todas sus salas.", 'mantenimiento_campanas.php?id=' . $idCampana);
        }
    }

    /** Libera la sala bloqueada: queda PROGRAMADA; si su fecha ya pasó o ahora choca, se mueve al primer hueco. */
    private static function liberar(PDO $pdo, array $c, array $m, ?int $userId): void
    {
        MantenimientoCampanaRepository::desbloquear($pdo, (int)$m['id_programado']);
        $m['estado'] = 'PROGRAMADO';
        $duracion = MantenimientoAgenda::duracion($m['duracion_minutos'] ?? 60);
        $idResp = !empty($m['id_responsable']) ? (int)$m['id_responsable'] : null;
        $fecha = new DateTime((string)$m['fecha_programada']);
        MantenimientoAgenda::limpiarCache();
        $ocupada = MantenimientoAgenda::choques($pdo, (int)$m['id_sala'], $fecha, $duracion, (int)$m['id_programado'], $idResp);
        if ($fecha < new DateTime() || $ocupada) {
            $nueva = MantenimientoAgenda::primerHueco($pdo, (int)$m['id_sala'], new DateTime('+30 minutes'), $duracion, $idResp, (int)$m['id_programado']);
            if ($nueva) {
                MantenimientoService::moverFecha($pdo, $m, $nueva, 'SECUENCIA', 'La sala anterior de la campaña terminó', $userId);
                $fecha = $nueva;
            }
        }
        $sala = MantenimientoRepository::nombreSala($pdo, (int)$m['id_sala']);
        $texto = "Campaña «{$c['nombre']}»: ya puede empezar la sala {$sala} (" . $fecha->format('d/m/Y H:i') . ').';
        foreach (array_unique(array_filter([(int)($c['id_responsable'] ?? 0), (int)$c['id_creador']])) as $idU) {
            NotificationService::crear($pdo, $idU, 'MANTENIMIENTO', $texto, 'mantenimientos_programados.php?campana=' . (int)$c['id_campana']);
        }
    }

    /** Cancela la campaña: las salas que no han empezado quedan canceladas; las que están en proceso se cierran a mano. */
    public static function cancelar(PDO $pdo, int $userId, int $idCampana, string $motivo): array
    {
        $c = $idCampana ? MantenimientoCampanaRepository::buscar($pdo, $idCampana) : false;
        if (!$c || $c['estado'] !== 'ACTIVA') {
            return self::r('Esa campaña ya no está activa.');
        }
        if ($motivo === '') {
            return self::r('Escribe el motivo de la cancelación.');
        }
        $n = Database::transaccion(function (PDO $pdo) use ($idCampana, $motivo) {
            $n = MantenimientoCampanaRepository::cancelarNoIniciadas($pdo, $idCampana, 'Campaña cancelada: ' . $motivo);
            if (MantenimientoCampanaRepository::contarActivas($pdo, $idCampana) === 0) {
                MantenimientoCampanaRepository::cerrarCampana($pdo, $idCampana, 'CANCELADA');
                MantenimientoHistorialRepository::liberarAccionesPorMantenimiento($pdo, array_column(MantenimientoCampanaRepository::intervenciones($pdo, $idCampana), 'id_programado'));
            }
            return $n;
        }, $pdo, 'cancelar_campana');
        AuditoriaService::registrar($pdo, $userId, 'mantenimiento_campanas', $idCampana, 'EDITAR', "cancelada ($n salas sin iniciar): $motivo");
        $sigue = MantenimientoCampanaRepository::contarActivas($pdo, $idCampana) > 0;
        return self::r('', "Se cancelaron $n salas que no habían empezado." . ($sigue ? ' Las que están en proceso siguen abiertas: ciérralas con su resultado.' : ''));
    }

    // ───────────────────────── Datos de la pantalla

    public static function datosPantalla(PDO $pdo, int $idSeleccionada): array
    {
        $campana = $idSeleccionada ? MantenimientoCampanaRepository::buscar($pdo, $idSeleccionada) : false;
        $intervenciones = $campana ? MantenimientoCampanaRepository::intervenciones($pdo, $idSeleccionada) : [];
        $mantParaConflictos = array_map(fn($i) => $i + ['id_programado' => $i['id_programado']], $intervenciones);
        return [
            'campanas'        => MantenimientoCampanaRepository::listar($pdo),
            'campana'         => $campana ?: null,
            'intervenciones'  => $intervenciones,
            'conflictos'      => MantenimientoAgenda::conflictos($pdo, $mantParaConflictos),
            'salasElegibles'  => MantenimientoCampanaRepository::salasElegibles($pdo),
            'coordinadores'   => array_values(array_filter(MantenimientoRepository::tecnicosYBecas($pdo), fn($r) => $r['role'] === Rol::TECNICO)),
            'chipEstado'      => MantenimientoService::CHIP_ESTADO,
        ];
    }
}
