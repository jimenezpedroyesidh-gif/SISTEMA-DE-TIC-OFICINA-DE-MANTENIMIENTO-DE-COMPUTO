<?php
/**
 * Ficha de solución técnica → «Procedimientos» de instalación, ejecución, verificación y reversión, con video y
 * capturas. Editar crea una redacción nueva (la anterior queda en «Redacciones anteriores»). Variables del
 * controlador: $s, $procedimientos, $procedimientosAnteriores, $capturas, $fases, $permisos, $volvio, $formulario.
 */
$porFase = array_fill_keys(array_keys($fases), []);
foreach ($procedimientos as $p) {
    $porFase[$p['fase']][] = $p;
}
$puedeDocumentar = $permisos['gestionar'] && $s['estado'] !== 'RETIRADA';
$reemplazoPedido = $volvio && $formulario === 'procedimiento' ? (int)($_POST['id_reemplaza'] ?? 0) : -1; // ventana que vuelve abierta
?>
<?php if ($puedeDocumentar): ?>
  <p class="mb-3"><button type="button" class="btn btn-sm w-auto" data-vf-abrir="vf-proc-0">+ Nuevo procedimiento</button></p>
<?php endif; ?>

<?php foreach ($fases as $fase => $nombreFase): ?>
  <section class="panel-box">
    <h2 class="panel-title"><?= h($nombreFase) ?></h2>
    <?php if (!$porFase[$fase]): ?>
      <p class="text-muted">Sin procedimiento de <?= h(mb_strtolower($nombreFase)) ?>.</p>
    <?php endif; ?>
    <?php foreach ($porFase[$fase] as $p): ?>
      <article class="card-dashed mb-2">
        <h3 class="sec-title"><?= h($p['titulo']) ?></h3>
        <p class="hint"><?= h($p['autor_nombre']) ?> · <?= h($fh($p['creado_en'])) ?><?= $p['id_reemplaza'] ? ' · actualiza una redacción anterior' : '' ?></p>
        <p><?= nl2br(h($p['pasos'])) ?></p>
        <?php if ($p['enlace_video']): ?><p><a href="<?= h($p['enlace_video']) ?>" target="_blank" rel="noopener noreferrer">Ver video del procedimiento</a></p><?php endif; ?>
        <?php $galeria = $capturas[(int)$p['id_procedimiento']] ?? []; include __DIR__ . '/_galeria.php'; ?>
        <?php if ($puedeDocumentar): ?>
          <div class="flex-row flex-wrap gap-2">
            <button type="button" class="btn btn-secondary btn-sm w-auto" data-vf-abrir="vf-proc-<?= (int)$p['id_procedimiento'] ?>">Actualizar</button>
            <form method="POST" class="m-0" data-confirm="¿Dar de baja este procedimiento? Se conserva entre las redacciones anteriores.">
              <?= csrfField() ?>
              <input type="hidden" name="accion_st" value="baja_procedimiento">
              <input type="hidden" name="id_solucion" value="<?= (int)$s['id_solucion'] ?>">
              <input type="hidden" name="id_procedimiento" value="<?= (int)$p['id_procedimiento'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm w-auto">Dar de baja</button>
            </form>
          </div>
          <?php $fpProc = $p; $fpAbierta = $reemplazoPedido === (int)$p['id_procedimiento']; include __DIR__ . '/_form_procedimiento.php'; ?>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>

<?php if ($procedimientosAnteriores): ?>
  <section class="panel-box">
    <details>
      <summary>Redacciones anteriores y procedimientos dados de baja (<?= count($procedimientosAnteriores) ?>)</summary>
      <?php foreach ($procedimientosAnteriores as $p): ?>
        <article class="card-dashed mt-2">
          <h3 class="sec-title"><?= h($fases[$p['fase']] ?? $p['fase']) ?> · <?= h($p['titulo']) ?></h3>
          <p class="hint"><?= h($p['autor_nombre']) ?> · <?= h($fh($p['creado_en'])) ?></p>
          <p><?= nl2br(h($p['pasos'])) ?></p>
          <?php if ($p['enlace_video']): ?><p><a href="<?= h($p['enlace_video']) ?>" target="_blank" rel="noopener noreferrer">Video</a></p><?php endif; ?>
          <?php $galeria = $capturas[(int)$p['id_procedimiento']] ?? []; include __DIR__ . '/_galeria.php'; ?>
        </article>
      <?php endforeach; ?>
    </details>
  </section>
<?php endif; ?>

<?php if ($puedeDocumentar) { $fpProc = null; $fpAbierta = $reemplazoPedido === 0; include __DIR__ . '/_form_procedimiento.php'; } ?>
