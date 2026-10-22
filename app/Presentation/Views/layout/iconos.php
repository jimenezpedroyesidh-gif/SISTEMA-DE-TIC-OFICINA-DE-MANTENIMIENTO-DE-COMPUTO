<?php
/**
 * Íconos de línea (SVG en línea, 24x24, trazo de 1.75) para el menú lateral y el encabezado.
 * Reemplazan a los emojis del menú: se ven iguales en Windows, Android e iOS, heredan el
 * color del texto (currentColor) y no dependen de ninguna fuente ni CDN (compatible con la CSP).
 *
 * Uso: <?= iconoSvg('tickets') ?>  — nombre desconocido = punto neutro.
 */
if (!function_exists('iconoSvg')) {
    function iconoSvg(string $nombre, string $clase = 'ico'): string
    {
        static $trazos = [
            'tickets'    => '<path d="M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2v-2a2 2 0 0 0 0-4z"/><path d="M14 6v12" stroke-dasharray="2 2.5"/>',
            'dashboard'  => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
            'plus'       => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
            'calendario' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
            'qr'         => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3"/>',
            'escanear'   => '<path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2M7 12h10"/>',
            'herramienta'=> '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.8-3.8a6 6 0 0 1-7.9 7.9l-6.9 6.9a2.1 2.1 0 0 1-3-3l6.9-6.9a6 6 0 0 1 7.9-7.9z"/>',
            'reloj'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'caja'       => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8M12 13v8"/>',
            'sala'       => '<path d="M3 21h18M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6M9 10h.01M15 10h.01"/>',
            'monitor'    => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
            'archivo'    => '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9M10 13h4"/>',
            'bodega'     => '<path d="M3 21V9l9-6 9 6v12"/><path d="M7 21v-8h10v8M7 17h10"/>',
            'baja'       => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
            'usuarios'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6 6 0 0 1 3.5 6"/>',
            'usuario'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'conexion'   => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
            'base'       => '<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v12c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/>',
            'salir'      => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l-5-5 5-5M5 12h11"/>',
            'menu'       => '<path d="M4 7h16M4 12h16M4 17h16"/>',
            'cerrar'     => '<path d="M6 6l12 12M18 6L6 18"/>',
            'campana'    => '<path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10 21a2 2 0 0 0 4 0"/>',
            'tablero'    => '<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="5" rx="1.5"/><rect x="13" y="10" width="8" height="11" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/>',
            'buscar'     => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
            'chevron'    => '<path d="M6 9l6 6 6-6"/>',
            'grafica'    => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 6-6"/><path d="M16 8h4v4"/>',
            'escudo'     => '<path d="M12 3l8 3v6c0 4.5-3.4 8.4-8 9-4.6-.6-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
            // Puestos del plano de sala (monitor + torre, videobeam, parlante)
            'pc'         => '<rect x="2" y="5" width="13" height="10" rx="1.5"/><path d="M2 12.5h13M6.5 19h4M8.5 15v4"/><rect x="17" y="7" width="5" height="12" rx="1.2"/><path d="M18.5 10h2M18.5 12h2"/><circle cx="19.5" cy="16" r=".9"/>',
            'docente'    => '<rect x="2" y="4" width="20" height="12" rx="1.5"/><path d="M2 13h20M9 20h6M12 16v4"/><circle cx="12" cy="8.5" r="1.6"/>',
            'videobeam'  => '<rect x="2" y="8" width="20" height="9" rx="2"/><circle cx="15.5" cy="12.5" r="2.5"/><path d="M5.5 11.5h4M5.5 13.5h4M6 17v2M18 17v2"/>',
            'parlante'   => '<rect x="5" y="2" width="14" height="20" rx="2"/><circle cx="12" cy="14.5" r="3.5"/><circle cx="12" cy="7" r="1.5"/>',
        ];
        $svg = $trazos[$nombre] ?? '<circle cx="12" cy="12" r="2"/>';
        return '<svg class="' . htmlspecialchars($clase, ENT_QUOTES, 'UTF-8') . '" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"'
            . ' stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $svg . '</svg>';
    }
}
