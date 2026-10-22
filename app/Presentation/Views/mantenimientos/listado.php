<?php /** Vista parcial de «Mantenimientos programados» (listado). Solo presentación: recibe datos ya preparados por MantenimientosPage. */ ?>
<section class="panel-box">
  <h2 class="panel-title">Próximos mantenimientos</h2>
  <?php if (empty($mantenimientos)): ?>
    <p class="text-muted">No hay mantenimientos programados<?= !$puedeVerCompleto ? ' asignados a ti' : '' ?>.</p>
  <?php else: ?>
    <table class="data">
      <thead>
        <tr><th>Fecha</th><th>Sala / Equipo</th><th>Tipo</th><th>Descripción</th><th>Responsable</th><th>Estado</th><th>Acción</th></tr>
      </thead>
      <tbody>
        <?php foreach ($mantenimientos as $m): include __DIR__ . '/fila.php'; endforeach; ?>
      </tbody>
    </table>
    <?php
    // Plano de cada sala (sección aparte, se abre con «Checklist de equipos» en la columna Acción).
    foreach ($mantenimientos as $m):
        $checklist = $checklistPorProgramado[$m['id_programado']] ?? [];
        if ($checklist) include __DIR__ . '/checklist.php';
    endforeach;
    ?>
  <?php endif; ?>
  <?php if (!empty($paginacionHistorial['total_historial'])): ?>
    <p class="text-muted fs-85 mt-2">Historial (realizados y cancelados): <?= (int)$paginacionHistorial['total_historial'] ?>, del más reciente al más antiguo.</p>
    <?= renderControlesPaginacion($paginacionHistorial['pagina'], $paginacionHistorial['total_paginas'], [], 'pagina_historial') ?>
  <?php endif; ?>
</section>
