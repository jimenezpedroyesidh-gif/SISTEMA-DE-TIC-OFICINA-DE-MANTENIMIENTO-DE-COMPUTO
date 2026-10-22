<?php /** Vista del listado de equipos (public/ficha_equipo.php). Solo presentación: recibe datos ya preparados por FichaEquipoPage. */ ?>
<?php $paginaTitulo = 'Fichas de equipo'; $scriptsExtra = ['assets/catalogo_marca_modelo.js' => 'defer', 'assets/plano_sala.js' => 'defer', 'assets/vendor/qrcodejs-1.0.0/qrcode.min.js']; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php $tituloHeader = 'Fichas de equipo'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
<div class="app-dashboard-welcome page-head">
  <div>
    <h1><?= $vistaEquipos ? 'Fichas por equipo' : 'Fichas por salón' ?></h1>
    <p><?= $vistaEquipos
        ? 'Todos los equipos en una sola lista, uno por fila: código, sala, estado y piezas. Abre la ficha de cada uno.'
        : 'Plano de cada sala con el estado de sus equipos. Toca un equipo para ver sus piezas, traslados y ficha.' ?></p>
  </div>
</div>

<?php if (!$salaDelAvisoVisible && ($errorSala || $exitoSala)): ?>
  <?php /* El aviso del alta de infraestructura normalmente va dentro del acordeón de su sala; si esa sala no está en el listado (inexistente o fuera de tu dependencia) se muestra aquí. */ ?>
  <?php if ($errorSala): ?><div class="error mb-3"><?= h($errorSala) ?></div><?php endif; ?>
  <?php if ($exitoSala): ?><div class="exito mb-3"><?= h($exitoSala) ?></div><?php endif; ?>
<?php endif; ?>

<div class="barra-busqueda">
  <?= buscadorHtml(['id' => 'q', 'valor' => $q, 'placeholder' => 'Buscar por sala o código de equipo (ej: SA401 o PC-04)', 'etiqueta' => 'Buscar equipo por sala o código',
        'ocultos' => $vistaEquipos ? ['vista' => 'equipos'] : [],
        'limpiar' => 'ficha_equipo.php' . ($vistaEquipos ? '?vista=equipos' : ($filtrandoPorMiDependencia ? '' : '?todas=1'))]) ?>
  <p class="barra-busqueda-pie">
    <?php if ($q !== ''): ?>
      <span class="barra-busqueda-resultado"><?= count($equipos) ?> equipo(s) para «<?= h($q) ?>»</span>
    <?php elseif ($vistaEquipos): ?>
      <span><?= count($equipos) ?> equipo(s) de todas las dependencias</span>
    <?php elseif ($miDependencia !== null): ?>
      <?php if ($filtrandoPorMiDependencia): ?>
        <span>Mostrando tu dependencia: <strong><?= h($miDependencia) ?></strong></span>
        <a href="ficha_equipo.php?todas=1">Ver todas las dependencias</a>
      <?php else: ?>
        <span>Mostrando todas las dependencias</span>
        <a href="ficha_equipo.php">Ver solo mi dependencia</a>
      <?php endif; ?>
    <?php else: ?>
      <span><?= count($equipos) ?> equipo(s) registrados</span>
    <?php endif; ?>
  </p>
</div>

  <?php if (empty($equipos)): ?>
<section class="panel-box">
  <h2 class="panel-title">Equipos</h2>
    <p class="text-muted">No hay equipos registrados<?= $q !== '' ? ' que coincidan con la búsqueda' : '' ?>.</p>
</section>
  <?php elseif ($vistaEquipos): ?>
<section class="panel-box">
  <h2 class="panel-title">Equipos <span class="text-muted">(<?= count($equipos) ?>)</span></h2>
  <div class="tabla-scroll">
    <table class="data">
      <thead><tr><th>Equipo</th><th>Sala</th><th>Dependencia</th><th>Estado</th><th>Piezas</th><th>Acción</th></tr></thead>
      <tbody>
      <?php foreach ($equipos as $e): $estE = $estadosEquipos[(int)$e['id_equipo']] ?? null; ?>
        <tr>
          <td data-label="Equipo"><strong><?= h($e['codigo']) ?></strong><?= !empty($e['descripcion']) ? '<br><span class="hint">' . h($e['descripcion']) . '</span>' : '' ?></td>
          <td data-label="Sala"><?= h($e['nombre_sala']) ?></td>
          <td data-label="Dependencia"><?= trim((string)($e['dependencia'] ?? '')) !== '' ? h($e['dependencia']) : '<span class="text-muted">—</span>' ?></td>
          <td data-label="Estado"><?= ($estE === null || $estE['operativo']) ? '<span class="chip solucionado">Operativo</span>' : '<span class="chip danio">No operativo</span>' ?></td>
          <td data-label="Piezas"><?= (int)$e['total_componentes'] ?></td>
          <td data-label="Acción"><a class="btn btn-ghost btn-sm" href="ficha_equipo.php?id=<?= (int)$e['id_equipo'] ?>">Ver ficha</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
  <?php else: ?>
    <?php foreach ($equiposAgrupados as $dependencia => $salasDelGrupo):
      $totalDependencia = array_sum(array_map(fn($s) => count($s['equipos']), $salasDelGrupo));
    ?>
    <section class="panel-box">
      <h2 class="panel-title"><?= h($dependencia) ?>
        <span class="text-muted">(<?= (int)$totalDependencia ?> equipo(s) — <?= count($salasDelGrupo) ?> sala(s)/oficina(s))</span>
      </h2>
      <?php foreach ($salasDelGrupo as $idSala => $salaData):
        $nombreSala = $salaData['nombre_sala'];
        $equiposDeSala = $salaData['equipos'];
        $infraDeSala = $salaData['infraestructura'];
        $abrirPorAlta = ($exitoSalaId === $idSala) && ($exitoSala || $errorSala);
      ?>
        <?php /* Al tocar la sala se abre a pantalla completa (ver .ficha-sala en 04-modulos-inventario.css y app.js); se cierra con «Cerrar», Esc o el botón atrás del celular. */ ?>
        <details class="hg-accordion ficha-sala mb-3" id="ficha-sala-<?= (int)$idSala ?>"<?= $abrirPorAlta ? ' open' : '' ?>>
          <?php $noOpSala = count(array_filter($equiposDeSala, fn($e) => isset($estadosEquipos[(int)$e['id_equipo']]) && !$estadosEquipos[(int)$e['id_equipo']]['operativo'])); ?>
          <summary><?= h($nombreSala) ?> <span class="hg-accordion-badge"><?= count($equiposDeSala) ?> equipo(s)</span><?php if ($noOpSala): ?> <span class="hg-accordion-badge badge-alerta"><?= $noOpSala ?> no operativo(s)</span><?php endif; ?><span class="ficha-sala-cerrar" aria-hidden="true">Cerrar ✕</span></summary>
          <div class="hg-accordion-body">
            <?php
              // Videobeam/parlantes de la sala van en el plano (frente al tablero); el resto de infraestructura, en su desplegable.
              $otraInfra = array_values(array_filter($infraDeSala, fn($ci) => !in_array($ci['tipo'], PlanoSalaService::TIPOS_FRENTE, true)));
            ?>
            <div class="sala-ficha-barra">
              <h3 class="panel-subtitle m-0">Plano de la sala</h3>
              <details class="menu-acciones" data-menu>
                <summary class="btn btn-secondary btn-sm">Acciones ▾</summary>
                <div class="menu-acciones-lista">
                  <?php if (!empty($equiposDeSala)): ?>
                    <button type="button" class="menu-acciones-item" data-qr-accion="lote" data-qr-json="qr-data-sala-<?= (int)$idSala ?>" data-qr-nombre="<?= h($nombreSala) ?>">Imprimir QR de los equipos de esta sala</button>
                  <?php endif; ?>
                  <?php if ($puedeAgregarInfraestructura): ?>
                    <button type="button" class="menu-acciones-item" data-vf-abrir="infra-form-<?= (int)$idSala ?>">Agregar infraestructura o videobeam</button>
                  <?php endif; ?>
                  <?php if (in_array($rol, [Rol::TECNICO, Rol::ADMIN], true)): ?>
                    <a class="menu-acciones-item" href="distribucion_sala.php?id=<?= (int)$idSala ?>">Editar distribución y numeración</a>
                  <?php endif; ?>
                  <button type="button" class="menu-acciones-item" data-abrir-detalle="infra-lista-<?= (int)$idSala ?>">Ver otra infraestructura (<?= count($otraInfra) ?>)</button>
                </div>
              </details>
              <?php if (!empty($equiposDeSala)): ?>
                <script type="application/json" id="qr-data-sala-<?= (int)$idSala ?>"><?= jsJson(array_map(fn($e) => [
                    'codigo' => $e['codigo'],
                    'url' => $baseUrlQr . '/ficha_equipo.php?id=' . (int)$e['id_equipo'],
                ], $equiposDeSala)) ?></script>
              <?php endif; ?>
            </div>

            <?php $planoIdCont = 'plano-sala-' . (int)$idSala; $planoEquipos = $equiposDeSala; $planoEstados = $estadosEquipos; $planoFrente = $frentePorSala[(int)$idSala] ?? []; $planoDistribucion = $distribucionPorSala[(int)$idSala] ?? null; include PARCIALES_DIR . '/plano_sala_info.php'; ?>

            <?php if ($abrirPorAlta && $errorSala): ?><div class="error mt-3"><?= h($errorSala) ?></div><?php endif; ?>
            <?php if ($abrirPorAlta && $exitoSala): ?><div class="exito mt-3"><?= h($exitoSala) ?></div><?php endif; ?>

            <details class="hg-accordion mt-3" id="infra-lista-<?= (int)$idSala ?>">
              <summary>Otra infraestructura (aire, iluminación, sillas…) <span class="hg-accordion-badge"><?= count($otraInfra) ?></span></summary>
              <div class="hg-accordion-body">
                <?php if (empty($otraInfra)): ?>
                  <p class="text-muted m-0">Esta sala no tiene otros ítems de infraestructura registrados.</p>
                <?php else: ?>
                  <div class="comp-list">
                    <?php foreach ($otraInfra as $ci): ?>
                      <a class="comp-card" href="ficha_componente.php?id=<?= (int)$ci['id_componente'] ?>">
                        <div class="comp-card-top">
                          <span class="chip <?= $ci['estado']==='ACTIVO'?'solucionado':($ci['estado']==='EN_REVISION'?'proceso':'danio') ?>"><?= h(etiquetaValor($ci['estado'])) ?></span>
                          <strong><?= h(etiquetaValor($ci['tipo'])) ?></strong>
                        </div>
                        <div class="comp-card-body">
                          <span><?= h($ci['codigo_inventario']) ?><?= $ci['marca'] ? ' — ' . h($ci['marca']) . ' ' . h($ci['modelo']) : '' ?></span>
                        </div>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </details>

            <?php if ($puedeAgregarInfraestructura): ?>
              <?= vfAbrir('infra-form-' . (int)$idSala, 'Agregar a ' . $nombreSala, 'Videobeam, parlantes, aire acondicionado, iluminación, sillas… (directo a la sala, no a un puesto).', $abrirPorAlta && (bool)$errorSala, 'lg') ?>
                  <?php if ($abrirPorAlta && $errorSala): ?><div class="error mb-3"><?= h($errorSala) ?></div><?php endif; ?>
                  <form method="POST" class="form-grid">
                    <?= csrfField() ?>
                    <input type="hidden" name="id_sala" value="<?= (int)$idSala ?>">
                    <div class="field">
                      <label for="tipo_infra_<?= (int)$idSala ?>">Tipo</label>
                      <select id="tipo_infra_<?= (int)$idSala ?>" name="tipo_infra" required>
                        <option value="">-- Seleccione --</option>
                        <option value="videobeam">Videobeam / proyector (va frente al tablero)</option>
                        <option value="parlante">Parlantes / sonido de la sala</option>
                        <option value="aire_acondicionado">Aire acondicionado</option>
                        <option value="iluminacion">Iluminación</option>
                        <option value="toma_electrica">Tomas eléctricas</option>
                        <option value="silla">Silla</option>
                        <option value="otro_infraestructura">Otros</option>
                      </select>
                    </div>
                    <div class="field">
                      <label for="serial_infra_<?= (int)$idSala ?>">Serial (de fábrica)</label>
                      <input type="text" id="serial_infra_<?= (int)$idSala ?>" name="serial_infra" required>
                    </div>
                    <div class="field">
                      <label for="codigo_inventario_infra_<?= (int)$idSala ?>">Código de inventario</label>
                      <input type="text" id="codigo_inventario_infra_<?= (int)$idSala ?>" name="codigo_inventario_infra" required>
                    </div>
                    <div class="field">
                      <label for="id_fabricante_infra_<?= (int)$idSala ?>">Marca</label>
                      <select id="id_fabricante_infra_<?= (int)$idSala ?>" name="id_fabricante" data-catalogo="marca">
                        <option value="">-- Seleccione --</option>
                        <?php foreach ($fabricantes as $f): ?>
                          <option value="<?= (int)$f['id_fabricante'] ?>"><?= h($f['nombre']) ?></option>
                        <?php endforeach; ?>
                        <option value="__nueva__">Marca nueva…</option>
                      </select>
                      <input class="mt-6px" type="text" name="marca_nueva" placeholder="Nombre de la marca nueva" hidden>
                    </div>
                    <div class="field">
                      <label for="id_modelo_infra_<?= (int)$idSala ?>">Modelo</label>
                      <select id="id_modelo_infra_<?= (int)$idSala ?>" name="id_modelo" data-catalogo="modelo">
                        <option value="">-- Primero selecciona la marca --</option>
                      </select>
                      <input class="mt-6px" type="text" name="modelo_nuevo" placeholder="Nombre del modelo nuevo" hidden>
                    </div>
                    <div class="field field-full">
                      <label for="descripcion_infra_<?= (int)$idSala ?>">Descripción</label>
                      <input type="text" id="descripcion_infra_<?= (int)$idSala ?>" name="descripcion_infra" maxlength="150">
                    </div>
                    <button type="submit" name="agregar_componente_sala" class="btn field-full">Agregar a la sala</button>
                  </form>
              <?= vfCerrar() ?>
            <?php elseif ($rol === Rol::ADMIN): ?>
              <p class="hint mt-2">Solo el rol técnico puede agregar o mover infraestructura de la sala. Aquí solo se puede consultar y analizar (ver Panel de Administración → pestaña Infraestructura).</p>
            <?php endif; ?>

          </div>
        </details>
      <?php endforeach; ?>
    </section>
    <?php endforeach; ?>
  <?php endif; ?>
  </main>
</div>
</body>
</html>

