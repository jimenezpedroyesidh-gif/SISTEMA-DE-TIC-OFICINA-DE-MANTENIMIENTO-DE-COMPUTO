<?php
/**
 * Color de marca para salidas que NO leen CSS (PDF de FPDF). Fuente única: --color-principal (panel de
 * personalización de public/assets/tokens.css; --brand-500 deriva de él). Si no se puede leer o no es un hex
 * válido, devuelve el rojo institucional por defecto.
 */
if (!function_exists('marcaRgb')) {
    /** @return int[] [r, g, b] */
    function marcaRgb(): array
    {
        static $rgb = null;
        if ($rgb !== null) {
            return $rgb;
        }
        $rgb = [188, 0, 23];
        $css = @file_get_contents(__DIR__ . '/../../public/assets/tokens.css');
        if (is_string($css) && preg_match('/--(?:color-principal|brand-500):\s*#([0-9a-fA-F]{6})\b/', $css, $m)) {
            $rgb = [hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2))];
        }
        return $rgb;
    }
}
