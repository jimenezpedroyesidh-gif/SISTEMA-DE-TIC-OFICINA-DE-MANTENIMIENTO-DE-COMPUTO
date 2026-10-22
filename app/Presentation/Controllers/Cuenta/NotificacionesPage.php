<?php
/**
 * Controlador de «Notificaciones» (public/mis_notificaciones.php), todos los roles:
 *  - ?ir=ID: abre un aviso de la campanita: lo marca leído y lleva a la página correcta PARA ESE ROL
 *    (NotificationService::destino corrige páginas antiguas y nunca manda a una página sin acceso).
 *  - Sin parámetros: lista completa (todas o sin leer), paginada, con «Marcar todas como leídas».
 * Sin SQL: NotificationService. Ámbito global.
 */
requireAuth();
$idUsuario = (int)$_SESSION['user_id'];
$rol = (string)($_SESSION['role'] ?? '');

if (isset($_GET['ir'])) {
    header('Location: ' . NotificationService::abrir($pdo, (int)$_GET['ir'], $idUsuario, $rol));
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['marcar_todas'])) {
    NotificationService::marcarTodasLeidas($pdo, $idUsuario);
    header('Location: mis_notificaciones.php');
    exit();
}

$soloNoLeidas = ($_GET['ver'] ?? '') === 'sin_leer';
$avisos = NotificationService::pagina($pdo, $idUsuario, $soloNoLeidas, (int)($_GET['pagina'] ?? 1));

$shell = ShellData::cargar($pdo);
require dirname(__DIR__, 2) . '/Views/cuenta/notificaciones.php';
