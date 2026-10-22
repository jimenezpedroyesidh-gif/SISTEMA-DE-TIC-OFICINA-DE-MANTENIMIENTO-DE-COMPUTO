<?php
/**
 * Controlador de «Historial y análisis de mantenimiento» (public/historial_mantenimiento.php). Admin y técnico.
 * Dos vistas desde el menú lateral: análisis (?vista=, por defecto) y acciones correctivas (?vista=acciones).
 * Sin SQL: MantenimientoHistorialService. Exporta el detalle consolidado a CSV. Ámbito global.
 */
$raiz = dirname(__DIR__, 4);
requirePagina('historial_mantenimiento.php');

$rol = $_SESSION['role'];
$userId = (int)$_SESSION['user_id'];
$puedeProgramar = MantenimientoService::permisos($rol)['puedeProgramar']; // convertir una acción en mantenimiento
$vista = ($_GET['vista'] ?? '') === 'acciones' ? 'acciones' : 'analisis';
$error = '';
$exito = '';

if (isset($_POST['crear_accion'])) {
    $res = MantenimientoHistorialService::crearAccion($pdo, $userId, $_POST);
    [$error, $exito] = [$res['error'], $res['exito']];
}
if (isset($_POST['estado_accion'])) {
    $res = MantenimientoHistorialService::cambiarEstadoAccion($pdo, $userId, (int)($_POST['id_accion'] ?? 0), (string)($_POST['estado_accion'] ?? ''));
    [$error, $exito] = [$res['error'], $res['exito']];
}

$filtros = MantenimientoHistorialService::filtros($pdo, $_GET);
$periodos = MantenimientoHistorialService::periodosRecientes();
$salas = MantenimientoHistorialRepository::salas($pdo);
$bloques = MantenimientoHistorialRepository::bloques($pdo);
$personal = MantenimientoHistorialRepository::personal($pdo);
$tecnicos = array_values(array_filter($personal, fn($p) => $p['role'] === Rol::TECNICO));

if ($vista === 'acciones') {
    $destino = (string)($_GET['destino'] ?? '');
    $destino = HorarioPeriodo::valido($destino) ? $destino : HorarioPeriodo::siguiente(HorarioPeriodo::actual());
    $acciones = MantenimientoHistorialRepository::acciones($pdo, $destino);
    $periodosDestino = [HorarioPeriodo::siguiente(HorarioPeriodo::actual()), HorarioPeriodo::actual(), HorarioPeriodo::anterior(HorarioPeriodo::actual())];
} else {
    $datos = MantenimientoHistorialService::calcular($pdo, $filtros);
    if (($_GET['exportar'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="historial_mantenimiento_' . $filtros['desde_fecha'] . '_a_' . $filtros['hasta_fecha'] . '.csv"');
        echo MantenimientoHistorialService::csv($datos);
        exit();
    }
}
// Mismos filtros en los enlaces (exportar, ver detalle de una sala).
$qsFiltros = array_filter([
    'periodo' => $filtros['periodo'], 'desde' => $filtros['periodo'] === 'rango' ? $filtros['desde_fecha'] : null,
    'hasta' => $filtros['periodo'] === 'rango' ? $filtros['hasta_fecha'] : null, 'sala' => $filtros['id_sala'] ?: null,
    'bloque' => $filtros['bloque'] ?: null, 'equipo' => $filtros['equipo'] ?: null, 'responsable' => $filtros['id_responsable'] ?: null,
    'categoria' => $filtros['categoria'] ?: null,
], fn($v) => $v !== null);

$shell = ShellData::cargar($pdo);
require $raiz . '/app/Presentation/Views/historial_mantenimiento/' . ($vista === 'acciones' ? 'acciones.php' : 'analisis.php');
