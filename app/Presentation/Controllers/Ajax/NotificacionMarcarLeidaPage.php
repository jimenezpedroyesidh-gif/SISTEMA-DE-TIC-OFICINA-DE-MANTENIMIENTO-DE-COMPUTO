<?php
/** Controlador de public/notificacion_marcar_leida.php: POST + CSRF; marca una o todas las notificaciones del usuario. Ámbito global. */

header('Content-Type: application/json; charset=utf-8');
requireAuthJson();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit();
}

$idUsuario = (int)$_SESSION['user_id'];
if (isset($_POST['marcar_todas'])) {
    NotificationService::marcarTodasLeidas($pdo, $idUsuario);
    echo json_encode(['ok' => true]);
    exit();
}

$idNotificacion = (int)($_POST['id_notificacion'] ?? 0);
if ($idNotificacion <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'id_notificacion requerido']);
    exit();
}
// marcarLeida() valida que la notificación sea del usuario logueado (anti-IDOR).
echo json_encode(['ok' => NotificationService::marcarLeida($pdo, $idNotificacion, $idUsuario)]);
