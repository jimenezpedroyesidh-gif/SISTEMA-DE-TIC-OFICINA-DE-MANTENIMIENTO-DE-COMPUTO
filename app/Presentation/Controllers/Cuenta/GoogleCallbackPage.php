<?php
/** Ámbito global. */
$raiz = dirname(__DIR__, 4);

$cfg = GoogleOAuth::config();
if ($cfg === null) {
    http_response_code(404);
    exit('El inicio de sesión con Google no está habilitado.');
}

$fallo = static function (string $mensaje): void {
    $_SESSION['_google_error'] = $mensaje;
    header('Location: login.php');
    exit();
};

$stateEsperado = (string)($_SESSION['_google_state'] ?? '');
$verificador = (string)($_SESSION['_google_verificador'] ?? '');
unset($_SESSION['_google_state'], $_SESSION['_google_verificador']); // un solo uso

$state = $_GET['state'] ?? '';
$codigo = $_GET['code'] ?? '';
if (isset($_GET['error'])) {
    $fallo('Inicio de sesión con Google cancelado.');
}
if ($stateEsperado === '' || !is_string($state) || !hash_equals($stateEsperado, $state) || !is_string($codigo) || $codigo === '' || $verificador === '') {
    $fallo('La solicitud de Google no es válida. Inténtalo de nuevo.');
}

$perfil = GoogleOAuth::perfilDesdeCodigo($cfg, $codigo, $verificador);
if ($perfil === null) {
    $fallo('No se pudo verificar tu cuenta de Google.');
}

$res = GoogleLogin::resolver($pdo, $perfil);
if (!$res['ok']) {
    $fallo((string)$res['error']);
}

$destino = iniciarSesionDeUsuario($pdo, $res['usuario'], 'google');
if ($destino === null) {
    sesionDestruir();
    header('Location: login.php');
    exit();
}
header("Location: $destino");
exit();
