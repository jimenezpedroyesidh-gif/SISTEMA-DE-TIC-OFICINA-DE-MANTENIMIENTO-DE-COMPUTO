<?php
/**
 * Plano de sala de consulta (ficha por salón): mismo diseño que el checklist de mantenimientos.
 * Frente al tablero: videobeam y parlantes de la sala (componentes, no equipos de cómputo) y los
 * equipos tipo docente/videobeam heredados. Después, los puestos de cómputo a cada lado del pasillo.
 * Cada puesto muestra si está operativo (X roja si no) y al tocarlo se despliega su estado, piezas,
 * traslados (quién solicitó / trasladó / aprobó) y el enlace a su ficha.
 *
 * Espera: $planoIdCont (id único del contenedor), $planoEquipos (filas id_equipo, codigo, descripcion,
 * total_componentes), $planoEstados (PlanoSalaService::estadoEquipos) y $planoFrente
 * (PlanoSalaService::frentePorSalas()[id_sala]); opcional $planoDistribucion (distribución guardada del salón:
 * filas, mesas, número de puesto y punto de red). Comportamiento en assets/plano_sala.js.
 */
require_once LAYOUT_DIR . '/iconos.php';
$planoInfo = planoSala($planoEquipos ?? [], 'codigo', 'descripcion', $planoDistribucion ?? null);
$estadosInfo = $planoEstados ?? [];
$frenteInfo = $planoFrente ?? [];
$noOpEq = static fn(array $e): bool => isset($estadosInfo[(int)$e['id_equipo']]) && !$estadosInfo[(int)$e['id_equipo']]['operativo'];
$puestoInfo = static function (array $e) use ($noOpEq): void {
    $tipo = $e['_tipo'] ?? 'pc';
    $no = $noOpEq($e); ?>
    <button type="button" class="sala-puesto sala-puesto-<?= h($tipo) ?> <?= $no ? 'est-pendiente no-operativo' : 'est-realizado' ?>"
            data-puesto="e<?= (int)$e['id_equipo'] ?>" aria-pressed="false"
            aria-label="<?= h($e['codigo'] . ($no ? ' — no operativo' : ' — operativo')) ?>">
      <?= iconoSvg($tipo, 'ico sala-puesto-ico') ?>
      <?php if ($no): ?><span class="sala-puesto-x" aria-hidden="true"></span><?php endif; ?>
      <?= numPuestoSala($e) ?>
      <span class="sala-puesto-cod"><?= h($e['_cod'] ?? $e['codigo']) ?></span>
    </button>
<?php };
$totalNoOp = count(array_filter($planoEquipos ?? [], $noOpEq)) + count(array_filter($frenteInfo, fn($c) => !$c['estado']['operativo']));
?>
<div class="sala-ficha" id="<?= h($planoIdCont) ?>" data-plano-interactivo>
  <div class="sala-plano" data-lado-activo="izq">
    <ul class="sala-leyenda" aria-label="Convenciones">
      <li><span class="sala-swatch est-realizado"></span>Operativo</li>
      <li><span class="sala-swatch no-operativo"></span>No operativo<?= $totalNoOp ? " ($totalNoOp)" : '' ?></li>
    </ul>
    <div class="sala-frente">
      <div class="sala-tablero">Frente del salón · Tablero</div>
      <?php if ($frenteInfo || $planoInfo['frente']): ?>
        <div class="sala-frente-puestos">
          <?php foreach ($frenteInfo as $compFrente) { $modoFrente = 'puesto'; include __DIR__ . '/plano_frente_componente.php'; } ?>
          <?php foreach ($planoInfo['frente'] as $e) { if (!empty($e['_vacio'])) echo puestoVacioSala($e); else $puestoInfo($e); } ?>
        </div>
      <?php endif; ?>
    </div>
    <?php $planoL = $planoInfo; $pintarL = $puestoInfo; include PARCIALES_DIR . '/plano_sala_lados.php'; ?>
  </div>

  <aside class="sala-detalle-panel" aria-live="polite">
    <div class="sala-detalle-vacio">
      <?= iconoSvg('pc', 'ico sala-detalle-vacio-ico') ?>
      <p>Toca un equipo o el videobeam para ver si está operativo, sus piezas y quién lo trasladó.</p>
    </div>
    <?php foreach ($frenteInfo as $compFrente) { $modoFrente = 'detalle'; include __DIR__ . '/plano_frente_componente.php'; } ?>
    <?php foreach ($planoInfo['todos'] as $e): $est = $estadosInfo[(int)$e['id_equipo']] ?? null; ?>
      <div class="sala-detalle" data-detalle="e<?= (int)$e['id_equipo'] ?>" hidden>
        <div class="sala-detalle-head">
          <div>
            <strong class="sala-detalle-cod"><?= h($e['codigo']) ?></strong>
            <?php if (!empty($e['descripcion'])): ?><span class="hint d-block"><?= h($e['descripcion']) ?></span><?php endif; ?>
          </div>
          <span class="chip <?= $est && !$est['operativo'] ? 'danio' : 'solucionado' ?>"><?= $est && !$est['operativo'] ? 'No operativo' : 'Operativo' ?></span>
          <button type="button" class="sala-detalle-cerrar" data-sala-detalle-cerrar aria-label="Cerrar detalle"><?= iconoSvg('cerrar') ?></button>
        </div>
        <dl class="sala-datos">
          <?php if (($e['_num'] ?? '') !== ''): ?><dt>Puesto</dt><dd>N.º <?= h($e['_num']) ?></dd><?php endif; ?>
          <?php if (($e['_red'] ?? '') !== ''): ?><dt>Punto de red</dt><dd><?= h($e['_red']) ?></dd><?php endif; ?>
          <dt>Componentes</dt><dd><?= (int)($e['total_componentes'] ?? 0) ?></dd>
        </dl>
        <?php $estadoEq = $est; include __DIR__ . '/plano_estado_equipo.php'; ?>
        <details class="menu-acciones" data-menu>
          <summary class="btn btn-secondary btn-sm">Acciones ▾</summary>
          <div class="menu-acciones-lista">
            <a class="menu-acciones-item" href="ficha_equipo.php?id=<?= (int)$e['id_equipo'] ?>">Abrir ficha del equipo (componentes, historial y QR)</a>
          </div>
        </details>
      </div>
    <?php endforeach; ?>
  </aside>
</div>
