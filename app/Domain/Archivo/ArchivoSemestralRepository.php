<?php
/**
 * SQL del cierre de semestre (archivado de tickets). Solo consultas preparadas; sin reglas de negocio.
 * Ver ArchivoSemestralService para las reglas (qué se archiva, qué nunca se toca).
 */
final class ArchivoSemestralRepository
{
    /** Estados finales: solo estos tickets se archivan. Sin contestar / sin solucionar / pendientes de aprobación, nunca. */
    public const ESTADOS_FINALES = [TicketWorkflow::APROBADO, TicketWorkflow::CERRADO, TicketWorkflow::DESCARTADO];

    private static function inFinales(): string
    {
        return "'" . implode("','", self::ESTADOS_FINALES) . "'";
    }

    /** Expresión SQL del periodo AAAA-N de una fecha. */
    private static function exprPeriodo(string $col): string
    {
        return "CONCAT(YEAR($col), '-', IF(MONTH($col) <= 6, 1, 2))";
    }

    /**
     * Conteo por periodo: terminados y abiertos (en ambas tablas) y ya archivados.
     * @return array<string, array{terminados:int, abiertos:int, archivados:int}>
     */
    public static function resumenPorPeriodo(PDO $pdo): array
    {
        $res = [];
        foreach ([TablaTicket::NORMAL, TablaTicket::PROFESOR] as $tabla) {
            $filas = $pdo->query("
                SELECT " . self::exprPeriodo('fecha_creacion') . " AS periodo,
                       SUM(estado IN (" . self::inFinales() . ")) AS terminados,
                       SUM(estado IS NULL OR estado NOT IN (" . self::inFinales() . ")) AS abiertos
                FROM `$tabla` GROUP BY periodo
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($filas as $f) {
                $p = (string)$f['periodo'];
                $res[$p]['terminados'] = ($res[$p]['terminados'] ?? 0) + (int)$f['terminados'];
                $res[$p]['abiertos'] = ($res[$p]['abiertos'] ?? 0) + (int)$f['abiertos'];
            }
        }
        foreach ($pdo->query("SELECT periodo, COUNT(*) AS n FROM tickets_archivo GROUP BY periodo")->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $res[(string)$f['periodo']]['archivados'] = (int)$f['n'];
        }
        foreach ($res as $p => $v) {
            $res[$p] += ['terminados' => 0, 'abiertos' => 0, 'archivados' => 0];
        }
        krsort($res);
        return $res;
    }

    /**
     * Tickets de una tabla que se pueden archivar en el rango: estado final y sin nada abierto colgando de ellos
     * (mantenimiento sin terminar, incidente de seguridad sin cerrar, solicitud de baja pendiente).
     * @return int[]
     */
    public static function candidatos(PDO $pdo, string $tabla, string $desde, string $hasta): array
    {
        $colMant = $tabla === TablaTicket::NORMAL ? 'id_ticket_origen' : 'id_ticket_profesor_origen';
        $st = $pdo->prepare("
            SELECT t.id FROM `$tabla` t
            WHERE t.estado IN (" . self::inFinales() . ")
              AND t.fecha_creacion >= ? AND t.fecha_creacion < ?
              AND NOT EXISTS (SELECT 1 FROM mantenimientos_programados mp WHERE mp.$colMant = t.id AND mp.estado IN ('PENDIENTE','PROGRAMADO','REPROGRAMADO','EN_PROCESO'))
              AND NOT EXISTS (SELECT 1 FROM mantenimiento_vinculos mv JOIN mantenimientos_programados mp2 ON mp2.id_programado = mv.id_programado
                              WHERE mv.tabla_ticket = '$tabla' AND mv.id_ticket = t.id AND mp2.estado IN ('PENDIENTE','PROGRAMADO','REPROGRAMADO','EN_PROCESO'))
              AND NOT EXISTS (SELECT 1 FROM incidentes_seguridad i WHERE i.tabla_ticket = ? AND i.id_ticket = t.id AND i.estado <> 'CERRADO')
              AND NOT EXISTS (SELECT 1 FROM solicitudes_baja sb WHERE sb.tabla_ticket_relacionado = ? AND sb.id_ticket_relacionado = t.id AND sb.estado = 'PENDIENTE')
            ORDER BY t.id
        ");
        $st->execute([$desde, $hasta, $tabla, $tabla]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Terminados en el rango que NO se archivan porque algo sigue abierto (para explicarlo en pantalla). */
    public static function contarTerminados(PDO $pdo, string $tabla, string $desde, string $hasta): int
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM `$tabla` WHERE estado IN (" . self::inFinales() . ") AND fecha_creacion >= ? AND fecha_creacion < ?");
        $st->execute([$desde, $hasta]);
        return (int)$st->fetchColumn();
    }

    public static function contarAbiertos(PDO $pdo, string $tabla, string $desde, string $hasta): int
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM `$tabla` WHERE (estado IS NULL OR estado NOT IN (" . self::inFinales() . ")) AND fecha_creacion >= ? AND fecha_creacion < ?");
        $st->execute([$desde, $hasta]);
        return (int)$st->fetchColumn();
    }

    /** Fila completa de un ticket de la tabla `tickets` con los nombres ya resueltos. */
    public static function ticketSolicitante(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare("
            SELECT t.*, s.nombre_sala, s.abreviatura, sol.nombre_completo AS solicitante_nombre,
                   tec.nombre_completo AS tecnico_nombre, bec.nombre_completo AS beca_nombre
            FROM tickets t
            LEFT JOIN salas s ON s.id_sala = t.id_sala
            LEFT JOIN users sol ON sol.id = t.user_id
            LEFT JOIN users tec ON tec.id = t.tecnico_id
            LEFT JOIN users bec ON bec.id = t.beca_id
            WHERE t.id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function ticketProfesor(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare("
            SELECT tp.*, s.nombre_sala, s.abreviatura, prof.nombre_completo AS solicitante_nombre, prof.correo AS solicitante_correo,
                   tec.nombre_completo AS tecnico_nombre, bec.nombre_completo AS beca_nombre
            FROM tickets_profesor tp
            LEFT JOIN salas s ON s.id_sala = tp.id_sala
            LEFT JOIN users prof ON prof.id = tp.profesor_id
            LEFT JOIN users tec ON tec.id = tp.tecnico_id
            LEFT JOIN users bec ON bec.id = tp.beca_id
            WHERE tp.id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Datos del SLA, la encuesta, el cierre y los conteos de detalle de un ticket. */
    public static function metricas(PDO $pdo, string $tabla, int $id): array
    {
        $st = $pdo->prepare("SELECT prioridad, fecha_primera_respuesta, fecha_solucion, reaperturas FROM ticket_sla WHERE tabla_ticket = ? AND id_ticket = ?");
        $st->execute([$tabla, $id]);
        $sla = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $st = $pdo->prepare("SELECT calificacion FROM encuestas_satisfaccion WHERE tabla_ticket = ? AND id_ticket = ?");
        $st->execute([$tabla, $id]);
        $calif = $st->fetchColumn();

        $st = $pdo->prepare("SELECT MAX(fecha) FROM ticket_eventos WHERE tabla_ticket = ? AND id_ticket = ? AND estado_nuevo IN (" . self::inFinales() . ")");
        $st->execute([$tabla, $id]);
        $cierre = $st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) FROM ticket_comentarios WHERE tabla_ticket = ? AND id_ticket = ?");
        $st->execute([$tabla, $id]);
        $coment = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) FROM ticket_adjuntos WHERE tabla_ticket = ? AND id_ticket = ?");
        $st->execute([$tabla, $id]);
        $adj = (int)$st->fetchColumn();

        $colMant = $tabla === TablaTicket::NORMAL ? 'id_ticket_origen' : 'id_ticket_profesor_origen';
        // Mantenimiento que lo atendió: como origen o como reporte vinculado.
        $st = $pdo->prepare("SELECT COALESCE((SELECT MAX(id_programado) FROM mantenimientos_programados WHERE $colMant = ?),
                                             (SELECT MAX(id_programado) FROM mantenimiento_vinculos WHERE tabla_ticket = ? AND id_ticket = ?))");
        $st->execute([$id, $tabla, $id]);
        $mant = $st->fetchColumn();

        return [
            'prioridad' => $sla['prioridad'] ?? null,
            'fecha_primera_respuesta' => $sla['fecha_primera_respuesta'] ?? null,
            'fecha_solucion' => $sla['fecha_solucion'] ?? null,
            'reaperturas' => (int)($sla['reaperturas'] ?? 0),
            'calificacion' => $calif !== false && $calif !== null ? (int)$calif : null,
            'fecha_cierre_evento' => $cierre ?: null,
            'total_comentarios' => $coment,
            'total_adjuntos' => $adj,
            'id_programado' => $mant ? (int)$mant : null,
        ];
    }

    /** Equipos/componentes afectados (ticket_activos) con su código para el resumen. */
    public static function activos(PDO $pdo, string $tabla, int $id): array
    {
        $st = $pdo->prepare("
            SELECT ta.id_equipo, ta.id_componente, ta.tipo_afectacion,
                   e.codigo AS equipo_codigo, c.codigo_inventario AS componente_codigo
            FROM ticket_activos ta
            LEFT JOIN equipos e ON e.id_equipo = ta.id_equipo
            LEFT JOIN componentes c ON c.id_componente = ta.id_componente
            WHERE ta.tabla_ticket = ? AND ta.id_ticket = ?");
        $st->execute([$tabla, $id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function crearLote(PDO $pdo, string $periodo, string $desde, string $hasta, int $idAdmin): int
    {
        $pdo->prepare("INSERT INTO archivo_lotes (periodo, fecha_desde, fecha_hasta, id_admin) VALUES (?, ?, ?, ?)")
            ->execute([$periodo, $desde, $hasta, $idAdmin]);
        return (int)$pdo->lastInsertId();
    }

    public static function cerrarLote(PDO $pdo, int $idLote, int $nTickets, int $nProfesor, int $nAdjuntos, int $bytes): void
    {
        $pdo->prepare("UPDATE archivo_lotes SET total_tickets = ?, total_profesor = ?, adjuntos_borrados = ?, bytes_liberados = ? WHERE id_lote = ?")
            ->execute([$nTickets, $nProfesor, $nAdjuntos, $bytes, $idLote]);
    }

    public static function insertarArchivo(PDO $pdo, array $f): int
    {
        $cols = array_keys($f);
        $pdo->prepare("INSERT INTO tickets_archivo (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")
            ->execute(array_values($f));
        return (int)$pdo->lastInsertId();
    }

    public static function insertarArchivoActivo(PDO $pdo, int $idArchivo, ?int $idEquipo, ?int $idComponente, ?string $tipo): void
    {
        $pdo->prepare("INSERT INTO tickets_archivo_activos (id_archivo, id_equipo, id_componente, tipo_afectacion) VALUES (?, ?, ?, ?)")
            ->execute([$idArchivo, $idEquipo, $idComponente, $tipo]);
    }

    /** Adjuntos del ticket que se pueden borrar (los que no son evidencia de un movimiento de inventario). */
    public static function adjuntosBorrables(PDO $pdo, string $tabla, int $id): array
    {
        $st = $pdo->prepare("
            SELECT a.id, a.nombre_almacenado, a.tamano_bytes FROM ticket_adjuntos a
            WHERE a.tabla_ticket = ? AND a.id_ticket = ?
              AND NOT EXISTS (SELECT 1 FROM movimientos_activos m WHERE m.id_adjunto = a.id)");
        $st->execute([$tabla, $id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Quita el ticket y su detalle operativo. El historial de componentes y los movimientos de inventario se
     * CONSERVAN: el historial queda apuntando al registro del archivo (id_ticket_archivado).
     */
    public static function eliminarTicket(PDO $pdo, string $tabla, int $id, int $idArchivo, array $idsAdjuntosBorrar): void
    {
        if ($tabla === TablaTicket::NORMAL) {
            $pdo->prepare("UPDATE historial_componente SET id_ticket_archivado = ?, id_ticket = NULL WHERE id_ticket = ?")->execute([$idArchivo, $id]);
            $pdo->prepare("UPDATE mantenimientos_programados SET id_ticket_origen = NULL WHERE id_ticket_origen = ?")->execute([$id]);
        } else {
            $pdo->prepare("UPDATE mantenimientos_programados SET id_ticket_profesor_origen = NULL WHERE id_ticket_profesor_origen = ?")->execute([$id]);
        }
        // El resumen archivado guarda id_programado_relacionado; el vínculo ya no hace falta.
        $pdo->prepare("DELETE FROM mantenimiento_vinculos WHERE tabla_ticket = ? AND id_ticket = ?")->execute([$tabla, $id]);
        foreach (['ticket_activos', 'ticket_asignaciones', 'ticket_comentarios', 'ticket_eventos', 'ticket_pausas', 'ticket_sla', 'encuestas_satisfaccion'] as $detalle) {
            $pdo->prepare("DELETE FROM `$detalle` WHERE tabla_ticket = ? AND id_ticket = ?")->execute([$tabla, $id]);
        }
        if ($idsAdjuntosBorrar) {
            $pdo->prepare("DELETE FROM ticket_adjuntos WHERE id IN (" . implode(',', array_fill(0, count($idsAdjuntosBorrar), '?')) . ")")
                ->execute(array_map('intval', $idsAdjuntosBorrar));
        }
        // Un adjunto que se conserva (evidencia de movimiento) no puede quedar apuntando a un ticket que ya no existe.
        $pdo->prepare("DELETE FROM ticket_adjuntos WHERE tabla_ticket = ? AND id_ticket = ? AND NOT EXISTS (SELECT 1 FROM movimientos_activos m WHERE m.id_adjunto = ticket_adjuntos.id)")
            ->execute([$tabla, $id]);
        $pdo->prepare("DELETE FROM `$tabla` WHERE id = ?")->execute([$id]);
    }

    public static function lotes(PDO $pdo): array
    {
        return $pdo->query("
            SELECT l.*, u.nombre_completo AS admin_nombre FROM archivo_lotes l
            LEFT JOIN users u ON u.id = l.id_admin ORDER BY l.creado_en DESC LIMIT 30
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Búsqueda en el archivo (para consultar un ticket viejo). */
    public static function buscar(PDO $pdo, string $periodo, string $texto, int $limite = 100): array
    {
        $where = [];
        $params = [];
        if ($periodo !== '') { $where[] = 'periodo = ?'; $params[] = $periodo; }
        if ($texto !== '') {
            $where[] = '(sala_nombre LIKE ? OR descripcion LIKE ? OR solicitante LIKE ? OR tecnico LIKE ? OR beca LIKE ? OR equipos_afectados LIKE ? OR id_original = ?)';
            $like = '%' . $texto . '%';
            array_push($params, $like, $like, $like, $like, $like, $like, ctype_digit($texto) ? (int)$texto : -1);
        }
        $st = $pdo->prepare("SELECT * FROM tickets_archivo" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY fecha_creacion DESC LIMIT " . (int)$limite);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Tickets archivados de un equipo (o de su sala completa) para la ficha del equipo. */
    public static function deEquipo(PDO $pdo, int $idEquipo, int $idSala): array
    {
        try {
            $st = $pdo->prepare("
                SELECT DISTINCT a.* FROM tickets_archivo a
                LEFT JOIN tickets_archivo_activos aa ON aa.id_archivo = a.id_archivo
                WHERE aa.id_equipo = ? OR (a.id_sala = ? AND NOT EXISTS (SELECT 1 FROM tickets_archivo_activos x WHERE x.id_archivo = a.id_archivo))
                ORDER BY a.fecha_creacion DESC LIMIT 30");
            $st->execute([$idEquipo, $idSala]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return []; // tablas del archivo aún no creadas
        }
    }

    /** Reporte QR archivado por su código de seguimiento (consulta pública). */
    public static function porCodigo(PDO $pdo, string $codigo): ?array
    {
        try {
            $st = $pdo->prepare("SELECT id_original AS id, estado_final AS estado, descripcion, fecha_creacion, fecha_cierre AS fecha_terminacion, NULL AS nombre_dependencia, sala_nombre AS nombre_sala FROM tickets_archivo WHERE codigo_seguimiento = ?");
            $st->execute([$codigo]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
}
