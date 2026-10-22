<?php
/**
 * Controlador del PDF de horas (public/seguimiento_horas_pdf.php). Mismas reglas que la página:
 * la beca solo el suyo; admin o quien tenga el permiso, cualquier beca (?codigo) o todas (?todas=1).
 * Los errores son mensajes de texto (die). El dibujo está en Infrastructure/Pdf/ReporteHorasPdf.php. Ámbito global.
 */
$raiz = dirname(__DIR__, 4);

requireAuthOrDie(); // app/Support/auth.php
$acceso = SeguimientoHorasService::acceso($pdo, (int)$_SESSION['user_id'], (string)$_SESSION['role']);
if (!$acceso['entra']) die('Acceso denegado');

$periodo = SeguimientoHorasService::periodo($_GET['anio'] ?? null, $_GET['mes'] ?? null, $_GET['desde'] ?? null, $_GET['hasta'] ?? null);
$anio = $periodo['anio'];
$mes = $periodo['mes'];

$sel = SeguimientoHorasService::becasParaPdf($pdo, (int)$_SESSION['user_id'], $acceso, isset($_GET['todas']), trim((string)($_GET['codigo'] ?? '')));
if ($sel['error'] !== null) die($sel['error']);

$pdf = new ReporteHorasPdf('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(false);
if ($periodo['error'] !== null) die($periodo['error']);
$pdf->periodo = $periodo['rango'] ? ucfirst($periodo['etiqueta']) : $periodo['etiqueta'];

foreach ($sel['becas'] as $b) {
    $pdf->nombre = (string)$b['nombre_completo'];
    $pdf->codigo = (string)$b['codigo'];
    $pdf->dependencia = (string)($b['dependencia'] ?? '');
    $pdf->AddPage();

    $seg = SeguimientoHorasService::seguimientoDe($b['codigo'], $periodo['inicio'], $periodo['fin']);
    foreach ($seg['dias'] as $d) $pdf->filaDia($d);
    if (empty($seg['dias'])) $pdf->sinRegistros();
    $pdf->total($seg['total']);
}

$pdf->Output('I', SeguimientoHorasService::nombreArchivoPdf($sel['becas'], $mes, $anio, $periodo));
