<?php /** Pestaña Departamento de Sistemas: una sola bandeja (solicitantes/QR + profesores), Pendientes y Firmados. */ ?>
  <section class="panel active" id="tickets-departamento">
    <h2>Tickets del Departamento de Sistemas</h2>
    <?php if(!empty($mensaje_bloqueo_inventario)): ?>
      <div class="error mb-3"><?= h($mensaje_bloqueo_inventario) ?></div>
    <?php endif; ?>
    <p class="hint mb-2">Como administrador supervisas: aprobar o rechazar un daño y elevarlo a mantenimiento de sala son acciones del técnico responsable. Los IDs «P-» son tickets de profesor.</p>
    <?php $bandeja = $bandejaAdminDepto; $prefijoPagina = 'depto'; $seccionAncla = 'tickets-departamento'; include __DIR__ . '/bandeja.php'; ?>
  </section>
