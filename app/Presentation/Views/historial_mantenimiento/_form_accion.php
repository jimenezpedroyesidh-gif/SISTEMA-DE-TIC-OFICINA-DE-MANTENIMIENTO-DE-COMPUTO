<?php
/**
 * Vista parcial: formulario corto para convertir un hallazgo del análisis en una acción correctiva del semestre
 * siguiente. Recibe $accSala (id o 0), $accEquipo (código o ''), $accTipo, $accCausa, $filtros, $tecnicos.
 */
$destinoAcc = HorarioPeriodo::siguiente($filtros['periodo_analizado']);
$fechaObjetivoDef = date('Y-m-d', strtotime(MantenimientoHistorialService::rangoPeriodo($destinoAcc)[0] . ' +45 days'));
?>
<details class="mt-1">
  <summary>Definir acción</summary>
  <form method="POST" class="mt-2">
    <?= csrfField() ?>
    <input type="hidden" name="periodo_origen" value="<?= h($filtros['periodo_analizado']) ?>">
    <input type="hidden" name="periodo_destino" value="<?= h($destinoAcc) ?>">
    <input type="hidden" name="id_sala" value="<?= (int)$accSala ?>">
    <input type="hidden" name="equipo" value="<?= h($accEquipo) ?>">
    <div class="field">
      <label>Acción</label>
      <select name="tipo_accion">
        <?php foreach (MantenimientoHistorialService::TIPOS_ACCION as $k => $t): ?><option value="<?= h($k) ?>" <?= $k === $accTipo ? 'selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Causa</label>
      <input type="text" name="causa" maxlength="255" required value="<?= h($accCausa) ?>">
    </div>
    <div class="field">
      <label>Detalle (opcional)</label>
      <textarea name="descripcion" rows="2"></textarea>
    </div>
    <div class="form-grid">
      <div class="field">
        <label>Prioridad</label>
        <select name="prioridad">
          <?php foreach (MantenimientoHistorialService::PRIORIDADES as $k => $t): ?><option value="<?= h($k) ?>" <?= $k === 'MEDIA' ? 'selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Fecha objetivo</label>
        <input type="date" name="fecha_objetivo" required value="<?= h($fechaObjetivoDef) ?>">
      </div>
    </div>
    <div class="field">
      <label>Responsable</label>
      <select name="id_responsable">
        <option value="">-- Sin asignar --</option>
        <?php foreach ($tecnicos as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['nombre_completo']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <button type="submit" name="crear_accion" class="btn btn-sm">Guardar para <?= h($destinoAcc) ?></button>
  </form>
</details>
