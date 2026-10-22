<?php
/**
 * Include reutilizable de la campanita de notificaciones. Se incluye
 * dentro del <div class="actions"> de cada header/topbar de página
 * privada (mismo lugar que el buscador global del Módulo 5).
 *
 * Requiere que el archivo que lo incluye ya tenga: session_start(),
 * $shell (ShellData::cargar, preparado por el controlador), csrf.php cargados, y
 * $_SESSION['user_id'] presente (páginas privadas ya lo garantizan con
 * su propio guard de rol antes de llegar aquí).
 *
 * Cada aviso enlaza a mis_notificaciones.php?ir=ID (lo marca leído en el servidor y redirige a la página
 * correcta del rol). «Marcar todas» es un botón que dispara fetch() contra notificacion_marcar_leida.php.
 */
$idUsuarioNotif = (int)($_SESSION['user_id'] ?? 0);
// Contador y últimas notificaciones las precarga el controlador (ShellData::cargar → $shell); esta vista no
// usa PDO.
$noLeidasCount = (int)($shell['noLeidas'] ?? 0);
$ultimasNotif = $shell['ultimas'] ?? [];

?>
<div class="notif-widget" data-csrf="<?= h(csrfToken()) ?>">
  <button type="button" class="btn btn-ghost btn-sm notif-bell" data-shell="notif-toggle" aria-label="Notificaciones" aria-haspopup="true" aria-expanded="false">
    <?= iconoSvg('campana') ?><?php if ($noLeidasCount > 0): ?><span class="notif-badge" id="notifBadge"><?= $noLeidasCount > 99 ? '99+' : (int)$noLeidasCount ?></span><?php endif; ?>
  </button>
  <div class="notif-dropdown" id="notifDropdown" hidden>
    <div class="notif-dropdown-head">
      <strong>Notificaciones</strong>
      <button type="button" class="btn-link" data-shell="notif-todas">Marcar todas como leídas</button>
    </div>
    <div class="notif-dropdown-list" id="notifList">
      <?php if (empty($ultimasNotif)): ?>
        <p class="text-muted notif-empty">Sin notificaciones todavía.</p>
      <?php else: ?>
        <?php foreach ($ultimasNotif as $n): ?>
          <div class="notif-item <?= $n['leido'] ? 'leido' : 'no-leido' ?>" data-id="<?= (int)$n['id_notificacion'] ?>">
            <?php /* Se abre por mis_notificaciones.php?ir=: el servidor lo marca leído y lleva a la página correcta del rol. */ ?>
            <a href="mis_notificaciones.php?ir=<?= (int)$n['id_notificacion'] ?>">
              <?= h($n['mensaje']) ?>
              <span class="notif-fecha"><?= h((NotificationService::ETIQUETAS_TIPO[$n['tipo']] ?? 'Aviso') . ' · ' . NotificationService::haceCuanto((string)$n['fecha_creacion'])) ?></span>
            </a>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <a class="notif-ver-todas" href="mis_notificaciones.php">Ver todas las notificaciones</a>
  </div>
</div>
