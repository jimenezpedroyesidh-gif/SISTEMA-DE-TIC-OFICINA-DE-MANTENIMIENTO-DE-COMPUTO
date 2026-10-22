<?php /* Vista parcial: solo presentación; las variables las prepara public/tickets.php */ ?>
<?php /* ===================== PIEZAS EN BODEGA (SIN EQUIPO ASIGNADO) ===================== */ ?>
<?php /* Único acceso del beca a "inventario": piezas sueltas, no el catálogo
     completo de equipos/componentes. */ ?>
<section class="panel-box" id="bodega">
  <div class="sec-title">
    <h2 class="panel-title">Piezas en bodega</h2>
  </div>
  <?php if (empty($componentesEnBodega)): ?>
    <p class="text-muted">No hay piezas sueltas en este momento.</p>
  <?php else: ?>
    <div class="comp-list">
      <?php foreach ($componentesEnBodega as $c): ?>
        <div class="comp-card">
          <div class="comp-card-top">
            <strong><?= h(etiquetaValor($c['tipo'])) ?> — <?= h($c['codigo_inventario']) ?></strong>
            <span class="chip <?= $c['estado'] === 'EN_REVISION' ? 'danio' : 'espera' ?>"><?= h(etiquetaValor($c['estado'])) ?></span>
          </div>
          <div class="comp-card-body">
            <?php if ($c['marca'] || $c['modelo']): ?><span class="text-muted"><?= h(trim(($c['marca'] ?? '') . ' ' . ($c['modelo'] ?? ''))) ?></span><br><?php endif; ?>
            <?php if ($c['equipo_anterior_codigo']): ?>
              <span>Salió de: <?= h($c['equipo_anterior_codigo']) ?> (<?= h($c['sala_anterior_nombre'] ?? 'sala desconocida') ?>)</span>
            <?php else: ?>
              <span class="text-muted">Sin historial de equipo previo (repuesto nuevo)</span>
            <?php endif; ?>
          </div>
          <div class="mt-2">
            <a href="ficha_componente.php?id=<?= (int)$c['id_componente'] ?>" class="btn btn-secondary btn-sm">Ver historial</a>
            <?php if ($c['equipo_anterior_codigo']): ?>
              <a href="create_ticket_beca.php#form-cambio_pieza" class="btn btn-sm">Ya la reparé, devolverla</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if (($bodegaPaginado['pagina'] ?? 1) > 1 || !empty($bodegaPaginado['hay_siguiente'])): ?>
    <?php $urlBodega = fn(int $p) => '?' . http_build_query(array_merge(array_filter($_GET, 'is_scalar'), ['vista' => 'bodega', 'pagina_bodega' => $p])) . '#bodega'; ?>
    <nav class="paginacion" aria-label="Paginación de piezas en bodega">
      <?php if ($bodegaPaginado['pagina'] > 1): ?><a class="btn btn-secondary btn-sm pag-nav" href="<?= h($urlBodega($bodegaPaginado['pagina'] - 1)) ?>" aria-label="Página anterior">«</a><?php endif; ?>
      <span class="btn btn-sm" aria-current="page">Página <?= (int)$bodegaPaginado['pagina'] ?></span>
      <?php if ($bodegaPaginado['hay_siguiente']): ?><a class="btn btn-secondary btn-sm pag-nav" href="<?= h($urlBodega($bodegaPaginado['pagina'] + 1)) ?>" aria-label="Página siguiente">»</a><?php endif; ?>
    </nav>
  <?php endif; ?>
  <p class="text-muted mt-2 fs-85">Para devolver una pieza, ve a «Nuevo ticket» → «Cambio de pieza» y búscala en «Componente de reemplazo» por su código.</p>
</section>

