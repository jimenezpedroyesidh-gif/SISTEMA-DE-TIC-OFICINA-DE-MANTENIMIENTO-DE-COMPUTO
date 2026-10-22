<?php
/**
 * Encuesta de satisfacción (1 a 5) al cerrar un ticket. La responde el dueño desde su panel (profesor,
 * solicitante, secretaría) o quien reportó por QR sin cuenta, con su código de seguimiento. Una sola
 * respuesta por ticket. También resume los resultados para los indicadores.
 */
final class EncuestaService
{
    public const CALIFICACIONES = [5 => 'Excelente', 4 => 'Buena', 3 => 'Aceptable', 2 => 'Mala', 1 => 'Muy mala'];
    public const ESTADOS_CALIFICABLES = [TicketWorkflow::SOLUCIONADO, TicketWorkflow::LISTO_SIN_VALIDAR, TicketWorkflow::APROBADO, TicketWorkflow::CERRADO];

    /** ¿El ticket admite encuesta? (resuelto y sin respuesta previa). */
    public static function puedeCalificar(PDO $pdo, string $tabla, int $id, string $estado): bool
    {
        return in_array($estado, self::ESTADOS_CALIFICABLES, true) && self::respuesta($pdo, $tabla, $id) === null;
    }

    public static function respuesta(PDO $pdo, string $tabla, int $id): ?array
    {
        try {
            $st = $pdo->prepare('SELECT calificacion, comentario, fecha FROM encuestas_satisfaccion WHERE tabla_ticket = ? AND id_ticket = ?');
            $st->execute([$tabla, $id]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return array<int,array<string,mixed>> id_ticket => respuesta, para pintar las listas de un panel */
    public static function respuestasDe(PDO $pdo, string $tabla, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT id_ticket, calificacion FROM encuestas_satisfaccion WHERE tabla_ticket = ? AND id_ticket IN ($in)");
            $st->execute(array_merge([$tabla], $ids));
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id_ticket']] = $r;
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Registra la respuesta desde el panel: el ticket debe ser de quien responde y estar resuelto.
     * @return array{ok:bool,mensaje:string}
     */
    public static function responderDesdePanel(PDO $pdo, int $idUsuario, string $tabla, int $id, $calificacion, string $comentario): array
    {
        if (!TablaTicket::valida($tabla)) return ['ok' => false, 'mensaje' => 'Ticket no válido.'];
        $col = $tabla === TablaTicket::NORMAL ? 'user_id' : 'profesor_id';
        $st = $pdo->prepare("SELECT estado FROM `$tabla` WHERE id = ? AND $col = ?");
        $st->execute([$id, $idUsuario]);
        $estado = $st->fetchColumn();
        if ($estado === false) return ['ok' => false, 'mensaje' => 'Ese ticket no es tuyo.'];
        return self::guardar($pdo, $tabla, $id, (string)$estado, $calificacion, $comentario, 'PANEL', $idUsuario);
    }

    /** Registra la respuesta de un reporte anónimo por QR (se identifica por su código de seguimiento). */
    public static function responderConCodigo(PDO $pdo, string $codigo, $calificacion, string $comentario): array
    {
        $normalizado = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $codigo));
        $t = $normalizado !== '' ? ReporteQrRepository::reportePublicoPorCodigo($pdo, $normalizado) : null;
        if (!$t) return ['ok' => false, 'mensaje' => 'No se encontró el reporte.'];
        return self::guardar($pdo, TablaTicket::NORMAL, (int)$t['id'], (string)$t['estado'], $calificacion, $comentario, 'CODIGO', null);
    }

    private static function guardar(PDO $pdo, string $tabla, int $id, string $estado, $calificacion, string $comentario, string $canal, ?int $idUsuario): array
    {
        $cal = (int)$calificacion;
        if (!isset(self::CALIFICACIONES[$cal])) return ['ok' => false, 'mensaje' => 'Elige una calificación de 1 a 5.'];
        if (!in_array($estado, self::ESTADOS_CALIFICABLES, true)) return ['ok' => false, 'mensaje' => 'Podrás calificar la atención cuando el ticket esté resuelto.'];
        $comentario = trim($comentario);
        if (mb_strlen($comentario) > 500) return ['ok' => false, 'mensaje' => 'El comentario admite máximo 500 caracteres.'];
        try {
            $st = $pdo->prepare('INSERT IGNORE INTO encuestas_satisfaccion (tabla_ticket, id_ticket, calificacion, comentario, canal, id_usuario) VALUES (?, ?, ?, ?, ?, ?)');
            $st->execute([$tabla, $id, $cal, $comentario !== '' ? $comentario : null, $canal, $idUsuario]);
        } catch (PDOException $e) {
            return ['ok' => false, 'mensaje' => 'La encuesta aún no está disponible (falta actualizar la base de datos).'];
        }
        if ($st->rowCount() === 0) return ['ok' => false, 'mensaje' => 'Este ticket ya fue calificado. ¡Gracias!'];
        return ['ok' => true, 'mensaje' => 'Gracias por calificar la atención.'];
    }
}
