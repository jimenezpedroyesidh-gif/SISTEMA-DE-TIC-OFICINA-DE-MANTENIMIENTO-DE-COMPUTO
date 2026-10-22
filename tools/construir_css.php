<?php
/**
 * Junta en public/assets/app.css todo lo que importa public/assets/estilo.css (tokens.css + css/NN-*.css, en el
 * mismo orden) y quita comentarios y espacios: el navegador baja un solo archivo en vez de 13.
 *
 *   php tools/construir_css.php            regenera app.css
 *   php tools/construir_css.php --revisar  sale con código 1 si app.css no está al día (lo usa el CI)
 *
 * Mientras app.css esté desactualizado (editaste un .css y no lo regeneraste), las páginas cargan estilo.css con
 * sus @import, así un cambio nunca deja de verse.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por consola.'); }

$assets = dirname(__DIR__) . '/public/assets';
preg_match_all('~@import url\("([^"]+)"\);~', (string)file_get_contents("$assets/estilo.css"), $m);
$css = '';
foreach ($m[1] as $parte) {
    $css .= (string)file_get_contents("$assets/$parte") . "\n";
}
$css = preg_replace('~/\*.*?\*/~s', '', $css);          // comentarios
$css = preg_replace('~\s+~', ' ', $css);                 // espacios repetidos y saltos
$css = preg_replace('~\s*([{};,>])\s*~', '$1', $css);    // espacios alrededor de llaves y separadores
$css = "/* Generado por tools/construir_css.php a partir de estilo.css: no editar a mano. */\n" . trim($css) . "\n";

$destino = "$assets/app.css";
if (in_array('--revisar', $argv, true)) {
    $alDia = is_file($destino) && file_get_contents($destino) === $css;
    echo $alDia ? "app.css al día.\n" : "app.css desactualizado: corre php tools/construir_css.php\n";
    exit($alDia ? 0 : 1);
}
file_put_contents($destino, $css);
printf("app.css: %d partes, %.1f KB\n", count($m[1]), strlen($css) / 1024);
