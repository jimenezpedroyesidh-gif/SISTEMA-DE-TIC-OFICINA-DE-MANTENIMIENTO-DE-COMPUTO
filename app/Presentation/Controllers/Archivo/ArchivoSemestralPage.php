<?php
/**
 * Controlador del cierre de semestre (public/archivo_semestral.php): solo admin.
 * El admin ve, por semestre, cuántos tickets están terminados y cuántos siguen abiertos; elige uno, revisa la
 * vista previa y confirma escribiendo «ARCHIVAR AAAA-N». La regla está en ArchivoSemestralService.
 * También permite consultar el archivo y descargarlo en CSV. Sin SQL aquí.
 */
$raiz = dirname(__DIR__, 4);
requirePagina('archivo_semestral.php');

$idAdmin = (int)$_SESSION['user_id'];
$error = '';
$exito = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archivar_periodo'])) {
    $res = ArchivoSemestralService::archivar($pdo, $idAdmin, (string)($_POST['periodo'] ?? ''), (string)($_POST['confirmacion'] ?? ''), !empty($_POST['borrar_adjuntos']));
    $_SESSION['flash_archivo'] = [$res['ok'] ? 'exito' : 'error' => $res['mensaje']];
    header('Location: archivo_semestral.php' . ($res['ok'] ? '' : '?periodo=' . urlencode((string)($_POST['periodo'] ?? ''))));
    exit();
}
if (!empty($_SESSION['flash_archivo'])) {
    $error = (string)($_SESSION['flash_archivo']['error'] ?? '');
    $exito = (string)($_SESSION['flash_archivo']['exito'] ?? '');
    unset($_SESSION['flash_archivo']);
}

$resumen = ArchivoSemestralRepository::resumenPorPeriodo($pdo);
$periodoSel = (string)($_GET['periodo'] ?? '');
if (!HorarioPeriodo::valido($periodoSel)) {
    $aviso = ArchivoSemestralService::avisoPendiente($pdo);
    $periodoSel = $aviso['periodo'] ?? HorarioPeriodo::anterior(HorarioPeriodo::actual());
}
$previa = ArchivoSemestralService::vistaPrevia($pdo, $periodoSel);
$lotes = ArchivoSemestralRepository::lotes($pdo);

// Consulta del archivo
$buscarPeriodo = HorarioPeriodo::valido((string)($_GET['b_periodo'] ?? '')) ? (string)$_GET['b_periodo'] : '';
$buscarTexto = trim((string)(validarTexto($_GET['q'] ?? '', 100) ?? ''));
$resultados = ($buscarPeriodo !== '' || $buscarTexto !== '') ? ArchivoSemestralRepository::buscar($pdo, $buscarPeriodo, $buscarTexto) : [];

if (($_GET['csv'] ?? '') === '1') {
    $filas = ArchivoSemestralRepository::buscar($pdo, $buscarPeriodo, $buscarTexto, 100000);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tickets_archivados' . ($buscarPeriodo ? '_' . $buscarPeriodo : '') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel abre bien las tildes
    $cols = ['periodo', 'tabla_origen', 'id_original', 'tipo', 'origen', 'categoria', 'estado_final', 'sala_nombre', 'solicitante', 'correo_contacto',
             'tecnico', 'beca', 'descripcion', 'solucion', 'equipos_afectados', 'prioridad', 'fecha_creacion', 'fecha_primera_respuesta',
             'fecha_cierre', 'minutos_resolucion', 'reaperturas', 'calificacion', 'total_comentarios', 'total_adjuntos', 'codigo_seguimiento'];
    fputcsv($out, $cols, ';');
    foreach ($filas as $f) {
        // Evita que Excel interprete como fórmula un texto que empieza con = + - @
        fputcsv($out, array_map(fn($c) => is_string($f[$c] ?? null) && preg_match('/^[=+\-@]/', (string)$f[$c]) ? "'" . $f[$c] : ($f[$c] ?? ''), $cols), ';');
    }
    fclose($out);
    exit();
}

$shell = ShellData::cargar($pdo);
require $raiz . '/app/Presentation/Views/archivo/pagina.php';
