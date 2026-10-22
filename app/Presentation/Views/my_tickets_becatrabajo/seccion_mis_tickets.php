<?php /* Vista parcial: solo presentación; las variables las prepara public/tickets.php */ ?>
<?php /* ===================== MIS TICKETS (creados por mí) ===================== */ ?>
<section class="panel-box" id="mis-tickets-beca">
  <div class="sec-title">
    <h2 class="panel-title">Mis tickets creados</h2>
  </div>
  <?php if (empty($tickets_mios)): ?>
    <p class="text-muted">Todavía no has creado tickets de mantenimiento de sala o cambio de pieza.</p>
  <?php else: ?>
  <table class="data">
    <thead>
      <tr><th>ID</th><th>Tipo</th><th>Sala / Equipo</th><th>Descripción</th><th>Estado</th><th>Técnico</th><th>Fecha</th></tr>
    </thead>
    <tbody>
      <?php foreach($tickets_mios as $m):
        $statusClass = claseChipEstadoTicket($m['estado'], true);
        $esCambioPieza = !empty($m['id_componente_saliente']) || !empty($m['id_componente_entrante']);
      ?>
      <tr>
        <td data-label="ID"><?= (int)$m['id'] ?></td>
        <td data-label="Tipo"><?= $esCambioPieza ? '<span class="badge danio">Cambio de pieza</span>' : '<span class="badge requerimiento">Mantenimiento sala</span>' ?></td>
        <td data-label="Sala / Equipo"><?= h($m['nombre_dependencia']) ?><?= $m['equipo'] ? ' — ' . h($m['equipo']) : '' ?></td>
        <td data-label="Descripción"><?= nl2br(h($m['descripcion'])) ?></td>
        <td data-label="Estado"><span class="chip <?= $statusClass ?>"><?= h($m['estado']) ?></span></td>
        <td data-label="Técnico"><?= $m['tecnico_nombre'] ? h($m['tecnico_nombre']) : 'No asignado' ?></td>
        <td data-label="Fecha"><?= h($m['fecha_creacion']) ?><?php if (!empty($m['mantenimiento_generado'])): ?><br><span class="hint">Generó mantenimiento #<?= (int)$m['mantenimiento_generado'] ?></span><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?= renderControlesPaginacion($resultMios['pagina'], $resultMios['total_paginas'], ['vista' => 'creados'], 'pagina_mios', 'mis-tickets-beca') ?>
  <?php endif; ?>
</section>

