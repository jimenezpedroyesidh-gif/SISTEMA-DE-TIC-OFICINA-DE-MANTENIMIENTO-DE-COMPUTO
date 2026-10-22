<?php /** Vista de «Solicitudes de baja» (public/solicitudes_baja.php). Solo presentación: recibe datos ya preparados por SolicitudesBajaPage. */ ?>
<?php $paginaTitulo = 'Solicitudes de baja'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php $tituloHeader = 'Solicitudes de baja'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <div class="app-dashboard-welcome">
      <h1>Solicitudes de baja</h1>
      <p>Componentes propuestos para dar de baja: revisa el motivo y aprueba o rechaza.</p>
    </div>

<?php if ($error): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
<?php if ($exito): ?><div class="exito mb-3"><?= h($exito) ?></div><?php endif; ?>

<section class="panel-box">
  <h2 class="panel-title">Pendientes de aprobación</h2>
  <?php if (empty($pendientes)): ?>
    <p class="text-muted">No hay solicitudes de baja pendientes.</p>
  <?php else: ?>
    <div class="comp-list">
      <?php foreach ($pendientes as $s): ?>
        <div class="comp-card comp-card-static">
          <div class="comp-card-top">
            <span class="chip proceso">PENDIENTE</span>
            <strong><?= h($s['codigo_inventario']) ?></strong>
          </div>
          <div class="comp-card-body">
            <span><?= h(etiquetaValor($s['tipo'])) ?><?= $s['marca'] ? ' — ' . h($s['marca']) . ' ' . h($s['modelo']) : '' ?></span><br>
            <span class="text-muted">Solicitado por <?= h($s['solicito_nombre']) ?> el <?= h($s['fecha_solicitado']) ?></span><br>
            <p><strong>Concepto técnico:</strong> <?= nl2br(h($s['motivo'])) ?></p>
          </div>
          <form method="POST" class="mt-2">
            <?= csrfField() ?>
            <input type="hidden" name="id_solicitud" value="<?= (int)$s['id'] ?>">
            <div class="field">
              <label>Observación (opcional)</label>
              <textarea name="observacion_aprobacion" placeholder="Notas del admin sobre esta decisión"></textarea>
            </div>
            <div class="actions d-flex gap-2">
              <button type="submit" name="decision" value="aprobar" class="btn">Aprobar baja</button>
              <button type="submit" name="decision" value="rechazar" class="btn btn-ghost">Rechazar</button>
              <input type="hidden" name="resolver_solicitud" value="1">
            </div>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="panel-box mt-3">
  <details>
    <summary><h2 class="panel-title d-inline">Historial reciente de decisiones</h2></summary>
    <?php if (empty($resueltas)): ?>
      <p class="text-muted">Todavía no hay solicitudes resueltas.</p>
    <?php else: ?>
      <ul class="my-2 pl-18">
        <?php foreach ($resueltas as $r): ?>
          <li>
            <span class="chip <?= $r['estado'] === 'APROBADA' ? 'danio' : 'espera' ?>"><?= h(etiquetaValor($r['estado'])) ?></span>
            <?= h($r['codigo_inventario']) ?> — solicitó <?= h($r['solicito_nombre']) ?>,
            resolvió <?= h($r['aprobo_nombre'] ?? '—') ?> el <?= h($r['fecha_aprobado']) ?>
            <?php if ($r['observacion_aprobacion']): ?> — <em><?= h($r['observacion_aprobacion']) ?></em><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </details>
</section>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
