<?php
/**
 * TicketPanelRepository
 * ===============================
 * Persistencia (solo LECTURA) de los paneles de tickets.
 */
require_once __DIR__ . '/../../Support/validacion.php';  // correoValido(), validarTexto()
require_once __DIR__ . '/../../Support/paginacion.php';

final class TicketPanelRepository
{
    private const SQL_BASE_NORMALES = "
    SELECT t.*, sol.nombre_completo AS solicitante_nombre, tec.nombre_completo AS tecnico_nombre, bec.nombre_completo AS beca_nombre, s.nombre_sala,
           sd.nombre_sala AS nombre_sala_destino,
           COALESCE(mp.id_programado, mpv.id_programado) AS mantenimiento_generado, COALESCE(mp.estado, mpv.estado) AS mantenimiento_estado,
           (SELECT COUNT(*) FROM mantenimiento_equipos me WHERE me.id_programado = COALESCE(mp.id_programado, mpv.id_programado)) AS mant_total_equipos,
           (SELECT COUNT(*) FROM mantenimiento_equipos me WHERE me.id_programado = COALESCE(mp.id_programado, mpv.id_programado) AND me.estado = 'REALIZADO') AS mant_realizados,
           (SELECT GROUP_CONCAT(
                CONCAT(c.tipo, ': ', COALESCE(NULLIF(TRIM(CONCAT(COALESCE(c.marca,''),' ',COALESCE(c.modelo,''))),''),'sin marca/modelo registrada'), ' (', c.codigo_inventario, ')')
                SEPARATOR ' | ')
            FROM componentes c WHERE c.id_equipo_actual = t.id_equipo) AS componentes_actuales
    FROM tickets t
    LEFT JOIN users sol ON t.user_id = sol.id
    LEFT JOIN users tec ON t.tecnico_id = tec.id
    LEFT JOIN users bec ON t.beca_id = bec.id
    LEFT JOIN salas s ON t.id_sala = s.id_sala
    LEFT JOIN salas sd ON t.id_sala_destino = sd.id_sala
    LEFT JOIN mantenimientos_programados mp ON mp.id_ticket_origen = t.id
    LEFT JOIN mantenimiento_vinculos mvx ON mvx.tabla_ticket = 'tickets' AND mvx.id_ticket = t.id
    LEFT JOIN mantenimientos_programados mpv ON mpv.id_programado = mvx.id_programado
";

    private const SQL_BASE_PROFESOR = "
    SELECT tp.*, prof.nombre_completo AS profesor_nombre, tec.nombre_completo AS tecnico_nombre, bec.nombre_completo AS beca_nombre,
           h.dia_semana AS horario_dia, h.hora_inicio AS horario_hora_inicio, h.hora_fin AS horario_hora_fin,
           hs.nombre_sala AS horario_sala,
           sp.dependencia AS dependencia_sala, spa.dependencia AS dependencia_sala_actual,
           COALESCE(mp.id_programado, mpv.id_programado) AS mantenimiento_generado, COALESCE(mp.estado, mpv.estado) AS mantenimiento_estado,
           (SELECT COUNT(*) FROM mantenimiento_equipos me WHERE me.id_programado = COALESCE(mp.id_programado, mpv.id_programado)) AS mant_total_equipos,
           (SELECT COUNT(*) FROM mantenimiento_equipos me WHERE me.id_programado = COALESCE(mp.id_programado, mpv.id_programado) AND me.estado = 'REALIZADO') AS mant_realizados
    FROM tickets_profesor tp
    JOIN users prof ON tp.profesor_id = prof.id
    LEFT JOIN users tec ON tp.tecnico_id = tec.id
    LEFT JOIN users bec ON tp.beca_id = bec.id
    LEFT JOIN horarios h ON tp.id_horario_referencia = h.id_horario
    LEFT JOIN salas hs ON h.id_sala = hs.id_sala
    LEFT JOIN salas sp ON tp.id_sala = sp.id_sala
    LEFT JOIN salas spa ON tp.id_sala_actual = spa.id_sala
    LEFT JOIN mantenimientos_programados mp ON mp.id_ticket_profesor_origen = tp.id
    LEFT JOIN mantenimiento_vinculos mvx ON mvx.tabla_ticket = 'tickets_profesor' AND mvx.id_ticket = tp.id
    LEFT JOIN mantenimientos_programados mpv ON mpv.id_programado = mvx.id_programado
";

    /** Todas las salas (para ofrecer "¿es sobre esta sala?" en tickets de texto libre). */
    public static function salasTodas(PDO $pdo): array
    {
        return $pdo->query("SELECT id_sala, nombre_sala FROM salas ORDER BY nombre_sala")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Becas activas para el selector "Asignar a una beca". */
    public static function becasParaAsignar(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT id, nombre_completo FROM users WHERE role = 'beca_trabajo' AND estado_activo = 1 AND " . AreaBeca::condicionMantenimientoSQL($pdo) . " ORDER BY nombre_completo"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Todas las becas con su código (para cruzar con la asistencia facial). */
    public static function becasConCodigo(PDO $pdo): array
    {
        return $pdo->query("    SELECT id, nombre_completo, codigo 
    FROM users 
    WHERE role='beca_trabajo'
    ORDER BY nombre_completo")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Profesor dueño de un ticket de tickets_profesor (o false si no existe). */
    public static function idProfesorDeTicket(PDO $pdo, int $idTicketProfesor)
    {
        $stmt = $pdo->prepare("SELECT profesor_id FROM tickets_profesor WHERE id = ?");
        $stmt->execute([$idTicketProfesor]);
        return $stmt->fetchColumn();
    }

    /** Con 30.000 tickets la bandeja completa agotaba la memoria (HTTP 500). */
    public static function todosNormalesLigeros(PDO $pdo, bool $sistemas): array
    {
        $cond = TicketConsultas::condicionSistemasTicketsSQL('t', 's');
        return $pdo->query('SELECT t.id, t.fecha_creacion, t.estado, t.firmado FROM tickets t LEFT JOIN salas s ON t.id_sala = s.id_sala WHERE '
            . ($sistemas ? $cond : "NOT $cond") . ' ORDER BY t.fecha_creacion DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Filas completas (mismas columnas que todosNormales) de los ids indicados, indexadas por id. */
    public static function normalesPorIds(PDO $pdo, array $ids): array
    {
        return self::porIds($pdo, self::SQL_BASE_NORMALES, 't.id', $ids);
    }

    /** Igual que todosNormalesLigeros(), para tickets de profesor (mismo WHERE que todosProfesor). */
    public static function todosProfesorLigeros(PDO $pdo, bool $sistemas): array
    {
        $cond = TicketConsultas::condicionSistemasProfesorSQL('sp', 'spa');
        return $pdo->query('SELECT tp.id, tp.fecha_creacion, tp.estado, tp.firmado FROM tickets_profesor tp
            JOIN users prof ON tp.profesor_id = prof.id
            LEFT JOIN salas sp ON tp.id_sala = sp.id_sala
            LEFT JOIN salas spa ON tp.id_sala_actual = spa.id_sala
            WHERE ' . ($sistemas ? $cond : "NOT $cond") . ' ORDER BY tp.fecha_creacion DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Filas completas (mismas columnas que todosProfesor) de los ids indicados, indexadas por id. */
    public static function profesorPorIds(PDO $pdo, array $ids): array
    {
        return self::porIds($pdo, self::SQL_BASE_PROFESOR, 'tp.id', $ids);
    }

    /** SELECT base + «WHERE <col> IN (…)», devuelto como id => fila (la primera si el JOIN repite filas). */
    public static function porIds(PDO $pdo, string $sqlBase, string $columnaId, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        $st = $pdo->prepare($sqlBase . " WHERE $columnaId IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $out[(int)$f['id']] ??= $f;
        }
        return $out;
    }

}
