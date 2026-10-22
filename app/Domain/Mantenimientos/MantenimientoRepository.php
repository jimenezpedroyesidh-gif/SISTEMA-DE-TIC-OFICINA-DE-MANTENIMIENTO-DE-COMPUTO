<?php
/**
 * MantenimientoRepository — persistencia del módulo «Mantenimientos programados»
 * (tablas mantenimientos_programados y mantenimiento_equipos, más lecturas puntuales de
 * tickets / tickets_profesor / equipos / users / ticket_adjuntos).
 *
 * Solo SQL con consultas preparadas; las reglas de negocio viven en MantenimientoService.
 * Los tickets de origen los escriben los servicios de tickets; aquí solo se leen y se cierran al terminar
 * un mantenimiento elevado desde ellos.
 */
final class MantenimientoRepository
{
    // ───────────────────────── Lecturas puntuales

    public static function salaExiste(PDO $pdo, int $idSala): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM salas WHERE id_sala = ?");
        $st->execute([$idSala]);
        return (bool)$st->fetchColumn();
    }

    /**
     * De los equipos pedidos, los que existen y están en esa sala; con $soloComputo, además con torre/portátil/
     * todo en uno (instalar software). Nunca se confía en los ids del formulario.
     * @return int[]
     */
    public static function equiposValidosDeSala(PDO $pdo, int $idSala, array $ids, bool $soloComputo = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $computo = $soloComputo ? " AND EXISTS (SELECT 1 FROM componentes c WHERE c.id_equipo_actual = e.id_equipo AND c.tipo IN ('torre','portatil','todo_en_uno'))" : '';
        $st = $pdo->prepare("SELECT e.id_equipo FROM equipos e WHERE e.id_sala = ? AND e.id_equipo IN ($in)$computo");
        $st->execute(array_merge([$idSala], $ids));
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function rolDeUsuario(PDO $pdo, int $idUsuario)
    {
        $st = $pdo->prepare("SELECT role FROM users WHERE id=?");
        $st->execute([$idUsuario]);
        return $st->fetchColumn();
    }

    /** Nombre de un técnico o beca (false si el usuario no existe o es de otro rol). */
    public static function nombreStaff(PDO $pdo, int $idUsuario)
    {
        $st = $pdo->prepare("SELECT nombre_completo FROM users WHERE id=? AND role IN ('beca_trabajo','tecnico')");
        $st->execute([$idUsuario]);
        return $st->fetchColumn();
    }

    public static function ticketParaElevar(PDO $pdo, int $id)
    {
        $st = $pdo->prepare("SELECT id, id_sala, origen, estado, firmado, tecnico_id FROM tickets WHERE id=?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /** `software` se expone como software_especifico (nombre que usa la regla de elegibilidad). */
    public static function ticketProfesorParaElevar(PDO $pdo, int $id)
    {
        $st = $pdo->prepare("SELECT id, id_sala, tipo_ticket, subtipo_requerimiento, software AS software_especifico, estado, firmado, tecnico_id FROM tickets_profesor WHERE id=?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /** Datos del ticket de profesor al validar el POST de programar. */
    public static function ticketProfesorParaProgramar(PDO $pdo, int $id)
    {
        $st = $pdo->prepare("SELECT id, tipo_ticket, subtipo_requerimiento, id_sala, estado, firmado, tecnico_id FROM tickets_profesor WHERE id=?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /** Datos del ticket normal al validar el POST de programar. */
    public static function ticketParaProgramar(PDO $pdo, int $id)
    {
        $st = $pdo->prepare("SELECT id, origen, firmado, tecnico_id FROM tickets WHERE id=?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * id_programado del mantenimiento ya creado desde ese ticket o al que quedó vinculado (0 si no hay).
     * Sin filtro de estado: una sola elevación por ticket, para siempre.
     */
    public static function programadoDeTicket(PDO $pdo, int $idTicket): int
    {
        $st = $pdo->prepare("SELECT COALESCE(
            (SELECT id_programado FROM mantenimientos_programados WHERE id_ticket_origen=? LIMIT 1),
            (SELECT id_programado FROM mantenimiento_vinculos WHERE tabla_ticket='tickets' AND id_ticket=? LIMIT 1))");
        $st->execute([$idTicket, $idTicket]);
        return (int)$st->fetchColumn();
    }

    public static function programadoDeTicketProfesor(PDO $pdo, int $idTicketProfesor): int
    {
        $st = $pdo->prepare("SELECT COALESCE(
            (SELECT id_programado FROM mantenimientos_programados WHERE id_ticket_profesor_origen=? LIMIT 1),
            (SELECT id_programado FROM mantenimiento_vinculos WHERE tabla_ticket='tickets_profesor' AND id_ticket=? LIMIT 1))");
        $st->execute([$idTicketProfesor, $idTicketProfesor]);
        return (int)$st->fetchColumn();
    }

    public static function nombreSala(PDO $pdo, int $idSala): string
    {
        $st = $pdo->prepare("SELECT nombre_sala FROM salas WHERE id_sala=?");
        $st->execute([$idSala]);
        return (string)$st->fetchColumn();
    }

    /** Estado actual del mantenimiento ('' si no existe). */
    public static function estadoDe(PDO $pdo, int $idProgramado): string
    {
        $st = $pdo->prepare("SELECT estado FROM mantenimientos_programados WHERE id_programado=?");
        $st->execute([$idProgramado]);
        return (string)$st->fetchColumn();
    }

    // ───────────────────────── Reportes vinculados (mantenimiento_vinculos)

    /**
     * Reportes abiertos que el técnico tomó y puede atender en un mantenimiento de sala: daños y QR de la tabla
     * tickets (mismos orígenes que se pueden elevar) y daños / instalación de software de profesores, que aún no
     * se elevaron ni se vincularon a otro mantenimiento. $idSala null = de todas las salas (formulario).
     * @return list<array{tabla:string,id:int,id_sala:int,texto:string}>
     */
    public static function ticketsVinculables(PDO $pdo, int $idTecnico, ?int $idSala): array
    {
        $cerrados = "'Solucionado','Cerrado','Descartado','Rechazado'";
        $filtroSala = $idSala ? ' AND t.id_sala = ?' : '';
        $sql = "
            SELECT 'tickets' AS tabla, t.id, t.id_sala, CONCAT(COALESCE(t.categoria, 'Reporte'), ' · ', LEFT(COALESCE(t.descripcion, ''), 80)) AS texto
            FROM tickets t
            WHERE t.tecnico_id = ? AND t.id_sala IS NOT NULL AND t.origen IN ('QR_ANONIMO','BECA_DIRECTO','TECNICO_DIRECTO')
              AND COALESCE(t.firmado, 0) = 0 AND t.estado NOT IN ($cerrados) $filtroSala
              AND NOT EXISTS (SELECT 1 FROM mantenimientos_programados m WHERE m.id_ticket_origen = t.id)
              AND NOT EXISTS (SELECT 1 FROM mantenimiento_vinculos v WHERE v.tabla_ticket = 'tickets' AND v.id_ticket = t.id)
            UNION ALL
            SELECT 'tickets_profesor', t.id, t.id_sala,
                   CONCAT(IF(t.tipo_ticket = 'danio', 'Daño (profesor)', 'Instalación de software'), ' · ', LEFT(COALESCE(t.descripcion_problema, t.software, t.motivo, ''), 80))
            FROM tickets_profesor t
            WHERE t.tecnico_id = ? AND t.id_sala IS NOT NULL
              AND (t.tipo_ticket = 'danio' OR t.subtipo_requerimiento = 'INSTALACION_SOFTWARE')
              AND COALESCE(t.firmado, 0) = 0 AND t.estado NOT IN ($cerrados) $filtroSala
              AND NOT EXISTS (SELECT 1 FROM mantenimientos_programados m WHERE m.id_ticket_profesor_origen = t.id)
              AND NOT EXISTS (SELECT 1 FROM mantenimiento_vinculos v WHERE v.tabla_ticket = 'tickets_profesor' AND v.id_ticket = t.id)
            ORDER BY id_sala, id";
        $p = $idSala ? [$idTecnico, $idSala, $idTecnico, $idSala] : [$idTecnico, $idTecnico];
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function vincular(PDO $pdo, int $idProgramado, string $tabla, int $idTicket, int $idUsuario): void
    {
        $st = $pdo->prepare("INSERT INTO mantenimiento_vinculos (id_programado, tabla_ticket, id_ticket, id_usuario) VALUES (?,?,?,?)");
        $st->execute([$idProgramado, $tabla, $idTicket, $idUsuario]);
    }

    /** id_programado => [ {tabla_ticket, id_ticket} ] */
    public static function vinculosDe(PDO $pdo, array $idsProgramados): array
    {
        $ids = array_values(array_unique(array_map('intval', $idsProgramados)));
        if (!$ids) {
            return [];
        }
        $st = $pdo->prepare("SELECT id_programado, tabla_ticket, id_ticket FROM mantenimiento_vinculos WHERE id_programado IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY tabla_ticket, id_ticket");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $out[(int)$v['id_programado']][] = $v;
        }
        return $out;
    }

    // ───────────────────────── Reprogramaciones

    public static function registrarReprogramacion(PDO $pdo, int $idProgramado, string $anterior, string $nueva, string $tipo, ?string $motivo, ?int $idUsuario): void
    {
        $st = $pdo->prepare("INSERT INTO mantenimiento_reprogramaciones (id_programado, fecha_anterior, fecha_nueva, tipo, motivo, id_usuario) VALUES (?,?,?,?,?,?)");
        $st->execute([$idProgramado, $anterior, $nueva, $tipo, $motivo, $idUsuario]);
    }

    public static function cambiarFecha(PDO $pdo, int $idProgramado, string $fecha, string $estado): void
    {
        $st = $pdo->prepare("UPDATE mantenimientos_programados SET fecha_programada=?, estado=? WHERE id_programado=?");
        $st->execute([$fecha, $estado, $idProgramado]);
    }

    /** id_programado => historial de cambios de fecha (más reciente primero). */
    public static function reprogramacionesDe(PDO $pdo, array $idsProgramados): array
    {
        $ids = array_values(array_unique(array_map('intval', $idsProgramados)));
        if (!$ids) {
            return [];
        }
        $st = $pdo->prepare("SELECT r.*, u.nombre_completo AS usuario_nombre FROM mantenimiento_reprogramaciones r LEFT JOIN users u ON u.id = r.id_usuario
                             WHERE r.id_programado IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY r.id DESC");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id_programado']][] = $r;
        }
        return $out;
    }

    /**
     * Cuánto hay que revisar en la sala según el alcance: equipos operativos (TODOS / COMPUTO) o, en
     * VIDEOBEAM, los videobeam de la sala (no llevan checklist de equipos).
     */
    public static function contarEquiposAlcance(PDO $pdo, int $idSala, string $alcance): int
    {
        if ($alcance === 'VIDEOBEAM') {
            return count(MantenimientoService::frenteSegunAlcance(PlanoSalaService::frentePorSalas($pdo, [$idSala], false)[$idSala] ?? [], $alcance));
        }
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM equipos e
            WHERE e.id_sala = ?
              AND e.operativo = 1
              AND (
                    ? = 'TODOS'
                    OR (? = 'COMPUTO' AND EXISTS (
                          SELECT 1 FROM componentes c
                          WHERE c.id_equipo_actual = e.id_equipo AND c.tipo IN ('torre','portatil','todo_en_uno')
                        ))
                  )
        ");
        $st->execute([$idSala, $alcance, $alcance]);
        return (int)$st->fetchColumn();
    }

    public static function duenoTicket(PDO $pdo, int $idTicket)
    {
        $st = $pdo->prepare("SELECT user_id FROM tickets WHERE id = ?");
        $st->execute([$idTicket]);
        return $st->fetchColumn();
    }

    public static function duenoTicketProfesor(PDO $pdo, int $idTicketProfesor)
    {
        $st = $pdo->prepare("SELECT profesor_id FROM tickets_profesor WHERE id = ?");
        $st->execute([$idTicketProfesor]);
        return $st->fetchColumn();
    }

    // ───────────────────────── Programar

    /** Inserta el mantenimiento (estado PROGRAMADO) y devuelve su id. */
    public static function insertar(PDO $pdo, int $idSala, ?int $idEquipo, string $tipo, string $alcance, string $descripcion, ?string $enlace, string $fecha, ?int $idResponsable, int $idCreador, ?int $idTicketOrigen, ?int $idTicketProfesorOrigen, int $duracionMinutos = 60, ?string $actividades = null, ?int $idCampana = null, ?int $ordenCampana = null, string $estado = 'PROGRAMADO'): int
    {
        $st = $pdo->prepare(
            "INSERT INTO mantenimientos_programados
                (id_sala, id_equipo, tipo, alcance_equipo, descripcion, actividades, enlace_referencia, fecha_programada, duracion_minutos, id_responsable, id_creador, id_ticket_origen, id_ticket_profesor_origen, id_campana, orden_campana, estado)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $st->execute([$idSala, $idEquipo, $tipo, $alcance, $descripcion, $actividades, $enlace, $fecha, $duracionMinutos, $idResponsable, $idCreador, $idTicketOrigen, $idTicketProfesorOrigen, $idCampana, $ordenCampana, $estado]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Una fila PENDIENTE por cada equipo operativo que está de verdad en la sala ahora
     * mismo y entra en el alcance (uno ya trasladado a otra sala no entra). VIDEOBEAM no lleva checklist:
     * se revisa el videobeam de la sala, que se muestra aparte. SELECCION: solo los equipos elegidos (ya validados
     * con equiposValidosDeSala), estén operativos o no — puede que sea justo el que hay que reparar.
     */
    public static function generarChecklist(PDO $pdo, int $idProgramado, int $idSala, string $alcance, array $idsEquipo = []): void
    {
        if ($alcance === 'VIDEOBEAM') {
            return;
        }
        if ($alcance === 'SELECCION') {
            $st = $pdo->prepare("INSERT INTO mantenimiento_equipos (id_programado, id_equipo, estado) VALUES (?, ?, 'PENDIENTE')");
            foreach ($idsEquipo as $idEq) {
                $st->execute([$idProgramado, (int)$idEq]);
            }
            return;
        }
        $st = $pdo->prepare("
            INSERT INTO mantenimiento_equipos (id_programado, id_equipo, estado)
            SELECT ?, e.id_equipo, 'PENDIENTE'
            FROM equipos e
            WHERE e.id_sala = ?
              AND e.operativo = 1
              AND (
                    ? = 'TODOS'
                    OR (? = 'COMPUTO' AND EXISTS (
                          SELECT 1 FROM componentes c
                          WHERE c.id_equipo_actual = e.id_equipo AND c.tipo IN ('torre','portatil','todo_en_uno')
                        ))
                  )
        ");
        $st->execute([$idProgramado, $idSala, $alcance, $alcance]);
    }

    // ───────────────────────── Estado del mantenimiento

    public static function buscar(PDO $pdo, int $id)
    {
        $st = $pdo->prepare("SELECT * FROM mantenimientos_programados WHERE id_programado=?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /** Equipos de la checklist que aún no están realizados ni excluidos por traslado. */
    public static function contarChecklistPendiente(PDO $pdo, int $idProgramado): int
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM mantenimiento_equipos WHERE id_programado=? AND estado NOT IN ('REALIZADO','EXCLUIDO_TRASLADO')");
        $st->execute([$idProgramado]);
        return (int)$st->fetchColumn();
    }

    public static function cerrar(PDO $pdo, int $id, string $estado, string $observaciones, int $idUsuario, ?string $resultado = null): void
    {
        $st = $pdo->prepare(
            "UPDATE mantenimientos_programados
             SET estado=?, observaciones_cierre=?, resultado=?, fecha_realizado=NOW(), id_responsable=COALESCE(id_responsable, ?)
             WHERE id_programado=?"
        );
        $st->execute([$estado, $observaciones, $resultado, $idUsuario, $id]);
    }

    public static function marcarEnProceso(PDO $pdo, int $id, string $estado, int $idUsuario): void
    {
        $st = $pdo->prepare(
            "UPDATE mantenimientos_programados
             SET estado=?, id_responsable=COALESCE(id_responsable, ?)
             WHERE id_programado=?"
        );
        $st->execute([$estado, $idUsuario, $id]);
    }

    /** Cierra el ticket que originó el mantenimiento (idempotente: no toca uno ya Cerrado). */
    public static function cerrarTicketOrigen(PDO $pdo, int $idTicket): void
    {
        $st = $pdo->prepare("UPDATE tickets SET estado='Cerrado', fecha_terminacion=NOW() WHERE id=? AND estado <> 'Cerrado'");
        $st->execute([$idTicket]);
    }

    public static function cerrarTicketProfesorOrigen(PDO $pdo, int $idTicketProfesor): void
    {
        $st = $pdo->prepare("UPDATE tickets_profesor SET estado='Cerrado', firmado=1, fecha_firmado=NOW() WHERE id=? AND estado <> 'Cerrado'");
        $st->execute([$idTicketProfesor]);
    }

    /** Usuarios que dejaron marca en la checklist de ese mantenimiento. */
    public static function participantesChecklist(PDO $pdo, int $idProgramado): array
    {
        $st = $pdo->prepare("
            SELECT DISTINCT id_usuario_ultimo FROM mantenimiento_equipos
            WHERE id_programado=? AND id_usuario_ultimo IS NOT NULL
        ");
        $st->execute([$idProgramado]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Responsable y creador (a quién avisar de lo que pasa en la checklist). */
    public static function responsables(PDO $pdo, int $idProgramado)
    {
        $st = $pdo->prepare("SELECT id_responsable, id_creador FROM mantenimientos_programados WHERE id_programado=?");
        $st->execute([$idProgramado]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    // ───────────────────────── Checklist por equipo

    /** Asignación directa por el técnico: filas no realizadas ni excluidas. Devuelve filas afectadas. */
    public static function asignarEquipo(PDO $pdo, int $idMantEquipo, int $idProgramado, int $idAsignado, int $idQuienAsigna): int
    {
        $st = $pdo->prepare(
            "UPDATE mantenimiento_equipos
             SET estado='TOMADO', id_usuario_asignado=?, id_usuario_ultimo=?, fecha_actualizacion=NOW()
             WHERE id_mant_equipo=? AND id_programado=? AND estado NOT IN ('REALIZADO','EXCLUIDO_TRASLADO','TRASLADO_PENDIENTE')"
        );
        $st->execute([$idAsignado, $idQuienAsigna, $idMantEquipo, $idProgramado]);
        return $st->rowCount();
    }

    /**
     * Tomar atómico: solo si sigue PENDIENTE o quedó INCOMPLETO (amarillo). Un equipo incompleto
     * puede retomarlo cualquier beca o el técnico para terminarlo; la observación anterior se conserva.
     * Devuelve filas afectadas.
     */
    public static function tomarEquipo(PDO $pdo, int $idMantEquipo, int $idProgramado, int $idUsuario): int
    {
        $st = $pdo->prepare(
            "UPDATE mantenimiento_equipos
             SET estado='TOMADO', id_usuario_asignado=?, id_usuario_ultimo=?, fecha_actualizacion=NOW()
             WHERE id_mant_equipo=? AND id_programado=? AND estado IN ('PENDIENTE','INCOMPLETO')"
        );
        $st->execute([$idUsuario, $idUsuario, $idMantEquipo, $idProgramado]);
        return $st->rowCount();
    }

    /** Libera un TOMADO. $soloDueno != null limita a las filas asignadas a ese usuario. */
    public static function liberarEquipo(PDO $pdo, int $idMantEquipo, ?int $soloDueno): int
    {
        $sql = "UPDATE mantenimiento_equipos SET estado='PENDIENTE', id_usuario_asignado=NULL
                WHERE id_mant_equipo=? AND estado='TOMADO'";
        $params = [$idMantEquipo];
        if ($soloDueno !== null) {
            $sql .= " AND id_usuario_asignado=?";
            $params[] = $soloDueno;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function filaChecklist(PDO $pdo, int $idMantEquipo, int $idProgramado)
    {
        $st = $pdo->prepare("SELECT id_usuario_asignado, estado FROM mantenimiento_equipos WHERE id_mant_equipo=? AND id_programado=?");
        $st->execute([$idMantEquipo, $idProgramado]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    public static function filaChecklistCompleta(PDO $pdo, int $idMantEquipo, int $idProgramado)
    {
        $st = $pdo->prepare("SELECT * FROM mantenimiento_equipos WHERE id_mant_equipo=? AND id_programado=?");
        $st->execute([$idMantEquipo, $idProgramado]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /** Update atómico con guarda de estado: nunca pisa una marca ya REALIZADA. Devuelve filas afectadas. */
    public static function marcarEquipo(PDO $pdo, int $idMantEquipo, int $idProgramado, string $estado, int $idUsuario, ?string $observaciones, ?int $idCompSaliente, ?int $idCompEntrante): int
    {
        $st = $pdo->prepare(
            "UPDATE mantenimiento_equipos
             SET estado=?, id_usuario_ultimo=?, fecha_actualizacion=NOW(), observaciones=?,
                 id_componente_saliente=?, id_componente_entrante=?
             WHERE id_mant_equipo=? AND id_programado=? AND estado NOT IN ('REALIZADO','EXCLUIDO_TRASLADO','TRASLADO_PENDIENTE')"
        );
        $st->execute([$estado, $idUsuario, $observaciones, $idCompSaliente, $idCompEntrante, $idMantEquipo, $idProgramado]);
        return $st->rowCount();
    }

    /**
     * Propuesta de traslado a la oficina de mantenimiento (bodega): el equipo deja de contarse como trabajado y
     * queda TRASLADO_PENDIENTE con la pieza que sale, hasta que el técnico lo apruebe o lo rechace.
     * Solo sobre una fila TOMADA (quien la tomó o el técnico). Devuelve filas afectadas.
     */
    public static function proponerTraslado(PDO $pdo, int $idMantEquipo, int $idProgramado, int $idUsuario, string $motivo, int $idComponente): int
    {
        $st = $pdo->prepare(
            "UPDATE mantenimiento_equipos
             SET estado='TRASLADO_PENDIENTE', id_usuario_ultimo=?, fecha_actualizacion=NOW(), observaciones=?,
                 id_componente_saliente=?, id_componente_entrante=NULL
             WHERE id_mant_equipo=? AND id_programado=? AND estado='TOMADO'"
        );
        $st->execute([$idUsuario, $motivo, $idComponente, $idMantEquipo, $idProgramado]);
        return $st->rowCount();
    }

    /** Cierra la fila tras aprobar el traslado: sale del mantenimiento (EXCLUIDO_TRASLADO). */
    public static function cerrarTrasladoAprobado(PDO $pdo, int $idMantEquipo, int $idTecnico): int
    {
        $st = $pdo->prepare(
            "UPDATE mantenimiento_equipos
             SET estado='EXCLUIDO_TRASLADO', id_usuario_ultimo=?, fecha_actualizacion=NOW(),
                 observaciones=TRIM(CONCAT(COALESCE(observaciones,''), CASE WHEN COALESCE(observaciones,'')='' THEN '' ELSE ' | ' END, 'Traslado a la oficina de mantenimiento aprobado'))
             WHERE id_mant_equipo=? AND estado='TRASLADO_PENDIENTE'"
        );
        $st->execute([$idTecnico, $idMantEquipo]);
        return $st->rowCount();
    }

    /** El técnico rechaza el traslado: vuelve a TOMADO (mismo responsable) sin pieza propuesta. */
    public static function rechazarTraslado(PDO $pdo, int $idMantEquipo, int $idProgramado, int $idTecnico, string $motivo): int
    {
        $st = $pdo->prepare(
            "UPDATE mantenimiento_equipos
             SET estado='TOMADO', id_usuario_ultimo=?, fecha_actualizacion=NOW(), id_componente_saliente=NULL,
                 observaciones=TRIM(CONCAT(COALESCE(observaciones,''), ' | Traslado rechazado por el técnico', CASE WHEN ?='' THEN '' ELSE CONCAT(': ', ?) END))
             WHERE id_mant_equipo=? AND id_programado=? AND estado='TRASLADO_PENDIENTE'"
        );
        $st->execute([$idTecnico, $motivo, $motivo, $idMantEquipo, $idProgramado]);
        return $st->rowCount();
    }

    /** ¿El componente está instalado en el equipo de esa fila de la checklist? */
    public static function componenteEstaEnEquipoDeFila(PDO $pdo, int $idComponente, int $idMantEquipo): bool
    {
        $st = $pdo->prepare(
            "SELECT 1 FROM componentes c JOIN mantenimiento_equipos me ON me.id_equipo = c.id_equipo_actual
             WHERE c.id_componente = ? AND me.id_mant_equipo = ?"
        );
        $st->execute([$idComponente, $idMantEquipo]);
        return (bool)$st->fetchColumn();
    }

    public static function revertirEquipo(PDO $pdo, int $idMantEquipo, int $idUsuario): void
    {
        $st = $pdo->prepare("UPDATE mantenimiento_equipos SET estado='PENDIENTE', id_usuario_asignado=NULL, id_usuario_ultimo=?, fecha_actualizacion=NOW() WHERE id_mant_equipo=?");
        $st->execute([$idUsuario, $idMantEquipo]);
    }

    // ───────────────────────── Lecturas de la pantalla

    public static function salas(PDO $pdo): array
    {
        return $pdo->query("SELECT id_sala, nombre_sala, abreviatura FROM salas ORDER BY nombre_sala")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function tecnicosYBecas(PDO $pdo): array
    {
        return $pdo->query("
            SELECT id, nombre_completo, role FROM users
            WHERE role = 'tecnico' OR (role = 'beca_trabajo' AND " . AreaBeca::condicionMantenimientoSQL($pdo) . ")
            ORDER BY role, nombre_completo
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Listado (próximos primero). $idUsuarioRestringido != null = vista del beca: lo suyo, lo sin
     * asignar y cualquier mantenimiento activo. Los filtros de sala/estado solo para la vista completa.
     */
    public static function listar(PDO $pdo, ?int $idUsuarioRestringido, int $filtroSala, string $filtroEstado): array
    {
        $sql = "
            SELECT m.*, s.nombre_sala, s.abreviatura, e.codigo AS equipo_codigo,
                   r.nombre_completo AS responsable_nombre, c.nombre_completo AS creador_nombre,
                   torigen.id AS ticket_origen_id
            FROM mantenimientos_programados m
            JOIN salas s ON m.id_sala = s.id_sala
            LEFT JOIN equipos e ON m.id_equipo = e.id_equipo
            LEFT JOIN users r ON m.id_responsable = r.id
            JOIN users c ON m.id_creador = c.id
            LEFT JOIN tickets torigen ON m.id_ticket_origen = torigen.id
            WHERE 1=1
        ";
        $params = [];
        if ($idUsuarioRestringido !== null) {
            $sql .= " AND (m.id_responsable = ? OR m.id_responsable IS NULL OR m.estado IN ('PENDIENTE','PROGRAMADO','REPROGRAMADO','EN_PROCESO'))";
            $params[] = $idUsuarioRestringido;
        } else {
            if ($filtroSala) {
                $sql .= " AND m.id_sala = ?";
                $params[] = $filtroSala;
            }
            if ($filtroEstado !== '') {
                $sql .= " AND m.estado = ?";
                $params[] = $filtroEstado;
            }
        }
        $sql .= " ORDER BY m.fecha_programada ASC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Mantenimientos ya cerrados (realizados o cancelados) que se muestran por página. */
    public const HISTORIAL_POR_PAGINA = 10;

    /**
     * Igual que listar(), pero el HISTORIAL (REALIZADO / CANCELADO) va por páginas, el más reciente primero.
     *
     * @return array{datos: array, pagina: int, total_paginas: int, total_historial: int}
     */
    public static function listarConHistorialPaginado(PDO $pdo, ?int $idUsuarioRestringido, int $filtroSala, string $filtroEstado, int $paginaHistorial, int $porPagina = self::HISTORIAL_POR_PAGINA, int $filtroCampana = 0): array
    {
        $select = "
            SELECT m.*, s.nombre_sala, s.abreviatura, e.codigo AS equipo_codigo,
                   r.nombre_completo AS responsable_nombre, c.nombre_completo AS creador_nombre,
                   torigen.id AS ticket_origen_id, camp.nombre AS campana_nombre, camp.secuencial AS campana_secuencial
            FROM mantenimientos_programados m
            JOIN salas s ON m.id_sala = s.id_sala
            LEFT JOIN equipos e ON m.id_equipo = e.id_equipo
            LEFT JOIN users r ON m.id_responsable = r.id
            JOIN users c ON m.id_creador = c.id
            LEFT JOIN tickets torigen ON m.id_ticket_origen = torigen.id
            LEFT JOIN mantenimiento_campanas camp ON camp.id_campana = m.id_campana";
        $activos = MantenimientoAgenda::ESTADOS_ACTIVOS;
        $cerrados = ['REALIZADO', 'CANCELADO'];
        // Igual que listar(): la vista restringida (beca) no usa los filtros de sala/estado.
        if ($idUsuarioRestringido !== null) {
            $filtroSala = 0;
            $filtroEstado = '';
            $filtroCampana = 0;
        }
        $enIn = fn(array $a) => implode(',', array_fill(0, count($a), '?'));

        // La beca ve todos los activos.
        $estadosActivos = $filtroEstado === '' ? $activos : array_values(array_intersect($activos, [$filtroEstado]));
        $filas = [];
        if ($estadosActivos) {
            $sql = "$select WHERE m.estado IN (" . $enIn($estadosActivos) . ")";
            $params = $estadosActivos;
            if ($filtroSala) { $sql .= " AND m.id_sala = ?"; $params[] = $filtroSala; }
            if ($filtroCampana) { $sql .= " AND m.id_campana = ?"; $params[] = $filtroCampana; }
            $st = $pdo->prepare($sql . " ORDER BY m.fecha_programada ASC, m.id_programado ASC");
            $st->execute($params);
            $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        }

        // 2) Historial: realizados/cancelados, del más reciente al más antiguo, por páginas.
        $estadosHist = $filtroEstado === '' ? $cerrados : array_values(array_intersect($cerrados, [$filtroEstado]));
        if (!$estadosHist) {
            return ['datos' => $filas, 'pagina' => 1, 'total_paginas' => 1, 'total_historial' => 0];
        }
        $sql = "$select WHERE m.estado IN (" . $enIn($estadosHist) . ")";
        $params = $estadosHist;
        if ($idUsuarioRestringido !== null) {
            // Misma regla que listar(): de lo ya cerrado, solo lo suyo o lo que no tenía responsable.
            $sql .= " AND (m.id_responsable = ? OR m.id_responsable IS NULL)";
            $params[] = $idUsuarioRestringido;
        }
        if ($filtroSala) { $sql .= " AND m.id_sala = ?"; $params[] = $filtroSala; }
        if ($filtroCampana) { $sql .= " AND m.id_campana = ?"; $params[] = $filtroCampana; }
        $hist = paginar($pdo, $sql . " ORDER BY m.fecha_programada DESC, m.id_programado DESC", $params, $paginaHistorial, $porPagina);
        return [
            'datos'           => array_merge($filas, $hist['datos']),
            'pagina'          => $hist['pagina'],
            'total_paginas'   => $hist['total_paginas'],
            'total_historial' => $hist['total'],
        ];
    }

    /** id_programado => filas de la checklist (ordenadas por código de equipo). */
    public static function checklistDe(PDO $pdo, array $idsProgramados): array
    {
        if (!$idsProgramados) {
            return [];
        }
        $st = $pdo->prepare("
            SELECT me.*, eq.codigo AS equipo_codigo, eq.descripcion AS equipo_descripcion, eq.id_sala, u.nombre_completo AS marcado_por, ua.nombre_completo AS asignado_nombre,
                   cs.codigo_inventario AS saliente_codigo, cs.tipo AS saliente_tipo,
                   -- ¿El cambio de pieza propuesto sigue pendiente? (misma regla que InventarioService::aplicarCambioPieza:
                   -- la pieza que sale sigue en el equipo y la que entra aún no está instalada en él)
                   CASE WHEN me.id_componente_saliente IS NULL AND me.id_componente_entrante IS NULL THEN 0
                        WHEN me.id_componente_saliente IS NOT NULL AND (cs.id_equipo_actual IS NULL OR cs.id_equipo_actual <> me.id_equipo) THEN 0
                        WHEN me.id_componente_entrante IS NOT NULL AND ce.id_equipo_actual = me.id_equipo THEN 0
                        ELSE 1 END AS pieza_pendiente
            FROM mantenimiento_equipos me
            JOIN equipos eq ON me.id_equipo = eq.id_equipo
            LEFT JOIN users u ON me.id_usuario_ultimo = u.id
            LEFT JOIN users ua ON me.id_usuario_asignado = ua.id
            LEFT JOIN componentes cs ON cs.id_componente = me.id_componente_saliente
            LEFT JOIN componentes ce ON ce.id_componente = me.id_componente_entrante
            WHERE me.id_programado = ?
            ORDER BY eq.codigo
        ");
        $out = [];
        foreach ($idsProgramados as $id) {
            $st->execute([$id]);
            $out[$id] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        return $out;
    }
}
