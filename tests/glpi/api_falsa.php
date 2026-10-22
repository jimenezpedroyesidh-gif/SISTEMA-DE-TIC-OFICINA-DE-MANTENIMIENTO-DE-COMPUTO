<?php
/**
 * API REST de GLPI SIMULADA para las pruebas (router de `php -S`). Imita apirest.php: initSession con App-Token y
 * user_token, getFullSession, POST /Itemtype (crear), PUT /Itemtype/id (actualizar) y killSession. Cada llamada se
 * anota en el archivo GLPI_FALSA_LOG (JSON por línea) para que la prueba compruebe qué se envió.
 */
$log = getenv('GLPI_FALSA_LOG') ?: sys_get_temp_dir() . '/glpi_falsa.log';
$ruta = preg_replace('~^.*?/apirest\.php~', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$metodo = $_SERVER['REQUEST_METHOD'];
$h = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
$cuerpo = json_decode((string)file_get_contents('php://input'), true);
header('Content-Type: application/json');
$responder = function (int $codigo, $datos) use ($log, $metodo, $ruta, $cuerpo) {
    file_put_contents($log, json_encode(['metodo' => $metodo, 'ruta' => $ruta, 'cuerpo' => $cuerpo, 'codigo' => $codigo]) . "\n", FILE_APPEND);
    http_response_code($codigo);
    echo json_encode($datos);
    exit;
};
if (($h['app-token'] ?? '') !== 'APPZT') $responder(400, ['ERROR_WRONG_APP_TOKEN_PARAMETER', 'App-Token inválido']);
if ($ruta === '/initSession') {
    if (($h['authorization'] ?? '') !== 'user_token USRZT') $responder(401, ['ERROR_GLPI_LOGIN_USER_TOKEN', 'Token de usuario inválido']);
    $responder(200, ['session_token' => 'SESZT']);
}
if (($h['session-token'] ?? '') !== 'SESZT') $responder(401, ['ERROR_SESSION_TOKEN_INVALID', 'Sesión inválida']);
if ($ruta === '/getFullSession') $responder(200, ['session' => ['glpiname' => 'glpi-zt']]);
if ($ruta === '/killSession') $responder(200, []);
if ($metodo === 'POST' && preg_match('~^/(Computer|Monitor|Peripheral)$~', $ruta)) {
    if (($cuerpo['input']['serial'] ?? '') === 'ZTG-FALLA') $responder(400, ['ERROR_GLPI_ADD', 'No se pudo crear']);
    $responder(201, ['id' => 500 + random_int(1, 400), 'message' => '']);
}
if ($metodo === 'PUT' && preg_match('~^/(Computer|Monitor|Peripheral)/(\d+)$~', $ruta, $m)) $responder(200, [[$m[2] => true, 'message' => '']]);
$responder(400, ['ERROR_RESOURCE_NOT_FOUND_NOR_COMMONDBTM', 'Ruta no soportada: ' . $ruta]);
