<?php
/**
 * Tabla comparativa (Markdown) de varias mediciones de medir_paginas.php.
 *   php tools/carga/comparar.php resultados/antes.json resultados/despues.json [--umbral=300]
 * Muestra, por página (peor rol), la mediana en ms de cada medición; solo las que superan el umbral en alguna.
 */
if (PHP_SAPI !== 'cli') exit;
$umbral = 300; $archivos = [];
foreach (array_slice($argv, 1) as $a) { if (str_starts_with($a, '--umbral=')) $umbral = (int)substr($a, 9); else $archivos[] = $a; }
$datos = []; $ruta = fn(string $u) => preg_replace('~(id|ir|elevar_ticket)=\d+~', '$1=N', $u);
foreach ($archivos as $k => $f) {
    foreach (json_decode(file_get_contents($f), true) as $r) {
        $key = $ruta($r['url']);
        $v = $r['status'] >= 500 || $r['error_php'] ? 'ERROR ' . $r['status'] : $r['ms'];
        $prev = $datos[$key][$k] ?? null;
        if ($prev === null || (is_string($v)) || (!is_string($prev) && $v > $prev)) { $datos[$key][$k] = $v; $datos[$key]['kb'][$k] = $r['kb']; $datos[$key]['rol'][$k] = $r['rol']; }
    }
}
$cab = array_map(fn($f) => basename($f, '.json'), $archivos);
echo '| Página | ' . implode(' | ', $cab) . " | KB (último) |\n|---|" . str_repeat('---:|', count($cab)) . "---:|\n";
uasort($datos, function ($a, $b) use ($archivos) { $n = count($archivos) - 2; $x = $a[max(0,$n)] ?? 0; $y = $b[max(0,$n)] ?? 0; return (is_string($y) ? 1e9 : $y) <=> (is_string($x) ? 1e9 : $x); });
foreach ($datos as $url => $d) {
    $max = 0; foreach ($archivos as $k => $_) { $v = $d[$k] ?? 0; $max = max($max, is_string($v) ? 1e9 : $v); }
    if ($max < $umbral) continue;
    $celdas = [];
    foreach ($archivos as $k => $_) { $v = $d[$k] ?? '—'; $celdas[] = is_string($v) ? "**$v**" : number_format($v, 0, ',', '.'); }
    $kb = end($d['kb']);
    echo "| `$url` | " . implode(' | ', $celdas) . ' | ' . number_format((float)$kb, 0, ',', '.') . " |\n";
}
