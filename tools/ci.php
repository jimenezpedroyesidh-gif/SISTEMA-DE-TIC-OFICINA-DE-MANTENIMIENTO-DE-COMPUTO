<?php
/**
 * Puerta de calidad: la misma que corre el CI de GitHub.
 *
 *   php tools/ci.php              todas las etapas locales (sin HTTP)
 *   php tools/ci.php lint         solo `php -l` sobre todo el PHP propio
 *   php tools/ci.php classmap     app/classmap.php al día
 *   php tools/ci.php css          public/assets/app.css al día
 *   php tools/ci.php phpstan      análisis estático (phpstan.neon)
 *   php tools/ci.php unit         tests/run_tests.php
 *   php tools/ci.php lints        lint_js + lint_codigo_muerto
 *   php tools/ci.php http         suite HTTP (arranca php -S contra la base *_test)
 *   php tools/ci.php all          todo, incluido HTTP
 *
 * Unit y HTTP necesitan MySQL con la base de pruebas (ver tests/run_tests.php).
 * Variables: TEST_DB_HOST/NAME/USER/PASS, TEST_PORT, PHPSTAN (ruta del ejecutable o .phar).
 * Sale con código distinto de 0 si alguna etapa falla.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por línea de comandos.'); }

$raiz = dirname(__DIR__);
chdir($raiz);
$php = escapeshellarg(PHP_BINARY);

function etapa(string $titulo, callable $fn): bool
{
    echo "\n=== $titulo ===\n";
    $t = microtime(true);
    $ok = $fn();
    printf("%s %s (%.1fs)\n", $ok ? 'OK  ' : 'FALLA', $titulo, microtime(true) - $t);
    return $ok;
}

function ejecutar(string $cmd): int
{
    passthru($cmd, $codigo);
    return $codigo;
}

function archivosPhp(string $raiz): array
{
    $salida = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $ruta = $f->getPathname();
        if (substr($ruta, -4) !== '.php') continue;
        $rel = substr($ruta, strlen($raiz) + 1);
        if (preg_match('~^(vendor|storage|\.git|node_modules)/~', $rel)) continue;
        $salida[] = $rel;
    }
    sort($salida);
    return $salida;
}

$etapas = [
    'lint' => ['php -l en todo el PHP del proyecto', function () use ($raiz, $php) {
        $malos = 0; $n = 0;
        foreach (archivosPhp($raiz) as $rel) {
            $n++;
            $out = []; $cod = 0;
            exec("$php -l " . escapeshellarg($rel) . ' 2>&1', $out, $cod);
            if ($cod !== 0) { $malos++; echo implode("\n", $out) . "\n"; }
        }
        echo "$n archivos revisados, $malos con errores de sintaxis\n";
        return $malos === 0;
    }],
    'classmap' => ['classmap al día', function () use ($raiz, $php) {
        $actual = file_get_contents($raiz . '/app/classmap.php');
        $cod = ejecutar("$php tools/generar_classmap.php");
        $nuevo = file_get_contents($raiz . '/app/classmap.php');
        if ($actual !== $nuevo) {
            echo "app/classmap.php estaba desactualizado (ya se regeneró: haz commit del cambio).\n";
            return false;
        }
        return $cod === 0;
    }],
    'css' => ['app.css al día (tools/construir_css.php)', function () use ($php) {
        return ejecutar("$php tools/construir_css.php --revisar") === 0;
    }],
    'phpstan' => ['PHPStan (phpstan.neon)', function () use ($raiz, $php) {
        $cand = array_filter([
            getenv('PHPSTAN') ?: null,
            $raiz . '/vendor/bin/phpstan',
            $raiz . '/tools/phpstan.phar',
            trim((string)@shell_exec('command -v phpstan 2>/dev/null')) ?: null,
        ]);
        foreach ($cand as $c) {
            if (is_file($c)) {
                $cmd = substr($c, -5) === '.phar' ? "$php " . escapeshellarg($c) : escapeshellarg($c);
                return ejecutar("$cmd analyse -c phpstan.neon --no-progress --memory-limit=1G") === 0;
            }
        }
        echo "PHPStan no está instalado: descarga https://github.com/phpstan/phpstan/releases/download/1.12.7/phpstan.phar en tools/phpstan.phar (o define PHPSTAN=ruta).\n";
        return getenv('CI') === 'true' ? false : true; // en local solo avisa; en CI es obligatorio
    }],
    'unit' => ['pruebas unitarias (tests/run_tests.php)', function () use ($php) {
        return ejecutar("$php tests/run_tests.php") === 0;
    }],
    'lints' => ['lint JS y código muerto', function () use ($php) {
        return ejecutar("$php tests/lint_js.php") === 0 && ejecutar("$php tests/lint_codigo_muerto.php") === 0;
    }],
    'http' => ['pruebas HTTP (tests/http/run.sh)', function () {
        return ejecutar('bash tests/http/run.sh') === 0;
    }],
];

$pedidas = array_slice($argv, 1);
if ($pedidas === [] ) {
    $pedidas = ['lint', 'classmap', 'css', 'phpstan', 'unit', 'lints'];
} elseif ($pedidas === ['all']) {
    $pedidas = array_keys($etapas);
}
$fallos = [];
foreach ($pedidas as $p) {
    if (!isset($etapas[$p])) {
        fwrite(STDERR, "Etapa desconocida: $p (válidas: " . implode(', ', array_keys($etapas)) . ", all)\n");
        exit(2);
    }
    if (!etapa($etapas[$p][0], $etapas[$p][1])) {
        $fallos[] = $p;
    }
}
echo "\n" . ($fallos === [] ? "CI: todo en orden.\n" : 'CI: fallaron: ' . implode(', ', $fallos) . "\n");
exit($fallos === [] ? 0 : 1);
