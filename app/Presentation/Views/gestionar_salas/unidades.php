<?php /** Ventana flotante «Unidades organizacionales». Solo presentación: recibe los datos ya preparados por SalasPage.
 *  Lista única de dependencias/departamentos/oficinas/planes de estudio para todo el sistema; solo el admin crea. */ ?>
<?= vfAbrir('vf-unidades', 'Unidades organizacionales', 'Una sola lista de dependencias para todo el sistema (salas y usuarios).', !empty($formularioConErrorUnidad) && $error !== '', 'md') ?>
  <?php if(!empty($formularioConErrorUnidad) && $error): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
  <?php if(empty($unidades)): ?>
    <p class="text-muted">Todavía no hay ninguna unidad registrada.</p>
  <?php else: ?>
    <ul class="lista-simple mb-3">
      <?php foreach($unidades as $u): ?>
        <li>
          <strong><?= h($u['nombre']) ?></strong> <span class="text-muted">(<?= h(etiquetaValor($u['tipo'])) ?>)</span>
          <?php if($u['es_administradora_sistema']): ?><span class="chip solucionado">Administradora del sistema</span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if(!$esAdmin): ?>
    <p class="nota-info">Solo un administrador puede crear o modificar esta lista.</p>
  <?php else: ?>
  <h3 class="panel-subtitle">Crear unidad</h3>
  <form method="POST" class="form-grid">
    <?= csrfField() ?>
    <div class="field">
      <label for="nombre_unidad">Nombre</label>
      <input type="text" id="nombre_unidad" name="nombre_unidad" placeholder="Ej: Departamento de Redes" required>
    </div>
    <div class="field">
      <label for="tipo_unidad">Tipo</label>
      <select id="tipo_unidad" name="tipo_unidad" required>
        <option value="DEPARTAMENTO">Departamento</option>
        <option value="DEPENDENCIA">Dependencia</option>
        <option value="OFICINA">Oficina</option>
        <option value="PLAN_ESTUDIOS">Plan de estudios</option>
      </select>
    </div>
    <?php if(!empty($unidades)): ?>
    <div class="field field-full">
      <label for="id_unidad_padre">Unidad padre (opcional)</label>
      <select id="id_unidad_padre" name="id_unidad_padre">
        <option value="">— Ninguna (raíz) —</option>
        <?php foreach($unidades as $u): ?>
          <option value="<?= (int)$u['id_unidad'] ?>"><?= h($u['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="field-check field-full">
      <label><input type="checkbox" name="es_administradora_sistema" value="1"> Es la unidad administradora del sistema (resuelve los tickets; reemplaza a la marcada hoy)</label>
    </div>
    <button type="submit" name="crear_unidad" class="btn field-full">Crear unidad</button>
  </form>
  <?php endif; ?>
<?= vfCerrar() ?>
