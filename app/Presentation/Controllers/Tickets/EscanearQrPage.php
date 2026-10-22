<?php
/**
 * Controlador de «Escanear QR» (public/escanear_qr.php). Cualquier rol con sesión puede usar el escáner;
 * reportar.php sigue siendo anónimo por diseño, aquí solo se exige sesión para llegar desde el menú. Ámbito global.
 */
requireAuth();

$shell = ShellData::cargar($pdo); // datos del header/notificaciones
require dirname(__DIR__, 2) . '/Views/tickets/escanear_qr.php';
