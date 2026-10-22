<?php
/**
 * El token del enlace solo se acepta si existe, no venció y no se usó (PasswordReset). Al terminar se
 * desbloquea la cuenta y se envía al login con un aviso. Ámbito global.
 */
$raiz = dirname(__DIR__, 4);

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$token = is_string($token) ? $token : '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
}
$valido = PasswordReset::validar($pdo, $token) !== null;

if ($valido && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = PasswordReset::restablecer($pdo, $token, (string)($_POST['nueva_clave'] ?? ''), (string)($_POST['confirmar_clave'] ?? ''));
    if ($res['ok']) {
        $_SESSION['_flash_login'] = 'Contraseña actualizada. Ya puedes iniciar sesión.';
        header('Location: login.php');
        exit();
    }
    $error = (string)$res['error'];
    $valido = PasswordReset::validar($pdo, $token) !== null; // por si el error fue el enlace
}

require $raiz . '/app/Presentation/Views/cuenta/restablecer_clave.php';
