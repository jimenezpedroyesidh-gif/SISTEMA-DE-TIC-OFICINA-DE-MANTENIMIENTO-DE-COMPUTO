<?php /** Vista de «Crear ticket» de beca/técnico/admin (public/create_ticket_beca.php). Solo presentación: recibe datos ya preparados por CrearTicketBecaPage. */ ?>
<?php $paginaTitulo = 'Nuevo ticket'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php $tituloHeader = 'Nuevo ticket'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <div class="app-dashboard-welcome">
      <h1>Nuevo ticket</h1>
      <p>Registra un daño o solicitud indicando la sala y, si aplica, el equipo afectado.</p>
    </div>

<?php if ($error): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="exito mb-3"><?= h($success) ?></div><?php endif; ?>

<section class="panel-box">
  <h2 class="panel-title">Tipo de ticket</h2>
  <div class="tabs">
    <button type="button" class="btn btn-secondary btn-sm" data-click="beca.mostrarTipo" data-arg="cambio_pieza">Cambio de pieza</button>
    <button type="button" class="btn btn-secondary btn-sm" data-click="beca.mostrarTipo" data-arg="cambio_posicion">Cambio de posición (equipo completo)</button>
  </div>
  <?php if (!$esTecnicoOAdmin): ?>
    <p class="text-muted mt-2 fs-90">¿Encontraste que un problema afecta a varios equipos de la sala? No crees un ticket nuevo aquí — desde el ticket que ya tomaste, usa el botón "Sugerir mantenimiento general" para que el técnico lo revise y lo eleve.</p>
  <?php endif; ?>
</section>

<?php /* ============ Cambio de pieza puntual ============ */ ?>
<section class="panel-box" id="form-cambio_pieza" hidden>
  <h2 class="panel-title">Cambio de pieza</h2>
  <?php if ($ticketProfesorOrigenGet): ?>
    <div class="exito mb-3">
      Este ticket quedará enlazado al reporte de daño #<?= (int)$ticketProfesorOrigenGet['id'] ?>
      (<?= h($ticketProfesorOrigenGet['sala_danio']) ?>). Cuando el técnico apruebe el cambio, el profesor que
      reportó el daño recibirá una notificación automática.
    </div>
  <?php endif; ?>
  <p class="text-muted mb-3">Un "equipo" aquí es el conjunto completo (torre, monitor, teclado, mouse, cargador...). Casi siempre solo se retira <strong>una pieza puntual</strong> (por ejemplo la torre) — el resto se queda en el salón. Usa este formulario para las tres situaciones:</p>
  <ul class="text-muted mb-3 fs-90">
    <li><strong>Solo llena "Componente dañado"</strong> → sacas esa pieza a bodega para revisarla/repararla. El equipo se queda en el salón, pero marcado como no operativo.</li>
    <li><strong>Solo llena "Componente de reemplazo"</strong> → ya reparaste una pieza y la devuelves a este mismo equipo (búscala por su código, aparecerá si ya no está instalada en ningún otro equipo).</li>
    <li><strong>Llena ambas</strong> → cambio directo: sale una pieza dañada y entra un reemplazo ya listo, en el mismo momento.</li>
  </ul>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="tipo_ticket_beca" value="cambio_pieza">
    <?php if ($ticketProfesorOrigenGet): ?>
      <input type="hidden" name="id_ticket_profesor_origen" value="<?= (int)$ticketProfesorOrigenGet['id'] ?>">
    <?php endif; ?>

    <div class="field">
      <label for="id_sala_pieza">Sala</label>
      <select id="id_sala_pieza" name="id_sala_pieza" required data-equipos-destino="id_equipo" data-equipos-cargando="Cargando..." data-equipos-sin-sala="-- Primero selecciona la sala --" data-equipos-lista="-- Seleccione --">
        <option value="">-- Seleccione --</option>
        <?php foreach ($salas as $s): ?>
          <option value="<?= (int)$s['id_sala'] ?>" <?= ($ticketProfesorOrigenGet && (int)$ticketProfesorOrigenGet['id_sala'] === (int)$s['id_sala']) ? 'selected' : '' ?>><?= h($s['nombre_sala']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="id_equipo">Equipo (el conjunto completo, ej. "PC03")</label>
      <select id="id_equipo" name="id_equipo" required>
        <option value="">-- Primero selecciona la sala --</option>
      </select>
    </div>

    <?php $requerido = false; $salaSelectorId = 'id_sala_pieza'; include PARCIALES_DIR . '/buscador_componente_widget.php'; ?>

    <div class="field">
      <label for="descripcion_pieza">Descripción</label>
      <textarea id="descripcion_pieza" name="descripcion_pieza" required placeholder="Ej: la torre no enciende, se lleva a revisión. Monitor/teclado/mouse se quedan en el salón."></textarea>
    </div>

    <button type="submit" class="btn">Crear ticket</button>
  </form>
</section>

<?php /* ============ Tipo 3: Cambio de posición (reubicar equipo entre salas) ============ */ ?>
<section class="panel-box" id="form-cambio_posicion" hidden>
  <h2 class="panel-title">Cambio de posición</h2>
  <p class="text-muted mb-3">Úsalo cuando un equipo se mueve físicamente de una sala a otra (no es un daño). <?= $esTecnicoOAdmin ? 'Como eres técnico/admin, al aprobar tu propio ticket el sistema mueve el equipo automáticamente en el inventario.' : 'Un técnico deberá tomarlo, aprobarlo y ejecutar el traslado.' ?></p>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="tipo_ticket_beca" value="cambio_posicion">
    <div class="field">
      <label for="id_equipo_mover">Equipo a mover</label>
      <select id="id_equipo_mover" name="id_equipo_mover" required>
        <option value="">-- Seleccione --</option>
        <?php foreach ($todosLosEquipos as $eq): ?>
          <option value="<?= (int)$eq['id_equipo'] ?>"><?= h($eq['codigo']) ?> — actualmente en <?= h($eq['nombre_sala']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="id_sala_destino">Sala destino</label>
      <select id="id_sala_destino" name="id_sala_destino" required>
        <option value="">-- Seleccione --</option>
        <?php foreach ($salas as $s): ?>
          <option value="<?= (int)$s['id_sala'] ?>"><?= h($s['nombre_sala']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="descripcion_posicion">Motivo del traslado</label>
      <textarea id="descripcion_posicion" name="descripcion_posicion" required placeholder="Ej: se reubica el videobeam a la sala SC403 por solicitud del docente"></textarea>
    </div>
    <button type="submit" class="btn">Crear ticket</button>
  </form>
</section>

<?php if ($ticketProfesorOrigenGet): ?>
<script type="application/json" id="beca-config"><?= jsJson(['origenSala' => (int)$ticketProfesorOrigenGet['id_sala']]) ?></script>
<?php endif; ?>
<script src="<?= h(asset('assets/crear_ticket_beca.js')) ?>"></script>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
