<?php
/**
 * Las dos tablas físicas de tickets: `tickets` (QR, solicitantes, secretaria, beca/técnico) y `tickets_profesor`.
 * Única validación de nombre de tabla: evita inyección por nombre en las consultas que reciben la tabla.
 */
final class TablaTicket
{
    public const NORMAL   = 'tickets';
    public const PROFESOR = 'tickets_profesor';
    public const TODAS    = [self::NORMAL, self::PROFESOR];

    public static function valida($tabla): bool
    {
        return is_string($tabla) && in_array($tabla, self::TODAS, true);
    }

    /** Tabla que llega del formulario (tabla_ticket) o null si no es válida. */
    public static function desdeEntrada($valor): ?string
    {
        return self::valida($valor) ? $valor : null;
    }
}
