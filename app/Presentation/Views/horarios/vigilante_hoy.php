<?php
/**
 * Vista de horarios — rol vigilante: lo que pasa HOY en cada sala, para saber si se puede prestar.
 * Las salas con mantenimiento programado o en proceso salen primero y en rojo («No prestar»).
 * Solo presentación: $hoySalas lo prepara HorarioCalendario::armar().
 */
$etiquetaOrigen = ['horario' => 'Clase', 'mantenimiento' => 'Mantenimiento', 'prestamo' => 'Préstamo'];
$ahoraHHMM = date('H:i');
?>
<section class="panel-box">
  <h2 class="panel-title">Hoy en las salas <span class="hint"><?= h(HorarioService::DIAS[(int)date('N')] ?? '') ?> <?= date('j/m/Y') ?></span></h2>
  <?php if (!$esSemanaActual): ?>
    <p class="text-muted">Estás viendo otra semana. <a href="<?= h($linkSemanaHoy) ?>">Volver a hoy</a>.</p>
  <?php elseif (empty($hoySalas)): ?>
    <p class="text-muted">Hoy no hay clases, préstamos ni mantenimientos registrados en las salas.</p>
  <?php else: ?>
  <div class="hg-hoy-grid">
    <?php foreach ($hoySalas as $hs):
        $ocupadaAhora = false;
        foreach ($hs['bloques'] as $b) { if ($b['hora_inicio'] <= $ahoraHHMM && $ahoraHHMM < $b['hora_fin']) { $ocupadaAhora = true; break; } }
    ?>
      <article class="hg-hoy-sala<?= !empty($hs['mantenimiento']) ? ' hg-hoy-no-prestar' : '' ?>">
        <h3>
          <span title="<?= h($hs['nombre']) ?>"><?= h($hs['abrev'] ?: $hs['nombre']) ?></span>
          <?php if (!empty($hs['mantenimiento'])): ?><span class="hg-hoy-aviso">No prestar · mantenimiento</span>
          <?php elseif ($ocupadaAhora): ?><span class="chip proceso">Ocupada ahora</span>
          <?php else: ?><span class="chip solucionado">Libre ahora</span><?php endif; ?>
        </h3>
        <ul>
          <?php foreach ($hs['bloques'] as $b): ?>
            <li><strong><?= h($b['hora_inicio'] . '–' . $b['hora_fin']) ?></strong> · <?= h($etiquetaOrigen[$b['origen']] ?? $b['origen']) ?><?php
              if ($b['origen'] === 'mantenimiento') echo ' — ' . h((string)strtok((string)$b['detalle'], ':')) . (!empty($b['estado']) ? ' (' . h(etiquetaValor((string)$b['estado'])) . ')' : '');
              elseif ($b['origen'] === 'horario' && !empty($b['usuario_nombre'])) echo ' — ' . h($b['usuario_nombre']);
            ?></li>
          <?php endforeach; ?>
        </ul>
      </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<div class="hg-leyenda mb-3">
  <span class="hg-leyenda-item"><i class="hg-swatch bloque-clase"></i> Clase</span>
  <span class="hg-leyenda-item"><i class="hg-swatch bloque-mantenimiento"></i> Mantenimiento (no prestar)</span>
  <span class="hg-leyenda-item"><i class="hg-swatch bloque-prestamo"></i> Préstamo</span>
  <span class="hg-leyenda-item"><i class="hg-swatch hg-swatch-ahora"></i> En curso ahora</span>
</div>
