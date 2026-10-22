<?php /** Ventana flotante «Nueva sala». Solo presentación: recibe los datos ya preparados por SalasPage.
 *  Si hay conexión con GLPI, ofrece sus ubicaciones agrupadas y salas.js elige sola la que coincide con el nombre. */ ?>
<?= vfAbrir('vf-nueva-sala', 'Nueva sala', 'Se genera su código QR de reporte al guardarla.', ($formularioConError ?? '') === 'vf-nueva-sala') ?>
  <?php if(($formularioConError ?? '') === 'vf-nueva-sala' && $error): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
  <form method="POST" class="form-grid" data-sala-glpi>
    <?= csrfField() ?>
    <div class="field">
      <label for="nombre_sala">Nombre de la sala</label>
      <input type="text" id="nombre_sala" name="nombre_sala" placeholder="Ej: SA405" required data-glpi-fuente>
    </div>
    <div class="field">
      <label for="abreviatura">Abreviatura</label>
      <input type="text" id="abreviatura" name="abreviatura" placeholder="Ej: LAB3" maxlength="10" data-glpi-fuente>
      <span class="hint">Se usa en horarios, QR y reportes.</span>
    </div>
    <div class="field field-full">
      <label for="id_unidad_nueva_sala">Dependencia / departamento</label>
      <select id="id_unidad_nueva_sala" name="id_unidad">
        <option value="">— Sin dependencia asignada —</option>
        <?php foreach($unidades as $u): ?>
          <option value="<?= (int)$u['id_unidad'] ?>"><?= h($u['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if(empty($unidades)): ?>
        <span class="hint">Todavía no hay ninguna dependencia creada. <?= $esAdmin ? 'Créala en «Unidades organizacionales».' : 'Pide a un administrador que cree la primera.' ?></span>
      <?php endif; ?>
    </div>
    <?php $selectGlpiId = 'glpi_nueva_sala'; $glpiSeleccionada = null; include __DIR__ . '/select_glpi.php'; ?>
    <button type="submit" name="crear_sala" class="btn field-full">Crear sala y generar QR</button>
  </form>
<?= vfCerrar() ?>
