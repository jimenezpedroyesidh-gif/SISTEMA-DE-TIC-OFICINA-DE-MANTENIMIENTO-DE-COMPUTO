<?php
/**
 * AdminTicketsQueries
 * ============================
 * El SQL es exactamente el mismo; solo se movió aquí para que la página no contenga SQL. No escribe nada en
 * la base de datos.
 */
final class AdminTicketsQueries
{
    /** SELECT + FROM/JOIN de los tickets de solicitante/QR del panel admin (sin WHERE ni ORDER). */
    private const SQL_SOLICITANTE = "
    SELECT t.*, u.nombre_completo AS solicitante, u.role AS solicitante_rol, s.nombre_sala, s.dependencia AS sala_dependencia,
           COALESCE(mp.id_programado, mpv.id_programado) AS mantenimiento_generado, COALESCE(mp.estado, mpv.estado) AS mantenimiento_estado,
           (SELECT COUNT(*) FROM mantenimiento_equipos me WHERE me.id_programado = COALESCE(mp.id_programado, mpv.id_programado)) AS mant_total_equipos,
           (SELECT COUNT(*) FROM mantenimiento_equipos me WHERE me.id_programado = COALESCE(mp.id_programado, mpv.id_programado) AND me.estado = 'REALIZADO') AS mant_realizados
    FROM tickets t
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN salas s ON t.id_sala = s.id_sala
    LEFT JOIN mantenimientos_programados mp ON mp.id_ticket_origen = t.id
    LEFT JOIN mantenimiento_vinculos mvx ON mvx.tabla_ticket = 'tickets' AND mvx.id_ticket = t.id
    LEFT JOIN mantenimientos_programados mpv ON mpv.id_programado = mvx.id_programado
";

    /** SELECT + FROM/JOIN de los tickets de profesor del panel admin (sin WHERE ni ORDER). */
    private const SQL_PROFESOR = "
    SELECT t.*, 
           u.nombre_completo AS profesor_nombre, 
           tec.nombre_completo AS tecnico_nombre,
           h.dia_semana AS horario_dia, h.hora_inicio AS horario_hora_inicio, h.hora_fin AS horario_hora_fin,
           hs.nombre_sala AS horario_sala,
           sp.dependencia AS dependencia_sala, spa.dependencia AS dependencia_sala_actual
    FROM tickets_profesor t
    JOIN users u ON t.profesor_id = u.id
    LEFT JOIN users tec ON t.tecnico_id = tec.id
    LEFT JOIN horarios h ON t.id_horario_referencia = h.id_horario
    LEFT JOIN salas hs ON h.id_sala = hs.id_sala
    LEFT JOIN salas sp ON t.id_sala = sp.id_sala
    LEFT JOIN salas spa ON t.id_sala_actual = spa.id_sala
";

    /** Las filas completas de la página visible se piden con ticketsSolicitantePorIds(). */
    public static function ticketsSolicitanteLigeros(PDO $pdo): array
    {
        return $pdo->query("
    SELECT t.id, t.fecha_creacion, t.estado, t.firmado, t.origen, u.role AS solicitante_rol, s.dependencia AS sala_dependencia
    FROM tickets t
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN salas s ON t.id_sala = s.id_sala
    WHERE t.origen = 'QR_ANONIMO' OR u.role IN ('solicitante','secretaria')
    ORDER BY t.fecha_creacion DESC
")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Filas completas (mismas columnas que ticketsSolicitante) de los ids indicados: id => fila. */
    public static function ticketsSolicitantePorIds(PDO $pdo, array $ids): array
    {
        return TicketPanelRepository::porIds($pdo, self::SQL_SOLICITANTE, 't.id', $ids);
    }

    /** Versión LIGERA de ticketsProfesor() (mismo orden; columnas para clasificar y paginar). */
    public static function ticketsProfesorLigeros(PDO $pdo): array
    {
        return $pdo->query("
    SELECT t.id, t.fecha_creacion, t.estado, t.firmado, sp.dependencia AS dependencia_sala, spa.dependencia AS dependencia_sala_actual
    FROM tickets_profesor t
    JOIN users u ON t.profesor_id = u.id
    LEFT JOIN salas sp ON t.id_sala = sp.id_sala
    LEFT JOIN salas spa ON t.id_sala_actual = spa.id_sala
    ORDER BY t.fecha_creacion DESC
")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Filas completas (mismas columnas que ticketsProfesor) de los ids indicados: id => fila. */
    public static function ticketsProfesorPorIds(PDO $pdo, array $ids): array
    {
        return TicketPanelRepository::porIds($pdo, self::SQL_PROFESOR, 't.id', $ids);
    }

    /** Promedio de horas de resolución de `tickets` (promedio_horas, total_resueltos). */
    public static function tiempoPromedioTickets(PDO $pdo): array
    {
        return $pdo->query("
    SELECT AVG(TIMESTAMPDIFF(HOUR, fecha_creacion, fecha_terminacion)) AS promedio_horas,
           COUNT(*) AS total_resueltos
    FROM tickets
    WHERE fecha_terminacion IS NOT NULL
")->fetch(PDO::FETCH_ASSOC);
    }

    /** Ídem para `tickets_profesor` (usa fecha_firmado). */
    public static function tiempoPromedioTicketsProfesor(PDO $pdo): array
    {
        return $pdo->query("
    SELECT AVG(TIMESTAMPDIFF(HOUR, fecha_creacion, fecha_firmado)) AS promedio_horas,
           COUNT(*) AS total_resueltos
    FROM tickets_profesor
    WHERE fecha_firmado IS NOT NULL
")->fetch(PDO::FETCH_ASSOC);
    }

    public static function tiempoPorTecnico(PDO $pdo): array
    {
        return $pdo->query("
    SELECT u.nombre_completo AS tecnico,
           AVG(TIMESTAMPDIFF(HOUR, t.fecha_creacion, t.fecha_terminacion)) AS promedio_horas,
           COUNT(*) AS total_resueltos
    FROM tickets t
    JOIN users u ON t.tecnico_id = u.id
    WHERE t.fecha_terminacion IS NOT NULL
    GROUP BY u.id
    ORDER BY promedio_horas ASC
")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function rankingSalas(PDO $pdo): array
    {
        return $pdo->query("
    SELECT etiqueta_sala, COUNT(*) AS total FROM (
        SELECT COALESCE(s.nombre_sala, NULLIF(TRIM(t.nombre_dependencia), ''), 'Sin especificar') AS etiqueta_sala
        FROM tickets t
        LEFT JOIN salas s ON t.id_sala = s.id_sala
        UNION ALL
        SELECT COALESCE(NULLIF(TRIM(tp.sala_danio), ''), NULLIF(TRIM(tp.salon), ''), 'Sin especificar') AS etiqueta_sala
        FROM tickets_profesor tp
        WHERE tp.tipo_ticket = 'danio'
    ) combinado
    GROUP BY etiqueta_sala
    ORDER BY total DESC, etiqueta_sala ASC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function rankingModelosTexto(PDO $pdo): array
    {
        return $pdo->query("
    SELECT TRIM(marca_modelo) AS modelo, COUNT(*) AS total
    FROM tickets
    WHERE marca_modelo IS NOT NULL AND TRIM(marca_modelo) <> ''
    GROUP BY TRIM(marca_modelo)
    ORDER BY total DESC, modelo ASC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function rankingModelosComponentes(PDO $pdo): array
    {
        return $pdo->query("
    SELECT c.tipo, c.marca, c.modelo, COUNT(*) AS total
    FROM historial_componente h
    JOIN componentes c ON h.id_componente = c.id_componente
    WHERE h.tipo_evento = 'DESASIGNACION'
    GROUP BY c.tipo, c.marca, c.modelo
    ORDER BY total DESC, c.tipo ASC, c.marca ASC, c.modelo ASC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function becasUsuarios(PDO $pdo): array
    {
        return $pdo->query("SELECT id, nombre_completo, codigo FROM users WHERE role='beca_trabajo'")->fetchAll(PDO::FETCH_ASSOC);
    }
}
