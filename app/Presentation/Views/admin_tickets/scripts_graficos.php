<?php
/**
 * Datos de los gráficos Chart.js del panel admin. Ya no genera JavaScript: emite un bloque JSON (no
 * ejecutable, permitido por la CSP) que assets/graficos_admin.js lee para construir los gráficos.
 */
$datosGraficos = [
    'estados' => ['labels' => array_keys($stats), 'data' => array_values($stats)],
    'tecnicos' => [
        'labels' => array_column($stats_tecnicos, 'tecnico'),
        'pendientes' => array_column($stats_tecnicos, 'pendientes'),
        'en_proceso' => array_column($stats_tecnicos, 'en_proceso'),
        'resueltos' => array_column($stats_tecnicos, 'resueltos'),
    ],
    'tiempoTecnico' => empty($tiempoPorTecnico) ? null : [
        'labels' => array_column($tiempoPorTecnico, 'tecnico'),
        'data' => array_map(fn($r) => round((float)$r['promedio_horas'], 1), $tiempoPorTecnico),
    ],
    'rankingSalas' => empty($rankingSalas) ? null : [
        'labels' => array_column($rankingSalas, 'etiqueta_sala'),
        'data' => array_map('intval', array_column($rankingSalas, 'total')),
    ],
    'modelosTexto' => empty($rankingModelosTexto) ? null : [
        'labels' => array_column($rankingModelosTexto, 'modelo'),
        'data' => array_map('intval', array_column($rankingModelosTexto, 'total')),
    ],
    'tendenciaSemanal' => empty($tendenciaSemanal) ? null : [
        'labels' => $tendenciaLabels,
        'data' => $tendenciaValores,
    ],
    'modelosComponentes' => empty($rankingModelosComponentes) ? null : [
        'labels' => array_map(fn($r) => trim(($r['marca'] ?? '') . ' ' . ($r['modelo'] ?? '')) ?: etiquetaValor($r['tipo']), $rankingModelosComponentes),
        'data' => array_map('intval', array_column($rankingModelosComponentes, 'total')),
    ],
];
?>
<script type="application/json" id="graficos-datos"><?= jsJson($datosGraficos) ?></script>
