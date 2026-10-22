<?php
/**
 * Controlador de «Conexiones» (public/conexiones.php), solo admin: datos de la asistencia facial (PostgreSQL),
 * de la base de GLPI (lectura) y de la API de GLPI (escritura). Guardar y probar; las claves nunca se muestran.
 * Sin SQL: ConexionesService / ConexionesExternas. Ámbito global.
 */
requirePagina('conexiones.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clave = (string)($_POST['clave'] ?? '');
    $res = ($_POST['accion'] ?? '') === 'probar'
        ? ConexionesService::probar($pdo, $clave)
        : ConexionesService::guardar($pdo, $clave, $_POST, (int)$_SESSION['user_id']);
    $_SESSION['flash_conexiones'] = ['ok' => $res['ok'], 'mensaje' => $res['mensaje'], 'clave' => $clave];
    header('Location: conexiones.php#' . rawurlencode($clave));
    exit();
}

$flash = $_SESSION['flash_conexiones'] ?? null;
unset($_SESSION['flash_conexiones']);

$estado = ConexionesService::estado($pdo);
$pgsqlDisponible = in_array('pgsql', PDO::getAvailableDrivers(), true);
$curlDisponible = function_exists('curl_init');
$glpiDb = Database::glpi();
$ubicacionesGlpi = $glpiDb ? GlpiDb::ubicaciones($glpiDb) : [];

$shell = ShellData::cargar($pdo);
require dirname(__DIR__, 2) . '/Views/conexiones/pagina.php';
