<?php
/**
 * Avisos de sesión (expirada, cuenta deshabilitada, flash de restablecimiento, error de Google), llamada a
 * LoginService y apertura de la sesión (iniciarSesionDeUsuario, app/Support/auth.php). Sin SQL ni reglas de
 * negocio. Ámbito global.
 */
$raiz = dirname(__DIR__, 4);

$error = "";
$aviso = "";
if (!empty($_SESSION['_sesion_expirada'])) {
    $error = "Tu sesión expiró por inactividad. Inicia sesión de nuevo.";
    unset($_SESSION['_sesion_expirada']);
}
if (!empty($_SESSION['_cuenta_deshabilitada'])) {
    $error = "Esta cuenta está deshabilitada.";
    unset($_SESSION['_cuenta_deshabilitada']);
}
if (!empty($_SESSION['_flash_login'])) {
    $aviso = (string)$_SESSION['_flash_login'];
    unset($_SESSION['_flash_login']);
}
if (!empty($_SESSION['_google_error'])) {
    $error = (string)$_SESSION['_google_error'];
    unset($_SESSION['_google_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = LoginService::intentar(
        $pdo, trim($_POST['codigo'] ?? ''), trim($_POST['correo'] ?? ''), (string)($_POST['password'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? null
    );
    if ($res['ok']) {
        // Sesión nueva + datos de sesión (app/Support/auth.php)
        $destino = iniciarSesionDeUsuario($pdo, $res['usuario'], 'clave');
        if ($destino !== null) {
            header("Location: $destino");
        } else {
            // Rol desconocido: no se le concede acceso por defecto.
            sesionDestruir();
            header("Location: login.php");
        }
        exit();
    }
    $error = $res['error'];
}
$googleHabilitado = GoogleOAuth::habilitado();

require $raiz . '/app/Presentation/Views/cuenta/login.php';
