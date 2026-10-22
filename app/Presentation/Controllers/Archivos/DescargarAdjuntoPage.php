<?php
/**
 * Controlador de public/descargar_adjunto.php: único punto de descarga de adjuntos (storage/adjuntos está
 * bloqueada por .htaccess). Autorización y ruta: AdjuntoService. Ámbito global.
 */

requireAuth();

$res = AdjuntoService::resolver($pdo, validarId($_GET['id'] ?? null), (string)($_SESSION['role'] ?? ''), (int)$_SESSION['user_id']);
if (!$res['ok']) {
    http_response_code($res['status']);
    exit($res['mensaje']);
}

header('Content-Type: ' . $res['adjunto']['mime_type']);
header('Content-Length: ' . filesize($res['ruta']));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $res['nombre'] . '"');
readfile($res['ruta']);
