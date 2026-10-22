<?php /** Pestaña Otras dependencias: una sola bandeja, Pendientes y Firmados (solo supervisión). */ ?>
  <?php /* Externos: formulario clásico de solicitante sin sala vinculada, y QR/profesores de salas fuera de Sistemas. */ ?>
  <section class="panel" id="tickets-externos">
    <h2>Tickets de otras dependencias</h2>
    <p class="nota-info mb-3">Solo supervisión. Por ahora el equipo trabaja únicamente con el Departamento de Sistemas e Informática:
      un técnico ve y atiende estos tickets solo si le activas «Ver y atender tickets de otras dependencias» en <a href="usuarios.php">Usuarios</a>.</p>

    <?php $bandeja = $bandejaAdminExt; $prefijoPagina = 'ext'; $seccionAncla = 'tickets-externos'; include __DIR__ . '/bandeja.php'; ?>
  </section>
