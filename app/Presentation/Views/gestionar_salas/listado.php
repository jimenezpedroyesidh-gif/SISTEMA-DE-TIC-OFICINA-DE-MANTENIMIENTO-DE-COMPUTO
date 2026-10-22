<?php /** Vista parcial de «Salas y códigos QR» (listado). Solo presentación: recibe los datos ya preparados por SalasPage.
 *  Cada sala es una tarjeta compacta (estado, dependencia, GLPI, equipos) con su menú «Acciones ▾»; editar, ver el QR
 *  y asignar personal de la oficina se abren en ventanas flotantes. */ ?>
  <section class="panel-box">
    <h2 class="panel-title">Salas registradas</h2>

    <?php if(empty($salas)): ?>
      <p class="text-muted">Todavía no hay salas registradas. Usa «+ Nueva sala» para crear la primera.</p>
    <?php else: ?>
    <?php foreach ($salasPorDependencia as $nombreDep => $salasDelGrupo): ?>
      <details class="hg-accordion mb-3" open>
        <summary><?= h($nombreDep) ?> <span class="hg-accordion-badge"><?= count($salasDelGrupo) ?> sala(s)</span></summary>
        <div class="hg-accordion-body">
    <div class="sala-grid">
      <?php foreach($salasDelGrupo as $s):
        $idS = (int)$s['id_sala'];
        $url = $baseUrl . '/reportar.php?token=' . urlencode($s['codigo_qr_token']);
        $qrId = 'qr-' . $idS;
        $totalEquiposSala = count($equiposPorSala[$s['id_sala']] ?? []);
        $glpiActual = $glpiPorSala[$idS] ?? null;
        $glpiSugerida = $sugerenciaGlpiPorSala[$idS] ?? null;
        $servicio = $servicioResponsablePorSala[$s['id_sala']] ?? null;
      ?>
      <article class="sala-card">
        <div class="sala-card-head">
          <div>
            <h3 class="sala-card-titulo"><?= h($s['nombre_sala']) ?><?= $s['abreviatura'] && $s['abreviatura'] !== $s['nombre_sala'] ? ' <span class="text-muted">· ' . h($s['abreviatura']) . '</span>' : '' ?></h3>
            <p class="sala-card-sub"><?= $s['dependencia'] ? h($s['dependencia']) : 'Sin dependencia asignada' ?></p>
          </div>
          <details class="menu-acciones" data-menu>
            <summary class="btn btn-ghost btn-sm" aria-label="Acciones de <?= h($s['nombre_sala']) ?>">Acciones ▾</summary>
            <div class="menu-acciones-lista">
              <button type="button" class="menu-acciones-item" data-vf-abrir="vf-editar-sala-<?= $idS ?>">Editar sala<?= $ubicacionesGlpi ? ' y ubicación GLPI' : '' ?></button>
              <a class="menu-acciones-item" href="distribucion_sala.php?id=<?= $idS ?>">Distribución y numeración de puestos</a>
              <button type="button" class="menu-acciones-item" data-vf-abrir="vf-qr-<?= $idS ?>">Ver / imprimir código QR</button>
              <?php if ($totalEquiposSala > 0): ?><a class="menu-acciones-item" href="ficha_equipo.php?q=<?= urlencode($s['nombre_sala']) ?>&todas=1">Ver plano y equipos</a><?php endif; ?>
              <?php if($s['es_oficina_mantenimiento']): ?>
                <button type="button" class="menu-acciones-item" data-vf-abrir="vf-staff-<?= $idS ?>">Personal de la oficina</button>
                <form method="POST" class="m-0" data-confirm="¿Quitar la marca de oficina de mantenimiento a esta sala?">
                  <?= csrfField() ?>
                  <button type="submit" name="quitar_oficina_mantenimiento" class="menu-acciones-item">Quitar marca de oficina</button>
                </form>
              <?php else: ?>
                <form method="POST" class="m-0">
                  <?= csrfField() ?>
                  <input type="hidden" name="id_sala" value="<?= $idS ?>">
                  <button type="submit" name="marcar_oficina_mantenimiento" class="menu-acciones-item">Marcar como oficina de mantenimiento</button>
                </form>
              <?php endif; ?>
            </div>
          </details>
        </div>

        <div class="sala-card-chips">
          <?php if(isset($claseActualPorSala[$s['id_sala']])): ?>
            <span class="chip danio">En clase hasta <?= h(substr($claseActualPorSala[$s['id_sala']], 0, 5)) ?></span>
          <?php else: ?>
            <span class="chip solucionado">Libre ahora</span>
          <?php endif; ?>
          <?php if($s['es_oficina_mantenimiento']): ?><span class="chip proceso">Oficina de mantenimiento</span><?php endif; ?>
          <?php if ($ubicacionesGlpi): ?>
            <?php if ($glpiActual): ?>
              <span class="chip solucionado" title="Ubicación en GLPI">GLPI: <?= h($glpiActual['completo']) ?></span>
            <?php elseif ($glpiSugerida): ?>
              <span class="chip espera" title="Coincide por nombre; confírmala en «Editar sala»">GLPI sugerida: <?= h($glpiSugerida['completo']) ?></span>
            <?php else: ?>
              <span class="chip">GLPI: sin vincular</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <dl class="sala-card-datos">
          <dt>Distribución</dt>
          <dd><?= isset($distribucionPorSala[$idS]) ? 'Maquetada (filas, mesas y números)' : 'Automática por código' ?></dd>
          <dt>Equipos</dt>
          <dd><?= $totalEquiposSala > 0 ? $totalEquiposSala . ' registrado(s)' : 'Ninguno (el reporte será general de la sala)' ?></dd>
          <dt>Servicio responsable</dt>
          <dd><?= $servicio ? h($servicio['nombre']) : 'Sin resolver — asigna una dependencia' ?></dd>
          <?php if($s['es_oficina_mantenimiento']): $staffAqui = $staffPorSala[$s['id_sala']] ?? []; ?>
            <dt>Personal</dt>
            <dd><?= $staffAqui ? h(implode(', ', array_column($staffAqui, 'nombre_completo'))) : 'Nadie asignado todavía' ?></dd>
          <?php endif; ?>
        </dl>
      </article>

      <?php /* ── Ventanas flotantes de esta sala ── */ ?>
      <?= vfAbrir('vf-editar-sala-' . $idS, 'Editar ' . $s['nombre_sala'], 'Abreviatura, dependencia' . ($ubicacionesGlpi ? ' y ubicación en GLPI.' : '.')) ?>
        <form method="POST" class="form-grid" data-sala-glpi>
          <?= csrfField() ?>
          <input type="hidden" name="id_sala" value="<?= $idS ?>">
          <input type="hidden" value="<?= h($s['nombre_sala']) ?>" data-glpi-fuente>
          <div class="field">
            <label for="abrev-<?= $idS ?>">Abreviatura</label>
            <input type="text" id="abrev-<?= $idS ?>" name="abreviatura" value="<?= h($s['abreviatura']) ?>" maxlength="10" placeholder="Ej: LAB3" data-glpi-fuente>
          </div>
          <div class="field">
            <label for="unidad-<?= $idS ?>">Dependencia / departamento</label>
            <?php $asignadas = $unidadesPorSala[$s['id_sala']] ?? []; $unidadActualSala = $asignadas[0] ?? 0; ?>
            <select id="unidad-<?= $idS ?>" name="id_unidad">
              <option value="">— Sin dependencia asignada —</option>
              <?php foreach($unidades as $u): ?>
                <option value="<?= (int)$u['id_unidad'] ?>" <?= ((int)$u['id_unidad'] === (int)$unidadActualSala) ? 'selected' : '' ?>><?= h($u['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="bloque-<?= $idS ?>">Bloque / edificio (opcional)</label>
            <input type="text" id="bloque-<?= $idS ?>" name="bloque" value="<?= h((string)($s['bloque'] ?? '')) ?>" maxlength="40" placeholder="Ej: Aula Sur">
          </div>
          <?php if ($ubicacionesGlpi): $selectGlpiId = 'glpi-sala-' . $idS; $glpiSeleccionada = $glpiActual['id'] ?? ($glpiSugerida['id'] ?? null); include __DIR__ . '/select_glpi.php'; endif; ?>
          <button type="submit" name="editar_abreviatura" class="btn field-full">Guardar cambios</button>
        </form>
      <?= vfCerrar() ?>

      <?= vfAbrir('vf-qr-' . $idS, 'Código QR · ' . $s['nombre_sala'], 'Pégalo en la sala: quien lo escanee puede reportar un daño, con o sin cuenta.') ?>
        <div class="qr-card">
          <div id="<?= $qrId ?>" data-qr-texto="<?= h($url) ?>"></div>
          <p class="qr-url"><?= h($url) ?></p>
          <div class="qr-actions">
            <button type="button" class="btn btn-sm" data-qr-accion="descargar" data-qr-id="<?= $qrId ?>" data-qr-nombre="<?= h($s['nombre_sala']) ?>">Descargar QR</button>
            <button type="button" class="btn btn-ghost btn-sm" data-qr-accion="imprimir" data-qr-id="<?= $qrId ?>" data-qr-nombre="<?= h($s['nombre_sala']) ?>">Imprimir</button>
          </div>
        </div>
      <?= vfCerrar() ?>

      <?php if($s['es_oficina_mantenimiento']):
        $staffAqui = $staffPorSala[$s['id_sala']] ?? [];
        $idsYaAsignados = array_column($staffAqui, 'user_id');
        $staffParaAsignar = array_filter($staffDisponible, fn($u) => !in_array((int)$u['id'], $idsYaAsignados, true));
      ?>
        <?= vfAbrir('vf-staff-' . $idS, 'Personal de la oficina', 'Técnicos y becas que atienden la oficina de mantenimiento (bodega).') ?>
          <?php if(empty($staffAqui)): ?>
            <p class="text-muted">Todavía nadie asignado.</p>
          <?php else: ?>
            <ul class="lista-simple mb-3">
              <?php foreach($staffAqui as $st): ?>
                <li>
                  <span><?= h($st['nombre_completo']) ?> <span class="text-muted">(<?= $st['rol'] === Rol::TECNICO ? 'Técnico' : 'Beca de trabajo' ?>)</span></span>
                  <form method="POST" class="form-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="id_sala" value="<?= $idS ?>">
                    <input type="hidden" name="staff_user_id" value="<?= (int)$st['user_id'] ?>">
                    <button type="submit" name="quitar_staff_oficina" class="btn btn-ghost btn-sm">Quitar</button>
                  </form>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if(!empty($staffParaAsignar)): ?>
            <form method="POST">
              <?= csrfField() ?>
              <input type="hidden" name="id_sala" value="<?= $idS ?>">
              <div class="field">
                <label for="staff-<?= $idS ?>">Asignar persona</label>
                <select id="staff-<?= $idS ?>" name="staff_user_id" required>
                  <option value="">— Elegir técnico o beca —</option>
                  <?php foreach($staffParaAsignar as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"><?= h($u['nombre_completo']) ?> (<?= $u['role']===Rol::TECNICO?'Técnico':'Beca' ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" name="asignar_staff_oficina" class="btn">Asignar</button>
            </form>
          <?php else: ?>
            <p class="text-muted">No hay más técnicos/becas activos para asignar.</p>
          <?php endif; ?>
        <?= vfCerrar() ?>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
        </div>
      </details>
    <?php endforeach; ?>
    <?php endif; ?>
  </section>
