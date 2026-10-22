<?php
/**
 * Pestaña «Mis solicitudes» del admin: el jefe también es docente del Departamento y pide requerimientos o
 * reporta daños como cualquier profesor (create_ticket_profesor.php, solo salas de su dependencia). Aquí los
 * sigue igual que un profesor: conversación con soporte, solución con fotos y PDF.
 * Usa: $pagMisSolicitudes, $interaccion, $estadosConv, $flashConversacion.
 */
?>
  <section class="panel" id="mis-solicitudes">
    <div class="sec-title">
      <h2>Mis solicitudes como docente</h2>
    </div>
    <p class="hint mb-2">Requerimientos (instalación de software, préstamos, cambio de sala) o daños de las salas de tu dependencia. Los atiende el equipo técnico como a cualquier docente.</p>
    <?php if (isset($_GET['creado'])): ?><div class="exito mb-3">Solicitud creada y enviada al equipo técnico.</div><?php endif; ?>
    <?php if ($flashConversacion && $flashConversacion['mensaje'] !== ''): ?><div class="mb-3 <?= $flashConversacion['ok'] ? 'exito' : 'error' ?>"><?= h($flashConversacion['mensaje']) ?></div><?php endif; ?>
    <?php $filasTabla = $pagMisSolicitudes['datos']; $conSolucion = false; include dirname(__DIR__) . '/tickets/_tabla_profesor.php'; ?>
    <?= renderControlesPaginacion($pagMisSolicitudes['pagina'], $pagMisSolicitudes['total_paginas'], [], 'pagina_mis', 'mis-solicitudes') ?>
  </section>
