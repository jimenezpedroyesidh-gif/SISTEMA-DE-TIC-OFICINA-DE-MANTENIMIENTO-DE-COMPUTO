<?php /** Vista de la ficha de un componente (public/ficha_componente.php). Solo presentación: recibe datos ya preparados por FichaComponentePage. */ ?>
<?php $paginaTitulo = 'Componente ' . $componente['codigo_inventario']; $scriptsExtra = ['assets/vendor/qrcodejs-1.0.0/qrcode.min.js']; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php $tituloHeader = 'Ficha de componente'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
<?php
  // Dónde está la pieza y a dónde se vuelve con un toque.
  $claseEstado = $componente['estado'] === 'ACTIVO' ? 'solucionado' : ($componente['estado'] === 'EN_REVISION' ? 'proceso' : 'danio');
  $puedeVerSalas = in_array($rolActual, [Rol::ADMIN, Rol::TECNICO], true);   // el listado por salón es de admin/técnico
  $idSalaUbic = !empty($componente['id_sala_equipo']) ? (int)$componente['id_sala_equipo'] : (!empty($componente['id_sala_directa']) ? (int)$componente['id_sala_directa'] : 0);
  $nombreSalaUbic = $componente['equipo_codigo'] ? $componente['nombre_sala'] : ($componente['nombre_sala_directa'] ?: null);
?>
<div class="comp-ficha">
  <nav class="comp-volver" aria-label="Volver">
    <?php if (!empty($componente['id_equipo_actual']) && $componente['equipo_codigo']): ?>
      <a class="btn comp-volver-principal" href="ficha_equipo.php?id=<?= (int)$componente['id_equipo_actual'] ?>">← Volver al equipo <?= h($componente['equipo_codigo']) ?></a>
    <?php elseif ($idSalaUbic && $puedeVerSalas): ?>
      <a class="btn comp-volver-principal" href="ficha_equipo.php?todas=1#ficha-sala-<?= $idSalaUbic ?>">← Volver a la sala <?= h((string)$nombreSalaUbic) ?></a>
    <?php elseif ($puedeVerSalas): ?>
      <a class="btn comp-volver-principal" href="revisar_componentes_bodega.php">← Volver a bodega</a>
    <?php endif; ?>
    <?php if ($idSalaUbic && $puedeVerSalas && !empty($componente['id_equipo_actual'])): ?>
      <a class="btn btn-ghost btn-sm" href="ficha_equipo.php?todas=1#ficha-sala-<?= $idSalaUbic ?>">Ver la sala</a>
    <?php endif; ?>
  </nav>

  <header class="panel-box comp-head">
    <div class="comp-head-titulo">
      <span class="comp-head-ico" aria-hidden="true"><?= iconoSvg(in_array($componente['tipo'], ['videobeam', 'parlante'], true) ? $componente['tipo'] : 'pc') ?></span>
      <div>
        <p class="comp-head-tipo"><?= h(etiquetaValor($componente['tipo'])) ?></p>
        <h1 class="comp-head-codigo"><?= h($componente['codigo_inventario']) ?></h1>
        <p class="hint">Serial <?= h($componente['serial']) ?></p>
      </div>
      <span class="chip <?= $claseEstado ?> comp-head-estado"><?= h(etiquetaValor($componente['estado'])) ?></span>
    </div>
    <div class="comp-ubicacion">
      <span class="comp-ubicacion-etq">Ubicación actual</span>
      <?php if ($componente['equipo_codigo']): ?>
        <strong><?= h($componente['nombre_sala']) ?> · <?= h($componente['equipo_codigo']) ?></strong>
        <?php if (!empty($componente['equipo_descripcion'])): ?><span class="hint"><?= h($componente['equipo_descripcion']) ?></span><?php endif; ?>
      <?php elseif ($componente['nombre_sala_directa']): ?>
        <strong><?= h($componente['nombre_sala_directa']) ?></strong><span class="hint">Infraestructura de la sala</span>
      <?php else: ?>
        <strong>En bodega / sin asignar</strong>
      <?php endif; ?>
    </div>
  </header>

  <div class="comp-grid">
  <div class="comp-main">
<section class="panel-box">
  <h2 class="panel-title">Datos del componente</h2>
  <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
  <?php if ($exito): ?><p class="exito"><?= h($exito) ?></p><?php endif; ?>
  <dl class="comp-datos">
    <div><dt>Tipo</dt><dd><?= h(etiquetaValor($componente['tipo'])) ?></dd></div>
    <div><dt>Código de inventario</dt><dd><?= h($componente['codigo_inventario']) ?></dd></div>
    <div><dt>Serial</dt><dd><?= h($componente['serial']) ?></dd></div>
    <div><dt>Marca / modelo</dt><dd><?= trim((string)$componente['marca'] . ' ' . (string)$componente['modelo']) !== '' ? h(trim($componente['marca'] . ' ' . $componente['modelo'])) : '—' ?></dd></div>
    <div class="comp-datos-ancho"><dt>Descripción</dt><dd><?= h($componente['descripcion']) ?: '—' ?></dd></div>
    <div><dt>Estado</dt><dd><span class="chip <?= $claseEstado ?>"><?= h(etiquetaValor($componente['estado'])) ?></span></dd></div>
    <div><dt>Registrado</dt><dd><?= h(date('d/m/Y H:i', strtotime((string)$componente['fecha_registro']))) ?></dd></div>
  </dl>

  <?php if (!$esInfraestructura && empty($componente['id_equipo_actual']) && in_array($componente['estado'], ['ACTIVO', 'EN_REVISION'], true)): ?>
    <?php if (in_array($rolActual, [Rol::ADMIN, Rol::TECNICO], true)): ?>
    <?php /* Reinstalar ya requiere ser técnico/admin — becario ya no puede
         ejecutar el movimiento directo, solo avisar (ver bloque else). */ ?>
    <form method="POST" class="mt-3">
      <?= csrfField() ?>
      <h3 class="panel-subtitle">Reinstalar este componente</h3>
      <p class="hint">Úsalo cuando esta pieza ya esté reparada (o sea un repuesto nuevo) y la vayas a poner en un equipo.</p>

      <label>Sala</label>
      <select id="sala_destino_reinstalar" data-equipos-destino="equipo_destino_reinstalar" data-equipos-cargando="Cargando equipos de la sala…" data-equipos-sin-sala="-- Primero selecciona la sala --" data-equipos-lista="-- Selecciona el equipo --" required>
        <option value="">-- Selecciona la sala --</option>
        <?php foreach ($salasParaReinstalar as $s): ?>
          <option value="<?= (int)$s['id_sala'] ?>"><?= h($s['nombre_sala']) ?></option>
        <?php endforeach; ?>
      </select>

      <label>Equipo</label>
      <select name="id_equipo_destino" id="equipo_destino_reinstalar" required>
        <option value="">-- Primero selecciona la sala --</option>
      </select>

      <label>Motivo / nota de la reparación (queda en el historial de la pieza)</label>
      <textarea name="motivo_reinstalacion" placeholder="Ej: se cambió la fuente de poder, ya quedó funcionando."></textarea>

      <div class="mt-2">
        <button class="btn" type="submit" name="reinstalar_componente">Reinstalar</button>
      </div>
    </form>
    <?php else: ?>
      <form method="POST" class="mt-3">
        <?= csrfField() ?>
        <h3 class="panel-subtitle">Lista para reinstalar</h3>
        <p class="hint">Esta pieza puede reinstalarse, pero solo un técnico o el admin puede confirmarlo. Puedes avisarles desde aquí.</p>
        <button class="btn btn-secondary" type="submit" name="avisar_lista_reinstalar">Avisar a los técnicos</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($esInfraestructura): ?>
    <?php if ($rolActual === Rol::TECNICO): ?>
      <form method="POST" class="mt-3">
        <?= csrfField() ?>
        <h3 class="panel-subtitle">Cambiar estado de esta infraestructura</h3>
        <?php if ($errorEstadoInfra): ?><p class="error"><?= h($errorEstadoInfra) ?></p><?php endif; ?>
        <?php if ($exitoEstadoInfra): ?><p class="exito"><?= h($exitoEstadoInfra) ?></p><?php endif; ?>
        <p class="hint">Marca si sigue operativo, si quedó en revisión, dañado o de baja. Queda registrado en la línea de tiempo de abajo.</p>

        <label>Nuevo estado</label>
        <select name="nuevo_estado_infra" required>
          <option value="">-- Selecciona --</option>
          <option value="ACTIVO" <?= $componente['estado']==='ACTIVO'?'disabled':'' ?>>Operativo</option>
          <option value="EN_REVISION" <?= $componente['estado']==='EN_REVISION'?'disabled':'' ?>>En revisión</option>
          <option value="DEFECTUOSO" <?= $componente['estado']==='DEFECTUOSO'?'disabled':'' ?>>Dañado</option>
        </select>

        <label>Observación (opcional, queda en el historial)</label>
        <textarea name="observacion_estado_infra" placeholder="Ej: se apagó solo, hace ruido, ya no enfría..."></textarea>

        <div class="mt-2">
          <button class="btn" type="submit" name="cambiar_estado_infra">Guardar estado</button>
        </div>
      </form>
      <p class="hint mt-2">Para dar de baja definitiva esta infraestructura, usa "Solicitar baja" desde <a href="revisar_componentes_bodega.php">Revisar en bodega</a> — el admin debe aprobarla.</p>
    <?php else: ?>
      <p class="hint mt-3">Solo el rol técnico puede cambiar el estado de la infraestructura. Aquí solo se puede consultar.</p>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section class="panel-box">
  <h2 class="panel-title">Línea de tiempo</h2>
  <?php if (empty($historial)): ?>
    <p class="text-muted">Sin eventos registrados todavía.</p>
  <?php else: ?>
    <ul class="timeline">
      <?php foreach ($historial as $ev): ?>
        <li class="timeline-item">
          <div class="timeline-head">
            <strong><?= h($etiquetasEvento[$ev['tipo_evento']] ?? $ev['tipo_evento']) ?></strong>
            <span class="text-muted"><?= h($ev['fecha_evento']) ?></span>
          </div>
          <div class="timeline-body">
            <?php if ($ev['estado_anterior'] || $ev['estado_nuevo']): ?>
              <p>Estado: <?= h($ev['estado_anterior'] ?? '—') ?> → <?= h($ev['estado_nuevo'] ?? '—') ?></p>
            <?php endif; ?>
            <?php if ($ev['id_equipo_anterior'] || $ev['id_equipo_nuevo']): ?>
              <p>Equipo: <?= h($ev['equipo_anterior_codigo'] ?? '—') ?> → <?= h($ev['equipo_nuevo_codigo'] ?? '—') ?></p>
            <?php endif; ?>
            <?php if ($ev['id_ticket']): ?>
              <p>Ticket relacionado: #<?= (int)$ev['id_ticket'] ?></p>
            <?php endif; ?>
            <?php if (!empty($ev['motivo'])): ?>
              <p><strong>Motivo:</strong> <?= nl2br(h($ev['motivo'])) ?></p>
            <?php endif; ?>
            <?php if ($ev['observacion']): ?>
              <p><?= nl2br(h($ev['observacion'])) ?></p>
            <?php endif; ?>
            <p class="text-muted">Responsable: <?= h($ev['responsable_nombre'] ?? '—') ?></p>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="panel-box">
  <details class="comp-plegable">
  <summary><h2 class="panel-title">Historial de auditoría <span class="text-muted">(<?= count($historialAuditoriaComponente) ?>)</span></h2></summary>
  <p class="text-muted fs-90">Registro de altas y ediciones de datos de esta pieza (distinto de la línea de tiempo de movimientos, arriba).</p>
  <?php if (empty($historialAuditoriaComponente)): ?>
    <p class="text-muted">Todavía no hay eventos de auditoría registrados para este componente.</p>
  <?php else: ?>
    <?php $eventosAuditoria = $historialAuditoriaComponente; include PARCIALES_DIR . '/_timeline_auditoria.php'; ?>
  <?php endif; ?>
  </details>
</section>

  </div>
  <aside class="comp-aside">
<section class="panel-box">
  <h2 class="panel-title">QR de este componente</h2>
  <p class="text-muted fs-90">Escanéalo desde bodega para abrir esta misma ficha (info y todo el historial). Solo entran admin, técnico y beca_trabajo.</p>
  <div class="qr-card">
    <div id="qr-componente-<?= (int)$id ?>" data-qr-texto="<?= h($urlQrComponente) ?>"></div>
    <div class="qr-actions mt-2">
      <button type="button" class="btn btn-secondary btn-sm" data-qr-accion="descargar" data-qr-prefijo="qr-componente" data-qr-id="qr-componente-<?= (int)$id ?>" data-qr-nombre="<?= h($componente['codigo_inventario']) ?>">Descargar QR</button>
      <button type="button" class="btn btn-ghost btn-sm" data-qr-accion="imprimir" data-qr-id="qr-componente-<?= (int)$id ?>" data-qr-nombre="<?= h($componente['codigo_inventario']) ?>">Imprimir</button>
    </div>
  </div>
</section>
  </aside>
  </div>
</div>


  </main>
</div>
</body>
</html>
