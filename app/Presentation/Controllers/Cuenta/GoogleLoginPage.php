<?php
/** Controlador del paso 1 del inicio de sesión con Google (public/google_login.php): redirige a Google. Inactivo sin config/google.php. */
$cfg = GoogleOAuth::config();
if ($cfg === null) {
    http_response_code(404);
    exit('El inicio de sesión con Google no está habilitado.');
}

$sol = GoogleOAuth::nuevaSolicitud();
$_SESSION['_google_state'] = $sol['state'];
$_SESSION['_google_verificador'] = $sol['verificador'];
header('Location: ' . GoogleOAuth::urlAutorizacion($cfg, $sol['state'], $sol['verificador']));
exit();
