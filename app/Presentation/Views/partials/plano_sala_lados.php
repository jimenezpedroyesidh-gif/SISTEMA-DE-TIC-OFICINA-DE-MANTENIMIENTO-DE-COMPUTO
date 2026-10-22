<?php
/**
 * Cuerpo del plano de sala (común a la ficha del salón, la checklist de mantenimientos y el selector
 * de equipos con falla): selector de lado para el celular, los puestos a cada lado del pasillo, el
 * puesto docente si está al fondo y los equipos que aún no tienen puesto.
 *
 * Espera: $planoL (resultado de planoSala()) y $pintarL (callable que pinta un equipo como puesto).
 *  - Sin distribución guardada: reparto automático en dos bloques (como siempre).
 *  - Con distribución (distribucion_sala.php): filas de mesas tal como están en el salón; cada puesto
 *    con su número; los puestos sin equipo se ven vacíos para que el salón se reconozca.
 */
$pintarItemL = static function (array $it) use ($pintarL): void {
    if (!empty($it['_vacio'])) { echo puestoVacioSala($it); return; }
    $pintarL($it);
};
$filasL = $planoL['filas'] ?? null;
$hayDerL = $filasL === null ? (bool)$planoL['derecha'] : (bool)array_filter($filasL, fn($f) => !empty($f['der']));
?>
<?php if ($hayDerL): ?>
  <div class="sala-lados-nav" role="tablist" aria-label="Lado de la sala">
    <button type="button" class="sala-lado-btn active" data-sala-lado="izq" role="tab" aria-selected="true">◀ Izquierdo (<?= count($planoL['izquierda']) ?>)</button>
    <button type="button" class="sala-lado-btn" data-sala-lado="der" role="tab" aria-selected="false">Derecho (<?= count($planoL['derecha']) ?>) ▶</button>
  </div>
<?php endif; ?>

<?php if ($filasL === null): ?>
  <div class="sala-lados">
    <div class="sala-lado sala-lado-izq <?= $planoL['columnas'] === 3 ? 'sala-cols-3' : 'sala-cols-2' ?>" data-lado="izq">
      <?php foreach ($planoL['izquierda'] as $itL) $pintarL($itL); ?>
    </div>
    <?php if ($hayDerL): ?>
      <div class="sala-pasillo" aria-hidden="true"><span>Pasillo</span></div>
      <div class="sala-lado sala-lado-der <?= $planoL['columnas'] === 3 ? 'sala-cols-3' : 'sala-cols-2' ?>" data-lado="der">
        <?php foreach ($planoL['derecha'] as $itL) $pintarL($itL); ?>
      </div>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="sala-filas<?= $hayDerL ? ' sala-filas-pasillo' : '' ?>">
    <?php foreach ($filasL as $iFL => $filaL): ?>
      <?php foreach ($hayDerL ? ['izq', 'der'] : ['izq'] as $ladoL): ?>
        <?php if ($ladoL === 'der'): ?><div class="sala-fila-pasillo" aria-hidden="true"><?= $iFL === 0 ? '<span>Pasillo</span>' : '' ?></div><?php endif; ?>
        <div class="sala-fila-lado sala-lado-<?= $ladoL ?>" data-fila="<?= $iFL + 1 ?>">
          <?php foreach ($filaL[$ladoL] as $mesaL): ?>
            <div class="sala-mesa<?= $mesaL['pasillo'] ? ' sala-mesa-pasillo' : '' ?>">
              <?php foreach ($mesaL['puestos'] as $itL) $pintarItemL($itL); ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($hayDerL): ?>
  <button type="button" class="btn btn-ghost btn-sm sala-pasar" data-sala-pasar>Pasar al lado derecho ▶</button>
<?php endif; ?>

<?php if (!empty($planoL['fondo'])): ?>
  <div class="sala-fondo">
    <div class="sala-frente-puestos"><?php foreach ($planoL['fondo'] as $itL) $pintarItemL($itL); ?></div>
    <div class="sala-fondo-rotulo">Fondo del salón</div>
  </div>
<?php endif; ?>

<?php if (!empty($planoL['sinUbicar'])): ?>
  <div class="sala-sin-ubicar">
    <p class="sala-sin-ubicar-tit">Equipos sin puesto asignado en el plano (<?= count($planoL['sinUbicar']) ?>)</p>
    <div class="sala-frente-puestos"><?php foreach ($planoL['sinUbicar'] as $itL) $pintarL($itL); ?></div>
  </div>
<?php endif; ?>
