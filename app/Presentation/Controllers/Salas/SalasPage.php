<?php
/**
 * Controlador de «Salas y códigos QR» (public/gestionar_salas.php).
 *
 * Qué hace: autorización, CSRF, leer parámetros del POST, llamar a SalaService y
 * cargar la vista app/Presentation/Views/gestionar_salas/pagina.php. Sin SQL ni reglas.
 * Se carga en ámbito global desde la página (como los paneles de tickets).
 */
$raiz = dirname(__DIR__, 4);

// Solo técnico y admin pueden gestionar salas/QR
requirePagina('gestionar_salas.php');

$esAdmin = ($_SESSION['role'] ?? '') === Rol::ADMIN;
$formularioConError = '';       // id de la ventana flotante que debe reabrirse (el formulario volvió con error)
$formularioConErrorUnidad = false;
$error = '';
$exito = '';

$idPost = static function (string $campo): ?int {
    return !empty($_POST[$campo]) ? (int)$_POST[$campo] : null;
};
$aplicar = static function (array $res) use (&$error, &$exito): void {
    if ($res['error'] !== '') { $error = $res['error']; }
    if ($res['exito'] !== '') { $exito = $res['exito']; }
};

if (isset($_POST['crear_sala'])) {
    $aplicar(SalaService::crear($pdo, (string)($_POST['nombre_sala'] ?? ''), (string)($_POST['abreviatura'] ?? ''), $idPost('id_unidad'), $idPost('glpi_location_id')));
    $formularioConError = $error !== '' ? 'vf-nueva-sala' : '';
}
if (isset($_POST['editar_abreviatura'])) {
    $aplicar(SalaService::editar($pdo, (int)($_POST['id_sala'] ?? 0), (string)($_POST['abreviatura'] ?? ''), $idPost('id_unidad'),
        $idPost('glpi_location_id'), array_key_exists('glpi_location_id', $_POST), isset($_POST['bloque']) ? (string)$_POST['bloque'] : null));
}
if (isset($_POST['marcar_oficina_mantenimiento'])) {
    $aplicar(SalaService::marcarOficina($pdo, (int)($_POST['id_sala'] ?? 0)));
}
if (isset($_POST['quitar_oficina_mantenimiento'])) {
    $aplicar(SalaService::quitarOficina($pdo));
}
if (isset($_POST['asignar_staff_oficina'])) {
    $aplicar(SalaService::asignarStaff($pdo, (int)($_POST['id_sala'] ?? 0), (int)($_POST['staff_user_id'] ?? 0)));
}
if (isset($_POST['quitar_staff_oficina'])) {
    $aplicar(SalaService::quitarStaff($pdo, (int)($_POST['id_sala'] ?? 0), (int)($_POST['staff_user_id'] ?? 0)));
}
if (isset($_POST['vincular_glpi_sugeridas'])) {
    $aplicar(SalaService::vincularSugeridasGlpi($pdo, (int)$_SESSION['user_id']));
}
if (isset($_POST['crear_unidad'])) {
    $formularioConErrorUnidad = true;
    $aplicar(SalaService::crearUnidad(
        $pdo, $esAdmin,
        (string)($_POST['nombre_unidad'] ?? ''), (string)($_POST['tipo_unidad'] ?? ''),
        $idPost('id_unidad_padre'), isset($_POST['es_administradora_sistema'])
    ));
}

extract(SalaService::datosPantalla($pdo));

// URL base para construir el enlace de reportar.php (protocolo + host actuales)
$protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = "{$protocolo}://{$host}" . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

$shell = ShellData::cargar($pdo);
require $raiz . '/app/Presentation/Views/gestionar_salas/pagina.php';
