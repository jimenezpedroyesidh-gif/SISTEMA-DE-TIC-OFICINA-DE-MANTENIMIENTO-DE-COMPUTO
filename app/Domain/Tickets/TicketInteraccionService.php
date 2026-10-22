<?php
/**
 * Acciones comunes a todos los paneles de tickets (técnico, beca, admin, profesor, solicitante):
 * conversación con quien reportó y fotos de evidencia. Un solo punto para no repetir el mismo
 * manejo de POST en cada controlador. Recibe los arreglos ($post, $files) en vez de leer superglobales.
 */
final class TicketInteraccionService
{
    private const ROLES_PERSONAL = [Rol::TECNICO, Rol::BECA];

    /**
     * Procesa conversacion_enviar / evidencia_subir / evidencia_quitar si vienen en el POST.
     * El CSRF ya lo validó el controlador.
     * @return array{accion:?string,ok:bool,mensaje:string}
     */
    public static function procesar(PDO $pdo, array $post, array $files, int $idUsuario, string $rol): array
    {
        $tabla = TablaTicket::desdeEntrada($post['tabla_ticket'] ?? null);
        $idTicket = (int)($post['ticket_id'] ?? 0);

        if (isset($post['conversacion_enviar'])) {
            if (!$tabla || !$idTicket) return self::r('conversacion', false, 'Ticket no válido.');
            $texto = (string)($post['mensaje'] ?? '');
            $res = in_array($rol, self::ROLES_PERSONAL, true)
                ? TicketConversacion::enviarPersonal($pdo, $tabla, $idTicket, $idUsuario, $texto)
                // El admin solo lee las conversaciones ajenas; en SUS solicitudes (como docente) escribe como solicitante.
                : TicketConversacion::enviarSolicitante($pdo, $tabla, $idTicket, $idUsuario, $texto);
            return self::r('conversacion', $res['ok'], $res['mensaje']);
        }
        if (isset($post['evidencia_subir'])) {
            if (!$tabla || !$idTicket) return self::r('evidencia', false, 'Ticket no válido.');
            $res = TicketEvidencia::subir($pdo, $tabla, $idTicket, $idUsuario, $files['evidencia'] ?? null);
            $msg = $res['guardadas'] ? $res['guardadas'] . ' foto(s) agregada(s).' : 'No se agregó ninguna foto.';
            return self::r('evidencia', $res['ok'] && $res['guardadas'] > 0, trim($msg . ' ' . $res['mensaje']));
        }
        if (isset($post['evidencia_quitar'])) {
            $ok = TicketEvidencia::quitar($pdo, (int)($post['id_adjunto'] ?? 0), $idUsuario);
            return self::r('evidencia', $ok, $ok ? 'Foto quitada.' : 'No se pudo quitar la foto.');
        }
        return self::r(null, true, '');
    }

    /**
     * Antes de guardar un cambio de estado: sube las fotos que vengan en el mismo formulario.
     * Devuelve el aviso de archivos rechazados ('' si todo bien).
     */
    public static function subirFotosDelFormulario(PDO $pdo, string $tabla, int $idTicket, int $idUsuario, array $files): string
    {
        if (empty($files['evidencia'])) return '';
        return TicketEvidencia::subir($pdo, $tabla, $idTicket, $idUsuario, $files['evidencia'])['mensaje'];
    }

    /** Mensaje claro cuando un cambio a Solucionado no se guardó (falta explicación/foto u otra regla). */
    public static function motivoNoGuardado(PDO $pdo, string $tabla, int $idTicket, string $estado, ?string $solucion): string
    {
        $t = TicketRepository::crudo($pdo, $tabla, $idTicket);
        if ($t && $estado === TicketWorkflow::SOLUCIONADO) {
            $falta = TicketEvidencia::faltante($pdo, $tabla, $t, $solucion);
            if ($falta) return $falta;
        }
        return 'No se pudo actualizar el ticket (¿ya no está asignado a ti o ya fue cerrado?).';
    }

    /**
     * Datos de conversación y evidencia de las filas visibles, en bloque (la vista no consulta la base).
     * @param array<string,int[]> $idsPorTabla 'tickets' => [...], 'tickets_profesor' => [...]
     * @return array{conversacion:array,evidencias:array}
     */
    public static function precargar(PDO $pdo, array $idsPorTabla): array
    {
        $out = ['conversacion' => [], 'evidencias' => []];
        foreach ($idsPorTabla as $tabla => $ids) {
            $out['conversacion'][$tabla] = TicketConversacion::deTickets($pdo, $tabla, $ids);
            $out['evidencias'][$tabla] = TicketEvidencia::deTickets($pdo, $tabla, $ids);
        }
        return $out;
    }

    /**
     * Para las tablas del solicitante/profesor: si puede escribir ahora (respuesta o reiteración) o desde cuándo.
     * @param array<int,array> $filas filas visibles del ticket
     * @param array<int,array> $conversacion precargar()['conversacion'][$tabla]
     * @return array<int,array> id => TicketConversacion::estadoSolicitante()
     */
    public static function estadosSolicitante(PDO $pdo, string $tabla, array $filas, array $conversacion): array
    {
        $out = [];
        foreach ($filas as $f) {
            $out[(int)$f['id']] = TicketConversacion::estadoSolicitante($pdo, $tabla, $f, $conversacion[(int)$f['id']] ?? []);
        }
        return $out;
    }

    private static function r(?string $accion, bool $ok, string $mensaje): array
    {
        return ['accion' => $accion, 'ok' => $ok, 'mensaje' => $mensaje];
    }
}
