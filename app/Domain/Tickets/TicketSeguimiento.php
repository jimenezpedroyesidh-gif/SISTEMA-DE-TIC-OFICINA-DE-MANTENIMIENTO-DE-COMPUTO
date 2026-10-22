<?php
/** Seguimiento de un ticket: asignaciones de técnico, comentarios internos y línea de tiempo. */
final class TicketSeguimiento
{
    // ===================== ticket_asignaciones =====================

    /**
     * Registra que un técnico tomó un ticket — se llama junto al UPDATE que
     * ya existía en tomar_ticket/tomar_profesor. No reemplaza esa columna
     * (`tecnico_id` sigue siendo la fuente de verdad de "quién lo tiene
     * AHORA"), esto es solo el historial de asignaciones pasadas.
     */
    public static function registrarAsignacionTicket(PDO $pdo, string $tablaTicket, int $idTicket, int $idTecnico): void {
        $stmt = $pdo->prepare(
            "INSERT INTO ticket_asignaciones (tabla_ticket, id_ticket, id_tecnico) VALUES (?, ?, ?)"
        );
        $stmt->execute([$tablaTicket, $idTicket, $idTecnico]);
    }

    /**
     * Cierra la asignación abierta más reciente de ese ticket+técnico (la
     * que tiene fecha_liberado NULL) — se llama junto al UPDATE que ya
     * existía en liberar_ticket/liberar_profesor.
     */
    public static function liberarAsignacionTicket(PDO $pdo, string $tablaTicket, int $idTicket, int $idTecnico, ?string $motivo = null): void {
        $stmt = $pdo->prepare(
            "UPDATE ticket_asignaciones
             SET fecha_liberado = NOW(), motivo_liberacion = ?
             WHERE tabla_ticket = ? AND id_ticket = ? AND id_tecnico = ? AND fecha_liberado IS NULL
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$motivo, $tablaTicket, $idTicket, $idTecnico]);
    }

    // ===================== ticket_comentarios =====================

    /** Agrega un comentario interno (técnico/beca/admin) a un ticket. La conversación con quien reportó va por TicketConversacion. */
    public static function agregarComentarioTicket(PDO $pdo, string $tablaTicket, int $idTicket, int $idUsuario, string $comentario): void {
        $comentario = trim($comentario);
        if ($comentario === '') {
            return;
        }
        $stmt = $pdo->prepare(
            "INSERT INTO ticket_comentarios (tabla_ticket, id_ticket, id_usuario, comentario) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$tablaTicket, $idTicket, $idUsuario, $comentario]);
    }

    /**
     * Comentarios de varios tickets en una sola consulta.
     * @param int[] $ids
     * @return array<int,array> id_ticket => comentarios (más antiguo primero); los tickets sin comentarios no aparecen.
     */
    public static function comentariosDeTickets(PDO $pdo, string $tablaTicket, array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT c.*, u.nombre_completo, u.role
             FROM ticket_comentarios c
             JOIN users u ON u.id = c.id_usuario
             WHERE c.tabla_ticket = ? AND c.visibilidad = 'INTERNO' AND c.id_ticket IN ($marcadores)
             ORDER BY c.fecha_creacion ASC"
        );
        $stmt->execute(array_merge([$tablaTicket], $ids));
        $porTicket = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) $porTicket[(int)$c['id_ticket']][] = $c;
        return $porTicket;
    }

    // ===================== ticket_historial (auditoria ya existente) =====================

}
