<?php
/**
 * Pruebas HTTP de «Conexiones» y «Inventario GLPI» (solo admin). Se incluye desde run_http_tests.php
 * (aporta $pdo, $U, $C, $adm, $tec, cliente(), t() y seccion()). La integración real con GLPI (BD + API
 * simuladas) está en tests/run_tests.php.
 */
seccion('Conexiones y Inventario GLPI');
$cx = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); })();
$pdo->exec("DELETE FROM conexiones_externas");

foreach (['conexiones.php', 'glpi.php'] as $pag) {
    t("$pag: solo el admin entra", $tec->get($pag)->denied() && $C['beca_trabajo']->get($pag)->denied() && $C['secretaria']->get($pag)->denied() && $C['profesor']->get($pag)->denied() && (new HttpClient($base))->get($pag)->denied());
}
$r = $adm->get('conexiones.php');
t('conexiones: muestra las 3 conexiones y los datos sugeridos de la asistencia facial', $r->status === 200 && $r->contains('id="asistencia_pg"') && $r->contains('id="glpi_db"') && $r->contains('id="glpi_api"')
    && $r->contains('value="3.93.129.8"') && $r->contains('value="bd_sistemas_becario"') && $r->contains('Sin configurar'));
t('conexiones: el menú del admin tiene el grupo Conexiones', $r->contains('href="conexiones.php"') && $r->contains('href="glpi.php"'));

$r = $adm->post('conexiones.php', ['clave' => 'asistencia_pg', 'accion' => 'guardar', 'host' => '127.0.0.1', 'port' => '1', 'dbname' => 'bd_sistemas_becario', 'user' => 'dptosistemas', 'password' => 'ZT-CLAVE-SECRETA']);
$r = $adm->get('conexiones.php');
$crudo = (string)$pdo->query("SELECT datos FROM conexiones_externas WHERE clave='asistencia_pg'")->fetchColumn();
t('conexiones: guardar deja la contraseña cifrada en la base', $crudo !== '' && strpos($crudo, 'ZT-CLAVE-SECRETA') === false && strpos($crudo, 'enc:v1:') !== false);
t('conexiones: la página NUNCA muestra la contraseña guardada', !$r->contains('ZT-CLAVE-SECRETA') && !$r->contains('enc:v1:') && $r->contains('guardada — déjala vacía para conservarla') && $r->contains('Datos guardados'));
$adm->post('conexiones.php', ['clave' => 'asistencia_pg', 'accion' => 'probar']);
$r = $adm->get('conexiones.php');
t('conexiones: «Probar conexión» con un servidor que no responde muestra el error y marca «Falló la prueba»', $r->contains('Falló la prueba') && $r->contains('Última prueba') && !$r->contains('ZT-CLAVE-SECRETA'));
$adm->post('conexiones.php', ['clave' => 'glpi_db', 'accion' => 'guardar', 'host' => '10.0.0.5; DROP', 'port' => '3306']);
t('conexiones: host inválido se rechaza', $cx("SELECT COUNT(*) FROM conexiones_externas WHERE clave='glpi_db'") === 0 && $adm->get('conexiones.php')->status === 200);
$tec->postRaw('conexiones.php', ['clave' => 'glpi_db', 'accion' => 'guardar', 'host' => '1.2.3.4']);
$adm->postRaw('conexiones.php', ['clave' => 'glpi_db', 'accion' => 'guardar', 'host' => '1.2.3.4']);
t('conexiones: sin token CSRF o sin ser admin no se guarda nada', $cx("SELECT COUNT(*) FROM conexiones_externas WHERE clave='glpi_db'") === 0);

$r = $adm->get('glpi.php');
t('glpi: sin base de GLPI conectada avisa y muestra salas y cola', $r->status === 200 && $r->contains('No hay conexión con la base de datos de GLPI') && $r->contains('id="salas"') && $r->contains('id="cola"') && $r->contains('La API de GLPI no está configurada'));
$idSalaG = (int)$pdo->query("SELECT id_sala FROM salas ORDER BY id_sala LIMIT 1")->fetchColumn();
if ($idSalaG) {
    $adm->post('glpi.php', ['accion' => 'guardar_salas', 'glpi_salas' => [$idSalaG], 'glpi_ubicaciones' => ['7']]);
    t('glpi: el admin vincula una sala con una ubicación de GLPI', $cx("SELECT glpi_location_id FROM salas WHERE id_sala=?", [$idSalaG]) === 7);
    $adm->post('glpi.php', ['accion' => 'guardar_salas', 'glpi_salas' => [$idSalaG], 'glpi_ubicaciones' => ['']]);
    t('glpi: y la puede desvincular', $cx("SELECT COUNT(*) FROM salas WHERE id_sala=? AND glpi_location_id IS NULL", [$idSalaG]) === 1);
}
$r = $adm->post('glpi.php', ['accion' => 'reintentar']);
t('glpi: «Enviar pendientes» sin API configurada no rompe', $adm->get('glpi.php')->contains('La API de GLPI no está configurada'));
$pdo->exec("DELETE FROM conexiones_externas");
