<?php
/**
 * Prueba de concurrencia (README.md, «Rendimiento y capacidad»): N usuarios a la vez, cada uno con su propia sesión,
 * pidiendo las páginas que más usa su rol, sin pausas (peor caso). Mide peticiones/segundo, tiempos
 * p50/p95/p99 y errores. Mezcla de roles proporcional al personal real del departamento:
 * 50 profesores, 40 becas, 6 técnicos, 4 secretarias, 1 admin (+ reportes anónimos por QR).
 *
 *   php tools/carga/prueba_concurrencia.php --base=http://127.0.0.1:8080 --usuarios=25 --segundos=30
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por consola.'); }
require_once __DIR__ . '/../../tests/http/HttpClient.php';

$opt = getopt('', ['base:', 'usuarios:', 'segundos:', 'salida:', 'db:']);
$base = rtrim($opt['base'] ?? 'http://127.0.0.1:8080', '/') . '/';
$N = (int)($opt['usuarios'] ?? 25);
$dur = (int)($opt['segundos'] ?? 30);
$pdo = new PDO('mysql:host=localhost;dbname=' . ($opt['db'] ?? 'tickets_carga_test') . ';charset=utf8mb4', 'root', '');
$token = $pdo->query('SELECT codigo_qr_token FROM salas ORDER BY id_sala LIMIT 1')->fetchColumn();
$equipos = $pdo->query('SELECT id_equipo FROM equipos')->fetchAll(PDO::FETCH_COLUMN);
$codigos = $pdo->query("SELECT codigo_seguimiento FROM tickets WHERE codigo_seguimiento IS NOT NULL LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);

$mezcla = [
    'profesor'     => ['peso' => 50, 'tag' => 'PROF', 'max' => 50, 'urls' => ['tickets.php', 'horarios.php', 'create_ticket_profesor.php', 'buscar_global.php?q=SC2', 'mis_notificaciones.php']],
    'beca_trabajo' => ['peso' => 40, 'tag' => 'BECA', 'max' => 30, 'urls' => ['tickets.php', 'mantenimientos_programados.php', 'buscar_componente_ajax.php?q=UFPS-00{n}', 'ficha_equipo.php?id={eq}', 'horarios.php']],
    'tecnico'      => ['peso' => 6,  'tag' => 'TECN', 'max' => 6,  'urls' => ['tickets.php', 'ficha_equipo.php?id={eq}', 'buscar_componente_ajax.php?q=UFPS-00{n}', 'gestionar_inventario.php?q=UFPS-00{n}', 'mantenimientos_programados.php', 'buscar_global.php?q=Lenovo']],
    'secretaria'   => ['peso' => 4,  'tag' => 'SECR', 'max' => 4,  'urls' => ['tickets.php', 'horarios.php', 'buscar_global.php?q=no%20enciende']],
    'admin'        => ['peso' => 1,  'tag' => 'ADMI', 'max' => 1,  'urls' => ['tickets.php', 'indicadores.php', 'sla.php', 'buscar_global.php?q=SC205']],
    'anonimo'      => ['peso' => 6,  'tag' => '',     'max' => 999, 'urls' => ['reportar.php?token={tok}', 'consultar_estado.php?codigo={cod}', 'login.php']],
];
// Reparto de los N usuarios proporcional al peso de cada rol (determinista, para comparar corridas)
$total = array_sum(array_column($mezcla, 'peso'));
$vus = []; $cuenta = [];
$cupos = [];
foreach ($mezcla as $rol => $m) $cupos[$rol] = $N * $m['peso'] / $total;
for ($i = 0; $i < $N; $i++) {
    arsort($cupos);
    $rol = array_key_first($cupos);
    $cupos[$rol] -= 1;
    $k = ($cuenta[$rol] = ($cuenta[$rol] ?? 0) + 1);
    $k = ($k - 1) % $mezcla[$rol]['max'] + 1;
    $jar = tempnam(sys_get_temp_dir(), 'vu_');
    if ($rol !== 'anonimo') {
        $c = curl_init($base . 'login.php');
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['codigo' => sprintf('CG-%s-%02d', $mezcla[$rol]['tag'], $k),
            'correo' => sprintf('carga.%s.%02d@ufps.edu.co', $rol, $k), 'password' => 'Carga2026!']), CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_RETURNTRANSFER => true]);
        curl_setopt($c, CURLOPT_TIMEOUT, 60);
        curl_exec($c);
        $okLogin = curl_getinfo($c, CURLINFO_HTTP_CODE) === 302 && strpos((string)curl_getinfo($c, CURLINFO_REDIRECT_URL), 'login.php') === false;
        if (!$okLogin) { $loginsFallidos = ($loginsFallidos ?? 0) + 1; fwrite(STDERR, "login fallido: $rol #$k (" . curl_getinfo($c, CURLINFO_HTTP_CODE) . ' ' . curl_getinfo($c, CURLINFO_REDIRECT_URL) . ")\n"); }
        unset($c);
    }
    $vus[] = ['rol' => $rol, 'jar' => $jar];
}
echo "Usuarios simultáneos: $N → " . json_encode($cuenta) . " | logins fallidos: " . ($loginsFallidos ?? 0) . "\n";

$mh = curl_multi_init();
$lat = []; $latRol = []; $errRol = []; $errPorUrl = []; $errores = 0; $timeouts = 0; $bajo1s = 0; $porUrl = []; $hechas = 0;
$nueva = function (int $i) use (&$vus, $mezcla, $base, $token, $equipos, $codigos, $mh) {
    $u = $mezcla[$vus[$i]['rol']]['urls'][array_rand($mezcla[$vus[$i]['rol']]['urls'])];
    $u = strtr($u, ['{n}' => sprintf('%04d', mt_rand(1, 9999)), '{eq}' => $equipos[array_rand($equipos)], '{tok}' => $token, '{cod}' => $codigos[array_rand($codigos)]]);
    $c = curl_init($base . $u);
    curl_setopt_array($c, [CURLOPT_COOKIEFILE => $vus[$i]['jar'], CURLOPT_COOKIEJAR => $vus[$i]['jar'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_PRIVATE => $i . '|' . strtok($u, '?') . '|' . $vus[$i]['rol']]);
    curl_multi_add_handle($mh, $c);
};
for ($i = 0; $i < $N; $i++) $nueva($i);
$inicio = microtime(true);
do {
    curl_multi_exec($mh, $activos);
    curl_multi_select($mh, 0.05);
    while ($info = curl_multi_info_read($mh)) {
        $c = $info['handle'];
        [$i, $ruta, $rolVu] = explode('|', curl_getinfo($c, CURLINFO_PRIVATE), 3);
        $ms = curl_getinfo($c, CURLINFO_TOTAL_TIME) * 1000;
        $st = curl_getinfo($c, CURLINFO_HTTP_CODE);
        $body = curl_multi_getcontent($c);
        $loc = (string)curl_getinfo($c, CURLINFO_REDIRECT_URL);
        $timeout = $info['result'] === CURLE_OPERATION_TIMEDOUT || $st === 0;
        $err = $timeout || $st >= 500 || ($st === 302 && strpos($loc, 'login.php') !== false && strtok($ruta, '?') !== 'login.php')
            || preg_match('~Fatal error|SQLSTATE|Allowed memory~', (string)$body) || ($st === 200 && $body === '');
        if ($err) { $errRol[$rolVu] = ($errRol[$rolVu] ?? 0) + 1; $errores++; $errPorUrl[$ruta][$timeout ? 'timeout' : ($st >= 500 ? 'http' . $st : ($st === 302 ? 'sesion_perdida' : 'otro'))] = ($errPorUrl[$ruta][$timeout ? 'timeout' : ($st >= 500 ? 'http' . $st : ($st === 302 ? 'sesion_perdida' : 'otro'))] ?? 0) + 1; }
        if ($timeout) $timeouts++;
        if ($ms < 1000) $bajo1s++;
        $lat[] = $ms; $porUrl[$ruta][] = $ms; $latRol[$rolVu][] = $ms; $hechas++;
        curl_multi_remove_handle($mh, $c);
        curl_close($c);
        if (microtime(true) - $inicio < $dur) $nueva((int)$i);
    }
} while ($activos || microtime(true) - $inicio < $dur && $hechas === 0);
$seg = microtime(true) - $inicio;
sort($lat);
$p = fn(array $a, float $q) => $a ? round($a[min(count($a) - 1, (int)floor($q * count($a)))], 0) : 0;
$res = ['usuarios' => $N, 'segundos' => round($seg, 1), 'peticiones' => $hechas, 'rps' => round($hechas / $seg, 1), 'errores' => $errores, 'timeouts_30s' => $timeouts, 'pct_bajo_1s' => $hechas ? round(100 * $bajo1s / $hechas, 1) : 0,
    'p50_ms' => $p($lat, .5), 'p95_ms' => $p($lat, .95), 'p99_ms' => $p($lat, .99), 'max_ms' => round(max($lat ?: [0])), 'por_pagina' => []];
$res['errores_por_rol'] = $errRol;
foreach ($latRol as $r => $l) { sort($l); $res['por_rol'][$r] = ['n' => count($l), 'p50' => $p($l, .5), 'p95' => $p($l, .95), 'errores' => $errRol[$r] ?? 0, 'pct_error' => round(100 * ($errRol[$r] ?? 0) / count($l), 1)]; }
foreach ($porUrl as $r => $l) { sort($l); $res['por_pagina'][$r] = ['n' => count($l), 'p50' => $p($l, .5), 'p95' => $p($l, .95), 'errores' => $errPorUrl[$r] ?? []]; }
uasort($res['por_pagina'], fn($a, $b) => $b['p95'] <=> $a['p95']);
printf("%d usuarios | %d peticiones en %.0fs | %.1f pet/s | p50 %d ms | p95 %d ms | p99 %d ms | <1s %.1f%% | errores %d (timeouts %d)\n",
    $N, $hechas, $seg, $res['rps'], $res['p50_ms'], $res['p95_ms'], $res['p99_ms'], $res['pct_bajo_1s'], $errores, $timeouts);
foreach ($res['por_rol'] ?? [] as $r => $x) printf("   [%s] n=%d p50=%d p95=%d errores=%d (%.1f%%)\n", $r, $x['n'], $x['p50'], $x['p95'], $x['errores'], $x['pct_error']);
foreach (array_slice($res['por_pagina'], 0, 8, true) as $r => $x) printf("   %-32s n=%-5d p50=%-6d p95=%-6d %s\n", $r, $x['n'], $x['p50'], $x['p95'], $x['errores'] ? json_encode($x['errores']) : '');
if (!empty($opt['salida'])) {
    $todo = is_file($opt['salida']) ? json_decode(file_get_contents($opt['salida']), true) : [];
    $todo[] = $res;
    file_put_contents($opt['salida'], json_encode($todo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
foreach ($vus as $v) @unlink($v['jar']);
