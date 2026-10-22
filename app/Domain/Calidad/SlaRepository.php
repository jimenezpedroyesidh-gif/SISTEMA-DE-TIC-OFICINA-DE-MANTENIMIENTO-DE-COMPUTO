<?php
/**
 * SQL del módulo de calidad del servicio (SLA): tickets de ambas tablas en una sola vista, sus eventos
 * de estado (ticket_eventos, los llenan disparadores), asignaciones, comentarios del personal, pausas,
 * políticas y festivos. Solo consultas preparadas; las reglas viven en SlaService.
 */
final class SlaRepository
{
    public const ESTADOS_RESUELTOS = [TicketWorkflow::SOLUCIONADO, TicketWorkflow::LISTO_SIN_VALIDAR, TicketWorkflow::APROBADO, TicketWorkflow::CERRADO];
    public const ESTADOS_ABIERTOS = [TicketWorkflow::EN_ESPERA, TicketWorkflow::EN_PROCESO, TicketWorkflow::RECHAZADO];

    /**
     * Tickets de ambas tablas con sus datos de servicio. Filtros: desde/hasta (fecha de creación, Y-m-d),
     * id_sala, dependencia, abiertos (bool), tabla+id (uno solo).
     * @return list<array<string,mixed>>
     */
    public static function tickets(PDO $pdo, array $f = []): array
    {
        $sql = "SELECT x.*, ts.impacto, ts.urgencia, ts.prioridad, ts.prioridad_manual, ts.aviso_por_vencer, ts.aviso_vencido,
                       ts.fecha_primera_respuesta AS sla_primera_respuesta, ts.fecha_solucion AS sla_fecha_solucion,
                       ts.reaperturas AS sla_reaperturas, ur.nombre_completo AS responsable
                FROM (
                  SELECT 'tickets' AS tabla, t.id, t.estado, t.fecha_creacion, t.tipo_solicitud AS tipo, t.categoria, t.origen,
                         t.id_sala, s.nombre_sala, COALESCE(NULLIF(s.dependencia, ''), t.nombre_dependencia) AS dependencia,
                         COALESCE(t.tecnico_id, t.beca_id) AS id_responsable, t.user_id AS id_creador,
                         LEFT(COALESCE(t.descripcion, ''), 160) AS resumen, t.fecha_terminacion, t.fecha_firmado,
                         NULL AS subtipo
                  FROM tickets t LEFT JOIN salas s ON s.id_sala = t.id_sala
                  UNION ALL
                  SELECT 'tickets_profesor', p.id, p.estado, p.fecha_creacion,
                         IF(p.tipo_ticket = 'danio', 'INCIDENTE', 'REQUERIMIENTO'), p.categoria, p.origen,
                         COALESCE(p.id_sala, p.id_sala_actual), s.nombre_sala, s.dependencia,
                         COALESCE(p.tecnico_id, p.beca_id), p.profesor_id,
                         LEFT(COALESCE(p.descripcion_problema, p.descripcion_actividad, p.motivo, p.descripcion_software, ''), 160),
                         NULL, p.fecha_firmado, p.subtipo_requerimiento
                  FROM tickets_profesor p LEFT JOIN salas s ON s.id_sala = COALESCE(p.id_sala, p.id_sala_actual)
                ) x
                LEFT JOIN ticket_sla ts ON ts.tabla_ticket = x.tabla AND ts.id_ticket = x.id
                LEFT JOIN users ur ON ur.id = x.id_responsable
                WHERE 1 = 1";
        $p = [];
        if (!empty($f['desde'])) { $sql .= ' AND x.fecha_creacion >= ?'; $p[] = $f['desde'] . ' 00:00:00'; }
        if (!empty($f['hasta'])) { $sql .= ' AND x.fecha_creacion <= ?'; $p[] = $f['hasta'] . ' 23:59:59'; }
        if (!empty($f['id_sala'])) { $sql .= ' AND x.id_sala = ?'; $p[] = (int)$f['id_sala']; }
        if (isset($f['dependencia']) && $f['dependencia'] !== '') { $sql .= ' AND x.dependencia = ?'; $p[] = (string)$f['dependencia']; }
        if (!empty($f['abiertos'])) { $sql .= " AND x.estado IN ('En espera','En proceso','Rechazado')"; }
        if (!empty($f['tabla']) && !empty($f['id'])) { $sql .= ' AND x.tabla = ? AND x.id = ?'; $p[] = (string)$f['tabla']; $p[] = (int)$f['id']; }
        $sql .= ' ORDER BY x.fecha_creacion DESC, x.id DESC';
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Tickets que todavía no tienen fila en ticket_sla (para calcularles la prioridad automática). */
    public static function sinDatosSla(PDO $pdo): array
    {
        return $pdo->query("
            SELECT 'tickets' AS tabla, t.id, t.tipo_solicitud AS tipo, t.categoria, t.origen, NULL AS subtipo
            FROM tickets t LEFT JOIN ticket_sla s ON s.tabla_ticket = 'tickets' AND s.id_ticket = t.id
            WHERE s.id_ticket IS NULL
            UNION ALL
            SELECT 'tickets_profesor', p.id, IF(p.tipo_ticket = 'danio', 'INCIDENTE', 'REQUERIMIENTO'), p.categoria, p.origen, p.subtipo_requerimiento
            FROM tickets_profesor p LEFT JOIN ticket_sla s ON s.tabla_ticket = 'tickets_profesor' AND s.id_ticket = p.id
            WHERE s.id_ticket IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function insertarSla(PDO $pdo, string $tabla, int $id, string $tipo, int $impacto, int $urgencia, string $prioridad): void
    {
        $pdo->prepare('INSERT IGNORE INTO ticket_sla (tabla_ticket, id_ticket, tipo, impacto, urgencia, prioridad, actualizado_en) VALUES (?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$tabla, $id, $tipo, $impacto, $urgencia, $prioridad]);
    }

    /** Prioridad cambiada por el admin (queda marcada como manual: la regla automática ya no la toca). */
    public static function ajustarPrioridad(PDO $pdo, string $tabla, int $id, int $impacto, int $urgencia, string $prioridad, int $idAdmin): bool
    {
        $st = $pdo->prepare('UPDATE ticket_sla SET impacto = ?, urgencia = ?, prioridad = ?, prioridad_manual = 1, id_ajusto = ?,
                                    aviso_por_vencer = 0, aviso_vencido = 0, actualizado_en = NOW()
                             WHERE tabla_ticket = ? AND id_ticket = ?');
        $st->execute([$impacto, $urgencia, $prioridad, $idAdmin, $tabla, $id]);
        return $st->rowCount() > 0;
    }

    /** Guarda lo calculado (primera respuesta, solución, reaperturas) para consultas rápidas y trazabilidad. */
    public static function guardarDerivados(PDO $pdo, string $tabla, int $id, ?string $primeraRespuesta, ?string $primeraSolucion, ?string $solucion, int $reaperturas): void
    {
        $pdo->prepare('UPDATE ticket_sla SET fecha_primera_respuesta = ?, fecha_primera_solucion = ?, fecha_solucion = ?, reaperturas = ?
                       WHERE tabla_ticket = ? AND id_ticket = ?')
            ->execute([$primeraRespuesta, $primeraSolucion, $solucion, $reaperturas, $tabla, $id]);
    }

    public static function marcarAviso(PDO $pdo, string $tabla, int $id, string $columna): void
    {
        $col = $columna === 'aviso_vencido' ? 'aviso_vencido' : 'aviso_por_vencer';
        $pdo->prepare("UPDATE ticket_sla SET $col = 1 WHERE tabla_ticket = ? AND id_ticket = ?")->execute([$tabla, $id]);
    }

    /**
     * Agrupa por ticket ('tabla#id') los resultados de una consulta por tabla e ids.
     * @param array<string,list<int>> $ids tabla => ids
     * @return array<string,list<array<string,mixed>>>
     */
    private static function porTicket(PDO $pdo, string $sqlBase, array $ids): array
    {
        $out = [];
        foreach ($ids as $tabla => $lista) {
            foreach (array_chunk(array_values(array_unique(array_map('intval', $lista))), 500) as $chunk) {
                if (!$chunk) continue;
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $st = $pdo->prepare(str_replace('{IN}', $in, $sqlBase));
                $st->execute(array_merge([$tabla], $chunk));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[$tabla . '#' . (int)$r['id_ticket']][] = $r;
                }
            }
        }
        return $out;
    }

    public static function eventos(PDO $pdo, array $ids): array
    {
        return self::porTicket($pdo, 'SELECT id_ticket, estado_anterior, estado_nuevo, fecha FROM ticket_eventos
                                      WHERE tabla_ticket = ? AND id_ticket IN ({IN}) ORDER BY fecha, id_evento', $ids);
    }

    public static function asignaciones(PDO $pdo, array $ids): array
    {
        return self::porTicket($pdo, 'SELECT id_ticket, id_tecnico, fecha_asignado FROM ticket_asignaciones
                                      WHERE tabla_ticket = ? AND id_ticket IN ({IN}) ORDER BY fecha_asignado, id', $ids);
    }

    /** Comentarios escritos por el personal de soporte (cuentan como primera respuesta). */
    public static function comentariosPersonal(PDO $pdo, array $ids): array
    {
        return self::porTicket($pdo, "SELECT c.id_ticket, c.fecha_creacion FROM ticket_comentarios c JOIN users u ON u.id = c.id_usuario
                                      WHERE c.tabla_ticket = ? AND c.id_ticket IN ({IN}) AND u.role IN ('tecnico','beca_trabajo','admin')
                                      ORDER BY c.fecha_creacion", $ids);
    }

    public static function pausas(PDO $pdo, array $ids): array
    {
        return self::porTicket($pdo, 'SELECT id_pausa, id_ticket, inicio, fin, motivo FROM ticket_pausas
                                      WHERE tabla_ticket = ? AND id_ticket IN ({IN}) ORDER BY inicio', $ids);
    }

    public static function encuestas(PDO $pdo, array $ids): array
    {
        return self::porTicket($pdo, 'SELECT id_ticket, calificacion, comentario, canal, fecha FROM encuestas_satisfaccion
                                      WHERE tabla_ticket = ? AND id_ticket IN ({IN})', $ids);
    }

    public static function pausaAbierta(PDO $pdo, string $tabla, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ticket_pausas WHERE tabla_ticket = ? AND id_ticket = ? AND fin IS NULL ORDER BY id_pausa DESC LIMIT 1');
        $st->execute([$tabla, $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function abrirPausa(PDO $pdo, string $tabla, int $id, string $motivo, int $idUsuario): void
    {
        $pdo->prepare('INSERT INTO ticket_pausas (tabla_ticket, id_ticket, inicio, motivo, id_usuario) VALUES (?, ?, NOW(), ?, ?)')
            ->execute([$tabla, $id, $motivo, $idUsuario]);
    }

    public static function cerrarPausa(PDO $pdo, int $idPausa): void
    {
        $pdo->prepare('UPDATE ticket_pausas SET fin = NOW() WHERE id_pausa = ? AND fin IS NULL')->execute([$idPausa]);
    }

    /** @return array<string,array<string,array{respuesta:float,solucion:float}>> tipo => prioridad => horas */
    public static function politicas(PDO $pdo): array
    {
        $out = [];
        try {
            foreach ($pdo->query('SELECT tipo, prioridad, horas_respuesta, horas_solucion FROM sla_politicas')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['tipo']][$r['prioridad']] = ['respuesta' => (float)$r['horas_respuesta'], 'solucion' => (float)$r['horas_solucion']];
            }
        } catch (Throwable $e) {
            $out = [];
        }
        return $out;
    }

    public static function guardarPolitica(PDO $pdo, string $tipo, string $prioridad, float $respuesta, float $solucion): void
    {
        $pdo->prepare('INSERT INTO sla_politicas (tipo, prioridad, horas_respuesta, horas_solucion, actualizado_en) VALUES (?, ?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE horas_respuesta = VALUES(horas_respuesta), horas_solucion = VALUES(horas_solucion), actualizado_en = NOW()')
            ->execute([$tipo, $prioridad, $respuesta, $solucion]);
    }

    /** @return list<array{fecha:string,descripcion:string}> */
    public static function festivos(PDO $pdo, ?int $anio = null): array
    {
        try {
            if ($anio !== null) {
                $st = $pdo->prepare('SELECT fecha, descripcion FROM dias_festivos WHERE YEAR(fecha) = ? ORDER BY fecha');
                $st->execute([$anio]);
                return $st->fetchAll(PDO::FETCH_ASSOC);
            }
            return $pdo->query('SELECT fecha, descripcion FROM dias_festivos ORDER BY fecha')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function agregarFestivo(PDO $pdo, string $fecha, string $descripcion): void
    {
        $pdo->prepare('INSERT INTO dias_festivos (fecha, descripcion) VALUES (?, ?) ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion)')
            ->execute([$fecha, $descripcion]);
    }

    public static function quitarFestivo(PDO $pdo, string $fecha): bool
    {
        $st = $pdo->prepare('DELETE FROM dias_festivos WHERE fecha = ?');
        $st->execute([$fecha]);
        return $st->rowCount() > 0;
    }

    /** Salas para el filtro de los indicadores. */
    public static function salas(PDO $pdo): array
    {
        return $pdo->query('SELECT id_sala, nombre_sala, dependencia FROM salas ORDER BY nombre_sala')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<string> dependencias distintas (de las salas y de los tickets de solicitantes) */
    public static function dependencias(PDO $pdo): array
    {
        return $pdo->query("SELECT d FROM (
                SELECT DISTINCT dependencia AS d FROM salas WHERE dependencia IS NOT NULL AND dependencia <> ''
                UNION SELECT DISTINCT nombre_dependencia FROM tickets WHERE nombre_dependencia IS NOT NULL AND nombre_dependencia <> ''
            ) z ORDER BY d")->fetchAll(PDO::FETCH_COLUMN);
    }
}
