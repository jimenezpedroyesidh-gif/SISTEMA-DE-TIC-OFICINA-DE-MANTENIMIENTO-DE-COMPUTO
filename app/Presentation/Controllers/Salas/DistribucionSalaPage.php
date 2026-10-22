<?php
/**
 * Controlador de «Distribución del salón» (public/distribucion_sala.php?id=N).
 * Técnico y admin maquetan el salón tal como es (filas, mesas de 2/3/4… puestos, pasillos y
 * puesto docente adelante o atrás), ubican cada equipo en su puesto y le dan número y punto de red.
 * Sin SQL ni reglas: DistribucionSalaService. Vista: gestionar_salas/distribucion.php. Ámbito global.
 */
$raiz = dirname(__DIR__, 4);
requirePagina('distribucion_sala.php');

$idSala = validarId($_GET['id'] ?? ($_POST['id_sala'] ?? '')) ?? 0;
$error = '';
$exito = '';
$estructuraPost = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $idSala) {
    if (isset($_POST['guardar_distribucion'])) {
        $res = DistribucionSalaService::guardar($pdo, $idSala, (string)($_POST['estructura'] ?? ''), (int)$_SESSION['user_id']);
    } elseif (isset($_POST['restablecer_distribucion'])) {
        $res = DistribucionSalaService::restablecer($pdo, $idSala, (int)$_SESSION['user_id']);
    } else {
        $res = ['error' => '', 'exito' => ''];
    }
    if ($res['error'] === '' && $res['exito'] !== '') {
        // PRG: al recargar no se reenvía el formulario.
        $_SESSION['flash_distribucion'] = $res['exito'];
        header('Location: distribucion_sala.php?id=' . $idSala);
        exit();
    }
    $error = $res['error'];
    // Con error se devuelve al editor lo que se estaba armando (no se pierde el trabajo).
    $dec = json_decode((string)($_POST['estructura'] ?? ''), true);
    $estructuraPost = is_array($dec) ? $dec : null;
}
$exito = (string)($_SESSION['flash_distribucion'] ?? '');
unset($_SESSION['flash_distribucion']);

$datos = $idSala ? DistribucionSalaService::pantalla($pdo, $idSala) : null;
if ($datos === null) {
    http_response_code(404);
    $salaNoExiste = true;
} else {
    $salaNoExiste = false;
    extract($datos);
    if ($estructuraPost !== null) {
        $estructura = $estructuraPost;
    }
}
$shell = ShellData::cargar($pdo); // datos del header/notificaciones precargados; la vista no usa PDO
require $raiz . '/app/Presentation/Views/gestionar_salas/distribucion.php';
