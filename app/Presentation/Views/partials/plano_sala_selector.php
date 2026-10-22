<?php
/**
 * Selector de equipos afectados en forma de plano de sala (tipo «sillas de cine»), pintado en el servidor.
 * Lo usa el reporte de daño por QR de sala con sesión de profesor. Es la misma vista que arma
 * PlanoSala.selector() en assets/plano_sala.js para el formulario del panel del profesor.
 * Espera: $equiposSelector (filas id_equipo, codigo, descripcion), $nombreCampoSelector
 * y opcionalmente $seleccionadosSelector (ids ya marcados, p. ej. tras un error de validación) y
 * $estadosSelector (PlanoSalaService::estadoEquipos): los equipos no operativos salen con una X, no se
 * pueden marcar (ya se sabe que fallan) y debajo del plano se despliega por qué y quién los trasladó.
 * $frenteSelector (PlanoSalaService::frentePorSalas()[id_sala]): videobeam/parlantes de la sala, frente al
 * tablero; se marcan como componentes afectados (ids_componente_danio[]).
 * $distribucionSelector (opcional): distribución guardada del salón (filas, mesas y número de puesto).
 */
require_once LAYOUT_DIR . '/iconos.php';
$planoSel = planoSala($equiposSelector ?? [], 'codigo', 'descripcion', $distribucionSelector ?? null);
$marcadosSel = array_map('intval', $seleccionadosSelector ?? []);
$estadosSel = $estadosSelector ?? [];
$frenteSel = $frenteSelector ?? [];
$compsMarcadosSel = array_map('intval', $compsSeleccionadosSelector ?? []);
$puestoCompSel = static function (array $c) use ($compsMarcadosSel): void {
    $noOp = !$c['estado']['operativo'];
    $marcado = !$noOp && in_array((int)$c['id_componente'], $compsMarcadosSel, true); ?>
    <label class="sala-puesto sala-puesto-<?= h($c['tipo']) ?> sala-puesto-infra <?= $marcado ? 'est-seleccionado' : 'est-pendiente' ?><?= $noOp ? ' no-operativo' : '' ?>" title="<?= h($c['nombre'] . ' ' . $c['codigo'] . ($noOp ? ' — no operativo: ' . $c['estado']['motivo'] : '')) ?>">
      <input type="checkbox" class="sala-puesto-check" name="ids_componente_danio[]" value="<?= (int)$c['id_componente'] ?>" data-codigo="<?= h($c['nombre']) ?>"<?= $marcado ? ' checked' : '' ?><?= $noOp ? ' disabled' : '' ?>>
      <?= iconoSvg($c['tipo'], 'ico sala-puesto-ico') ?>
      <?php if ($noOp): ?><span class="sala-puesto-x" aria-hidden="true"></span><?php endif; ?>
      <span class="sala-puesto-cod"><?= h($c['nombre']) ?></span>
    </label>
<?php };
$puestoSel = static function (array $eq) use ($nombreCampoSelector, $marcadosSel, $estadosSel): void {
    $tipo = $eq['_tipo'] ?? 'pc';
    $noOp = isset($estadosSel[(int)$eq['id_equipo']]) && !$estadosSel[(int)$eq['id_equipo']]['operativo'];
    $marcado = !$noOp && in_array((int)$eq['id_equipo'], $marcadosSel, true); ?>
    <label class="sala-puesto sala-puesto-<?= h($tipo) ?> <?= $marcado ? 'est-seleccionado' : 'est-pendiente' ?><?= $noOp ? ' no-operativo' : '' ?>" title="<?= h($eq['codigo'] . ($noOp ? ' — no operativo: ' . $estadosSel[(int)$eq['id_equipo']]['motivo'] : (!empty($eq['descripcion']) ? ' — ' . $eq['descripcion'] : ''))) ?>">
      <input type="checkbox" class="sala-puesto-check" name="<?= h($nombreCampoSelector) ?>" value="<?= (int)$eq['id_equipo'] ?>" data-codigo="<?= h($eq['codigo']) ?>"<?= $marcado ? ' checked' : '' ?><?= $noOp ? ' disabled' : '' ?>>
      <?= iconoSvg($tipo, 'ico sala-puesto-ico') ?>
      <?php if ($noOp): ?><span class="sala-puesto-x" aria-hidden="true"></span><?php endif; ?>
      <?= numPuestoSala($eq) ?>
      <span class="sala-puesto-cod"><?= h($eq['_cod'] ?? $eq['codigo']) ?></span>
    </label>
<?php };
?>
<div class="sala-plano sala-plano-selector" data-lado-activo="izq" data-plano-selector>
  <ul class="sala-leyenda" aria-label="Convenciones">
    <li><span class="sala-swatch est-pendiente"></span>Disponible</li>
    <li><span class="sala-swatch est-seleccionado"></span>Con falla (seleccionado)</li>
    <li><span class="sala-swatch no-operativo"></span>No operativo</li>
  </ul>
  <div class="sala-frente">
    <div class="sala-tablero">Frente del salón · Tablero</div>
    <?php if ($planoSel['frente'] || $frenteSel): ?>
      <div class="sala-frente-puestos"><?php foreach ($frenteSel as $c) $puestoCompSel($c); ?><?php foreach ($planoSel['frente'] as $eq) { if (!empty($eq['_vacio'])) echo puestoVacioSala($eq); else $puestoSel($eq); } ?></div>
    <?php endif; ?>
  </div>
  <?php $planoL = $planoSel; $pintarL = $puestoSel; include PARCIALES_DIR . '/plano_sala_lados.php'; ?>
  <p class="sala-seleccion-resumen" data-sala-resumen>Ningún equipo seleccionado (opcional).</p>
  <?php $fueraSel = array_filter($equiposSelector ?? [], fn($e) => isset($estadosSel[(int)$e['id_equipo']]) && !$estadosSel[(int)$e['id_equipo']]['operativo']); ?>
  <?php $fueraCompSel = array_filter($frenteSel, fn($c) => !$c['estado']['operativo']); ?>
  <?php if ($fueraSel || $fueraCompSel): ?>
    <details class="sala-fuera">
      <summary>Equipos no operativos (<?= count($fueraSel) + count($fueraCompSel) ?>): ver qué les falta y quién los trasladó</summary>
      <?php foreach ($fueraCompSel as $cF): ?>
        <div class="sala-fuera-item">
          <strong><?= h($cF['nombre'] . ' · ' . $cF['codigo']) ?></strong>
          <?php $estadoEq = $cF['estado']; include __DIR__ . '/plano_estado_equipo.php'; ?>
        </div>
      <?php endforeach; ?>
      <?php foreach ($fueraSel as $eqF): ?>
        <div class="sala-fuera-item">
          <strong><?= h($eqF['codigo']) ?></strong>
          <?php $estadoEq = $estadosSel[(int)$eqF['id_equipo']]; include __DIR__ . '/plano_estado_equipo.php'; ?>
        </div>
      <?php endforeach; ?>
    </details>
  <?php endif; ?>
</div>
