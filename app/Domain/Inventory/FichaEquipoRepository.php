<?php
/**
 * FichaEquipoRepository — SQL (solo consultas preparadas) de public/ficha_equipo.php:
 * detalle de un puesto con sus historiales y listado/búsqueda de equipos agrupable por sala.
 * Las altas de componentes viven en ComponenteRepository (no se duplican aquí).
 */
final class FichaEquipoRepository
{
    /** Equipo con el nombre de su sala, o null. */
    public static function equipo(PDO $pdo, int $id): ?array
    {
        $s = $pdo->prepare("
            SELECT e.*, s.nombre_sala
            FROM equipos e JOIN salas s ON e.id_sala = s.id_sala
            WHERE e.id_equipo = ?
        ");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function componentes(PDO $pdo, int $idEquipo): array
    {
        $s = $pdo->prepare("SELECT * FROM componentes WHERE id_equipo_actual = ? ORDER BY tipo");
        $s->execute([$idEquipo]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Últimos eventos de cualquier componente que haya pasado por este equipo. */
    public static function eventosRecientes(PDO $pdo, int $idEquipo): array
    {
        $s = $pdo->prepare("
            SELECT h.*, c.codigo_inventario, c.tipo AS tipo_componente, u.nombre_completo AS responsable_nombre
            FROM historial_componente h
            JOIN componentes c ON h.id_componente = c.id_componente
            LEFT JOIN users u ON h.id_usuario_responsable = u.id
            WHERE h.id_equipo_anterior = ? OR h.id_equipo_nuevo = ?
            ORDER BY h.fecha_evento DESC
            LIMIT 20
        ");
        $s->execute([$idEquipo, $idEquipo]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Mantenimientos de sala que pasaron por este puesto (checklist compartida de equipos). */
    public static function mantenimientos(PDO $pdo, int $idEquipo): array
    {
        $s = $pdo->prepare("
            SELECT me.estado AS estado_equipo, me.fecha_actualizacion, me.observaciones,
                   m.id_programado, m.tipo, m.descripcion, m.fecha_programada,
                   u.nombre_completo AS marcado_por
            FROM mantenimiento_equipos me
            JOIN mantenimientos_programados m ON me.id_programado = m.id_programado
            LEFT JOIN users u ON me.id_usuario_ultimo = u.id
            WHERE me.id_equipo = ?
            ORDER BY m.fecha_programada DESC
            LIMIT 20
        ");
        $s->execute([$idEquipo]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Tickets que apuntan a este equipo, o a la sala completa cuando el reporte no fue de un puesto puntual. */
    public static function tickets(PDO $pdo, int $idEquipo, int $idSala): array
    {
        $s = $pdo->prepare("
            SELECT t.id, t.descripcion, t.estado, t.origen, t.fecha_creacion, t.fecha_terminacion,
                   t.id_equipo, u.nombre_completo AS creado_por
            FROM tickets t
            LEFT JOIN users u ON t.user_id = u.id
            WHERE t.id_equipo = ? OR (t.id_equipo IS NULL AND t.id_sala = ?)
            ORDER BY t.fecha_creacion DESC
            LIMIT 20
        ");
        $s->execute([$idEquipo, $idSala]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Requerimientos/daños de profesor (tabla tickets_profesor, a nivel de sala completa). */
    public static function ticketsProfesor(PDO $pdo, int $idSala): array
    {
        $s = $pdo->prepare("
            SELECT tp.id, tp.tipo_ticket, tp.subtipo_requerimiento, tp.descripcion_actividad,
                   tp.motivo, tp.software, tp.estado, tp.fecha_creacion,
                   u.nombre_completo AS creado_por
            FROM tickets_profesor tp
            LEFT JOIN users u ON tp.profesor_id = u.id
            WHERE tp.id_sala = ?
            ORDER BY tp.fecha_creacion DESC
            LIMIT 20
        ");
        $s->execute([$idSala]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Dependencia del usuario (se consulta en vivo para reflejar cambios de usuarios.php). */
    public static function dependenciaDeUsuario(PDO $pdo, int $idUsuario): ?string
    {
        $s = $pdo->prepare("SELECT dependencia FROM users WHERE id=?");
        $s->execute([$idUsuario]);
        return $s->fetchColumn() ?: null;
    }

    private const SELECT_EQUIPOS = "
        SELECT e.id_equipo, e.codigo, e.descripcion, s.id_sala, s.nombre_sala, s.dependencia,
               (SELECT COUNT(*) FROM componentes c WHERE c.id_equipo_actual = e.id_equipo) AS total_componentes
        FROM equipos e JOIN salas s ON e.id_sala = s.id_sala";

    /** Búsqueda por código de equipo, nombre de sala o dependencia. */
    public static function buscarEquipos(PDO $pdo, string $q): array
    {
        $s = $pdo->prepare(self::SELECT_EQUIPOS . "
            WHERE e.codigo LIKE ? OR s.nombre_sala LIKE ? OR s.dependencia LIKE ?
            ORDER BY s.dependencia, s.nombre_sala, e.codigo");
        $s->execute(["%$q%", "%$q%", "%$q%"]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function equiposDeDependencia(PDO $pdo, string $dependencia): array
    {
        $s = $pdo->prepare(self::SELECT_EQUIPOS . "
            WHERE s.dependencia = ?
            ORDER BY s.nombre_sala, e.codigo");
        $s->execute([$dependencia]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function todosLosEquipos(PDO $pdo): array
    {
        return $pdo->query(self::SELECT_EQUIPOS . "
            ORDER BY s.dependencia, s.nombre_sala, e.codigo")->fetchAll(PDO::FETCH_ASSOC);
    }
}
