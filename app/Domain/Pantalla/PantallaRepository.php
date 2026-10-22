<?php
/**
 * Datos de la pantalla de la oficina de mantenimiento (public/pantalla.php, rol «visualizacion»).
 * Dos listas, solo lectura y con consultas preparadas:
 *  - mantenimientos programados/en proceso, con el técnico responsable, quiénes están trabajando y el avance;
 *  - errores de sala abiertos que llegaron por QR (con sesión de profesor, desde su panel y sin sesión),
 *    con el técnico y el becario que lo están respondiendo.
 */
final class PantallaRepository
{
    /** Estados en los que un ticket ya no está pendiente (no salen en la pantalla). */
    private const ESTADOS_TERMINADOS = "'Aprobado','Cerrado','Descartado'";

    public static function mantenimientos(PDO $pdo): array
    {
        return $pdo->query("
            SELECT mp.id_programado, mp.tipo, mp.estado, mp.fecha_programada, mp.descripcion,
                   s.nombre_sala, s.abreviatura,
                   resp.nombre_completo AS responsable,
                   (SELECT COUNT(*) FROM mantenimiento_equipos me WHERE me.id_programado = mp.id_programado AND me.estado <> 'EXCLUIDO_TRASLADO') AS total_equipos,
                   (SELECT COUNT(*) FROM mantenimiento_equipos me WHERE me.id_programado = mp.id_programado AND me.estado = 'REALIZADO') AS realizados,
                   (SELECT COUNT(*) FROM mantenimiento_equipos me WHERE me.id_programado = mp.id_programado AND me.estado = 'INCOMPLETO') AS incompletos,
                   (SELECT GROUP_CONCAT(DISTINCT u.nombre_completo ORDER BY u.nombre_completo SEPARATOR ', ')
                      FROM mantenimiento_equipos me
                      JOIN users u ON u.id = COALESCE(me.id_usuario_asignado, me.id_usuario_ultimo)
                     WHERE me.id_programado = mp.id_programado AND me.estado IN ('TOMADO','REALIZADO','INCOMPLETO')) AS participantes
            FROM mantenimientos_programados mp
            JOIN salas s ON s.id_sala = mp.id_sala
            LEFT JOIN users resp ON resp.id = mp.id_responsable
            WHERE mp.estado IN ('PENDIENTE','PROGRAMADO','REPROGRAMADO','EN_PROCESO')
            ORDER BY mp.estado = 'EN_PROCESO' DESC, mp.fecha_programada ASC
            LIMIT 40
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function erroresSala(PDO $pdo): array
    {
        $prof = $pdo->query("
            SELECT 'tickets_profesor' AS tabla, tp.id, tp.origen, tp.estado, tp.fecha_creacion,
                   COALESCE(s.abreviatura, '') AS abreviatura, COALESCE(s.nombre_sala, tp.sala_danio, tp.salon) AS nombre_sala,
                   tp.descripcion_problema AS descripcion,
                   prof.nombre_completo AS reportado_por,
                   tec.nombre_completo AS tecnico, bec.nombre_completo AS beca
            FROM tickets_profesor tp
            JOIN users prof ON prof.id = tp.profesor_id
            LEFT JOIN salas s ON s.id_sala = tp.id_sala
            LEFT JOIN users tec ON tec.id = tp.tecnico_id
            LEFT JOIN users bec ON bec.id = tp.beca_id
            WHERE tp.tipo_ticket = 'danio' AND tp.estado NOT IN (" . self::ESTADOS_TERMINADOS . ")
        ")->fetchAll(PDO::FETCH_ASSOC);
        $qr = $pdo->query("
            SELECT 'tickets' AS tabla, t.id, t.origen, t.estado, t.fecha_creacion,
                   COALESCE(s.abreviatura, '') AS abreviatura, COALESCE(s.nombre_sala, t.ubicacion) AS nombre_sala,
                   t.descripcion,
                   COALESCE(t.nombre_contacto, 'Sin sesión') AS reportado_por,
                   tec.nombre_completo AS tecnico, bec.nombre_completo AS beca
            FROM tickets t
            LEFT JOIN salas s ON s.id_sala = t.id_sala
            LEFT JOIN users tec ON tec.id = t.tecnico_id
            LEFT JOIN users bec ON bec.id = t.beca_id
            WHERE t.origen = 'QR_ANONIMO' AND t.estado NOT IN (" . self::ESTADOS_TERMINADOS . ")
        ")->fetchAll(PDO::FETCH_ASSOC);
        $todos = array_merge($prof, $qr);
        // Primero lo que nadie ha tomado, después lo más antiguo (lo que más espera).
        usort($todos, fn($a, $b) => [(int)!empty($a['tecnico']) + (int)!empty($a['beca']) > 0, $a['fecha_creacion']]
                                   <=> [(int)!empty($b['tecnico']) + (int)!empty($b['beca']) > 0, $b['fecha_creacion']]);
        return array_slice($todos, 0, 40);
    }
}
