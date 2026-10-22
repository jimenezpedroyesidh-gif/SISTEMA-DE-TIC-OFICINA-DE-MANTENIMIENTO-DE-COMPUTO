<?php
/** Lectura de los datos que imprimen los PDF de ticket. Solo SQL preparado. */
final class TicketPdfRepository
{
    public static function ticket(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT t.*, u.nombre_completo AS solicitante, tec.nombre_completo AS tecnico, s.nombre_sala
             FROM tickets t
             LEFT JOIN users u ON t.user_id=u.id
             LEFT JOIN users tec ON t.tecnico_id=tec.id
             LEFT JOIN salas s ON t.id_sala = s.id_sala
             WHERE t.id=?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function ticketProfesor(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT t.*, p.nombre_completo AS profesor, tec.nombre_completo AS tecnico
             FROM tickets_profesor t
             JOIN users p ON t.profesor_id = p.id
             LEFT JOIN users tec ON t.tecnico_id = tec.id
             WHERE t.id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Equipos afectados (ticket_activos) de un ticket; $tabla = 'tickets' | 'tickets_profesor'. */
    public static function equiposAfectados(PDO $pdo, string $tabla, int $id): array
    {
        $stmt = $pdo->prepare(
            "SELECT e.codigo, e.descripcion
             FROM ticket_activos ta
             JOIN equipos e ON e.id_equipo = ta.id_equipo
             WHERE ta.tabla_ticket = ? AND ta.id_ticket = ?
             ORDER BY e.codigo"
        );
        $stmt->execute([$tabla, $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
