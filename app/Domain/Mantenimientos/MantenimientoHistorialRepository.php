<?php
/**
 * MantenimientoHistorialRepository — lecturas del «Historial y análisis de mantenimiento» y escritura de las
 * acciones correctivas (mantenimiento_acciones).
 *
 * No guarda copias: consolida lo que ya existe —mantenimientos_programados y su checklist, tickets y
 * tickets_profesor (con sus tiempos en ticket_sla / ticket_eventos), los tickets ya archivados en el cierre de
 * semestre (tickets_archivo), equipos afectados (ticket_activos) y traslados (movimientos_activos)—, así las
 * estadísticas se pueden verificar contra los registros originales.
 *
 * $f (filtros ya validados por MantenimientoHistorialService): desde, hasta ('Y-m-d H:i:s'), id_sala, bloque,
 * id_equipo, id_responsable, nombre_responsable, categoria.
 */
final class MantenimientoHistorialRepository
{
    /** Condiciones comunes de sala/bloque sobre el alias s (salas). */
    private static function filtroSala(array $f, array &$p, string $colSala): string
    {
        $sql = '';
        if ($f['id_sala']) { $sql .= " AND $colSala = ?"; $p[] = $f['id_sala']; }
        if ($f['bloque'] !== '') { $sql .= ' AND s.bloque = ?'; $p[] = $f['bloque']; }
        return $sql;
    }

    /**
     * Incidencias (reportes de daño, QR, solicitudes de profesores) del periodo, vivas y archivadas,
     * en un solo formato. Los descartados (duplicados, spam) no cuentan.
     */
    public static function incidencias(PDO $pdo, array $f): array
    {
        $out = [];

        // 1) tickets (QR con y sin sesión, daños que registra el personal, solicitudes de secretaría/solicitante)
        $p = [$f['desde'], $f['hasta']];
        $sql = "SELECT 'tickets' AS fuente, t.id, t.origen, NULL AS tipo_ticket, NULL AS subtipo, t.categoria, t.id_sala,
                       s.nombre_sala, s.abreviatura, s.bloque, t.fecha_creacion, t.estado, t.descripcion, t.solucion,
                       tec.nombre_completo AS tecnico, bec.nombre_completo AS beca,
                       COALESCE(sla.fecha_primera_respuesta, (SELECT MIN(e.fecha) FROM ticket_eventos e WHERE e.tabla_ticket = 'tickets' AND e.id_ticket = t.id AND e.estado_anterior IS NOT NULL)) AS fecha_respuesta,
                       COALESCE(sla.fecha_solucion, (SELECT MIN(e.fecha) FROM ticket_eventos e WHERE e.tabla_ticket = 'tickets' AND e.id_ticket = t.id AND e.estado_nuevo IN ('Solucionado','Cerrado'))) AS fecha_solucion
                FROM tickets t
                LEFT JOIN salas s ON s.id_sala = t.id_sala
                LEFT JOIN users tec ON tec.id = t.tecnico_id
                LEFT JOIN users bec ON bec.id = t.beca_id
                LEFT JOIN ticket_sla sla ON sla.tabla_ticket = 'tickets' AND sla.id_ticket = t.id
                WHERE t.fecha_creacion BETWEEN ? AND ? AND (t.estado IS NULL OR t.estado <> 'Descartado')";
        $sql .= self::filtroSala($f, $p, 't.id_sala');
        if ($f['categoria'] !== '') { $sql .= ' AND t.categoria = ?'; $p[] = $f['categoria']; }
        if ($f['id_responsable']) { $sql .= ' AND (t.tecnico_id = ? OR t.beca_id = ?)'; array_push($p, $f['id_responsable'], $f['id_responsable']); }
        if ($f['id_equipo']) {
            $sql .= " AND (t.id_equipo = ? OR EXISTS (SELECT 1 FROM ticket_activos ta WHERE ta.tabla_ticket = 'tickets' AND ta.id_ticket = t.id AND ta.id_equipo = ?))";
            array_push($p, $f['id_equipo'], $f['id_equipo']);
        }
        $st = $pdo->prepare($sql);
        $st->execute($p);
        $out = array_merge($out, $st->fetchAll(PDO::FETCH_ASSOC));

        // 2) tickets_profesor (daño en sala y solicitudes: software, préstamo, cambio de sala)
        $p = [$f['desde'], $f['hasta']];
        $sql = "SELECT 'tickets_profesor' AS fuente, t.id, t.origen, t.tipo_ticket, t.subtipo_requerimiento AS subtipo, t.categoria, t.id_sala,
                       s.nombre_sala, s.abreviatura, s.bloque, t.fecha_creacion, t.estado,
                       COALESCE(t.descripcion_problema, t.descripcion_software, t.descripcion_actividad, t.motivo) AS descripcion, t.solucion,
                       tec.nombre_completo AS tecnico, bec.nombre_completo AS beca,
                       COALESCE(sla.fecha_primera_respuesta, (SELECT MIN(e.fecha) FROM ticket_eventos e WHERE e.tabla_ticket = 'tickets_profesor' AND e.id_ticket = t.id AND e.estado_anterior IS NOT NULL)) AS fecha_respuesta,
                       COALESCE(sla.fecha_solucion, t.fecha_solucion, (SELECT MIN(e.fecha) FROM ticket_eventos e WHERE e.tabla_ticket = 'tickets_profesor' AND e.id_ticket = t.id AND e.estado_nuevo IN ('Solucionado','Cerrado'))) AS fecha_solucion
                FROM tickets_profesor t
                LEFT JOIN salas s ON s.id_sala = t.id_sala
                LEFT JOIN users tec ON tec.id = t.tecnico_id
                LEFT JOIN users bec ON bec.id = t.beca_id
                LEFT JOIN ticket_sla sla ON sla.tabla_ticket = 'tickets_profesor' AND sla.id_ticket = t.id
                WHERE t.fecha_creacion BETWEEN ? AND ? AND (t.estado IS NULL OR t.estado <> 'Descartado')";
        $sql .= self::filtroSala($f, $p, 't.id_sala');
        if ($f['categoria'] !== '') { $sql .= ' AND t.categoria = ?'; $p[] = $f['categoria']; }
        if ($f['id_responsable']) { $sql .= ' AND (t.tecnico_id = ? OR t.beca_id = ?)'; array_push($p, $f['id_responsable'], $f['id_responsable']); }
        if ($f['id_equipo']) {
            $sql .= " AND EXISTS (SELECT 1 FROM ticket_activos ta WHERE ta.tabla_ticket = 'tickets_profesor' AND ta.id_ticket = t.id AND ta.id_equipo = ?)";
            $p[] = $f['id_equipo'];
        }
        $st = $pdo->prepare($sql);
        $st->execute($p);
        $out = array_merge($out, $st->fetchAll(PDO::FETCH_ASSOC));

        // 3) Archivados en el cierre de semestre (resumen compacto; responsables por nombre).
        $p = [$f['desde'], $f['hasta']];
        $sql = "SELECT 'archivo' AS fuente, a.id_archivo AS id, a.origen,
                       CASE WHEN a.tabla_origen = 'tickets_profesor' AND a.tipo = 'ERROR_SALA' THEN 'danio'
                            WHEN a.tabla_origen = 'tickets_profesor' THEN 'requerimiento' ELSE NULL END AS tipo_ticket,
                       CASE WHEN a.tipo LIKE 'REQUERIMIENTO:%' THEN SUBSTRING(a.tipo, 15) ELSE NULL END AS subtipo,
                       a.categoria, a.id_sala, COALESCE(s.nombre_sala, a.sala_nombre) AS nombre_sala, s.abreviatura, s.bloque,
                       a.fecha_creacion, a.estado_final AS estado, a.descripcion, a.solucion, a.tecnico, a.beca,
                       a.fecha_primera_respuesta AS fecha_respuesta, a.fecha_cierre AS fecha_solucion, a.tabla_origen, a.id_original
                FROM tickets_archivo a
                LEFT JOIN salas s ON s.id_sala = a.id_sala
                WHERE a.fecha_creacion BETWEEN ? AND ? AND a.estado_final <> 'Descartado'";
        $sql .= self::filtroSala($f, $p, 'a.id_sala');
        if ($f['categoria'] !== '') { $sql .= ' AND a.categoria = ?'; $p[] = $f['categoria']; }
        if ($f['id_responsable']) { $sql .= ' AND (a.tecnico = ? OR a.beca = ?)'; array_push($p, $f['nombre_responsable'], $f['nombre_responsable']); }
        if ($f['id_equipo']) {
            $sql .= ' AND EXISTS (SELECT 1 FROM tickets_archivo_activos aa WHERE aa.id_archivo = a.id_archivo AND aa.id_equipo = ?)';
            $p[] = $f['id_equipo'];
        }
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return array_merge($out, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Equipos afectados por cada incidencia: "fuente#id" => [ [id_equipo, codigo, id_sala] ].
     * Vivos: tickets.id_equipo + ticket_activos. Archivados: tickets_archivo_activos.
     */
    public static function equiposDeIncidencias(PDO $pdo, array $incidencias): array
    {
        $ids = [TablaTicket::NORMAL => [], TablaTicket::PROFESOR => [], 'archivo' => []];
        foreach ($incidencias as $i) {
            $ids[$i['fuente']][] = (int)$i['id'];
        }
        $out = [];
        $agregar = function (array $filas, string $fuente) use (&$out) {
            foreach ($filas as $r) {
                $k = $fuente . '#' . (int)$r['id_ref'];
                $out[$k][(int)$r['id_equipo']] = ['id_equipo' => (int)$r['id_equipo'], 'codigo' => $r['codigo'], 'id_sala' => $r['id_sala'] !== null ? (int)$r['id_sala'] : null];
            }
        };
        foreach (array_chunk($ids['tickets'], 500) as $lote) {
            $in = implode(',', array_fill(0, count($lote), '?'));
            $st = $pdo->prepare("SELECT t.id AS id_ref, e.id_equipo, e.codigo, e.id_sala FROM tickets t JOIN equipos e ON e.id_equipo = t.id_equipo WHERE t.id IN ($in)
                                 UNION SELECT ta.id_ticket, e.id_equipo, e.codigo, e.id_sala FROM ticket_activos ta JOIN equipos e ON e.id_equipo = ta.id_equipo
                                 WHERE ta.tabla_ticket = 'tickets' AND ta.id_ticket IN ($in)");
            $st->execute([...$lote, ...$lote]);
            $agregar($st->fetchAll(PDO::FETCH_ASSOC), TablaTicket::NORMAL);
        }
        foreach (array_chunk($ids['tickets_profesor'], 500) as $lote) {
            $in = implode(',', array_fill(0, count($lote), '?'));
            $st = $pdo->prepare("SELECT ta.id_ticket AS id_ref, e.id_equipo, e.codigo, e.id_sala FROM ticket_activos ta JOIN equipos e ON e.id_equipo = ta.id_equipo
                                 WHERE ta.tabla_ticket = 'tickets_profesor' AND ta.id_ticket IN ($in)");
            $st->execute($lote);
            $agregar($st->fetchAll(PDO::FETCH_ASSOC), TablaTicket::PROFESOR);
        }
        foreach (array_chunk($ids['archivo'], 500) as $lote) {
            $in = implode(',', array_fill(0, count($lote), '?'));
            $st = $pdo->prepare("SELECT aa.id_archivo AS id_ref, e.id_equipo, e.codigo, e.id_sala FROM tickets_archivo_activos aa JOIN equipos e ON e.id_equipo = aa.id_equipo
                                 WHERE aa.id_archivo IN ($in)");
            $st->execute($lote);
            $agregar($st->fetchAll(PDO::FETCH_ASSOC), 'archivo');
        }
        return array_map('array_values', $out);
    }

    /** Mantenimientos con fecha en el periodo (cualquier estado), con su resultado y sus equipos trabajados. */
    public static function mantenimientos(PDO $pdo, array $f): array
    {
        $p = [$f['desde'], $f['hasta']];
        $sql = "SELECT m.id_programado, m.id_sala, m.tipo, m.descripcion, m.fecha_programada, m.fecha_realizado, m.estado, m.resultado,
                       m.observaciones_cierre, m.id_campana, s.nombre_sala, s.abreviatura, s.bloque, r.nombre_completo AS responsable,
                       (SELECT COUNT(*) FROM mantenimiento_equipos me WHERE me.id_programado = m.id_programado AND me.estado IN ('REALIZADO','INCOMPLETO')) AS equipos_trabajados,
                       (SELECT COUNT(*) FROM mantenimiento_equipos me WHERE me.id_programado = m.id_programado AND me.estado = 'EXCLUIDO_TRASLADO') AS equipos_trasladados
                FROM mantenimientos_programados m
                JOIN salas s ON s.id_sala = m.id_sala
                LEFT JOIN users r ON r.id = m.id_responsable
                WHERE m.fecha_programada BETWEEN ? AND ?";
        $sql .= self::filtroSala($f, $p, 'm.id_sala');
        if ($f['id_responsable']) {
            $sql .= ' AND (m.id_responsable = ? OR EXISTS (SELECT 1 FROM mantenimiento_equipos me WHERE me.id_programado = m.id_programado AND (me.id_usuario_ultimo = ? OR me.id_usuario_asignado = ?)))';
            array_push($p, $f['id_responsable'], $f['id_responsable'], $f['id_responsable']);
        }
        if ($f['id_equipo']) {
            $sql .= ' AND EXISTS (SELECT 1 FROM mantenimiento_equipos me WHERE me.id_programado = m.id_programado AND me.id_equipo = ?)';
            $p[] = $f['id_equipo'];
        }
        $st = $pdo->prepare($sql . ' ORDER BY m.fecha_programada DESC');
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Equipos que quedaron incompletos o se trasladaron en la checklist de mantenimientos del periodo (reincidencia). */
    public static function equiposProblemaEnMantenimientos(PDO $pdo, array $f): array
    {
        $p = [$f['desde'], $f['hasta']];
        $sql = "SELECT me.id_equipo, e.codigo, e.id_sala, me.estado, COUNT(*) AS n
                FROM mantenimiento_equipos me
                JOIN mantenimientos_programados m ON m.id_programado = me.id_programado
                JOIN equipos e ON e.id_equipo = me.id_equipo
                JOIN salas s ON s.id_sala = m.id_sala
                WHERE m.fecha_programada BETWEEN ? AND ? AND me.estado IN ('INCOMPLETO','EXCLUIDO_TRASLADO','TRASLADO_PENDIENTE')";
        $sql .= self::filtroSala($f, $p, 'm.id_sala');
        if ($f['id_equipo']) { $sql .= ' AND me.id_equipo = ?'; $p[] = $f['id_equipo']; }
        $st = $pdo->prepare($sql . ' GROUP BY me.id_equipo, e.codigo, e.id_sala, me.estado');
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Traslados de equipos o piezas (sala ↔ oficina de mantenimiento ↔ otra sala) del periodo. */
    public static function traslados(PDO $pdo, array $f): array
    {
        $p = [$f['desde'], $f['hasta']];
        $sql = "SELECT mv.id, mv.id_equipo, mv.id_componente, mv.id_sala_origen, mv.id_sala_destino, mv.motivo, mv.fecha_solicitado,
                       mv.fecha_ejecutado, so.nombre_sala AS sala_origen, sd.nombre_sala AS sala_destino,
                       COALESCE(e.codigo, ec.codigo) AS equipo_codigo, c.codigo_inventario AS componente_codigo, c.tipo AS componente_tipo,
                       u.nombre_completo AS solicito
                FROM movimientos_activos mv
                LEFT JOIN salas so ON so.id_sala = mv.id_sala_origen
                LEFT JOIN salas sd ON sd.id_sala = mv.id_sala_destino
                LEFT JOIN equipos e ON e.id_equipo = mv.id_equipo
                LEFT JOIN componentes c ON c.id_componente = mv.id_componente
                LEFT JOIN equipos ec ON ec.id_equipo = c.id_equipo_actual
                LEFT JOIN users u ON u.id = mv.id_solicito
                LEFT JOIN salas s ON s.id_sala = COALESCE(mv.id_sala_origen, mv.id_sala_destino)
                WHERE mv.fecha_solicitado BETWEEN ? AND ?";
        if ($f['id_sala']) { $sql .= ' AND (mv.id_sala_origen = ? OR mv.id_sala_destino = ?)'; array_push($p, $f['id_sala'], $f['id_sala']); }
        if ($f['bloque'] !== '') { $sql .= ' AND s.bloque = ?'; $p[] = $f['bloque']; }
        if ($f['id_equipo']) { $sql .= ' AND (mv.id_equipo = ? OR c.id_equipo_actual = ?)'; array_push($p, $f['id_equipo'], $f['id_equipo']); }
        if ($f['id_responsable']) { $sql .= ' AND (mv.id_solicito = ? OR mv.id_aprobo = ? OR mv.id_ejecuto = ?)'; array_push($p, $f['id_responsable'], $f['id_responsable'], $f['id_responsable']); }
        $st = $pdo->prepare($sql . ' ORDER BY mv.fecha_solicitado DESC LIMIT 500');
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ───────────────────────── Catálogos para los filtros

    public static function salas(PDO $pdo): array
    {
        return $pdo->query("SELECT id_sala, nombre_sala, abreviatura, bloque FROM salas WHERE es_oficina_mantenimiento = 0 ORDER BY nombre_sala")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function bloques(PDO $pdo): array
    {
        return $pdo->query("SELECT DISTINCT bloque FROM salas WHERE bloque IS NOT NULL AND bloque <> '' ORDER BY bloque")->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function personal(PDO $pdo): array
    {
        return $pdo->query("SELECT id, nombre_completo, role FROM users WHERE role IN ('tecnico','beca_trabajo') ORDER BY role, nombre_completo")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function equipoPorCodigo(PDO $pdo, string $codigo)
    {
        $st = $pdo->prepare("SELECT id_equipo, codigo, id_sala FROM equipos WHERE codigo = ?");
        $st->execute([$codigo]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    public static function nombreUsuario(PDO $pdo, int $id): string
    {
        $st = $pdo->prepare("SELECT nombre_completo FROM users WHERE id = ?");
        $st->execute([$id]);
        return (string)$st->fetchColumn();
    }

    // ───────────────────────── Acciones correctivas (mantenimiento_acciones)

    public static function insertarAccion(PDO $pdo, array $a): int
    {
        $st = $pdo->prepare(
            "INSERT INTO mantenimiento_acciones (periodo_origen, periodo_destino, id_sala, id_equipo, tipo_accion, causa, descripcion, prioridad, id_responsable, fecha_objetivo, id_creador)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $st->execute([$a['periodo_origen'], $a['periodo_destino'], $a['id_sala'], $a['id_equipo'], $a['tipo_accion'], $a['causa'], $a['descripcion'],
            $a['prioridad'], $a['id_responsable'], $a['fecha_objetivo'], $a['id_creador']]);
        return (int)$pdo->lastInsertId();
    }

    public static function accion(PDO $pdo, int $id)
    {
        $st = $pdo->prepare("SELECT a.*, s.nombre_sala, e.codigo AS equipo_codigo FROM mantenimiento_acciones a
                             LEFT JOIN salas s ON s.id_sala = a.id_sala LEFT JOIN equipos e ON e.id_equipo = a.id_equipo WHERE a.id_accion = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /** Acciones de un semestre de destino (pendientes primero, luego por prioridad y fecha objetivo). */
    public static function acciones(PDO $pdo, string $periodoDestino): array
    {
        $st = $pdo->prepare(
            "SELECT a.*, s.nombre_sala, s.abreviatura, e.codigo AS equipo_codigo, r.nombre_completo AS responsable_nombre,
                    m.estado AS mantenimiento_estado, m.fecha_programada AS mantenimiento_fecha, m.id_campana
             FROM mantenimiento_acciones a
             LEFT JOIN salas s ON s.id_sala = a.id_sala
             LEFT JOIN equipos e ON e.id_equipo = a.id_equipo
             LEFT JOIN users r ON r.id = a.id_responsable
             LEFT JOIN mantenimientos_programados m ON m.id_programado = a.id_programado
             WHERE a.periodo_destino = ?
             ORDER BY FIELD(a.estado, 'PENDIENTE', 'PROGRAMADA', 'HECHA', 'DESCARTADA'), FIELD(a.prioridad, 'ALTA', 'MEDIA', 'BAJA'), a.fecha_objetivo, a.id_accion"
        );
        $st->execute([$periodoDestino]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function cambiarEstadoAccion(PDO $pdo, int $id, string $estado, array $desde): int
    {
        $in = implode(',', array_fill(0, count($desde), '?'));
        $st = $pdo->prepare("UPDATE mantenimiento_acciones SET estado = ? WHERE id_accion = ? AND estado IN ($in)");
        $st->execute([$estado, $id, ...$desde]);
        return $st->rowCount();
    }

    public static function enlazarAccion(PDO $pdo, int $idAccion, int $idProgramado): void
    {
        $pdo->prepare("UPDATE mantenimiento_acciones SET id_programado = ?, estado = 'PROGRAMADA' WHERE id_accion = ? AND estado = 'PENDIENTE'")->execute([$idProgramado, $idAccion]);
    }

    /** Si el mantenimiento que salió de una acción se cancela, la acción vuelve a quedar pendiente para programarla otra vez. */
    public static function liberarAccionesPorMantenimiento(PDO $pdo, array $idsProgramados): void
    {
        $ids = array_values(array_filter(array_map('intval', $idsProgramados)));
        if (!$ids) {
            return;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE mantenimiento_acciones SET estado = 'PENDIENTE', id_programado = NULL WHERE estado = 'PROGRAMADA' AND id_programado IN ($in)")->execute($ids);
    }

    /** Al cerrar como REALIZADO un mantenimiento (o la campaña) que salió de una acción, la acción queda HECHA. */
    public static function marcarHechasPorMantenimiento(PDO $pdo, array $idsProgramados): void
    {
        $ids = array_values(array_filter(array_map('intval', $idsProgramados)));
        if (!$ids) {
            return;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE mantenimiento_acciones SET estado = 'HECHA' WHERE estado = 'PROGRAMADA' AND id_programado IN ($in)")->execute($ids);
    }
}
