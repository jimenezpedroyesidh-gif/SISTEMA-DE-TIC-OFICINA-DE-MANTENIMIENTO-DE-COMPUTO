<?php
/** Vista de «Inventario de Componentes» (public/gestionar_inventario.php). Solo presentación: recibe datos ya preparados por GestionarInventarioPage. */
$paginaTitulo = 'Inventario de componentes'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php $tituloHeader = 'Inventario de componentes'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1>Inventario de componentes</h1>
        <p>Busca una pieza por su código de inventario o serial, o registra una nueva.</p>
      </div>
      <div class="page-acciones">
        <?php if ($puedeEditar): ?><button type="button" class="btn btn-sm" data-vf-abrir="vf-registrar-componente">+ Registrar componente</button><?php endif; ?>
        <?php if (in_array($rol, [Rol::ADMIN,Rol::TECNICO], true)): ?>
        <details class="menu-acciones" data-menu>
          <summary class="btn btn-ghost btn-sm">Ir a ▾</summary>
          <div class="menu-acciones-lista">
            <a class="menu-acciones-item" href="ficha_equipo.php">Fichas por salón (plano de salas)</a>
            <a class="menu-acciones-item" href="revisar_componentes_bodega.php">Revisar en bodega</a>
          </div>
        </details>
        <?php endif; ?>
      </div>
    </div>

<?php if ($error): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
<?php if ($exito): ?><div class="exito mb-3"><?= h($exito) ?></div><?php endif; ?>

<div class="barra-busqueda">
  <?= buscadorHtml(['id' => 'q', 'valor' => $q, 'placeholder' => 'Código de inventario o serial (ej: INV-0231 o SN123ABC)', 'etiqueta' => 'Buscar componente por código de inventario o serial', 'limpiar' => 'gestionar_inventario.php', 'autofocus' => $q === '']) ?>
  <p class="barra-busqueda-pie">
    <?php if ($q !== ''): ?>
      <span class="barra-busqueda-resultado"><?= count($resultados) ?> resultado<?= count($resultados) === 1 ? '' : 's' ?> para «<?= h($q) ?>»</span>
    <?php else: ?>
      <span>Escribe el código pegado en la pieza o su serial de fábrica.</span>
    <?php endif; ?>
  </p>
</div>

<?php if ($q !== ''): ?>
<section class="panel-box">
  <h2 class="panel-title">Resultados</h2>
  <?php if ($q !== ''): ?>
    <?php if (empty($resultados)): ?>
      <p class="text-muted m-0">Sin resultados para «<?= h($q) ?>». Revisa el código o busca por el serial.</p>
    <?php else: ?>
      <div class="comp-list">
        <?php foreach ($resultados as $c): ?>
          <a class="comp-card" href="ficha_componente.php?id=<?= (int)$c['id_componente'] ?>">
            <div class="comp-card-top">
              <span class="chip <?= $c['estado']==='ACTIVO'?'solucionado':($c['estado']==='EN_REVISION'?'proceso':'danio') ?>"><?= h(etiquetaValor($c['estado'])) ?></span>
              <strong><?= h($c['codigo_inventario']) ?></strong>
            </div>
            <div class="comp-card-body">
              <span><?= h(etiquetaValor($c['tipo'])) ?><?= $c['marca'] ? ' — ' . h($c['marca']) . ' ' . h($c['modelo']) : '' ?></span><br>
              <span class="text-muted">Serial: <?= h($c['serial']) ?></span><br>
              <span class="text-muted">
                <?= $c['equipo_codigo'] ? h($c['nombre_sala']) . ' — ' . h($c['equipo_codigo']) : 'En bodega / sin asignar' ?>
              </span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($puedeEditar): ?>
<?php /* Registro de componente nuevo: ventana flotante (se reabre si el alta volvió con error) */ ?>
<?= vfAbrir('vf-registrar-componente', 'Registrar componente nuevo', 'Pieza de Equipos TI; puedes dejarla en bodega o asignarla a un equipo.', $error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST', 'lg') ?>
  <form method="POST" class="form-grid">
    <?= csrfField() ?>
    <div class="field">
      <label for="tipo">Tipo</label>
      <select id="tipo" name="tipo" required>
        <option value="">-- Seleccione --</option>
        <?php foreach (ComponenteService::ETIQUETAS_EQUIPO_TI as $valorTipo => $etiquetaTipo): ?>
          <option value="<?= h($valorTipo) ?>"><?= h($etiquetaTipo) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="serial">Serial (de fábrica)</label>
      <input type="text" id="serial" name="serial" required>
    </div>
    <div class="field">
      <label for="codigo_inventario">Código de inventario (etiqueta física)</label>
      <input type="text" id="codigo_inventario" name="codigo_inventario" required>
    </div>
    <div class="field">
      <label for="marca">Marca</label>
      <input type="text" id="marca" name="marca">
    </div>
    <div class="field">
      <label for="modelo">Modelo</label>
      <input type="text" id="modelo" name="modelo">
    </div>
    <div class="field field-full">
      <label for="descripcion">Descripción</label>
      <input type="text" id="descripcion" name="descripcion" maxlength="150">
    </div>
    <div class="field field-full">
      <label for="id_equipo_inicial">Asignar a equipo (opcional)</label>
      <select id="id_equipo_inicial" name="id_equipo_inicial">
        <option value="">-- Sin asignar (bodega) --</option>
        <?php foreach ($equipos as $eq): ?>
          <option value="<?= (int)$eq['id_equipo'] ?>"><?= h($eq['nombre_sala']) ?> — <?= h($eq['codigo']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" name="crear_componente" class="btn field-full">Registrar componente</button>
  </form>
<?= vfCerrar() ?>
<?php endif; ?>

  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
