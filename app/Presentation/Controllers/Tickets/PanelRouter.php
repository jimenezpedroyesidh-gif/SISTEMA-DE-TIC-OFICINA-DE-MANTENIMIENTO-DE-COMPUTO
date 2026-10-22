<?php
/**
 * Enrutador del panel único de tickets. Dice qué script de panel carga cada rol. Los scripts de panel
 * conservan su propio requireRole() como segunda barrera.
 */

/** Rol → nombre del panel (archivo en este mismo directorio: <Nombre>Panel.php). */
function panelTicketsDeRol(string $rol): ?string
{
    static $paneles = [
        Rol::TECNICO      => 'Tecnico',
        Rol::ADMIN        => 'Admin',
        Rol::BECA => 'Beca',
        Rol::PROFESOR     => 'Profesor',
        Rol::SOLICITANTE  => 'Solicitante',
        Rol::SECRETARIA   => 'Solicitante', // la secretaria usa el panel de solicitante (+ pestaña Sistemas con permiso)
    ];
    return $paneles[$rol] ?? null;
}
