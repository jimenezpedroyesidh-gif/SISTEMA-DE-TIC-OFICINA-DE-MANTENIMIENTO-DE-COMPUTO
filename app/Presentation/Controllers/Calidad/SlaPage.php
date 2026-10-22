<?php
/**
 * Controlador de «Cumplimiento de SLA» (public/sla.php), solo admin. Dos vistas:
 *  - monitor (por defecto): tickets abiertos con su semáforo; ajustar prioridad y pausar/reanudar el reloj.
 *  - acuerdos: tiempos de respuesta/solución por tipo y prioridad, horario de atención, umbrales y días no hábiles.
 * Sin SQL: SlaService / SlaRepository. Ámbito global.
 */
requirePagina('sla.php');

$vista = ($_GET['vista'] ?? '') === 'acuerdos' ? 'acuerdos' : 'monitor';
$idAdmin = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tabla = (string)($_POST['tabla'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $res = ['ok' => false, 'mensaje' => 'Acción no reconocida.'];
    if (isset($_POST['ajustar_prioridad'])) {
        $res = SlaService::ajustarPrioridad($pdo, $tabla, $id, (int)($_POST['impacto'] ?? 0), (int)($_POST['urgencia'] ?? 0), $idAdmin);
    } elseif (isset($_POST['alternar_pausa'])) {
        $res = SlaService::alternarPausa($pdo, $tabla, $id, (string)($_POST['motivo'] ?? ''), $idAdmin);
    } elseif (isset($_POST['guardar_politicas'])) {
        $res = SlaService::guardarPoliticas($pdo, (array)($_POST['pol'] ?? []), $idAdmin);
    } elseif (isset($_POST['guardar_horario'])) {
        $res = SlaService::guardarHorario($pdo, $_POST, $idAdmin);
    } elseif (isset($_POST['agregar_festivo'])) {
        $res = SlaService::agregarFestivo($pdo, (string)($_POST['fecha'] ?? ''), (string)($_POST['descripcion'] ?? ''), $idAdmin);
    } elseif (isset($_POST['cargar_festivos'])) {
        $res = SlaService::cargarFestivosColombia($pdo, (int)($_POST['anio'] ?? 0), $idAdmin);
    } elseif (isset($_POST['quitar_festivo'])) {
        $res = SlaService::quitarFestivo($pdo, (string)($_POST['fecha'] ?? ''), $idAdmin);
    }
    $_SESSION['flash_sla'] = $res + ['form' => (string)($_POST['form'] ?? '')];
    $qs = $_GET;
    header('Location: sla.php' . ($qs ? '?' . http_build_query($qs) : ''));
    exit();
}
$flash = $_SESSION['flash_sla'] ?? null;
unset($_SESSION['flash_sla']);

$politicas = SlaRepository::politicas($pdo);
$umbral = ConfigSistema::entero($pdo, 'sla_umbral_aviso');

if ($vista === 'monitor') {
    $filtroSem = isset(SlaService::SEMAFORO[$_GET['semaforo'] ?? '']) ? (string)$_GET['semaforo'] : '';
    $filtroPrio = isset(SlaService::PRIORIDADES[$_GET['prioridad'] ?? '']) ? (string)$_GET['prioridad'] : '';
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
    $abiertos = SlaService::medir($pdo, ['abiertos' => true]);
    $conteo = array_fill_keys(['a_tiempo', 'por_vencer', 'vencido', 'pausa'], 0);
    foreach ($abiertos as $t) if (isset($conteo[$t['semaforo']])) $conteo[$t['semaforo']]++;
    $lista = array_values(array_filter($abiertos, static function ($t) use ($filtroSem, $filtroPrio, $q) {
        if ($filtroSem !== '' && $t['semaforo'] !== $filtroSem) return false;
        if ($filtroPrio !== '' && $t['prioridad_sla'] !== $filtroPrio) return false;
        if ($q !== '') {
            $texto = mb_strtolower('#' . $t['id'] . ' ' . $t['resumen'] . ' ' . $t['nombre_sala'] . ' ' . $t['dependencia'] . ' ' . $t['responsable']);
            if (mb_strpos($texto, mb_strtolower($q)) === false) return false;
        }
        return true;
    }));
    // Más urgentes primero: vencidos, luego por vencer, por avance del tiempo.
    usort($lista, static fn($a, $b) => $b['consumo'] <=> $a['consumo']);
    $pag = paginarArreglo($lista, (int)($_GET['pagina'] ?? 1), 20);
    $lista = $pag['datos'];
} else {
    $semana = CalendarioHabil::semanaDeConfig(ConfigSistema::texto($pdo, 'horario_semana'));
    $meta = ConfigSistema::entero($pdo, 'sla_meta_cumplimiento');
    $anioFestivos = (int)($_GET['anio'] ?? date('Y'));
    if ($anioFestivos < 2000 || $anioFestivos > 2100) $anioFestivos = (int)date('Y');
    $festivos = SlaRepository::festivos($pdo, $anioFestivos);
}

$shell = ShellData::cargar($pdo);
require dirname(__DIR__, 2) . '/Views/calidad/sla.php';
