<?php
/**
 * Campos de los datos de una solución técnica: los usa «Nueva solución» (catálogo) y «Editar datos» (ficha).
 *
 * Variables:
 *   $fsValores  valores actuales (lo que volvió del formulario con error, o los datos guardados)
 *   $fsPrefijo  prefijo de los id de los campos (dos formularios en la misma página no repiten id)
 *   $tipos, $tecnicos
 */
$fv = static fn(string $campo): string => (string)($fsValores[$campo] ?? '');
?>
<div class="form-grid">
  <div class="field">
    <label for="<?= h($fsPrefijo) ?>-nombre">Nombre</label>
    <input type="text" id="<?= h($fsPrefijo) ?>-nombre" name="nombre" maxlength="150" required value="<?= h($fv('nombre')) ?>" placeholder="Ej. GLPI Agent con inventario diario">
  </div>
  <div class="field">
    <label for="<?= h($fsPrefijo) ?>-codigo">Código (opcional)</label>
    <input type="text" id="<?= h($fsPrefijo) ?>-codigo" name="codigo" maxlength="20" value="<?= h($fv('codigo')) ?>" placeholder="Automático: ST-0001">
  </div>
  <div class="field">
    <label for="<?= h($fsPrefijo) ?>-tipo">Tipo</label>
    <select id="<?= h($fsPrefijo) ?>-tipo" name="id_tipo" required>
      <option value="">-- Elige --</option>
      <?php foreach ($tipos as $t): ?>
        <option value="<?= (int)$t['id_tipo'] ?>" <?= (int)$fv('id_tipo') === (int)$t['id_tipo'] ? 'selected' : '' ?> title="<?= h($t['descripcion']) ?>"><?= h($t['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="<?= h($fsPrefijo) ?>-responsable">Responsable técnico</label>
    <select id="<?= h($fsPrefijo) ?>-responsable" name="id_responsable">
      <option value="">-- Sin asignar --</option>
      <?php foreach ($tecnicos as $t): ?>
        <option value="<?= (int)$t['id'] ?>" <?= (int)$fv('id_responsable') === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['nombre_completo']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>
<div class="field">
  <label for="<?= h($fsPrefijo) ?>-descripcion">Descripción</label>
  <textarea id="<?= h($fsPrefijo) ?>-descripcion" name="descripcion" rows="3" required placeholder="Qué es y cómo funciona (script, política, agente…)"><?= h($fv('descripcion')) ?></textarea>
</div>
<div class="field">
  <label for="<?= h($fsPrefijo) ?>-objetivo">Objetivo</label>
  <textarea id="<?= h($fsPrefijo) ?>-objetivo" name="objetivo" rows="2" required placeholder="Qué problema resuelve o qué garantiza en los equipos"><?= h($fv('objetivo')) ?></textarea>
</div>
