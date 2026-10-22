<?php
/**
 * Controlador de la pantalla de la oficina de mantenimiento (public/pantalla.php).
 * Pensada para un televisor: rol «visualizacion» (y admin/técnico para verla). Dos columnas que se
 * actualizan solas cada 30 s (assets/pantalla.js pide ?parcial=1 y reemplaza solo las columnas).
 * La actualización periódica mantiene viva la sesión del televisor (cuenta como actividad).
 */
$raiz = dirname(__DIR__, 4);

requirePagina('pantalla.php');

$mantenimientos = PantallaRepository::mantenimientos($pdo);
$errores = PantallaRepository::erroresSala($pdo);
$actualizado = date('H:i:s');

if (($_GET['parcial'] ?? '') === '1') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    require $raiz . '/app/Presentation/Views/pantalla/columnas.php';
    exit();
}
require $raiz . '/app/Presentation/Views/pantalla/pagina.php';
