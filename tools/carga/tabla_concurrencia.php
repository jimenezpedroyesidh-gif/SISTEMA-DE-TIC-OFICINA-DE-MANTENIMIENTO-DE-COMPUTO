<?php
/**
 * Tabla Markdown de una o varias corridas de prueba_concurrencia.php.
 *   php tools/carga/tabla_concurrencia.php resultados/concurrencia.json
 */
if (PHP_SAPI !== 'cli') exit;
$roles = ['profesor' => 'Profesor', 'beca_trabajo' => 'Beca', 'tecnico' => 'Técnico', 'secretaria' => 'Secretaria', 'admin' => 'Admin', 'anonimo' => 'QR anónimo'];
foreach (array_slice($argv, 1) as $f) {
    echo "\n**" . basename($f, '.json') . "**\n\n";
    echo '| Usuarios simultáneos | Pet/s | % < 1 s | Errores | ' . implode(' | ', array_map(fn($r) => "$r p95 (error)", $roles)) . " |\n";
    echo '|---:|---:|---:|---:|' . str_repeat('---:|', count($roles)) . "\n";
    foreach (json_decode(file_get_contents($f), true) as $c) {
        $celdas = [];
        foreach ($roles as $k => $_) {
            $x = $c['por_rol'][$k] ?? null;
            $celdas[] = $x ? self_ms($x['p95']) . ($x['pct_error'] > 0 ? " ({$x['pct_error']} %)" : '') : '—';
        }
        $pctErr = $c['peticiones'] ? round(100 * $c['errores'] / $c['peticiones'], 1) : 0;
        echo "| {$c['usuarios']} | {$c['rps']} | {$c['pct_bajo_1s']} % | {$c['errores']} ({$pctErr} %) | " . implode(' | ', $celdas) . " |\n";
    }
}
function self_ms($ms): string { return $ms >= 1000 ? number_format($ms / 1000, 1, ',', '.') . ' s' : $ms . ' ms'; }
