<?php
/**
 * Respaldo cifrado desde la consola (para el Programador de tareas de Windows o cron):
 *   RESPALDO_CLAVE='frase larga' php tools/respaldo.php
 * Hace lo mismo que «Seguridad → Respaldos → Generar»: base de datos + archivos subidos, cifrado con la frase
 * (que nunca se guarda). Queda registrado en la bitácora como «tarea programada».
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por consola.'); }
require __DIR__ . '/../bootstrap.php';

$frase = (string)getenv('RESPALDO_CLAVE');
if ($frase === '') {
    fwrite(STDERR, "Falta la variable RESPALDO_CLAVE con la frase de cifrado.\n");
    exit(2);
}
$r = RespaldoService::generar($pdo, $frase, null);
echo ($r['ok'] ? 'OK  ' : 'ERROR  ') . $r['mensaje'] . "\n";
exit($r['ok'] ? 0 : 1);
