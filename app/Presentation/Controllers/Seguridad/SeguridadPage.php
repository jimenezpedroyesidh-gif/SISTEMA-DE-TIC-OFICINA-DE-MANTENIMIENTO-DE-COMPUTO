<?php
/**
 * Controlador de «Seguridad de la información» (public/seguridad.php), solo admin (hasta que exista el rol
 * super admin). Vistas: panel (diagnóstico + configuración), bitacora (accesos), respaldos e incidentes.
 * Sin SQL: SeguridadService, BitacoraAccesos, RespaldoService, IncidenteService. Ámbito global.
 */
requirePagina('seguridad.php');

$vistas = ['panel', 'bitacora', 'respaldos', 'incidentes'];
$vista = in_array($_GET['vista'] ?? '', $vistas, true) ? (string)$_GET['vista'] : 'panel';
$idAdmin = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = ['ok' => false, 'mensaje' => 'Acción no reconocida.'];
    $id = (int)($_POST['id'] ?? 0);
    if (isset($_POST['guardar_config'])) {
        $res = SeguridadService::guardarConfiguracion($pdo, $_POST, $idAdmin, SeguridadService::esHttps());
    } elseif (isset($_POST['depurar_bitacora'])) {
        $res = BitacoraAccesos::depurar($pdo, $idAdmin);
    } elseif (isset($_POST['verificar_bitacora'])) {
        $v = BitacoraAccesos::verificar($pdo);
        $res = ['ok' => $v['ok'], 'mensaje' => $v['mensaje']];
    } elseif (isset($_POST['generar_respaldo'])) {
        $frase = (string)($_POST['frase'] ?? '');
        $res = $frase !== (string)($_POST['frase_confirmar'] ?? '')
            ? ['ok' => false, 'mensaje' => 'Las dos frases no coinciden.']
            : RespaldoService::generar($pdo, $frase, $idAdmin);
    } elseif (isset($_POST['verificar_respaldo'])) {
        $res = RespaldoService::verificar($pdo, $id, (string)($_POST['frase'] ?? ''), $idAdmin);
    } elseif (isset($_POST['eliminar_respaldo'])) {
        $res = RespaldoService::eliminar($pdo, $id, $idAdmin);
    } elseif (isset($_POST['registrar_incidente'])) {
        $res = IncidenteService::registrar($pdo, $_POST, $idAdmin);
    } elseif (isset($_POST['actualizar_incidente'])) {
        $res = IncidenteService::actualizar($pdo, $id, $_POST, $idAdmin);
    }
    $_SESSION['flash_seguridad'] = $res + ['form' => (string)($_POST['form'] ?? '')];
    header('Location: seguridad.php' . ($_GET ? '?' . http_build_query($_GET) : ''));
    exit();
}
$flash = $_SESSION['flash_seguridad'] ?? null;
unset($_SESSION['flash_seguridad']);

if ($vista === 'panel') {
    $config = ConfigSistema::todos($pdo);
    $diagnostico = SeguridadService::diagnostico($pdo);
    $resumen = BitacoraAccesos::resumen24h($pdo);
    $esHttps = SeguridadService::esHttps();
    $incidentesAbiertos = IncidenteService::abiertos($pdo);
} elseif ($vista === 'bitacora') {
    $filtros = BitacoraAccesos::filtros($_GET);
    if (($_GET['exportar'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="bitacora_accesos_' . date('Ymd_His') . '.csv"');
        echo BitacoraAccesos::csv($pdo, $filtros);
        exit();
    }
    $bitacora = BitacoraAccesos::listar($pdo, $filtros);
    $retencionDias = max(90, ConfigSistema::entero($pdo, 'seg_retencion_bitacora_dias'));
} elseif ($vista === 'respaldos') {
    if (isset($_GET['descargar'])) {
        $errorDescarga = RespaldoService::descargar($pdo, (int)$_GET['descargar'], $idAdmin); // si lo encuentra, envía el archivo y termina
        $_SESSION['flash_seguridad'] = ['ok' => false, 'mensaje' => (string)$errorDescarga, 'form' => ''];
        header('Location: seguridad.php?vista=respaldos');
        exit();
    }
    $respaldos = RespaldoService::listar($pdo);
    $faltaExtension = RespaldoService::disponible();
    $conservar = ConfigSistema::entero($pdo, 'seg_respaldos_conservar');
} else {
    IncidenteService::desdeTickets($pdo);
    $filtroEstado = isset(IncidenteService::ESTADOS[$_GET['estado'] ?? '']) ? (string)$_GET['estado'] : '';
    $incidentes = IncidenteService::listar($pdo, $filtroEstado);
    $responsables = IncidenteService::responsables($pdo);
}

$shell = ShellData::cargar($pdo);
require dirname(__DIR__, 2) . '/Views/seguridad/pagina.php';
