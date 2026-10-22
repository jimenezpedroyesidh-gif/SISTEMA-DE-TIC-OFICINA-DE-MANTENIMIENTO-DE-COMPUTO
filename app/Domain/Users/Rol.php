<?php
/** Roles de usuario (columna users.role). Úsalos en vez de escribir el texto a mano. */
final class Rol
{
    public const ADMIN         = 'admin';
    public const TECNICO       = 'tecnico';
    public const BECA          = 'beca_trabajo';
    public const PROFESOR      = 'profesor';
    public const SECRETARIA    = 'secretaria';
    public const SOLICITANTE   = 'solicitante';
    public const VIGILANTE     = 'vigilante';
    public const VISUALIZACION = 'visualizacion';

    /** Mismo orden que el ENUM de users.role. */
    public const TODOS = [self::SOLICITANTE, self::TECNICO, self::ADMIN, self::PROFESOR, self::BECA, self::SECRETARIA, self::VIGILANTE, self::VISUALIZACION];
}
