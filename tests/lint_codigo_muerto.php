<?php
/**
 * Lint de código muerto. Uso: php tests/lint_codigo_muerto.php Busca, solo con lectura (no modifica nada): 1.
 * funciones/métodos PHP que ningún archivo llama (ni por nombre en texto); 2. clases CSS definidas en
 * estilo.css que no aparecen en ningún PHP/JS; 3. funciones JavaScript sin uso; 4. archivos de public/ y app/
 * que ningún otro archivo menciona. Sale con código 1 si encuentra algo que no esté en la lista de
 * excepciones.
 */
require_once __DIR__ . '/css_helper.php';

/** API de dominio conservada a propósito aunque hoy no tenga llamadores. */
const LINT_EXCEPCIONES_FUNCIONES = [];
/** Archivos cargados por nombre construido (require dinámico) o que son plantillas/semillas. */
const LINT_EXCEPCIONES_ARCHIVOS = [
    'AdminPanel.php', 'BecaPanel.php', 'ProfesorPanel.php', 'SolicitantePanel.php', 'TecnicoPanel.php', // tickets.php los carga por rol
    'postgres.example.php', 'database.example.php', 'seed_datos_prueba_salas.sql', 'seed_usuarios_prueba.php', '.gitkeep', '.htaccess',
];

function lintCodigoMuerto(string $raiz): array
{
    $leer = fn(string $f) => (string)file_get_contents($f);
    $php = []; $js = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
    $todos = [];
    foreach ($it as $f) {
        $ruta = str_replace('\\', '/', substr($f->getPathname(), strlen($raiz) + 1));
        if (preg_match('#^(vendor|legacy|\.git)/|/vendor/#', $ruta)) continue;
        $todos[] = $ruta;
        if (substr($ruta, -4) === '.php') $php[$ruta] = $leer($f->getPathname());
        if (substr($ruta, -3) === '.js') $js[$ruta] = $leer($f->getPathname());
    }
    $sinTests = array_filter($php, fn($k) => strpos($k, 'tests/') !== 0, ARRAY_FILTER_USE_KEY);
    $texto = implode("\n", $sinTests) . "\n" . implode("\n", $js);
    $res = ['funciones' => [], 'css' => [], 'js' => [], 'archivos' => []];

    foreach ($sinTests as $f => $t) {
        if (!preg_match_all('/^\s*(?:(?:public|private|protected)\s+)?(?:static\s+)?function\s+([A-Za-z_]\w*)\s*\(/m', $t, $m)) continue;
        foreach ($m[1] as $n) {
            if ($n === '__construct' || in_array($n, LINT_EXCEPCIONES_FUNCIONES, true)) continue;
            if (preg_match_all('/\b' . preg_quote($n, '/') . '\b/', $texto) <= 1) $res['funciones'][] = "$f::$n";
        }
    }
    $css = preg_replace('#/\*.*?\*/#s', '', cssCompleto($raiz));
    preg_match_all('/\.([A-Za-z_][\w-]*)/', preg_replace('/\{[^}]*\}/', '{}', $css), $mc);
    foreach (array_unique($mc[1]) as $c) { if (strpos($texto, $c) === false) $res['css'][] = $c; }
    foreach ($js as $f => $t) {
        if (preg_match_all('/function\s+([A-Za-z_]\w*)\s*\(/', $t, $m)) {
            foreach ($m[1] as $n) { if (preg_match_all('/\b' . preg_quote($n, '/') . '\b/', $texto) <= 1) $res['js'][] = "$f::$n"; }
        }
    }
    $codigo = array_filter($sinTests + $js, fn($t) => true);
    foreach ($todos as $f) {
        if (!preg_match('#^(public|app|config|database)/#', $f)) continue;
        $b = basename($f);
        if (in_array($b, LINT_EXCEPCIONES_ARCHIVOS, true) || $b === 'estilo.css' || strpos($f, 'public/assets/css/') === 0) continue; // las partes de css/ las importa estilo.css (lo verifica una prueba de tests/run_tests.php)
        if (strpos($f, 'database/migraciones/') === 0) continue; // las lee tools/migrar.php por patrón NNN_*.sql
        $usado = false;
        foreach ($codigo as $g => $t) { if ($g !== $f && strpos($t, $b) !== false) { $usado = true; break; } }
        if (!$usado) $res['archivos'][] = $f;
    }
    return $res;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $r = lintCodigoMuerto(dirname(__DIR__));
    $total = 0;
    foreach ($r as $tipo => $lista) {
        echo strtoupper($tipo) . ' sin uso: ' . count($lista) . "\n";
        foreach ($lista as $x) echo "  - $x\n";
        $total += count($lista);
    }
    exit($total === 0 ? 0 : 1);
}
