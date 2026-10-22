<?php
/**
 * Autorización (admin/técnico), CSRF, parámetros, llamadas a BodegaService y carga de la vista. Sin SQL ni
 * reglas de negocio. Se carga en el ámbito global.
 */
$raiz = dirname(__DIR__, 4);
require_once $raiz . '/app/Support/lib_inventario.php';

// Decidir el destino de un componente en bodega es una acción de ejecución, no de consulta:
// solo admin/técnico (la beca puede ver el inventario, pero no decidir).
requirePagina('revisar_componentes_bodega.php');

$error = '';
$exito = '';
$idUsuario = (int)($_SESSION['user_id'] ?? 0);

if (isset($_POST['revisar_componente'])) {
    $res = BodegaService::revisar($pdo, $idUsuario, (int)($_POST['id_componente'] ?? 0), (string)($_POST['nuevo_estado'] ?? ''), (string)($_POST['observacion'] ?? ''));
    $error = $res['error'];
    $exito = $res['exito'];
}
if (isset($_POST['solicitar_baja'])) {
    $res = BodegaService::solicitarBaja($pdo, $idUsuario, (int)($_POST['id_componente'] ?? 0), (string)($_POST['concepto_tecnico'] ?? ''));
    $error = $res['error'];
    $exito = $res['exito'];
}

// Por páginas: con inventarios grandes la lista completa agotaba la memoria.
$pagRevision = BodegaRepository::enRevision($pdo, (int)($_GET['pagina_revision'] ?? 1));
$pagDefectuosos = BodegaRepository::defectuososSinSolicitud($pdo, (int)($_GET['pagina_defectuosos'] ?? 1));
$enRevision = $pagRevision['datos'];
$defectuosos = $pagDefectuosos['datos'];

$shell = ShellData::cargar($pdo);
require $raiz . '/app/Presentation/Views/becas/revisar_bodega.php';
