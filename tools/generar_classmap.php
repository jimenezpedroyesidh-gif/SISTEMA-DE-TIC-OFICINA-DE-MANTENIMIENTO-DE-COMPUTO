<?php
/** Regenera app/classmap.php (mapa clase → archivo para el autoload). Uso: php tools/generar_classmap.php */
require_once __DIR__ . '/../app/Support/autoload.php';
$raiz = dirname(__DIR__);
$mapa = autoloadEscanear($raiz);
$txt = "<?php\n// GENERADO por tools/generar_classmap.php — no editar a mano.\nreturn " . var_export($mapa, true) . ";\n";
file_put_contents($raiz . '/app/classmap.php', $txt);
echo count($mapa) . " clases en app/classmap.php\n";
