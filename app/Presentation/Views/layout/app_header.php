<?php
/**
 * Header unificado para las 4 pantallas (profesor, beca_trabajo,
 * técnico, admin). Requiere que la página que lo incluye ya tenga:
 * session_start(), csrf.php y ui_shell.php cargados, y $shell
 * (ShellData::cargar, que prepara el controlador), y $_SESSION['user_id'] presente.
 *
 * $tituloHeader (opcional): título de la página actual, mostrado
 * junto al logo en pantallas anchas. Si no se define, no se muestra.
 */

$usuarioShell = $shell['usuario'] ?? null;
$nombreShell  = $usuarioShell['nombre_completo'] ?? ($_SESSION['nombre_completo'] ?? '');
$rolShell     = $usuarioShell['role'] ?? ($_SESSION['role'] ?? '');
$dependShell  = $usuarioShell['unidad_nombre'] ?? $usuarioShell['dependencia'] ?? null;

$hrefInicioShell = rutaInicioPorRol((string)$rolShell) ?? 'index.php'; // tabla única en app/Support/auth.php
require_once __DIR__ . '/iconos.php';
?>
<header class="app-header">
  <button type="button" class="app-header-burger" data-shell="toggle-sidebar" aria-label="Abrir menú" aria-controls="appSidebar" aria-expanded="false"><?= iconoSvg('menu') ?></button>

  <a href="<?= h($hrefInicioShell) ?>" class="app-header-brand">
    <span class="app-header-brand-mark"><?= h(institucion('sigla')) ?></span>
    <span class="app-header-brand-text">
      <?= h(institucion('sistema')) ?><?php if (!empty($tituloHeader)): ?><span class="app-header-brand-sub"><?= h($tituloHeader) ?></span><?php endif; ?>
    </span>
  </a>

  <div class="app-header-tools">
    <?php if (!(function_exists('esBecaDeSecretaria') && esBecaDeSecretaria()) && !in_array($rolShell, [Rol::VIGILANTE, Rol::VISUALIZACION], true)) include PARCIALES_DIR . '/buscador_global_widget.php'; // la beca de secretaría y los roles de solo consulta no buscan tickets ?>
    <?php include PARCIALES_DIR . '/notificaciones_widget.php'; ?>

    <div class="app-user-menu">
      <button type="button" class="app-user-trigger" data-shell="toggle-usermenu" aria-haspopup="true" aria-expanded="false" aria-controls="appUserDropdown">
        <span class="app-avatar" aria-hidden="true">
          <?php if (!empty($usuarioShell['foto_perfil'])): ?>
            <img src="<?= h(urlFotoPerfil($usuarioShell['foto_perfil'])) ?>" alt="">
          <?php else: ?>
            <?= h(inicialesUsuario($nombreShell)) ?>
          <?php endif; ?>
        </span>
        <span class="app-user-trigger-text">
          <span class="app-user-name"><?= h($nombreShell) ?></span>
          <span class="app-user-role"><?= h(etiquetaRol($rolShell)) ?></span>
        </span>
        <span class="app-user-caret"><?= iconoSvg('chevron') ?></span>
      </button>
      <div class="app-user-dropdown" id="appUserDropdown" hidden>
        <div class="app-user-dropdown-head">
          <strong><?= h($nombreShell) ?></strong>
          <span class="text-muted"><?= h(etiquetaRol($rolShell)) ?><?php if ($dependShell): ?> · <?= h($dependShell) ?><?php endif; ?></span>
        </div>
        <a href="perfil.php"><?= iconoSvg('usuario') ?> Mi perfil</a>
        <a href="logout.php" class="app-user-dropdown-danger"><?= iconoSvg('salir') ?> Cerrar sesión</a>
      </div>
    </div>
  </div>
</header>
<script src="<?= h(asset('assets/shell.js')) ?>"></script>
