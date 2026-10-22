<?php /** Vista del detalle de un equipo (public/ficha_equipo.php). Solo presentación: recibe datos ya preparados por FichaEquipoPage. */ ?>
<?php $paginaTitulo = 'Equipo ' . $equipo['codigo']; $scriptsExtra = ['assets/catalogo_marca_modelo.js' => 'defer', 'assets/vendor/qrcodejs-1.0.0/qrcode.min.js']; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php $tituloHeader = 'Ficha de equipo'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
<div class="app-dashboard-welcome page-head">
      <div>
        <h1><?= h($equipo['codigo']) ?>
          <?php if ($estadoEquipo && !$estadoEquipo['operativo']): ?>
            <span class="chip danio">No operativo</span>
          <?php elseif ($estadoEquipo): ?>
            <span class="chip solucionado">Operativo</span>
          <?php endif; ?>
        </h1>
        <p><?= h($equipo['nombre_sala']) ?><?= !empty($puestoEnSala['numero']) ? ' · puesto n.º ' . h($puestoEnSala['numero']) : '' ?><?= $equipo['descripcion'] ? ' · ' . h($equipo['descripcion']) : '' ?></p>
      </div>
    </div>

    <section class="panel-box">
      <h2 class="panel-title">Puesto de trabajo</h2>
      <div class="user-info">
        <p><strong>Sala:</strong> <?= h($equipo['nombre_sala']) ?></p>
        <p><strong>Código:</strong> <?= h($equipo['codigo']) ?></p>
        <p><strong>Descripción:</strong> <?= h($equipo['descripcion']) ?: '—' ?></p>
        <?php if (!empty($puestoEnSala)): ?>
          <p><strong>Puesto:</strong> <?= $puestoEnSala['docente'] ? 'Docente' : 'N.º ' . h($puestoEnSala['numero'] ?: '—') . ' · fila ' . (int)$puestoEnSala['fila'] . ', mesa ' . (int)$puestoEnSala['mesa'] ?></p>
          <p><strong>Punto de red:</strong> <?= $puestoEnSala['red'] !== '' ? h($puestoEnSala['red']) : '<span class="text-muted">Sin registrar</span>' ?></p>
        <?php endif; ?>
      </div>
      <?php /* Mismo criterio que el plano de la sala: piezas que tiene, las que salieron y quién las trasladó/aprobó. */ ?>
      <div class="mt-3"><?php $estadoEq = $estadoEquipo; include PARCIALES_DIR . '/plano_estado_equipo.php'; ?></div>
    </section>

    <section class="panel-box">
      <h2 class="panel-title">QR de este equipo</h2>
      <p class="text-muted fs-90">Escanéalo desde bodega o inventario para abrir esta misma ficha (info y historial completos). Solo entran admin, técnico y beca_trabajo — si el que escanea no tiene sesión, le pedirá iniciar sesión primero.</p>
      <div class="qr-card mw-260">
        <div id="qr-equipo-<?= (int)$id ?>" data-qr-texto="<?= h($urlQrEquipo) ?>"></div>
        <p class="qr-url" hidden><?= h($urlQrEquipo) ?></p>
        <div class="qr-actions mt-2">
          <details class="menu-acciones" data-menu>
            <summary class="btn btn-secondary btn-sm">Opciones del QR ▾</summary>
            <div class="menu-acciones-lista">
              <button type="button" class="menu-acciones-item" data-qr-accion="descargar" data-qr-prefijo="qr-equipo" data-qr-id="qr-equipo-<?= (int)$id ?>" data-qr-nombre="<?= h($equipo['codigo']) ?>">Descargar QR</button>
              <button type="button" class="menu-acciones-item" data-qr-accion="imprimir" data-qr-id="qr-equipo-<?= (int)$id ?>" data-qr-nombre="<?= h($equipo['codigo']) ?>">Imprimir QR</button>
            </div>
          </details>
        </div>
      </div>
    </section>

    <section class="panel-box">
      <h2 class="panel-title">Componentes actuales</h2>
      <?php if ($errorPuesto): ?><div class="error mb-3"><?= h($errorPuesto) ?></div><?php endif; ?>
      <?php if ($exitoPuesto): ?><div class="exito mb-3"><?= h($exitoPuesto) ?></div><?php endif; ?>
      <?php if (empty($componentes)): ?>
        <p class="text-muted">Este equipo no tiene componentes asignados todavía.</p>
      <?php else: ?>
        <div class="comp-list">
          <?php foreach ($componentes as $c): ?>
            <a class="comp-card" href="ficha_componente.php?id=<?= (int)$c['id_componente'] ?>">
              <div class="comp-card-top">
                <span class="chip <?= $c['estado']==='ACTIVO'?'solucionado':($c['estado']==='EN_REVISION'?'proceso':'danio') ?>"><?= h(etiquetaValor($c['estado'])) ?></span>
                <strong><?= h(etiquetaValor($c['tipo'])) ?></strong>
              </div>
              <div class="comp-card-body">
                <span><?= h($c['codigo_inventario']) ?><?= $c['marca'] ? ' — ' . h($c['marca']) . ' ' . h($c['modelo']) : '' ?></span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($puedeEditarInventario): ?>
        <button type="button" class="btn btn-secondary btn-sm mt-3" data-vf-abrir="vf-agregar-puesto">+ Agregar componente a este puesto</button>
        <?= vfAbrir('vf-agregar-puesto', 'Agregar componente a ' . $equipo['codigo'], 'Pieza de Equipos TI instalada en este puesto (torre, monitor, teclado…).', $errorPuesto !== '', 'lg') ?>
            <?php if ($errorPuesto): ?><div class="error mb-3"><?= h($errorPuesto) ?></div><?php endif; ?>
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
                <label for="codigo_inventario">Código de inventario</label>
                <input type="text" id="codigo_inventario" name="codigo_inventario" required>
              </div>
              <div class="field">
                <label for="id_fabricante_puesto">Marca</label>
                <select id="id_fabricante_puesto" name="id_fabricante" data-catalogo="marca">
                  <option value="">-- Seleccione --</option>
                  <?php foreach ($fabricantes as $f): ?>
                    <option value="<?= (int)$f['id_fabricante'] ?>"><?= h($f['nombre']) ?></option>
                  <?php endforeach; ?>
                  <option value="__nueva__">Marca nueva…</option>
                </select>
                <input class="mt-6px" type="text" name="marca_nueva" placeholder="Nombre de la marca nueva" hidden>
              </div>
              <div class="field">
                <label for="id_modelo_puesto">Modelo</label>
                <select id="id_modelo_puesto" name="id_modelo" data-catalogo="modelo">
                  <option value="">-- Primero selecciona la marca --</option>
                </select>
                <input class="mt-6px" type="text" name="modelo_nuevo" placeholder="Nombre del modelo nuevo" hidden>
              </div>
              <div class="field field-full">
                <label for="descripcion">Descripción</label>
                <input type="text" id="descripcion" name="descripcion" maxlength="150">
              </div>
              <button type="submit" name="agregar_componente_puesto" class="btn field-full">Agregar a este puesto</button>
            </form>
        <?= vfCerrar() ?>
      <?php endif; ?>
    </section>

    <section class="panel-box">
      <h2 class="panel-title">Eventos recientes en este puesto</h2>
      <?php if (empty($historial)): ?>
        <p class="text-muted">Sin eventos registrados.</p>
      <?php else: ?>
        <ul class="timeline">
          <?php foreach ($historial as $ev): ?>
            <li class="timeline-item">
              <div class="timeline-head">
                <strong><?= h($ev['tipo_evento']) ?> — <?= h($ev['codigo_inventario']) ?></strong>
                <span class="text-muted"><?= h($ev['fecha_evento']) ?></span>
              </div>
              <div class="timeline-body">
                <?php if (!empty($ev['motivo'])): ?><p><strong>Motivo:</strong> <?= nl2br(h($ev['motivo'])) ?></p><?php endif; ?>
                <?php if ($ev['observacion']): ?><p><?= nl2br(h($ev['observacion'])) ?></p><?php endif; ?>
                <p class="text-muted">Responsable: <?= h($ev['responsable_nombre'] ?? '—') ?></p>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <?php
    ?>

    <section class="panel-box">
      <h2 class="panel-title">Tickets sobre este equipo/sala</h2>
      <?php if (empty($historialTickets)): ?>
        <p class="text-muted">Sin tickets registrados (solicitante o reportes de sala por QR) para este equipo o su sala.</p>
      <?php else: ?>
        <ul class="timeline">
          <?php foreach ($historialTickets as $t): ?>
            <li class="timeline-item">
              <div class="timeline-head">
                <strong>Ticket #<?= (int)$t['id'] ?> <?= $t['id_equipo'] ? '(este equipo)' : '(sala completa)' ?></strong>
                <span class="chip <?= claseChipHistorialTicket($t['estado']) ?>"><?= h($t['estado']) ?></span>
              </div>
              <div class="timeline-body">
                <p><?= nl2br(h($t['descripcion'])) ?></p>
                <p class="text-muted">
                  Origen: <?= h($t['origen']) ?> ·
                  Creado por: <?= h($t['creado_por'] ?? 'Anónimo (QR)') ?> ·
                  <?= h($t['fecha_creacion']) ?>
                </p>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if (!empty($historialTicketsArchivados)): ?>
        <details class="mt-3">
          <summary>Tickets archivados en cierres de semestre (<?= count($historialTicketsArchivados) ?>)</summary>
          <ul class="timeline">
            <?php foreach ($historialTicketsArchivados as $ta): ?>
              <li class="timeline-item">
                <div class="timeline-head">
                  <strong><?= $ta['tabla_origen'] === TablaTicket::NORMAL ? 'Ticket #' : 'Ticket profesor P-' ?><?= (int)$ta['id_original'] ?> · <?= h($ta['periodo']) ?></strong>
                  <span class="chip <?= claseChipHistorialTicket($ta['estado_final']) ?>"><?= h($ta['estado_final']) ?></span>
                </div>
                <div class="timeline-body">
                  <p><?= nl2br(h((string)$ta['descripcion'])) ?></p>
                  <?php if (!empty($ta['solucion'])): ?><p><strong>Solución:</strong> <?= h($ta['solucion']) ?></p><?php endif; ?>
                  <p class="text-muted">
                    Reportó: <?= h($ta['solicitante'] ?? '—') ?> · Atendió: <?= h(implode(' · ', array_filter([$ta['tecnico'], $ta['beca']])) ?: '—') ?> ·
                    <?= h($ta['fecha_creacion']) ?><?= !empty($ta['equipos_afectados']) ? ' · Equipos: ' . h($ta['equipos_afectados']) : '' ?>
                  </p>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
    </section>

    <section class="panel-box">
      <h2 class="panel-title">Requerimientos / errores de sala reportados por profesores</h2>
      <?php if (empty($historialTicketsProfesor)): ?>
        <p class="text-muted">Sin requerimientos ni errores de sala reportados por profesores para esta sala.</p>
      <?php else: ?>
        <ul class="timeline">
          <?php foreach ($historialTicketsProfesor as $tp): ?>
            <li class="timeline-item">
              <div class="timeline-head">
                <strong>Ticket #<?= (int)$tp['id'] ?> — <?= h(TicketConsultas::etiquetaTipoTicketProfesor($tp['tipo_ticket'])) ?><?= $tp['subtipo_requerimiento'] ? ' · ' . h(etiquetaValor($tp['subtipo_requerimiento'])) : '' ?></strong>
                <span class="chip <?= claseChipHistorialTicket($tp['estado']) ?>"><?= h($tp['estado']) ?></span>
              </div>
              <div class="timeline-body">
                <?php if ($tp['descripcion_actividad']): ?><p><?= nl2br(h($tp['descripcion_actividad'])) ?></p><?php endif; ?>
                <?php if ($tp['software']): ?><p>Software: <?= h($tp['software']) ?></p><?php endif; ?>
                <?php if ($tp['motivo']): ?><p>Motivo: <?= h($tp['motivo']) ?></p><?php endif; ?>
                <p class="text-muted">Profesor: <?= h($tp['creado_por'] ?? '—') ?> · <?= h($tp['fecha_creacion']) ?></p>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="panel-box">
      <h2 class="panel-title">Historial de mantenimientos de sala</h2>
      <?php if (empty($historialMantenimientos)): ?>
        <p class="text-muted">Este puesto no ha estado en ningún mantenimiento de sala registrado.</p>
      <?php else: ?>
        <ul class="timeline">
          <?php foreach ($historialMantenimientos as $hm): ?>
            <li class="timeline-item">
              <div class="timeline-head">
                <strong>Mantenimiento #<?= (int)$hm['id_programado'] ?> — <?= h(etiquetaValor($hm['tipo'])) ?></strong>
                <span class="text-muted"><?= h($hm['fecha_programada']) ?></span>
              </div>
              <div class="timeline-body">
                <p><?= nl2br(h($hm['descripcion'])) ?></p>
                <p><span class="chip <?= $hm['estado_equipo']==='REALIZADO'?'solucionado':($hm['estado_equipo']==='INCOMPLETO'?'danio':'espera') ?>"><?= h($hm['estado_equipo']) ?></span></p>
                <?php if ($hm['observaciones']): ?><p><?= nl2br(h($hm['observaciones'])) ?></p><?php endif; ?>
                <p class="text-muted">Marcado por: <?= h($hm['marcado_por'] ?? '—') ?></p>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="panel-box">
      <h2 class="panel-title">Historial de auditoría</h2>
      <?php if (empty($historialAuditoriaEquipo)): ?>
        <p class="text-muted">Todavía no hay eventos de auditoría registrados para este equipo.</p>
      <?php else: ?>
        <?php $eventosAuditoria = $historialAuditoriaEquipo; include PARCIALES_DIR . '/_timeline_auditoria.php'; ?>
      <?php endif; ?>
    </section>
      </main>
    </div>
    </body>
    </html>
