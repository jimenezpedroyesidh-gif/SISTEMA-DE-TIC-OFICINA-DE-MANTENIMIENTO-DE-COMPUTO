<?php
/** Solo admin. CSRF, parámetros, BodegaService y vista; sin SQL ni reglas de negocio. Ámbito global. */
$raiz = dirname(__DIR__, 4);
require_once $raiz . '/app/Support/lib_inventario.php';

// Aprobar/rechazar una baja definitiva es exclusivo del admin; el técnico solo la solicita
// (revisar_componentes_bodega.php).
requirePagina('solicitudes_baja.php');

$error = '';
$exito = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolver_solicitud'])) {
    $res = BodegaService::resolverSolicitud(
        $pdo, (int)($_SESSION['user_id'] ?? 0), (int)($_POST['id_solicitud'] ?? 0),
        ($_POST['decision'] ?? '') === 'aprobar', (string)($_POST['observacion_aprobacion'] ?? '')
    );
    $error = $res['error'];
    $exito = $res['exito'];
}

$pendientes = ComponenteBajas::solicitudesBajaPendientes($pdo);
$resueltas = BodegaRepository::solicitudesResueltas($pdo, 30);

$shell = ShellData::cargar($pdo);
require $raiz . '/app/Presentation/Views/becas/solicitudes_baja.php';
