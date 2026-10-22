<?php
/**
 * Revisión de CAPACIDAD (ISO/IEC 27001 A.8.6 «Gestión de la capacidad»; ITIL 4 «Gestión de capacidad y
 * desempeño»). Solo LEE: cuenta las tablas que crecen, compara con los límites medidos en las pruebas de carga
 * (README.md, «Rendimiento y capacidad»), comprueba que existan los índices de rendimiento y revisa la configuración de
 * MySQL/MariaDB y PHP. No modifica nada, así que se puede correr contra la base real.
 *
 *   php tools/carga/revisar_capacidad.php            (usa config/database.php, igual que el sistema)
 *
 * Sugerencia: correrlo al inicio de cada semestre y guardar la salida como evidencia de la revisión.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por consola.'); }
require __DIR__ . '/../../bootstrap.php';

$alertas = 0;
$linea = function (string $estado, string $texto) use (&$alertas): void {
    if ($estado !== 'OK') $alertas++;
    printf("  [%-7s] %s\n", $estado, $texto);
};
$valor = fn(string $sql) => $pdo->query($sql)->fetchColumn();

echo "Revisión de capacidad · " . date('Y-m-d H:i') . " · base " . $valor('SELECT DATABASE()') . "\n\n";

// 1) Volumen: [tabla => [límite probado sin degradarse, qué pasa cerca del límite]]
echo "Volumen de datos (comparado con lo probado (README.md, «Rendimiento y capacidad»))\n";
$limites = [
    'componentes'          => [2000000, 'búsquedas e inventario probados hasta 2.000.000'],
    'historial_componente' => [2200000, 'plano de sala y fichas probados hasta 2.200.000'],
    'tickets'              => [72000,   'bandejas probadas hasta 120.000 tickets en total (tickets + tickets_profesor)'],
    'tickets_profesor'     => [48000,   'idem'],
    'notificaciones'       => [30000,   'probado con 30.000'],
    'auditoria'            => [60000,   'probado con 60.000'],
    'bitacora_accesos'     => [40000,   'probado con 40.000; «Verificar integridad» recorre toda la tabla: depurar por retención (Seguridad)'],
];
foreach ($limites as $tabla => [$limite, $nota]) {
    try {
        $n = (int)$valor("SELECT COUNT(*) FROM `$tabla`");
    } catch (Throwable $e) {
        $linea('FALTA', "$tabla: no existe (¿falta importar database/tickets_db.sql?)");
        continue;
    }
    $pct = $limite ? $n / $limite : 0;
    // > 100 %: el sistema no está roto, pero ya trabaja con más datos de los que se probaron → repetir la prueba de carga.
    $linea($pct > 1 ? 'REVISAR' : ($pct >= 0.8 ? 'AVISO' : 'OK'), sprintf('%-22s %12s filas (%3d %% de lo probado) — %s', $tabla, number_format($n, 0, ',', '.'), (int)round($pct * 100), $nota));
}

// 2) Índices del bloque RENDIMIENTO de tickets_db.sql
echo "\nÍndices de rendimiento\n";
$indices = ['historial_componente' => ['idx_hist_tipo_comp', 'idx_hist_comp_tipo', 'idx_hist_equipo_anterior', 'idx_hist_equipo_nuevo'],
            'componentes' => ['idx_comp_estado_fecha', 'idx_comp_bodega', 'idx_comp_codigo_rev']];
$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
foreach ($indices as $tabla => $lista) {
    foreach ($lista as $idx) {
        $st->execute([$tabla, $idx]);
        $existe = (int)$st->fetchColumn() > 0;
        $linea($existe ? 'OK' : 'FALTA', "$tabla.$idx" . ($existe ? '' : ' → importa el bloque RENDIMIENTO de database/tickets_db.sql'));
    }
}

// 3) Configuración del servidor de base de datos
echo "\nMySQL / MariaDB\n";
$bp = (int)$valor('SELECT @@innodb_buffer_pool_size') / 1048576;
$linea($bp >= 256 ? 'OK' : 'AVISO', sprintf('innodb_buffer_pool_size = %d MB (recomendado 512–1024 MB; XAMPP trae 16 MB)', $bp));
$maxConn = (int)$valor('SELECT @@max_connections');
$linea($maxConn >= 200 ? 'OK' : 'AVISO', "max_connections = $maxConn (recomendado ≥ 200: con 150 hilos de Apache y 151 conexiones se ven errores 503 al saturarse)");
$usadas = (int)($pdo->query("SHOW GLOBAL STATUS LIKE 'Max_used_connections'")->fetch(PDO::FETCH_NUM)[1] ?? 0);
$linea($usadas < $maxConn * 0.8 ? 'OK' : 'AVISO', "conexiones simultáneas máximas desde el arranque = $usadas de $maxConn");
$lentas = (int)($pdo->query("SHOW GLOBAL STATUS LIKE 'Slow_queries'")->fetch(PDO::FETCH_NUM)[1] ?? 0);
$linea('OK', "consultas lentas registradas desde el arranque = $lentas (long_query_time = " . $valor('SELECT @@long_query_time') . ' s)');

// 4) PHP (la consola puede tener otro php.ini que Apache: revisa también phpinfo() del servidor)
echo "\nPHP (consola)\n";
$mem = ini_get('memory_limit');
$bytes = (int)$mem * (stripos($mem, 'G') !== false ? 1073741824 : (stripos($mem, 'M') !== false ? 1048576 : 1));
$linea($mem === '-1' || $bytes >= 128 * 1048576 ? 'OK' : 'AVISO', "memory_limit = $mem (las pruebas se hicieron con 128M)");
$linea(function_exists('opcache_get_status') && ini_get('opcache.enable') ? 'OK' : 'AVISO', 'OPcache ' . (ini_get('opcache.enable') ? 'activo' : 'inactivo (actívalo en php.ini: opcache.enable=1)'));

echo "\n" . ($alertas ? "$alertas punto(s) para revisar.\n" : "Todo dentro de lo probado.\n");
exit($alertas ? 1 : 0);
