<?php
/**
 * Datos del «shell» visual (header + campanita de notificaciones) que antes consultaban las vistas con $pdo.
 * El controlador llama `$shell = ShellData::cargar($pdo);` justo antes de renderizar (después de procesar sus
 * POST, para que el contador refleje lo ocurrido en la misma petición) y las vistas solo leen
 * $shell['usuario'], $shell['noLeidas'], $shell['ultimas'] y $shell['veHorasBecas'] (el menú lateral muestra
 * «Horas de becas» a quien el admin le activó ese permiso). De paso dispara la revisión periódica del SLA
 * (SlaService::revisionPeriodica, a lo sumo cada 5 minutos).
 */

final class ShellData
{
    /** @return array{usuario:?array,noLeidas:int,ultimas:array,veHorasBecas:bool,veHorariosSalas:bool} */
    public static function cargar(PDO $pdo): array
    {
        $id = (int)($_SESSION['user_id'] ?? 0);
        if ($id <= 0) return ['usuario' => null, 'noLeidas' => 0, 'ultimas' => [], 'veHorasBecas' => false, 'veHorariosSalas' => false];
        // Revisión del SLA (avisos de vencimiento, retención de IP, incidentes de seguridad) cada 5 minutos como máximo,
        // aprovechando que alguien está usando el sistema: no hace falta programar un cron. Antes de contar las notificaciones.
        SlaService::revisionPeriodica($pdo);
        return [
            'usuario'  => UserRepository::datosShell($pdo, $id),
            'noLeidas' => NotificationService::contarNoLeidas($pdo, $id),
            'ultimas'  => NotificationService::ultimas($pdo, $id, 10),
            'veHorasBecas' => UserPermissions::tiene($pdo, $id, UserPermissions::VER_HORAS_BECAS),
            // La secretaria ve «Horarios» en el menú solo si el admin le activó el permiso de horarios de salas.
            'veHorariosSalas' => ($_SESSION['role'] ?? '') !== Rol::SECRETARIA || UserPermissions::tiene($pdo, $id, UserPermissions::HORARIOS),
        ];
    }
}
