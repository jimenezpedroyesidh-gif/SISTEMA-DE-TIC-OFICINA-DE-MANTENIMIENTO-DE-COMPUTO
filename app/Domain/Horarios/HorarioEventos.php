<?php

/**
 * Eventos del calendario semanal (horarios recurrentes con sus excepciones, préstamos y mantenimientos) y
 * detección de choques por sala/fecha/hora.
 */
final class HorarioEventos
{
    /**
     * Eventos de la sala que ocupan ese instante (clase con su excepción, préstamo u otro mantenimiento).
     * $fecha en formato 'Y-m-d'; $hora en formato 'H:i' (o 'H:i:s', se recorta
     * a los primeros 5 caracteres). $idHorarioExcluir excluye un horario
     * recurrente puntual del choque (por ejemplo, al editar ese mismo horario,
     * para que no choque consigo mismo).
     */
    public static function choques(PDO $pdo, int $idSala, string $fecha, string $hora, ?int $idHorarioExcluir = null): array {
        $fechaObj = DateTime::createFromFormat('Y-m-d', $fecha);
        if (!$fechaObj) {
            return [];
        }
        $horaCorta = substr($hora, 0, 5);
        $diaISO = (int)$fechaObj->format('N');
        $lunes = (clone $fechaObj)->modify('-' . ($diaISO - 1) . ' days')->setTime(0, 0, 0);

        $eventos = self::semana($pdo, $lunes, $idSala);
        $choques = [];
        foreach ($eventos as $e) {
            if ($e['fecha'] !== $fecha) continue;
            if ($idHorarioExcluir && $e['origen'] === 'HORARIO' && $e['id_origen'] === $idHorarioExcluir) continue;
            if ($horaCorta >= $e['hora_inicio'] && $horaCorta < $e['hora_fin']) {
                $choques[] = $e;
            }
        }
        return $choques;
    }

    /**
     * Todos los eventos de una semana en un solo formato: horarios recurrentes llevados a su fecha real,
     * préstamos de sala/equipo y mantenimientos programados.
     * $lunesDeLaSemana debe ser la medianoche del lunes de la semana a mostrar
     * (quien llama ya la calculó). $idSala filtra a una sola sala; null trae
     * todas.
     *
     * Si un horario recurrente tiene una fila en `excepciones_horario` para la fecha exacta de esta semana,
     * se aplica antes de devolver el evento -- CANCELADO lo excluye, CAMBIO_SALA/CAMBIO_HORA lo devuelven con
     * la sala/hora nueva y editable_solo_esta_semana=true. Por eso el filtro $idSala para horarios se aplica
     * en PHP después de resolver la excepción, no en el SQL: un CAMBIO_SALA puede mover el evento dentro o
     * fuera de la sala pedida.
     *
     * Cada evento trae origen/id_origen/fecha/hora_inicio/hora_fin/id_sala/titulo/detalle/estado/
     * editable_solo_esta_semana, más campos opcionales para las reglas de visibilidad por rol (id_usuario y
     * tipo_horario en HORARIO, profesor_id en PRESTAMO) y la excepción aplicada (nota_excepcion/id_excepcion).
     */
    public static function semana(PDO $pdo, DateTime $lunesDeLaSemana, ?int $idSala = null): array {
        $lunes = (clone $lunesDeLaSemana)->setTime(0, 0, 0);
        $domingo = (clone $lunes)->modify('+6 days')->setTime(23, 59, 59);
        $eventos = [];

        // ---- 1) Horarios recurrentes, expandidos a la fecha real de esta
        //         semana. Solo se expande el periodo académico en curso: un
        //         horario de otro semestre no tiene fecha real en esta semana.
        //         NOTA: aquí NO se filtra por $idSala en el SQL -- una excepción
        //         (ver más abajo) puede cambiar la sala de una fecha puntual, así
        //         que hay que resolver la sala final antes de poder filtrar.
        $periodo = HorarioPeriodo::actual();
        $stmt = $pdo->prepare("SELECT h.id_horario, h.id_sala, h.id_usuario, h.tipo, h.dia_semana,
                                       h.hora_inicio, h.hora_fin, h.detalle, u.nombre_completo AS usuario_nombre
                                FROM horarios h
                                LEFT JOIN users u ON h.id_usuario = u.id
                                WHERE h.periodo = ?");
        $stmt->execute([$periodo]);
        $filasHorarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Excepciones puntuales de estos horarios que caen en esta semana, indexadas por id_horario + fecha
        // para O(1) al expandir.
        $excepcionesPorHorarioFecha = [];
        $idsHorarios = array_column($filasHorarios, 'id_horario');
        if ($idsHorarios) {
            $marcadores = implode(',', array_fill(0, count($idsHorarios), '?'));
            $stmtExc = $pdo->prepare("SELECT e.id_excepcion, e.id_horario, e.fecha, e.tipo_excepcion,
                                              e.nueva_id_sala, e.nueva_hora_inicio, e.nueva_hora_fin, e.motivo,
                                              s.nombre_sala AS nueva_sala_nombre
                                       FROM excepciones_horario e
                                       LEFT JOIN salas s ON e.nueva_id_sala = s.id_sala
                                       WHERE e.id_horario IN ($marcadores) AND e.fecha BETWEEN ? AND ?");
            $stmtExc->execute([...$idsHorarios, $lunes->format('Y-m-d'), $domingo->format('Y-m-d')]);
            foreach ($stmtExc->fetchAll(PDO::FETCH_ASSOC) as $exc) {
                $excepcionesPorHorarioFecha[$exc['id_horario']][$exc['fecha']] = $exc;
            }
        }

        $tituloTipoHorario = [
            'CLASE' => 'Clase',
            'TURNO_BECA' => 'Turno de beca-trabajo',
            'TURNO_TECNICO' => 'Turno de técnico',
        ];
        foreach ($filasHorarios as $h) {
            $dia = (int)$h['dia_semana'];
            if ($dia < 1 || $dia > 7) continue;
            $fecha = (clone $lunes)->modify('+' . ($dia - 1) . ' days')->format('Y-m-d');
            $titulo = $tituloTipoHorario[$h['tipo']] ?? $h['tipo'];
            if ($h['tipo'] === 'CLASE' && $h['detalle']) {
                $titulo .= ' - ' . $h['detalle'];
            }

            $idSalaFinal = $h['id_sala'] !== null ? (int)$h['id_sala'] : null;
            $horaInicioFinal = substr($h['hora_inicio'], 0, 5);
            $horaFinFinal = substr($h['hora_fin'], 0, 5);
            $editable = false;
            $notaExcepcion = null;

            $exc = $excepcionesPorHorarioFecha[$h['id_horario']][$fecha] ?? null;
            if ($exc) {
                if ($exc['tipo_excepcion'] === 'CANCELADO') {
                    // No se incluye el evento esta semana.
                    continue;
                }
                if ($exc['tipo_excepcion'] === 'CAMBIO_SALA') {
                    $idSalaFinal = $exc['nueva_id_sala'] !== null ? (int)$exc['nueva_id_sala'] : null;
                    $notaExcepcion = 'Cambiado de sala esta semana' . ($exc['nueva_sala_nombre'] ? ' (' . $exc['nueva_sala_nombre'] . ')' : '');
                } elseif ($exc['tipo_excepcion'] === 'CAMBIO_HORA') {
                    $horaInicioFinal = substr($exc['nueva_hora_inicio'], 0, 5);
                    $horaFinFinal = substr($exc['nueva_hora_fin'], 0, 5);
                    $notaExcepcion = 'Cambiado de hora esta semana';
                }
                $editable = true;
            }

            if ($idSala !== null && $idSalaFinal !== $idSala) continue;

            $eventos[] = [
                'origen' => 'HORARIO',
                'id_origen' => (int)$h['id_horario'],
                'fecha' => $fecha,
                'hora_inicio' => $horaInicioFinal,
                'hora_fin' => $horaFinFinal,
                'id_sala' => $idSalaFinal,
                'titulo' => $titulo,
                'detalle' => $h['detalle'],
                'estado' => null,
                'editable_solo_esta_semana' => $editable,
                'nota_excepcion' => $notaExcepcion,
                'id_excepcion' => $exc ? (int)$exc['id_excepcion'] : null,
                'excepcion_tipo' => $exc['tipo_excepcion'] ?? null,
                'excepcion_motivo' => $exc['motivo'] ?? null,
                'tipo_horario' => $h['tipo'],
                'id_usuario' => $h['id_usuario'] !== null ? (int)$h['id_usuario'] : null,
                'usuario_nombre' => $h['usuario_nombre'],
            ];
        }

        // ---- 2) Préstamos con fecha puntual (tickets_profesor). No se excluyen
        //         solicitudes todavía "En espera"/"En proceso": un profesor
        //         necesita ver que esa franja YA está pedida aunque el técnico
        //         no la haya aprobado, para no chocar al programar algo ahí. Sí
        //         se excluyen las que ya no van a ocurrir.
        $sqlPrestamos = "SELECT tp.id, tp.profesor_id, tp.subtipo_requerimiento, tp.id_sala, tp.motivo,
                                 tp.fecha_requerida, tp.fecha_devolucion_prevista, tp.duracion_prestamo, tp.estado,
                                 u.nombre_completo AS profesor_nombre
                          FROM tickets_profesor tp
                          LEFT JOIN users u ON tp.profesor_id = u.id
                          WHERE tp.subtipo_requerimiento IN ('PRESTAMO_SALA','PRESTAMO_EQUIPO')
                            AND tp.estado NOT IN ('Rechazado','Descartado','Cerrado')
                            AND tp.fecha_requerida IS NOT NULL
                            AND tp.fecha_requerida <= ?
                            AND (tp.fecha_devolucion_prevista IS NULL OR tp.fecha_devolucion_prevista >= ?)";
        $paramsPrestamos = [$domingo->format('Y-m-d'), $lunes->format('Y-m-d')];
        if ($idSala !== null) {
            $sqlPrestamos .= " AND tp.id_sala = ?";
            $paramsPrestamos[] = $idSala;
        }
        $stmt = $pdo->prepare($sqlPrestamos);
        $stmt->execute($paramsPrestamos);
        // La tabla no guarda hora de inicio del préstamo, solo la fecha -- se
        // ancla a las 06:00 (apertura del edificio) y se estima el fin según la
        // duración elegida. Si algún día se agrega hora real al formulario de
        // requerimiento, reemplazar esta estimación por la hora real.
        $duracionAHoraFin = ['1_HORA' => '07:00', '2_HORAS' => '08:00', 'TODO_DIA' => '22:00'];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $horaFin = $duracionAHoraFin[$p['duracion_prestamo']] ?? '07:00';
            $eventos[] = [
                'origen' => 'PRESTAMO',
                'id_origen' => (int)$p['id'],
                'fecha' => $p['fecha_requerida'],
                'hora_inicio' => '06:00',
                'hora_fin' => $horaFin,
                'id_sala' => $p['id_sala'] !== null ? (int)$p['id_sala'] : null,
                'titulo' => $p['subtipo_requerimiento'] === 'PRESTAMO_SALA' ? 'Préstamo de sala' : 'Préstamo de equipo',
                'detalle' => $p['motivo'],
                'estado' => $p['estado'],
                'editable_solo_esta_semana' => false,
                'profesor_id' => $p['profesor_id'] !== null ? (int)$p['profesor_id'] : null,
                'profesor_nombre' => $p['profesor_nombre'],
            ];
        }

        // ---- 3) Mantenimientos programados con fecha puntual.
        $sqlMant = "SELECT m.id_programado, m.id_sala, m.tipo, m.descripcion, m.fecha_programada, m.duracion_minutos, m.estado
                    FROM mantenimientos_programados m
                    WHERE m.fecha_programada BETWEEN ? AND ? AND m.estado <> 'CANCELADO'";
        $paramsMant = [$lunes->format('Y-m-d H:i:s'), $domingo->format('Y-m-d H:i:s')];
        if ($idSala !== null) {
            $sqlMant .= " AND m.id_sala = ?";
            $paramsMant[] = $idSala;
        }
        $stmt = $pdo->prepare($sqlMant);
        $stmt->execute($paramsMant);
        $tituloTipoMant = [
            'MANTENIMIENTO_PREVENTIVO' => 'Mantenimiento preventivo',
            'INSTALACION_SOFTWARE' => 'Instalación de software',
            'OTRO' => 'Mantenimiento',
        ];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $ts = strtotime($m['fecha_programada']);
            $eventos[] = [
                'origen' => 'MANTENIMIENTO',
                'id_origen' => (int)$m['id_programado'],
                'fecha' => date('Y-m-d', $ts),
                'hora_inicio' => date('H:i', $ts),
                // Duración estimada del mantenimiento (60 min por defecto). Si pasa de la
                // medianoche se corta en 23:59 para no salirse del día en la grilla.
                'hora_fin' => date('Y-m-d', $ts + 60 * max(15, (int)$m['duracion_minutos'])) !== date('Y-m-d', $ts)
                    ? '23:59' : date('H:i', $ts + 60 * max(15, (int)$m['duracion_minutos'])),
                'id_sala' => (int)$m['id_sala'],
                'titulo' => $tituloTipoMant[$m['tipo']] ?? $m['tipo'],
                'detalle' => $m['descripcion'],
                'estado' => $m['estado'],
                'editable_solo_esta_semana' => false,
            ];
        }

        return $eventos;
    }

    /**
     * Al aprobar un requerimiento de profesor de subtipo CAMBIO_SALA que apunta a un horario recurrente
     * (id_horario_referencia), crea automáticamente la excepción "solo esta semana" correspondiente en
     * excepciones_horario, en vez de dejar el cambio solo en el texto del ticket. Devuelve false, sin lanzar
     * error, si el ticket no es CAMBIO_SALA o le falta algún dato.
     *
     * El ticket de CAMBIO_SALA no guarda una fecha puntual -- solo referencia el
     * horario recurrente completo (ver create_ticket_profesor.php) -- así que se
     * asume que el cambio aplica a la próxima vez que caiga esa clase desde hoy
     * (hoy mismo si su día de la semana ya no pasó, si no la semana siguiente),
     * que es el caso de uso que describe el prompt ("por ejemplo, por un daño":
     * una necesidad inmediata). Si el profesor necesitara el cambio para una
     * semana más adelante, un admin/técnico puede mover la excepción directo
     * desde el calendario (botón en horarios.php).
     */
    public static function crearExcepcionPorCambioSalaAprobado(PDO $pdo, int $idTicketProfesor, int $idAprobador): bool {
        $stmt = $pdo->prepare("SELECT subtipo_requerimiento, id_horario_referencia, id_sala, motivo
                                FROM tickets_profesor WHERE id = ?");
        $stmt->execute([$idTicketProfesor]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket || $ticket['subtipo_requerimiento'] !== 'CAMBIO_SALA'
            || !$ticket['id_horario_referencia'] || !$ticket['id_sala']) {
            return false;
        }

        $stmtH = $pdo->prepare("SELECT id_horario, dia_semana FROM horarios WHERE id_horario = ?");
        $stmtH->execute([(int)$ticket['id_horario_referencia']]);
        $horario = $stmtH->fetch(PDO::FETCH_ASSOC);
        if (!$horario) {
            return false;
        }

        $hoy = new DateTime();
        $diffDias = ((int)$horario['dia_semana'] - (int)$hoy->format('N') + 7) % 7;
        $fecha = (clone $hoy)->modify("+$diffDias days")->format('Y-m-d');

        $motivoTexto = 'Aprobado por ticket #' . $idTicketProfesor . ($ticket['motivo'] ? (': ' . $ticket['motivo']) : '');
        $stmtUp = $pdo->prepare(
            "INSERT INTO excepciones_horario (id_horario, fecha, tipo_excepcion, nueva_id_sala, motivo, id_creador)
             VALUES (?, ?, 'CAMBIO_SALA', ?, ?, ?)
             ON DUPLICATE KEY UPDATE tipo_excepcion='CAMBIO_SALA', nueva_id_sala=VALUES(nueva_id_sala),
                                      nueva_hora_inicio=NULL, nueva_hora_fin=NULL, motivo=VALUES(motivo), id_creador=VALUES(id_creador)"
        );
        $stmtUp->execute([(int)$horario['id_horario'], $fecha, (int)$ticket['id_sala'], $motivoTexto, $idAprobador]);
        MantenimientoAgenda::avisarCruces($pdo, (int)$ticket['id_sala'], "Cambio de sala aprobado (ticket #$idTicketProfesor)");
        return true;
    }
}
