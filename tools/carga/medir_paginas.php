<?php
/**
 * Mide el tiempo de respuesta de TODAS las páginas a las que llega cada rol (README.md, «Rendimiento y capacidad»).
 *
 * Inicia sesión con un usuario de carga por rol, recorre los enlaces del sistema (hasta 2 niveles desde
 * el panel, sin seguir acciones que borran o cierran sesión) y además pide las búsquedas típicas
 * (buscador global, buscador de componentes, inventario, fichas). Cada URL se pide 3 veces y se guarda la
 * mediana, el código HTTP, el tamaño y si la respuesta trae un error de PHP.
 *
 *   php tools/carga/medir_paginas.php --base=http://127.0.0.1:8080 --salida=resultados/medicion_2M.json
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por consola.'); }
require_once __DIR__ . '/../../tests/http/HttpClient.php';

$opt = getopt('', ['base:', 'salida:', 'roles:', 'rep:']);
$base = rtrim($opt['base'] ?? 'http://127.0.0.1:8080', '/');
$rep = (int)($opt['rep'] ?? 3);
$roles = explode(',', $opt['roles'] ?? 'admin,tecnico,beca_trabajo,profesor,secretaria,vigilante,visualizacion');
$tag = ['admin' => 'ADMI', 'tecnico' => 'TECN', 'beca_trabajo' => 'BECA', 'profesor' => 'PROF', 'secretaria' => 'SECR', 'vigilante' => 'VIGI', 'visualizacion' => 'VISU'];
$NO_SEGUIR = '~logout|borrar|eliminar|cerrar_sesion|descargar_respaldo|google_login|accion=|restaurar|archivar|reiniciar~i';

// Búsquedas típicas que hace la gente (las que más sufren con 2M de componentes)
$busquedas = [
    'buscar_global.php?q=SC205', 'buscar_global.php?q=Lenovo', 'buscar_global.php?q=no%20enciende', 'buscar_global.php?q=1500',
    'buscar_componente_ajax.php?q=UFPS-0123', 'buscar_componente_ajax.php?q=UFPS-0123&disponibles=1', 'buscar_componente_ajax.php?q=SN9F',
    'buscar_componente_ajax.php?q=zzz-no-existe', 'buscar_componente_ajax.php?q=UFPS-I0001&sala=1',
    'gestionar_inventario.php', 'gestionar_inventario.php?q=UFPS-0099', 'gestionar_inventario.php?q=monitor',
    'ficha_equipo.php', 'ficha_equipo.php?vista=equipos', 'ficha_equipo.php?q=SC2', 'ficha_equipo.php?id=15', 'ficha_componente.php?id=75',
    'ficha_componente.php?id=1500000', 'revisar_componentes_bodega.php', 'listar_equipos_ajax.php?id_sala=3', 'listar_equipos_ajax.php?id_sala=3&plano=1',
    'distribucion_sala.php?id_sala=3', 'indicadores.php', 'sla.php', 'seguridad.php', 'pantalla.php', 'horarios.php', 'mantenimientos_programados.php',
];

function normal(string $href, string $base): ?string
{
    $href = html_entity_decode($href);
    if ($href === '' || $href[0] === '#' || preg_match('~^(mailto:|javascript:|tel:)~', $href)) return null;
    if (preg_match('~^https?://~', $href)) { if (strpos($href, $base) !== 0) return null; $href = substr($href, strlen($base)); }
    $href = ltrim($href, '/');
    if (!preg_match('~^[a-z0-9_]+\.php~i', $href)) return null;
    return preg_replace('~#.*$~', '', $href);
}

$resultados = [];
foreach ($roles as $rol) {
    $c = new HttpClient($base);
    $codigo = sprintf('CG-%s-01', $tag[$rol]);
    $correo = sprintf('carga.%s.01@ufps.edu.co', $rol);
    $r = $c->login($codigo, $correo, 'Carga2026!');
    if (!$r->redirects()) { echo "[$rol] no pudo iniciar sesión (status {$r->status})\n"; continue; }
    $inicio = normal($r->location(), $base) ?? 'index.php';
    $cola = [[$inicio, 0]];
    foreach ($busquedas as $b) $cola[] = [$b, 9];
    $vistos = []; $porRuta = [];
    while ($cola) {
        [$url, $nivel] = array_shift($cola);
        if (isset($vistos[$url]) || preg_match($NO_SEGUIR, $url)) continue;
        $ruta = strtok($url, '?');
        if (($porRuta[$ruta] = ($porRuta[$ruta] ?? 0) + 1) > 8) continue; // máx 8 variantes por página
        $vistos[$url] = true;
        $tiempos = []; $resp = null;
        for ($i = 0; $i < $rep; $i++) {
            $t = microtime(true);
            $resp = $c->get($url);
            $tiempos[] = (microtime(true) - $t) * 1000;
        }
        sort($tiempos);
        $errorPhp = (bool)preg_match('~(Fatal error|Uncaught|Warning: |Notice: |Deprecated: |SQLSTATE|Allowed memory size)~', $resp->body);
        $resultados[] = ['rol' => $rol, 'url' => $url, 'status' => $resp->status, 'ms' => round($tiempos[intdiv(count($tiempos), 2)], 1),
            'ms_max' => round(max($tiempos), 1), 'kb' => round(strlen($resp->body) / 1024, 1), 'error_php' => $errorPhp,
            'redirige' => $resp->redirects() ? $resp->location() : null];
        printf("%-13s %4d %8.1f ms %8.1f KB %s %s\n", $rol, $resp->status, $tiempos[intdiv(count($tiempos), 2)], strlen($resp->body) / 1024, $errorPhp ? 'ERROR_PHP' : '', $url);
        if ($nivel < 2 && $resp->status === 200 && stripos($resp->header('content-type'), 'html') !== false) {
            preg_match_all('~href="([^"]+)"~', $resp->body, $m);
            foreach ($m[1] as $h) if (($n = normal($h, $base)) !== null && !isset($vistos[$n])) $cola[] = [$n, $nivel + 1];
        }
    }
}
if (!empty($opt['salida'])) {
    @mkdir(dirname($opt['salida']), 0777, true);
    file_put_contents($opt['salida'], json_encode($resultados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
$lentos = array_filter($resultados, fn($r) => $r['ms'] > 500);
$errores = array_filter($resultados, fn($r) => $r['status'] >= 500 || $r['error_php']);
printf("\nURLs medidas: %d | > 500 ms: %d | errores (5xx o PHP): %d\n", count($resultados), count($lentos), count($errores));
