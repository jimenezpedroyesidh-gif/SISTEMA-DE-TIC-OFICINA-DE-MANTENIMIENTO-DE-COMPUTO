<?php
/**
 * Controlador de «Campañas de mantenimiento» (public/mantenimiento_campanas.php).
 * Una actividad general en varias salas (MantenimientoCampanaService). Técnico crea y cancela; el admin supervisa.
 * Cada intervención se ejecuta y se cierra en «Mantenimientos» (mantenimientos_programados.php?campana=N).
 * Sin SQL ni reglas de negocio. Ámbito global, como las demás páginas.
 */
$raiz = dirname(__DIR__, 4);
requirePagina('mantenimiento_campanas.php');

$rol = $_SESSION['role'];
$userId = (int)$_SESSION['user_id'];
$puedeProgramar = MantenimientoService::permisos($rol)['puedeProgramar'];
$idSeleccionada = (int)($_GET['id'] ?? 0);
$error = '';
$exito = '';

if ($puedeProgramar && isset($_POST['crear_campana'])) {
    $res = MantenimientoCampanaService::crear($pdo, $userId, $_POST);
    $error = $res['error'];
    $exito = $res['exito'];
    if ($res['id']) {
        $idSeleccionada = $res['id'];
    }
}
if ($puedeProgramar && isset($_POST['cancelar_campana'])) {
    $res = MantenimientoCampanaService::cancelar($pdo, $userId, (int)($_POST['id_campana'] ?? 0), trim((string)($_POST['motivo'] ?? '')));
    $error = $res['error'];
    $exito = $res['exito'];
}

extract(MantenimientoCampanaService::datosPantalla($pdo, $idSeleccionada));
// Prefill desde una acción correctiva sin sala (p. ej. estandarizar software en varias salas).
$prefillAccion = $puedeProgramar && !empty($_GET['desde_accion']) ? MantenimientoHistorialService::prefillDesdeAccion($pdo, (int)$_GET['desde_accion']) : null;
$duraciones = MantenimientoService::DURACIONES;

$shell = ShellData::cargar($pdo);
require $raiz . '/app/Presentation/Views/campanas/pagina.php';
