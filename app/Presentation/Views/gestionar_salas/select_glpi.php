<?php
/**
 * Campo «Ubicación en GLPI» (agrupado por ubicación padre). Solo aparece si hay conexión con la base de GLPI.
 * Espera $selectGlpiId, $glpiSeleccionada (id o null) y $ubicacionesGlpiAgrupadas. Con data-glpi-auto, salas.js
 * elige sola la ubicación cuyo nombre coincide con el nombre/abreviatura escritos (hasta que el usuario la cambie).
 */
?>
<div class="field field-full">
  <label for="<?= h($selectGlpiId) ?>">Ubicación en GLPI</label>
  <?php if (empty($ubicacionesGlpiAgrupadas)): ?>
    <p class="hint m-0">Sin conexión con GLPI. Cuando el administrador la configure en «Conexiones», aquí se detectará sola la ubicación de la sala.</p>
  <?php else: ?>
    <select id="<?= h($selectGlpiId) ?>" name="glpi_location_id" data-glpi-auto>
      <option value="">— Detectar automáticamente / sin vincular —</option>
      <?php foreach ($ubicacionesGlpiAgrupadas as $grupo => $ubs): ?>
        <optgroup label="<?= h($grupo) ?>">
          <?php foreach ($ubs as $ub): ?>
            <option value="<?= (int)$ub['id'] ?>" data-nombre="<?= h($ub['nombre']) ?>"<?= $glpiSeleccionada === (int)$ub['id'] ? ' selected' : '' ?>><?= h($ub['nombre']) ?></option>
          <?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
    <span class="hint" data-glpi-aviso>Se detecta sola por el nombre de la sala; puedes cambiarla.</span>
  <?php endif; ?>
</div>
