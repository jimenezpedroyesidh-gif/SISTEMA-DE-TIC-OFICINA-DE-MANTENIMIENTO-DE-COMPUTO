<?php
/**
 * Descifra un respaldo (.ufpsbk) y lo descomprime en una carpeta para restaurarlo a mano:
 *   RESPALDO_CLAVE='frase larga' php tools/restaurar_respaldo.php storage/respaldos/respaldo_X.ufpsbk C:\restauracion
 * Deja base_datos.sql (importarlo en una base vacía), archivos/ (adjuntos, fotos y archivos de soluciones técnicas) y manifiesto.json.
 * No toca la base de datos actual.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por consola.'); }
require __DIR__ . '/../app/Support/autoload.php';

[$archivo, $destino] = [$argv[1] ?? '', $argv[2] ?? ''];
$frase = (string)getenv('RESPALDO_CLAVE');
if ($archivo === '' || $destino === '' || $frase === '') {
    fwrite(STDERR, "Uso: RESPALDO_CLAVE='frase' php tools/restaurar_respaldo.php <archivo.ufpsbk> <carpeta_destino>\n");
    exit(2);
}
if (!is_file($archivo)) { fwrite(STDERR, "No existe el archivo $archivo\n"); exit(2); }
if (!is_dir($destino) && !mkdir($destino, 0700, true)) { fwrite(STDERR, "No se pudo crear $destino\n"); exit(2); }

$zip = rtrim($destino, '/\\') . DIRECTORY_SEPARATOR . 'respaldo.zip';
if (!RespaldoService::descifrar($archivo, $zip, $frase)) {
    fwrite(STDERR, "La frase no es correcta o el archivo está dañado.\n");
    exit(1);
}
$z = new ZipArchive();
if ($z->open($zip) !== true || !$z->extractTo($destino)) { fwrite(STDERR, "No se pudo descomprimir.\n"); exit(1); }
$z->close();
unlink($zip);
echo "Listo. En $destino quedaron base_datos.sql, archivos/ y manifiesto.json (lee LEEME.txt).\n";
