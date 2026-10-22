<?php
/**
 * Include reutilizable de la barra de búsqueda global. Se incluye junto
 * a la campanita (notificaciones_widget.php) dentro del
 * <div class="actions"> de cada header/topbar. No abre su propio <form>
 * y no necesita CSRF (es GET / solo lectura, igual que buscar_global.php).
 */
?>
<div class="buscador-global-widget">
  <?= function_exists('iconoSvg') ? iconoSvg('buscar', 'ico buscador-ico') : '' ?>
  <input type="search" id="buscadorGlobalInput" class="buscador-global-input" placeholder="Buscar en el sistema…" aria-label="Buscar en el sistema"
         autocomplete="off" data-shell="buscar-global">
  <div class="buscador-global-dropdown" id="buscadorGlobalDropdown" hidden></div>
</div>
