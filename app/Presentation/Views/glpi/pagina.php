<?php
/** Vista de «Inventario GLPI» (public/glpi.php). Solo presentación: los datos los prepara GlpiPage. */
$paginaTitulo = 'Inventario GLPI'; include LAYOUT_DIR . '/app_page_head.php';
$tituloHeader = 'Inventario GLPI'; include LAYOUT_DIR . '/app_page_body_start.php';
$urlFiltro = function (?int $ubic, ?string $tipo): string {
    $q = array_filter(['ubicacion' => $ubic, 'tipo' => $tipo], fn($v) => $v !== null);
    return 'glpi.php' . ($q ? '?' . http_build_query($q) : '') . '#detalle';
};
$estadoCola = ['PENDIENTE' => 'espera', 'ENVIADO' => 'solucionado', 'ERROR' => 'danio'];
?>
    <div class="app-dashboard-welcome">
      <h1>Inventario GLPI</h1>
      <p>Inventario de GLPI agrupado por tipo y ubicación, y lo que el sistema le envía a GLPI. Datos de conexión en <a href="conexiones.php">Conexiones</a>.</p>
    </div>

    <?php if ($flash): ?>
      <div class="mb-3 <?= $flash['ok'] ? 'callout-success' : 'error' ?>"><?= h($flash['mensaje']) ?></div>
    <?php endif; ?>

    <div class="stat-row stat-row-4">
      <div class="stat-card"><span class="stat-value"><?= $inv['conectado'] ? (int)$inv['total'] : '—' ?></span><span class="stat-label">Activos en GLPI</span></div>
      <div class="stat-card"><span class="stat-value"><?= $inv['conectado'] ? (int)$inv['enSistema'] : '—' ?></span><span class="stat-label">También registrados en el sistema (mismo serial)</span></div>
      <div class="stat-card"><span class="stat-value"><?= (int)$conteoCola['PENDIENTE'] ?></span><span class="stat-label">Envíos pendientes a GLPI</span></div>
      <div class="stat-card"><span class="stat-value"><?= (int)$conteoCola['ERROR'] ?></span><span class="stat-label">Envíos con error</span></div>
    </div>

    <?php if (!$inv['conectado']): ?>
      <section class="panel-box mb-4">
        <p class="error">No hay conexión con la base de datos de GLPI. Configúrala y pruébala en <a href="conexiones.php#glpi_db">Conexiones</a>.</p>
      </section>
    <?php else: ?>
    <section class="panel-box mb-4">
      <h2 class="panel-title">Por tipo</h2>
      <div class="glpi-tipos">
        <a class="chip<?= $filtroTipo === null && $filtroUbicacion === null ? ' activo' : '' ?>" href="glpi.php">Resumen</a>
        <?php foreach ($etiquetasTipo as $it => $et): ?>
          <a class="chip<?= $filtroTipo === $it ? ' activo' : '' ?>" href="<?= h($urlFiltro($filtroUbicacion, $it)) ?>"><?= h($et) ?>: <?= (int)($inv['porTipo'][$it] ?? 0) ?></a>
        <?php endforeach; ?>
      </div>

      <h2 class="panel-title mt-3">Por ubicación</h2>
      <div class="tabla-scroll">
      <table class="data">
        <thead><tr><th>Ubicación</th><?php foreach ($etiquetasTipo as $et): ?><th><?= h($et) ?></th><?php endforeach; ?><th>Total</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($inv['porUbicacion'] as $nombre => $u): ?>
          <tr<?= $filtroUbicacion === (int)$u['id'] ? ' class="fila-activa"' : '' ?>>
            <td data-label="Ubicación"><?= h($nombre) ?></td>
            <?php foreach ($etiquetasTipo as $it => $et): ?><td data-label="<?= h($et) ?>"><?= (int)($u['tipos'][$it] ?? 0) ?: '·' ?></td><?php endforeach; ?>
            <td data-label="Total"><strong><?= (int)$u['total'] ?></strong></td>
            <td><a class="btn btn-ghost btn-sm" href="<?= h($urlFiltro((int)$u['id'], $filtroTipo)) ?>">Ver</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($inv['porUbicacion'])): ?><tr><td colspan="<?= count($etiquetasTipo) + 3 ?>" class="text-muted">GLPI no tiene activos.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
    </section>

    <?php if ($filtroUbicacion !== null || $filtroTipo !== null): ?>
    <section class="panel-box mb-4" id="detalle">
      <h2 class="panel-title">Detalle (<?= count($inv['detalle']) ?>)</h2>
      <p><a href="glpi.php">← Quitar filtros</a></p>
      <div class="tabla-scroll">
      <table class="data">
        <thead><tr><th>Tipo</th><th>Nombre</th><th>Serial</th><th>N.º inventario</th><th>Fabricante / modelo</th><th>Estado</th><th>Ubicación</th><th>En el sistema</th></tr></thead>
        <tbody>
        <?php foreach ($inv['detalle'] as $a): ?>
          <tr>
            <td data-label="Tipo"><?= h($etiquetasTipo[$a['itemtype']] ?? $a['itemtype']) ?></td>
            <td data-label="Nombre"><?= h($a['nombre']) ?></td>
            <td data-label="Serial"><?= h($a['serial'] ?: '—') ?></td>
            <td data-label="Inventario"><?= h($a['inventario'] ?: '—') ?></td>
            <td data-label="Fabricante / modelo"><?= h(trim($a['fabricante'] . ' ' . $a['modelo']) ?: '—') ?></td>
            <td data-label="Estado"><?= h($a['estado'] ?: '—') ?></td>
            <td data-label="Ubicación"><?= h($a['ubicacion'] ?: '—') ?></td>
            <td data-label="En el sistema"><?= $a['id_componente_local'] ? '<a href="ficha_componente.php?id=' . (int)$a['id_componente_local'] . '">Ver ficha</a>' : '<span class="text-muted">No</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($inv['detalle'])): ?><tr><td colspan="8" class="text-muted">Nada con ese filtro.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
    </section>
    <?php endif; ?>

    <section class="panel-box mb-4">
      <h2 class="panel-title">Vincular componentes por número de serie</h2>
      <p class="text-muted">Marca como «ya existe en GLPI» a los componentes del sistema cuyo serial también está en GLPI. Así no se crean duplicados y sus cambios de ubicación se envían al activo correcto.</p>
      <form method="POST"><?= csrfField() ?><input type="hidden" name="accion" value="vincular_serial">
        <button type="submit" class="btn btn-secondary w-auto">Vincular por serial</button></form>
    </section>
    <?php endif; ?>

    <section class="panel-box mb-4" id="salas">
      <h2 class="panel-title">Salas del sistema ↔ ubicaciones de GLPI</h2>
      <p class="text-muted">Para mover un componente en GLPI, cada sala debe corresponder a una ubicación de GLPI.</p>
      <?php if (empty($salas)): ?>
        <p class="text-muted">No hay salas registradas.</p>
      <?php else: ?>
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="accion" value="guardar_salas">
        <div class="tabla-scroll">
        <table class="data">
          <thead><tr><th>Sala</th><th>Dependencia</th><th>Ubicación en GLPI</th></tr></thead>
          <tbody>
          <?php foreach ($salas as $s): ?>
            <tr>
              <td data-label="Sala"><?= h($s['nombre_sala']) ?></td>
              <td data-label="Dependencia"><?= h($s['dependencia'] ?: '—') ?></td>
              <td data-label="Ubicación en GLPI">
                <input type="hidden" name="glpi_salas[]" value="<?= (int)$s['id_sala'] ?>">
                <?php if ($ubicacionesGlpi): ?>
                  <select name="glpi_ubicaciones[]" aria-label="Ubicación en GLPI de <?= h($s['nombre_sala']) ?>">
                    <option value="">— Sin vincular —</option>
                    <?php foreach ($ubicacionesGlpi as $u): ?>
                      <option value="<?= (int)$u['id'] ?>" <?= (int)$s['glpi_location_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['completename']) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <input name="glpi_ubicaciones[]" aria-label="Ubicación en GLPI de <?= h($s['nombre_sala']) ?>" inputmode="numeric" value="<?= $s['glpi_location_id'] !== null ? (int)$s['glpi_location_id'] : '' ?>" placeholder="id de la ubicación en GLPI">
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <button type="submit" class="btn w-auto mt-2">Guardar vínculos</button>
      </form>
      <?php endif; ?>
    </section>

    <section class="panel-box mb-4" id="cola">
      <div class="conexion-cabecera">
        <h2 class="panel-title">Envíos a GLPI</h2>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="accion" value="reintentar">
          <button type="submit" class="btn btn-secondary btn-sm"<?= $apiConfigurada ? '' : ' disabled title="Configura la API de GLPI en Conexiones"' ?>>Enviar pendientes y reintentar errores</button></form>
      </div>
      <p class="text-muted">Se encolan solos: cada componente nuevo de Equipos TI y cada cambio de ubicación que hace o aprueba un técnico. Se envían al momento; si GLPI no responde quedan aquí.
        <?= $apiConfigurada ? '' : '<strong>La API de GLPI no está configurada: todo queda pendiente.</strong>' ?></p>
      <div class="tabla-scroll">
      <table class="data">
        <thead><tr><th>#</th><th>Fecha</th><th>Acción</th><th>Componente</th><th>Destino</th><th>Estado</th><th>GLPI</th><th>Detalle</th></tr></thead>
        <tbody>
        <?php foreach ($cola as $q): ?>
          <tr>
            <td data-label="#"><?= (int)$q['id'] ?></td>
            <td data-label="Fecha"><?= h(date('d/m/Y H:i', strtotime((string)$q['creado_en']))) ?></td>
            <td data-label="Acción"><?= $q['tipo'] === 'CREAR_COMPONENTE' ? 'Alta' : 'Cambio de ubicación' ?></td>
            <td data-label="Componente"><a href="ficha_componente.php?id=<?= (int)$q['id_componente'] ?>"><?= h($q['codigo_inventario']) ?></a> <span class="text-muted"><?= h($q['tipo_componente']) ?></span></td>
            <td data-label="Destino"><?= $q['tipo'] === 'CREAR_COMPONENTE' ? '—' : h($q['sala_destino'] ?? 'Bodega / oficina') ?></td>
            <td data-label="Estado"><span class="chip <?= $estadoCola[$q['estado']] ?? '' ?>"><?= h(etiquetaValor($q['estado'])) ?></span><?= (int)$q['intentos'] > 1 ? ' <span class="hint">×' . (int)$q['intentos'] . '</span>' : '' ?></td>
            <td data-label="GLPI"><?= $q['glpi_id'] ? h($q['glpi_itemtype'] . ' #' . $q['glpi_id']) : '—' ?></td>
            <td data-label="Detalle"><?= h((string)($q['ultimo_error'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($cola)): ?><tr><td colspan="8" class="text-muted">Todavía no hay envíos.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
    </section>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
