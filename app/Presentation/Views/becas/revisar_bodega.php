<?php /** Vista de «Revisar componentes en bodega» (public/revisar_componentes_bodega.php). Solo presentación: recibe datos ya preparados por RevisarComponentesBodegaPage. */ ?>
<?php $paginaTitulo = 'Componentes en bodega'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php $tituloHeader = 'Componentes en bodega'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <div class="app-dashboard-welcome">
      <h1>Componentes en revisión</h1>
      <p>Componentes en bodega pendientes de diagnóstico: decide si vuelven a operar o se dan de baja.</p>
    </div>

<?php if ($error): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
<?php if ($exito): ?><div class="exito mb-3"><?= h($exito) ?></div><?php endif; ?>

<section class="panel-box">
  <?php if (empty($enRevision)): ?>
    <p class="text-muted">No hay componentes en bodega esperando revisión.</p>
  <?php else: ?>
    <div class="comp-list">
      <?php foreach ($enRevision as $c): ?>
        <div class="comp-card comp-card-static">
          <div class="comp-card-top">
            <span class="chip proceso">EN_REVISION</span>
            <strong><?= h($c['codigo_inventario']) ?></strong>
          </div>
          <div class="comp-card-body">
            <span><?= h(etiquetaValor($c['tipo'])) ?><?= $c['marca'] ? ' — ' . h($c['marca']) . ' ' . h($c['modelo']) : '' ?></span><br>
            <span class="text-muted">Serial: <?= h($c['serial']) ?></span>
          </div>
          <details class="mt-2">
            <summary>Decidir destino</summary>
            <form method="POST" class="mt-2">
              <?= csrfField() ?>
              <input type="hidden" name="id_componente" value="<?= (int)$c['id_componente'] ?>">
              <div class="field">
                <label>Nuevo estado</label>
                <select name="nuevo_estado" required>
                  <option value="ACTIVO">Activo (vuelve a disponible)</option>
                  <option value="DEFECTUOSO">Defectuoso</option>
                </select>
              </div>
              <div class="field">
                <label>Observación</label>
                <textarea name="observacion" placeholder="Ej: probado, funciona bien / no enciende, se deja como defectuoso"></textarea>
              </div>
              <button type="submit" name="revisar_componente" class="btn">Guardar decisión</button>
            </form>
          </details>
          <details class="mt-2">
            <summary>Solicitar baja</summary>
            <form method="POST" class="mt-2">
              <?= csrfField() ?>
              <input type="hidden" name="id_componente" value="<?= (int)$c['id_componente'] ?>">
              <div class="field">
                <label>Concepto técnico del daño</label>
                <textarea name="concepto_tecnico" required placeholder="Ej: fuente dañada, no hay repuesto disponible en bodega — sin arreglo posible"></textarea>
              </div>
              <button type="submit" name="solicitar_baja" class="btn btn-secondary">Enviar solicitud de baja al admin</button>
            </form>
          </details>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="text-muted fs-85 mt-2"><?= (int)$pagRevision['total'] ?> en revisión</p>
    <?= renderControlesPaginacion($pagRevision['pagina'], $pagRevision['total_paginas'], [], 'pagina_revision') ?>
  <?php endif; ?>
</section>

<section class="panel-box mt-3">
  <h2 class="panel-title">Componentes defectuosos sin solicitud de baja</h2>
  <?php if (empty($defectuosos)): ?>
    <p class="text-muted">No hay componentes defectuosos pendientes de decidir su baja.</p>
  <?php else: ?>
    <div class="comp-list">
      <?php foreach ($defectuosos as $c): ?>
        <div class="comp-card comp-card-static">
          <div class="comp-card-top">
            <span class="chip danio">DEFECTUOSO</span>
            <strong><?= h($c['codigo_inventario']) ?></strong>
          </div>
          <div class="comp-card-body">
            <span><?= h(etiquetaValor($c['tipo'])) ?><?= $c['marca'] ? ' — ' . h($c['marca']) . ' ' . h($c['modelo']) : '' ?></span>
          </div>
          <details class="mt-2">
            <summary>Solicitar baja</summary>
            <form method="POST" class="mt-2">
              <?= csrfField() ?>
              <input type="hidden" name="id_componente" value="<?= (int)$c['id_componente'] ?>">
              <div class="field">
                <label>Concepto técnico del daño</label>
                <textarea name="concepto_tecnico" required placeholder="Ej: fuente dañada, no hay repuesto disponible en bodega — sin arreglo posible"></textarea>
              </div>
              <button type="submit" name="solicitar_baja" class="btn btn-secondary">Enviar solicitud de baja al admin</button>
            </form>
          </details>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="text-muted fs-85 mt-2"><?= (int)$pagDefectuosos['total'] ?> defectuosos sin solicitud</p>
    <?= renderControlesPaginacion($pagDefectuosos['pagina'], $pagDefectuosos['total_paginas'], [], 'pagina_defectuosos') ?>
  <?php endif; ?>
</section>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
