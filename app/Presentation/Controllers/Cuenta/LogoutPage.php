<?php
/** Controlador de cerrar sesión (public/logout.php): marca al usuario como desconectado, destruye la sesión y vuelve al inicio. */
if (isset($_SESSION['user_id'])) {
    UserRepository::marcarOffline($pdo, (int)$_SESSION['user_id']);
    BitacoraAccesos::registrar($pdo, 'LOGOUT', (int)$_SESSION['user_id'], (string)($_SESSION['correo'] ?? ''));
}

sesionDestruir();

header("Location: index.php");
exit();
