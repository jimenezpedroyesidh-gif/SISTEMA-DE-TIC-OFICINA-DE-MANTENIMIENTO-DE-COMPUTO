<?php
/**
 * Controlador del «Tablero de actividades» (public/actividades.php): solo la secretaria y las becas que
 * el admin asignó al área de secretaría. Sin SQL ni reglas: ActividadService. Vista: actividades/pagina.php.
 */
$raiz = dirname(__DIR__, 4);
requireAuth();

$userId = (int)$_SESSION['user_id'];
$permisos = ActividadService::permisos((string)($_SESSION['role'] ?? ''), $_SESSION['area_beca'] ?? null);
if (!$permisos['ver']) {
    http_response_code(403);
    $paginaSinAcceso = true;
} else {
    $paginaSinAcceso = false;
}
$error = '';
$exito = '';
$formConError = '';

if (!$paginaSinAcceso && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $idA = (int)($_POST['id_actividad'] ?? 0);
    $res = ['error' => '', 'exito' => ''];
    if ($permisos['gestionar'] && isset($_POST['crear_actividad'])) {
        $res = ActividadService::crear($pdo, $userId, $_POST);
        if ($res['error'] !== '') $formConError = 'vf-nueva-actividad';
    } elseif ($permisos['gestionar'] && isset($_POST['editar_actividad'])) {
        $res = ActividadService::editar($pdo, $userId, $idA, $_POST);
        if ($res['error'] !== '') $formConError = 'vf-act-' . $idA;
    } elseif ($permisos['gestionar'] && isset($_POST['borrar_actividad'])) {
        $res = ActividadService::borrar($pdo, $idA);
    } elseif (isset($_POST['mover_actividad'])) {
        $res = ActividadService::mover($pdo, $userId, $permisos['gestionar'], $idA, (string)($_POST['estado'] ?? ''));
    }
    if ($res['error'] === '' && $res['exito'] !== '') {
        $_SESSION['flash_actividades'] = $res['exito'];
        header('Location: actividades.php' . (!empty($_GET['mias']) ? '?mias=1' : '') . ($idA ? '#act-' . $idA : ''));
        exit();
    }
    $error = $res['error'];
}
$exito = (string)($_SESSION['flash_actividades'] ?? '');
unset($_SESSION['flash_actividades']);

$soloMias = $permisos['esBeca'] && !empty($_GET['mias']);
$actividades = !$paginaSinAcceso ? ActividadRepository::listar($pdo, $soloMias ? $userId : null) : [];
$porEstado = array_fill_keys(array_keys(ActividadService::ESTADOS), []);
foreach ($actividades as $a) { $porEstado[$a['estado']][] = $a; }
$becasSecretaria = $permisos['gestionar'] ? AreaBeca::becas($pdo, AreaBeca::SECRETARIA) : [];

$shell = ShellData::cargar($pdo);
require $raiz . '/app/Presentation/Views/actividades/pagina.php';
