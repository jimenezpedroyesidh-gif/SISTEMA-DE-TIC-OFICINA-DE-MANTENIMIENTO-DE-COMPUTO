<?php
/** Vista de «Mantenimientos programados» (public/mantenimientos_programados.php). Solo presentación: recibe datos ya preparados por MantenimientosPage. */
$paginaTitulo = 'Mantenimientos'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php $tituloHeader = 'Mantenimientos'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1>Mantenimientos programados</h1>
        <p><?= count($mantenimientos) ?> en la lista<?= $filtroCampana ? ' de la campaña #' . (int)$filtroCampana : '' ?> · abre «Checklist de equipos» para ver el plano de la sala y tomar o asignar equipos</p>
      </div>
      <?php if ($puedeProgramar): ?>
      <div class="page-acciones">
        <button type="button" class="btn btn-sm" data-vf-abrir="vf-programar">+ Programar nuevo</button>
      </div>
      <?php endif; ?>
    </div>

<?php if ($error && !isset($_POST['programar'])): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
<?php if ($exito): ?><div class="exito mb-3"><?= h($exito) ?></div><?php endif; ?>
<?php if ($ticketYaElevadoA): ?>
  <div class="error mb-3">Este ticket ya fue elevado al mantenimiento #<?= (int)$ticketYaElevadoA ?>.</div>
<?php endif; ?>

<?php include __DIR__ . '/programar_form.php'; ?>

<?php include __DIR__ . '/filtro.php'; ?>

<?php include __DIR__ . '/listado.php'; ?>
  <?php include LAYOUT_DIR . '/app_page_main_end.php'; ?>

<?php if ($puedeProgramar): ?>
<script src="<?= h(asset('assets/mantenimientos.js')) ?>"></script>
<?php endif; ?>
<script src="<?= h(asset('assets/plano_sala.js')) ?>"></script>
</body>
</html>
