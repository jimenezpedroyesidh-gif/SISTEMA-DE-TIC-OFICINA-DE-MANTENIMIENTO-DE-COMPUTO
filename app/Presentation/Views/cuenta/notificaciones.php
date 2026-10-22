<?php /** Vista de «Notificaciones» (public/mis_notificaciones.php). Solo presentación: los datos los prepara NotificacionesPage. */ ?>
<?php $paginaTitulo = 'Notificaciones'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php $tituloHeader = 'Notificaciones'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1>Notificaciones</h1>
        <p><?= (int)($shell['noLeidas'] ?? 0) ?> sin leer · toca un aviso para ir directo a lo que corresponde.</p>
      </div>
      <div class="page-acciones">
        <nav class="segmento" aria-label="Filtrar notificaciones">
          <a href="mis_notificaciones.php" class="segmento-op<?= $soloNoLeidas ? '' : ' segmento-activo' ?>"<?= $soloNoLeidas ? '' : ' aria-current="page"' ?>>Todas</a>
          <a href="mis_notificaciones.php?ver=sin_leer" class="segmento-op<?= $soloNoLeidas ? ' segmento-activo' : '' ?>"<?= $soloNoLeidas ? ' aria-current="page"' : '' ?>>Sin leer</a>
        </nav>
        <?php if (!empty($shell['noLeidas'])): ?>
          <form method="POST" class="m-0">
            <?= csrfField() ?>
            <button type="submit" name="marcar_todas" class="btn btn-ghost btn-sm">Marcar todas como leídas</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <section class="panel-box mw-1100">
      <?php if (!$avisos['filas']): ?>
        <p class="text-muted"><?= $soloNoLeidas ? 'No tienes avisos sin leer.' : 'Todavía no tienes notificaciones.' ?></p>
      <?php else: ?>
      <div class="tabla-scroll">
        <table class="data">
          <thead><tr><th>Aviso</th><th>Tipo</th><th>Cuándo</th><th>Estado</th><th>Acciones</th></tr></thead>
          <tbody>
            <?php foreach ($avisos['filas'] as $n): ?>
              <tr class="<?= $n['leido'] ? '' : 'fila-activa' ?>">
                <td data-label="Aviso"><a href="mis_notificaciones.php?ir=<?= (int)$n['id_notificacion'] ?>"><?= $n['leido'] ? h($n['mensaje']) : '<strong>' . h($n['mensaje']) . '</strong>' ?></a></td>
                <td data-label="Tipo"><?= h(NotificationService::ETIQUETAS_TIPO[$n['tipo']] ?? $n['tipo']) ?></td>
                <td data-label="Cuándo"><span title="<?= h($n['fecha_creacion']) ?>"><?= h(NotificationService::haceCuanto((string)$n['fecha_creacion'])) ?></span></td>
                <td data-label="Estado"><?= $n['leido'] ? '<span class="chip">Leído</span>' : '<span class="chip proceso">Nuevo</span>' ?></td>
                <td data-label="Acciones"><a class="btn btn-ghost btn-sm" href="mis_notificaciones.php?ir=<?= (int)$n['id_notificacion'] ?>">Abrir</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= renderControlesPaginacion($avisos['pagina'], $avisos['paginas']) ?>
      <?php endif; ?>
    </section>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
