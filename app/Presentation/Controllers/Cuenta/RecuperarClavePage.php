<?php
/**
 * El usuario escribe su correo y, si existe y está activo, recibe un enlace de un solo uso (60 min); esa
 * acción también desbloquea la cuenta. La respuesta es siempre la misma (no revela si el correo existe).
 * Ámbito global.
 */
$raiz = dirname(__DIR__, 4);

$enviado = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $res = RecuperacionClaveService::solicitar($pdo, validarTexto($_POST['correo'] ?? '', 100) ?? '', $_SERVER['REMOTE_ADDR'] ?? null);
    $error = $res['error'];
    $enviado = $res['enviado'];
}

require $raiz . '/app/Presentation/Views/cuenta/recuperar_clave.php';
