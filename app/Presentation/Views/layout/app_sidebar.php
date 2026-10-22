<?php
/**
 * Sidebar unificado. Cada rol ve únicamente los enlaces a páginas que
 * YA existen en public/ — no se inventa ninguna funcionalidad nueva.
 * "Notificaciones" no tiene página propia (solo la campanita del
 * header), así que no aparece aquí como enlace.
 *
 * Los ítems de cada rol salen de menuPorRol() (app/Support/rutas.php). Los grupos se renderizan como
 * <details> nativo y se abren solos si alguno de sus enlaces está activo; shell.js los hace acordeón (abrir
 * uno cierra los demás). Los íconos son SVG de línea (iconos.php), no emojis.
 *
 * Requiere: $_SESSION['role'] ya validado por la página que incluye
 * este archivo (cada page.php sigue haciendo su propio guard).
 */

require_once __DIR__ . '/iconos.php';

/**
 * ¿Este href corresponde a la página/parámetros actuales? Compara el
 * archivo (p. ej. "ficha_equipo.php") y, si el enlace trae query string
 * (p. ej. "?todas=1"), exige que ese mismo parámetro también esté
 * presente en la URL actual — así "Fichas por salón" y "Fichas por
 * equipo" (mismo archivo, distinto query) no quedan activos a la vez.
 */
function enlaceActivo(string $href, string $paginaActual, array $getActual): bool {
    [$archivo, $query] = array_pad(explode('?', $href, 2), 2, '');
    if ($archivo !== $paginaActual) return false;
    // Sin query: activo salvo que la URL actual sea otra vista del mismo archivo (?todas=1, ?vista=…).
    if ($query === '') return empty($getActual) || (!isset($getActual['todas']) && !isset($getActual['vista']));
    parse_str($query, $params);
    foreach ($params as $k => $v) {
        if (($getActual[$k] ?? null) != $v) return false;
    }
    return true;
}

$rolActual    = $_SESSION['role'] ?? '';
// Con el panel único (tickets.php) la página activa es siempre tickets.php, entre por la URL nueva o por una antigua.
$paginaActual = defined('PANEL_TICKETS') ? 'tickets.php' : basename($_SERVER['SCRIPT_NAME']);
// Crear/editar usuario es parte de «Usuarios»: se marca ese ítem del menú.
if ($paginaActual === 'register.php') $paginaActual = 'usuarios.php';
$getActual    = $_GET;
// La distribución de un salón es parte de «Salas / QR».
if ($paginaActual === 'distribucion_sala.php') { $paginaActual = 'gestionar_salas.php'; $getActual = []; }

$itemsMenu = menuPorRol((string)$rolActual, $shell ?? []);
?>
<?php
// Datos del usuario para la tarjeta del pie del menú (los prepara app_header.php, incluido justo antes).
$nombreMenu = $nombreShell ?? ($_SESSION['nombre_completo'] ?? '');
$rolMenu    = $rolShell ?? $rolActual;
$fotoMenu   = $usuarioShell['foto_perfil'] ?? null;
?>
<aside class="app-sidebar" id="appSidebar" aria-label="Menú principal">
  <div class="app-sidebar-head">
    <span class="app-sidebar-head-title">Menú</span>
    <button type="button" class="app-sidebar-close" data-shell="close-sidebar" aria-label="Cerrar menú"><?= iconoSvg('cerrar') ?></button>
  </div>

  <nav class="app-sidebar-nav" data-menu-acordeon>
    <?php foreach ($itemsMenu as $item): ?>
      <?php if ($item['tipo'] === 'seccion'): ?>
        <div class="app-sidebar-section"><?= h($item['etiqueta']) ?></div>
      <?php elseif ($item['tipo'] === 'link'): ?>
        <?php $activo = enlaceActivo($item['href'], $paginaActual, $getActual); ?>
        <a href="<?= h($item['href']) ?>" class="app-sidebar-link<?= $activo ? ' active' : '' ?>"<?= $activo ? ' aria-current="page"' : '' ?>>
          <span class="app-sidebar-icon"><?= iconoSvg($item['icono']) ?></span>
          <span class="app-sidebar-text"><?= h($item['etiqueta']) ?></span>
        </a>
      <?php else: ?>
        <?php
          $hayActivo = false;
          foreach ($item['hijos'] as $hijo) {
              if (enlaceActivo($hijo['href'], $paginaActual, $getActual)) { $hayActivo = true; break; }
          }
        ?>
        <details class="app-sidebar-group<?= $hayActivo ? ' has-active' : '' ?>"<?= $hayActivo ? ' open' : '' ?>>
          <summary class="app-sidebar-group-summary">
            <span class="app-sidebar-icon"><?= iconoSvg($item['icono']) ?></span>
            <span class="app-sidebar-text"><?= h($item['etiqueta']) ?></span>
            <span class="app-sidebar-caret"><?= iconoSvg('chevron') ?></span>
          </summary>
          <div class="app-sidebar-group-body">
            <?php foreach ($item['hijos'] as $hijo): ?>
              <?php $activo = enlaceActivo($hijo['href'], $paginaActual, $getActual); ?>
              <a href="<?= h($hijo['href']) ?>" class="app-sidebar-sublink<?= $activo ? ' active' : '' ?>"<?= $activo ? ' aria-current="page"' : '' ?>>
                <span class="app-sidebar-text"><?= h($hijo['etiqueta']) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>

  <div class="app-sidebar-footer">
    <a href="perfil.php" class="app-sidebar-user<?= $paginaActual === 'perfil.php' ? ' active' : '' ?>">
      <span class="app-avatar">
        <?php if (!empty($fotoMenu)): ?><img src="<?= h(urlFotoPerfil($fotoMenu)) ?>" alt=""><?php else: ?><?= h(inicialesUsuario($nombreMenu)) ?><?php endif; ?>
      </span>
      <span class="app-sidebar-user-text">
        <span class="app-user-name"><?= h($nombreMenu) ?></span>
        <span class="app-user-role"><?= h(etiquetaRol((string)$rolMenu)) ?> · Mi perfil</span>
      </span>
    </a>
    <a href="logout.php" class="app-sidebar-link app-sidebar-link-danger">
      <span class="app-sidebar-icon"><?= iconoSvg('salir') ?></span>
      <span class="app-sidebar-text">Cerrar sesión</span>
    </a>
  </div>
</aside>
<div class="app-sidebar-backdrop" id="appSidebarBackdrop" data-shell="close-sidebar"></div>
