<?php
/**
 * Ficha de solución técnica → «Verificaciones»: cada verificación manual (responsable, fecha, equipo, versión,
 * resultado, observaciones y evidencias). Solo se agregan: una verificación nueva no borra las anteriores.
 * Variables del controlador: $verificaciones, $evidenciasPorVerificacion, $urlFicha.
 */
$chipResultado = ['CORRECTO' => 'solucionado', 'CON_OBSERVACIONES' => 'incompleto', 'FALLIDO' => 'danio'];
?>
<section class="panel-box">
  <h2 class="panel-title">Verificaciones <span class="hint">las más recientes primero</span></h2>
  <?php if (!$verificaciones): ?>
    <p class="text-muted">Todavía no hay verificaciones. Se registran en <a href="<?= h($urlFicha) ?>&amp;sec=despliegue">Despliegue por equipo</a>: marca los equipos y elige «Registrar verificación».</p>
  <?php else: ?>
  <div class="tabla-scroll">
    <table class="data">
      <thead><tr><th>Fecha</th><th>Equipo</th><th>Versión</th><th>Resultado</th><th>Observaciones</th><th>Responsable</th><th>Evidencias</th></tr></thead>
      <tbody>
        <?php foreach ($verificaciones as $vf): ?>
          <tr>
            <td data-label="Fecha"><?= h($fh($vf['fecha'])) ?></td>
            <td data-label="Equipo"><strong><?= h($vf['equipo_codigo']) ?></strong><br><span class="hint"><?= h($vf['abreviatura'] ?: $vf['nombre_sala']) ?></span></td>
            <td data-label="Versión"><?= $vf['version_numero'] !== null ? h($vf['version_numero']) : '—' ?></td>
            <td data-label="Resultado"><span class="chip <?= h($chipResultado[$vf['resultado']] ?? '') ?>"><?= h(SolucionDespliegueService::RESULTADOS[$vf['resultado']] ?? $vf['resultado']) ?></span></td>
            <td data-label="Observaciones"><?= nl2br(h($vf['observaciones'])) ?></td>
            <td data-label="Responsable"><?= h($vf['usuario']) ?></td>
            <td data-label="Evidencias"><?php $galeria = $evidenciasPorVerificacion[(int)$vf['id_verificacion']] ?? []; ?><?= $galeria ? '' : '<span class="text-muted">—</span>' ?><?php include __DIR__ . '/_galeria.php'; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
