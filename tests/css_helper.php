<?php
/** estilo.css solo importa: este helper devuelve su contenido más todas las partes de public/assets/css/ en el orden de importación. */
function cssCompleto(string $raiz): string
{
    $base = "$raiz/public/assets";
    $txt = (string)file_get_contents("$base/estilo.css");
    if (preg_match_all('~@import url\("(css/[^"]+)"\);~', $txt, $m)) {
        foreach ($m[1] as $parte) $txt .= "\n" . (string)file_get_contents("$base/$parte");
    }
    return $txt;
}
