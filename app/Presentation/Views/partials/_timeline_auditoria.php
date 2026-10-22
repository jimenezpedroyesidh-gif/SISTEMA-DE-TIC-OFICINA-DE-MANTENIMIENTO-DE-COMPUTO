<?php
/**
 * Lista de eventos de auditoría (ficha de equipo y ficha de componente).
 *
 * Variable: $eventosAuditoria = filas con accion, fecha_hora, usuario, detalle y cambios[] (campo, valor_anterior, valor_nuevo).
 */
?>
<ul class="timeline">
  <?php foreach ($eventosAuditoria as $evento): ?>
    <li class="timeline-item">
      <div class="timeline-head">
        <strong><?= h($evento['accion']) ?></strong>
        <span class="text-muted"><?= h($evento['fecha_hora']) ?></span>
      </div>
      <div class="timeline-body">
        <p class="text-muted">Por: <?= h($evento['usuario'] ?? 'Sistema') ?></p>
        <?php if ($evento['detalle']): ?><p><?= nl2br(h($evento['detalle'])) ?></p><?php endif; ?>
        <?php if (!empty($evento['cambios'])): ?>
          <ul>
            <?php foreach ($evento['cambios'] as $cambio): ?>
              <li><?= h($cambio['campo']) ?>: <em><?= h($cambio['valor_anterior'] ?? '—') ?></em> → <strong><?= h($cambio['valor_nuevo'] ?? '—') ?></strong></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </li>
  <?php endforeach; ?>
</ul>
