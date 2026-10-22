<?php
/**
 * TicketPermissions
 * ==================
 * El resto de verificaciones de rol sigue en cada página hasta migrarla.
 *
 * Esto NO cambia ninguna verificación de rol que ya exista en
 * tickets.php, tickets.php, tickets.php,
 * etc. — esos `if ($_SESSION['role'] != 'admin')` siguen intactos y
 * siguen siendo la protección real hoy. Esta clase es la tabla de
 * permisos que código NUEVO puede empezar a consultar en vez de
 * escribir un nuevo `if role == ...` cada vez que aparezca un rol
 * distinto (ej. el rol 'secretaria', que existe en el ENUM de `users`
 * pero que ningún archivo PHP todavía distingue de 'solicitante').
 *
 * Asignación de permisos por rol:
 *   - tecnico  -> actúa sobre `tickets`        (tickets.php)
 *   - beca_trabajo -> actúa sobre `tickets_profesor` como si fuera
 *                     técnico (tickets.php)
 *   - admin    -> acceso completo a ambas tablas (tickets.php)
 *   - solicitante/profesor/secretaria -> solo crean y ven lo suyo
 *
 * 'secretaria' todavía no tiene una pantalla propia (no existe un
 * create_ticket_secretaria.php ni similar). Se deja mapeada igual que
 * 'solicitante' por ahora; confirmar antes de exponer un botón o ruta
 * nueva basada en este permiso.
 */

final class TicketPermissions
{
    public const CREATE   = 'ticket.create';
    public const VIEW     = 'ticket.view';
    public const TAKE     = 'ticket.take';
    public const ASSIGN   = 'ticket.assign';
    public const RELEASE  = 'ticket.release';
    public const COMMENT  = 'ticket.comment';
    public const SOLVE    = 'ticket.solve';
    public const APPROVE  = 'ticket.approve';
    public const REJECT   = 'ticket.reject';
    public const DISCARD  = 'ticket.discard';
    public const CLOSE    = 'ticket.close';

    private const TODOS = [
        self::CREATE, self::VIEW, self::TAKE, self::ASSIGN, self::RELEASE,
        self::COMMENT, self::SOLVE, self::APPROVE, self::REJECT,
        self::DISCARD, self::CLOSE,
    ];

    /** rol => permisos que hoy ejerce en el código real. */
    private const PERMISOS_POR_ROL = [
        Rol::ADMIN        => self::TODOS,
        // tecnico: además de tomar/liberar, en tickets.php asigna y
        // quita becas, aprueba/rechaza lo resuelto (por él o por una beca)
        // y descarta reportes inválidos. CLOSE (cierre administrativo) es
        // solo del admin.
        Rol::TECNICO      => [self::VIEW, self::TAKE, self::ASSIGN, self::RELEASE, self::COMMENT,
                           self::SOLVE, self::APPROVE, self::REJECT, self::DISCARD],
        Rol::BECA => [self::VIEW, self::TAKE, self::RELEASE, self::COMMENT, self::SOLVE],
        Rol::PROFESOR     => [self::CREATE, self::VIEW, self::COMMENT, self::APPROVE],
        // 'aprobar' para profesor = firmar/aprobar SU PROPIO ticket ya
        // solucionado (ver tickets.php sección "aprobar" / firmado),
        // no aprobar el de otros. Este permiso se combina siempre con
        // "es el dueño del ticket" en quien lo consulte — esta tabla
        // solo dice QUÉ puede llegar a hacer un rol, no sobre CUÁL ticket.
        Rol::SOLICITANTE  => [self::CREATE, self::VIEW, self::COMMENT, self::APPROVE],
        Rol::SECRETARIA   => [self::CREATE, self::VIEW, self::COMMENT], // ver duda arriba
    ];

    public static function tienePermiso(string $rol, string $permiso): bool
    {
        if (!in_array($permiso, self::TODOS, true)) {
            throw new InvalidArgumentException("Permiso no reconocido: $permiso");
        }
        return in_array($permiso, self::PERMISOS_POR_ROL[$rol] ?? [], true);
    }

}
