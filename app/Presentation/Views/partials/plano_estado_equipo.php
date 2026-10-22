<?php
/**
 * Estado real de un equipo del plano de sala: operativo o no (y por qué), piezas que tiene,
 * piezas que se llevaron y últimos traslados con quién solicitó, aprobó y ejecutó.
 * Espera $estadoEq (una fila de PlanoSalaService::estadoEquipos). Sin datos → no pinta nada.
 */
if (empty($estadoEq)) return;
?>
<div class="sala-estado<?= $estadoEq['operativo'] ? '' : ' sala-estado-no' ?>">
  <p class="sala-estado-linea">
    <strong><?= $estadoEq['operativo'] ? 'Operativo' : 'No operativo' ?></strong>
    <?php if ($estadoEq['motivo'] !== ''): ?> — <?= h($estadoEq['motivo']) ?><?php endif; ?>
  </p>
  <?php if (!empty($estadoEq['piezas']) || !empty($estadoEq['faltan'])): ?>
    <ul class="sala-piezas">
      <?php foreach ($estadoEq['piezas'] as $pz): ?>
        <li class="<?= $pz['estado'] === 'ACTIVO' ? '' : 'pieza-alerta' ?>"><?= h(etiquetaValor($pz['tipo'])) ?><?= $pz['codigo'] !== '' ? ' · ' . h($pz['codigo']) : '' ?><?= $pz['estado'] !== 'ACTIVO' ? ' (' . h(mb_strtolower(etiquetaValor($pz['estado']))) . ')' : '' ?></li>
      <?php endforeach; ?>
      <?php foreach ($estadoEq['faltan'] as $pz): ?>
        <li class="pieza-fuera"><?= h(etiquetaValor($pz['tipo'])) ?><?= $pz['codigo'] !== '' ? ' · ' . h($pz['codigo']) : '' ?> — fuera de la sala</li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if (!empty($estadoEq['traslados'])): ?>
    <p class="sala-estado-sub">Últimos traslados</p>
    <ol class="sala-traslados">
      <?php foreach ($estadoEq['traslados'] as $tr): ?>
        <li>
          <strong><?= h($tr['que']) ?></strong>: <?= h($tr['desde']) ?> → <?= h($tr['hacia']) ?>
          <span class="hint d-block"><?= h($tr['motivo']) ?><?= $tr['ticket'] !== '' ? ' · ticket ' . h($tr['ticket']) : '' ?> · <?= h($tr['fecha']) ?></span>
          <span class="hint d-block">
            <?php if ($tr['solicito'] !== ''): ?>Solicitó: <?= h($tr['solicito']) ?>. <?php endif; ?>
            <?php if ($tr['ejecuto'] !== ''): ?>Trasladó: <?= h($tr['ejecuto']) ?>. <?php endif; ?>
            Aprobó: <?= $tr['aprobo'] !== '' ? h($tr['aprobo']) : 'pendiente' ?>.
          </span>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</div>
