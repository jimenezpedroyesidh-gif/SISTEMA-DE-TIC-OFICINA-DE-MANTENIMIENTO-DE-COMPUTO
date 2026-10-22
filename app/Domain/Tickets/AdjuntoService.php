<?php
/**
 * Descarga de adjuntos: decide si un usuario puede ver un adjunto y resuelve su ruta en disco. Devuelve
 * ['ok'=>bool, 'status'=>int, 'mensaje'=>string, 'adjunto'=>array|null, 'ruta'=>string|null,
 * 'nombre'=>string|null].
 */

final class AdjuntoService
{
    public static function resolver(PDO $pdo, ?int $idAdjunto, string $rol, int $idUsuario): array
    {
        $fallo = fn(int $s, string $m) => ['ok' => false, 'status' => $s, 'mensaje' => $m, 'adjunto' => null, 'ruta' => null, 'nombre' => null];
        if ($idAdjunto === null) return $fallo(404, 'Adjunto no encontrado.');

        $stmt = $pdo->prepare("SELECT * FROM ticket_adjuntos WHERE id = ?");
        $stmt->execute([$idAdjunto]);
        $adjunto = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$adjunto) return $fallo(404, 'Adjunto no encontrado.');

        $esStaff = in_array($rol, [Rol::TECNICO, Rol::ADMIN], true);

        // beca_trabajo: solo adjuntos de tickets que le corresponden; los que no cuelgan de un ticket
        // (evidencia de mantenimiento/movimientos) siguen siendo visibles para ella.
        if (!$esStaff && $rol === Rol::BECA) {
            if (!empty($adjunto['id_ticket']) && TablaTicket::valida($adjunto['tabla_ticket'])) {
                $esStaff = TicketConsultas::becaPuedeVerTicket($pdo, $idUsuario, $adjunto['tabla_ticket'], (int)$adjunto['id_ticket']);
            } else {
                $esStaff = true;
            }
        }

        if (!$esStaff && !self::esDueno($pdo, $adjunto, $idUsuario)) {
            return $fallo(403, 'No tienes acceso a este archivo.');
        }

        $ruta = TicketAdjuntos::carpetaAdjuntos() . '/' . basename((string)$adjunto['nombre_almacenado']); // defensa contra path traversal
        if (!is_file($ruta)) return $fallo(404, 'El archivo ya no está disponible.');

        // nombre sin comillas ni caracteres de control (evita romper/inyectar la cabecera)
        $nombre = preg_replace('/["\\\\\x00-\x1f\x7f]/', '_', basename((string)$adjunto['nombre_original']));
        return ['ok' => true, 'status' => 200, 'mensaje' => '', 'adjunto' => $adjunto, 'ruta' => $ruta, 'nombre' => $nombre];
    }

    /** Solo el dueño del ticket relacionado; un adjunto de movimiento (evidencia) nunca es de un solicitante/profesor. */
    private static function esDueno(PDO $pdo, array $adjunto, int $idUsuario): bool
    {
        if (!$adjunto['id_ticket']) return false;
        if ($adjunto['tabla_ticket'] === TablaTicket::NORMAL) {
            $chk = $pdo->prepare("SELECT 1 FROM tickets WHERE id=? AND user_id=?");
        } elseif ($adjunto['tabla_ticket'] === TablaTicket::PROFESOR) {
            $chk = $pdo->prepare("SELECT 1 FROM tickets_profesor WHERE id=? AND profesor_id=?");
        } else {
            return false;
        }
        $chk->execute([$adjunto['id_ticket'], $idUsuario]);
        return (bool)$chk->fetchColumn();
    }
}
