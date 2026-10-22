<?php
/**
 * Controlador de «Indicadores» (public/indicadores.php), solo admin: tablero de la mesa de ayuda con filtros
 * (fechas, dependencia, sala) y exportación a Excel (CSV) y PDF. Sin SQL: IndicadoresService / SlaRepository.
 * Ámbito global.
 */
requirePagina('indicadores.php');

$filtros = IndicadoresService::filtros($_GET);
$datos = IndicadoresService::calcular($pdo, $filtros);
$salas = SlaRepository::salas($pdo);
$dependencias = SlaRepository::dependencias($pdo);

$nombreSala = '';
foreach ($salas as $s) if ((int)$s['id_sala'] === $filtros['id_sala']) $nombreSala = (string)$s['nombre_sala'];
$textoFiltro = trim(($filtros['dependencia'] !== '' ? 'Dependencia: ' . $filtros['dependencia'] . '   ' : '') . ($nombreSala !== '' ? 'Sala: ' . $nombreSala : ''));
$sufijo = $filtros['desde'] . '_a_' . $filtros['hasta'];

$exportar = (string)($_GET['exportar'] ?? '');
if ($exportar === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="indicadores_mesa_ayuda_' . $sufijo . '.csv"');
    echo IndicadoresService::csv($datos);
    exit();
}
if ($exportar === 'pdf') {
    IndicadoresPdf::generar($datos, $textoFiltro)->Output('I', 'indicadores_mesa_ayuda_' . $sufijo . '.pdf');
    exit();
}

// Enlaces de exportación con los mismos filtros.
$qsFiltros = http_build_query(['desde' => $filtros['desde'], 'hasta' => $filtros['hasta'], 'sala' => $filtros['id_sala'] ?: null, 'dependencia' => $filtros['dependencia'] !== '' ? $filtros['dependencia'] : null]);

$shell = ShellData::cargar($pdo);
require dirname(__DIR__, 2) . '/Views/calidad/indicadores.php';
