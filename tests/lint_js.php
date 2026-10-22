<?php
/**
 * Lint de JavaScript. Sin dependencias salvo `node` (opcional).
 *  1. Sintaxis: node --check de assets/*.js y de cada <script> en línea (con <?= ?> → null).
 *  2. Endpoints AJAX: todo fetch('x.php?...') apunta a un archivo existente y los parámetros
 *     enviados (?a=&b=) son leídos por ese PHP ($_GET/$_POST/$_REQUEST['a'] o validar*()).
 *  3. Seguridad: sin onclick/onsubmit/onchange que inyecten texto de la BD (<?= dentro del handler
 *     entre comillas simples) y sin innerHTML con variables sin UFPS.esc en assets/*.js.
 * Uso: php tests/lint_js.php   (exit 1 si hay problemas)
 */
$raiz = dirname(__DIR__);
$errores = [];
$tmp = sys_get_temp_dir() . '/lintjs_' . getmypid();
@mkdir($tmp);
$node = trim((string)shell_exec('command -v node 2>/dev/null'));

function ficheros(string $dir, array $ext): array {
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (strpos($p, '/vendor/') !== false || strpos($p, '/legacy/') !== false) continue;
        if (in_array(strtolower(pathinfo($p, PATHINFO_EXTENSION)), $ext, true)) $out[] = $p;
    }
    sort($out);
    return $out;
}
$php = array_merge(ficheros("$raiz/public", ['php']), ficheros("$raiz/app", ['php']));
$js  = ficheros("$raiz/public/assets", ['js']);
$n = 0;

// 1) sintaxis
if ($node !== '') {
    foreach ($js as $f) {
        exec('node --check ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
        if ($rc !== 0) $errores[] = 'sintaxis ' . basename($f) . ': ' . implode(' ', array_slice($o, 0, 3));
        $o = []; $n++;
    }
    foreach ($php as $f) {
        if (preg_match('~/app/(Support|Domain|Infrastructure)/~', $f)) continue; // solo clases PHP, sin HTML
        $c = preg_replace('~<\?php.*?\?>~s', '', (string)file_get_contents($f));
        if (!preg_match_all('~<script(?![^>]*\bsrc=)(?![^>]*type="application/json")[^>]*>(.*?)</script>~si', $c, $m)) continue;
        foreach ($m[1] as $i => $b) {
            $b = preg_replace('~<\?=.*?\?>~s', 'null', $b);
            $b = preg_replace('~<\?php.*?\?>~s', '', $b);
            $t = "$tmp/s_" . md5($f . $i) . '.js';
            file_put_contents($t, $b);
            exec('node --check ' . escapeshellarg($t) . ' 2>&1', $o, $rc);
            if ($rc !== 0) $errores[] = 'sintaxis <script> #' . ($i + 1) . ' de ' . str_replace("$raiz/", '', $f) . ': ' . implode(' ', array_slice($o, 0, 2));
            $o = []; $n++;
        }
    }
}

// 2) endpoints AJAX
$fuentes = array_merge($php, $js);
$endpoints = [];
foreach ($fuentes as $f) {
    $c = (string)file_get_contents($f);
    if (!preg_match_all('~(?:fetch|fetchJson)\(\s*[\'"]([a-z_]+\.php)(\?[^\'"]*)?[\'"]~', $c, $m, PREG_SET_ORDER)) continue;
    foreach ($m as $x) {
        $params = [];
        if (!empty($x[2])) { preg_match_all('~[?&]([a-z_]+)=~', $x[2], $pm); $params = $pm[1]; }
        $endpoints[$x[1]][str_replace("$raiz/", '', $f)] = $params;
    }
}
foreach ($endpoints as $ep => $consumidores) {
    $archivo = "$raiz/public/$ep";
    if (!is_file($archivo)) { $errores[] = "endpoint inexistente: $ep (" . implode(', ', array_keys($consumidores)) . ')'; continue; }
    $src = (string)file_get_contents($archivo);
    // Páginas ya separadas: el controlador es quien lee los parámetros.
    $controladores = ['horarios.php' => 'app/Presentation/Controllers/Horarios/HorariosPage.php', 'gestionar_salas.php' => 'app/Presentation/Controllers/Salas/SalasPage.php'];
    if (isset($controladores[$ep])) $src .= (string)file_get_contents("$raiz/" . $controladores[$ep]);
    // Genérico: si la página delega con require __DIR__ . '/../app/Presentation/Controllers/X.php', se lee también ese controlador.
    if (preg_match('~Presentation/Controllers/([A-Za-z/]+Page)\.php~', $src, $mc)) $src .= (string)file_get_contents("$raiz/app/Presentation/Controllers/{$mc[1]}.php");
    foreach ($consumidores as $quien => $params) foreach ($params as $p) {
        if (!preg_match('~\[\s*[\'"]' . preg_quote($p, '~') . '[\'"]\s*\]~', $src)) $errores[] = "$quien envía '$p' a $ep pero el PHP no lo lee";
        $n++;
    }
}
// fetch construidos con variable (url = 'buscar_componente_ajax.php?...')
foreach (['buscar_componente_ajax.php' => ['q', 'id', 'disponibles', 'sala']] as $ep => $ps) {
    $src = (string)file_get_contents("$raiz/app/Presentation/Controllers/Ajax/BuscarComponentePage.php"); // el controlador lee los parámetros
    foreach ($ps as $p) if (!preg_match('~\[\s*[\'"]' . $p . '[\'"]\s*\]~', $src)) $errores[] = "$ep no lee '$p' (lo envía componentes_widget.js)";
}

// 3) seguridad
foreach ($php as $f) {
    $c = str_replace('<?= $prefix ?>', 'PREFIJO', (string)file_get_contents($f)); // $prefix lo arma PHP con ids numéricos, no texto libre
    if (preg_match_all('~\son(?:click|change|submit|input|keyup)="[^"]*\'<\?=[^"]*"~', $c, $m)) {
        foreach ($m[0] as $h) $errores[] = 'handler con texto de PHP entre comillas JS en ' . str_replace("$raiz/", '', $f) . ': ' . substr(trim($h), 0, 80);
    }
}
foreach ($js as $f) {
    $c = (string)file_get_contents($f);
    if (basename($f) === 'app.js') continue;
    foreach (preg_split('/\R/', $c) as $ln => $linea) {
        if (preg_match('~innerHTML\s*\+?=\s*[^;]*\+\s*(?!UFPS\.esc|e\()~', $linea) && strpos($linea, 'UFPS.esc') === false && strpos($linea, 'e(') === false && preg_match('~\+\s*[a-zA-Z_]~', $linea))
            $errores[] = basename($f) . ':' . ($ln + 1) . ' innerHTML con variable sin escapar';
    }
}
exec('rm -rf ' . escapeshellarg($tmp));
echo "Endpoints AJAX analizados: " . count($endpoints) . " | comprobaciones: $n\n";
if ($node === '') echo "AVISO: node no disponible, se omitió la comprobación de sintaxis.\n";
if ($errores) { foreach ($errores as $e) echo "  ✗ $e\n"; exit(1); }
echo "JS lint: OK\n";
