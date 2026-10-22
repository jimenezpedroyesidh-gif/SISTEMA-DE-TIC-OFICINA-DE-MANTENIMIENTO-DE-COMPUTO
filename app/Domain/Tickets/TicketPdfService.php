<?php
/**
 * Datos y control de acceso de los PDF de ticket. Reglas conservadas: beca_trabajo no abre PDF de ticket
 * (403); tecnico/admin ven cualquiera; el resto solo los suyos (tickets.user_id /
 * tickets_profesor.profesor_id). Los mensajes siguen siendo texto plano. Devuelve ['error'=>?string,
 * 'status'=>int, 'ticket'=>?array, 'equipos'=>string, 'extra'=>['conversacion'=>[],'fotos'=>[]]].
 */

final class TicketPdfService
{
    public static function textoEquipos(array $equipos): string
    {
        return implode(', ', array_map(
            fn($eq) => $eq['codigo'] . ($eq['descripcion'] ? ' (' . $eq['descripcion'] . ')' : ''),
            $equipos
        ));
    }

    public static function paraTicket(PDO $pdo, $idCrudo, string $rol, int $idUsuario): array
    {
        return self::cargar($pdo, $idCrudo, $rol, $idUsuario, TablaTicket::NORMAL);
    }

    public static function paraTicketProfesor(PDO $pdo, $idCrudo, string $rol, int $idUsuario): array
    {
        return self::cargar($pdo, $idCrudo, $rol, $idUsuario, TablaTicket::PROFESOR);
    }

    private static function cargar(PDO $pdo, $idCrudo, string $rol, int $idUsuario, string $tabla): array
    {
        $fallo = fn(int $s, string $m) => ['error' => $m, 'status' => $s, 'ticket' => null, 'equipos' => '', 'extra' => []];
        if ($rol === Rol::BECA) {
            return $fallo(403, 'Los becas de trabajo no tienen acceso a los PDF de tickets.');
        }
        $id = $idCrudo === null ? null : validarId($idCrudo);
        if ($id === null) return $fallo(200, 'ID de ticket no especificado');

        $esProfesor = $tabla === TablaTicket::PROFESOR;
        $ticket = $esProfesor ? TicketPdfRepository::ticketProfesor($pdo, $id) : TicketPdfRepository::ticket($pdo, $id);
        if (!$ticket) return $fallo(200, 'Ticket no encontrado');

        // Control de acceso por objeto. Un ticket de QR (user_id NULL) no tiene dueño que inicie sesión.
        $duenoId = (int)($esProfesor ? $ticket['profesor_id'] : ($ticket['user_id'] ?? 0));
        if (!in_array($rol, [Rol::TECNICO, Rol::ADMIN], true) && $duenoId !== $idUsuario) {
            return $fallo(403, 'No tienes permiso para ver este ticket.');
        }

        return [
            'error' => null, 'status' => 200, 'ticket' => $ticket,
            'equipos' => self::textoEquipos(TicketPdfRepository::equiposAfectados($pdo, $tabla, $id)),
            // Constancia: conversación con quien reportó y fotos del trabajo realizado.
            'extra' => [
                'conversacion' => TicketConversacion::deTicket($pdo, $tabla, $id),
                'fotos' => TicketEvidencia::deTicket($pdo, $tabla, $id),
            ],
        ];
    }
}
