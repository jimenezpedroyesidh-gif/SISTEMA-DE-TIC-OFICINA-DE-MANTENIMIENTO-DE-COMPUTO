<?php
/**
 * Controlador de «Seguimiento de horas» (public/seguimiento_horas.php).
 * Beca trabajo: ve sus horas. Admin: ve las de todas las becas (no tiene horas propias).
 * Otros roles: solo si el admin les activó «Ver horas de todas las becas»; si no, 403.
 * Sin SQL ni reglas: llama a SeguimientoHorasService y carga la vista. Ámbito global.
 */
$raiz = dirname(__DIR__, 4);

requireAuth();
$acceso = SeguimientoHorasService::acceso($pdo, (int)$_SESSION['user_id'], (string)$_SESSION['role']);
if (!$acceso['entra']) {
    http_response_code(403);
    exit('No tienes permiso para ver el seguimiento de horas (solo becas trabajo, admin o quien tenga el permiso activado).');
}

$periodo = SeguimientoHorasService::periodo($_GET['anio'] ?? null, $_GET['mes'] ?? null, $_GET['desde'] ?? null, $_GET['hasta'] ?? null);
$anio = $periodo['anio'];
$mes = $periodo['mes'];
$mesesEs = SeguimientoHorasService::MESES;

extract(SeguimientoHorasService::consultaMensual($pdo, (int)$_SESSION['user_id'], $acceso, $_GET['ver_id'] ?? null, $periodo));

$shell = ShellData::cargar($pdo); // datos del header/notificaciones precargados; la vista no usa PDO
require $raiz . '/app/Presentation/Views/horas/seguimiento.php';
