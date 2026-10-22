<?php
/** Vista de «Salas y códigos QR» (public/gestionar_salas.php). Solo presentación: recibe datos ya preparados por SalasPage.
 *  Diseño: la lista de salas es el contenido principal; crear/editar/QR/unidades se abren en ventanas flotantes. */
$paginaTitulo = 'Salas y códigos QR'; $scriptsExtra = ['assets/salas.js' => 'defer']; include LAYOUT_DIR . '/app_page_head.php'; ?>
<script src="<?= h(asset('assets/vendor/qrcodejs-1.0.0/qrcode.min.js')) ?>"></script>
<?php $tituloHeader = 'Salas y códigos QR'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1>Salas y códigos QR</h1>
        <p><?= count($salas) ?> sala(s) registradas · cada sala tiene su QR para reportar daños<?= $ubicacionesGlpi ? ' · conectado a GLPI' : '' ?></p>
      </div>
      <div class="page-acciones">
        <button type="button" class="btn btn-sm" data-vf-abrir="vf-nueva-sala">+ Nueva sala</button>
        <button type="button" class="btn btn-ghost btn-sm" data-vf-abrir="vf-unidades">Unidades organizacionales</button>
        <?php if (!empty($sugerenciaGlpiPorSala)): ?>
          <form method="POST" class="form-inline">
            <?= csrfField() ?>
            <button type="submit" name="vincular_glpi_sugeridas" class="btn btn-secondary btn-sm" title="<?= h(implode(', ', array_map(fn($u) => $u['completo'], $sugerenciaGlpiPorSala))) ?>">Vincular <?= count($sugerenciaGlpiPorSala) ?> sala(s) con GLPI</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if($error): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
    <?php if($exito): ?><div class="exito mb-3"><?= h($exito) ?></div><?php endif; ?>

<?php include __DIR__ . '/listado.php'; ?>
<?php include __DIR__ . '/nueva_sala.php'; ?>
<?php include __DIR__ . '/unidades.php'; ?>
  <?php include LAYOUT_DIR . '/app_page_main_end.php'; ?>

</body>
</html>
