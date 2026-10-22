<?php
/**
 * Vista de «Cumplimiento de SLA» (public/sla.php). Solo presentación: los datos los prepara SlaPage.
 * Pestañas: Tickets abiertos (semáforo) · Acuerdos y horario. Los formularios van en ventanas flotantes.
 */
$paginaTitulo = 'Cumplimiento de SLA'; include LAYOUT_DIR . '/app_page_head.php';
$tituloHeader = 'Acuerdos de servicio (SLA)'; include LAYOUT_DIR . '/app_page_body_start.php';
$abrirForm = ($flash && !$flash['ok']) ? (string)($flash['form'] ?? '') : '';
?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1>Acuerdos de servicio (SLA)</h1>
        <p><?= $vista === 'monitor'
            ? 'Tickets abiertos medidos contra su tiempo de solución, en horas hábiles. El aviso «por vencer» sale al ' . (int)$umbral . ' % del tiempo.'
            : 'Tiempos comprometidos por tipo y prioridad, horario de atención y días no hábiles. Todo se mide en horas hábiles.' ?></p>
      </div>
    </div>

    <?php if ($flash && $abrirForm === ''): ?>
      <div class="mb-3 <?= $flash['ok'] ? 'exito' : 'error' ?>"><?= h($flash['mensaje']) ?></div>
    <?php endif; ?>

<?php include __DIR__ . ($vista === 'monitor' ? '/sla_monitor.php' : '/sla_acuerdos.php'); ?>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
