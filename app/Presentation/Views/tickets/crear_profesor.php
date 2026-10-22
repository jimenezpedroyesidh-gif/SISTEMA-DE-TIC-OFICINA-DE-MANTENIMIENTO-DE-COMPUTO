<?php
/** Vista de «Crear ticket (Profesor)» (public/create_ticket_profesor.php). Solo presentación: recibe datos ya preparados por CrearTicketProfesorPage. */
function old_val($old, $key) {
    return htmlspecialchars($old[$key] ?? '');
}
function old_selected($old, $key, $value) {
    return (isset($old[$key]) && (string)$old[$key] === (string)$value) ? 'selected' : '';
}
$paginaTitulo = 'Nuevo ticket'; include LAYOUT_DIR . '/app_page_head.php'; ?>
  <script src="<?= h(asset('assets/plano_sala.js')) ?>"></script>
  <script src="<?= h(asset('assets/profesor_nuevo_ticket.js')) ?>"></script>
<?php $tituloHeader = 'Nuevo ticket'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <section class="panel-box mw-560 m-0-auto">
      <h2 class="panel-title">Nuevo ticket</h2>
      <?php if (!empty($esAdminDocente)): ?>
        <p class="hint mb-3">Pides como docente del Departamento: solo aparecen las salas de tu dependencia. Lo verás en «Mis solicitudes» del panel.</p>
      <?php endif; ?>

      <?php if($success): ?>
        <div class="exito mb-3"><?= h($success) ?></div>
      <?php elseif($error): ?>
        <div class="error mb-3"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <?= csrfField() ?>

        <div class="field">
          <label for="tipo_ticket">Tipo de Ticket</label>
          <select name="tipo_ticket" id="tipo_ticket" data-change="profesor.tipoTicket" required>
            <option value="">-- Seleccione --</option>
            <option value="requerimiento" <?= old_selected($old,'tipo_ticket','requerimiento') ?>>Requerimiento</option>
            <option value="danio" <?= old_selected($old,'tipo_ticket','danio') ?>>Error de sala</option>
          </select>
        </div>

        <?php /* ============== Campos Requerimiento ============== */ ?>
        <div id="requerimiento_fields" hidden>
          <div class="field">
            <label for="subtipo_requerimiento">¿Qué necesitas?</label>
            <select name="subtipo_requerimiento" id="subtipo_requerimiento" data-change="profesor.subtipo" data-required>
              <option value="">-- Seleccione --</option>
              <option value="CAMBIO_SALA" <?= old_selected($old,'subtipo_requerimiento','CAMBIO_SALA') ?>>Cambio de sala</option>
              <option value="INSTALACION_SOFTWARE" <?= old_selected($old,'subtipo_requerimiento','INSTALACION_SOFTWARE') ?>>Instalación de software</option>
              <option value="PRESTAMO_SALA" <?= old_selected($old,'subtipo_requerimiento','PRESTAMO_SALA') ?>>Préstamo de sala</option>
              <option value="PRESTAMO_EQUIPO" <?= old_selected($old,'subtipo_requerimiento','PRESTAMO_EQUIPO') ?>>Préstamo de videobeam / panel / otro equipo</option>
            </select>
          </div>

          <?php /* ---------- Subtipo: Cambio de Sala ---------- */ ?>
          <fieldset id="bloque_cambio_sala" hidden>
            <legend>Cambio de sala</legend>

            <?php if (!empty($miHorario)): ?>
              <div class="field">
                <label for="id_horario_referencia">Horario actual (la clase que deseas cambiar)</label>
                <select name="id_horario_referencia" id="id_horario_referencia" data-required>
                  <option value="">-- Seleccione --</option>
                  <?php foreach ($miHorario as $h): ?>
                    <option value="<?= (int)$h['id_horario'] ?>" <?= old_selected($old,'id_horario_referencia',$h['id_horario']) ?>>
                      <?= htmlspecialchars($diasSemanaCorto[$h['dia_semana']] ?? '') ?>
                      <?= htmlspecialchars(substr($h['hora_inicio'],0,5)) ?>–<?= htmlspecialchars(substr($h['hora_fin'],0,5)) ?>
                      — <?= htmlspecialchars($h['nombre_sala'] ?? 'Sin sala') ?><?= $h['detalle'] ? ' (' . htmlspecialchars($h['detalle']) . ')' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <p class="text-muted fs-85">Esto captura automáticamente el día, la hora y la sala actual de esa clase.</p>
              </div>
            <?php else: ?>
              <p class="text-muted fs-85">No tienes clases registradas en tu horario todavía, así que no puedes solicitar un cambio de sala.</p>
            <?php endif; ?>

            <div class="field">
              <label for="motivo">Motivo del cambio</label>
              <textarea name="motivo" id="motivo" rows="2" data-required><?= old_val($old,'motivo') ?></textarea>
            </div>

            <div class="field">
              <label for="id_sala_destino">Sala a la que deseas cambiarte</label>
              <select name="id_sala_destino" id="id_sala_destino" data-required>
                <option value="">-- Seleccione --</option>
                <?php foreach ($salasDisponibles as $s): ?>
                  <option value="<?= (int)$s['id_sala'] ?>" <?= old_selected($old,'id_sala_destino',$s['id_sala']) ?>><?= htmlspecialchars($s['nombre_sala']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </fieldset>

          <?php /* ---------- Subtipo: Instalación de Software ---------- */ ?>
          <fieldset id="bloque_software" hidden>
            <legend>Instalación de software</legend>

            <div class="field">
              <label for="id_sala_software">Sala / laboratorio</label>
              <select name="id_sala_software" id="id_sala_software" data-required>
                <option value="">-- Seleccione --</option>
                <?php foreach ($salasDisponibles as $s): ?>
                  <option value="<?= (int)$s['id_sala'] ?>" <?= old_selected($old,'id_sala_software',$s['id_sala']) ?>><?= htmlspecialchars($s['nombre_sala']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label for="software">Software / aplicativo</label>
              <input type="text" name="software" id="software" data-required value="<?= old_val($old,'software') ?>">
            </div>

            <div class="field">
              <label for="descripcion_software">Descripción del software (versión, arquitectura, especificaciones)</label>
              <textarea name="descripcion_software" id="descripcion_software" rows="3" data-required><?= old_val($old,'descripcion_software') ?></textarea>
            </div>
          </fieldset>

          <?php /* ---------- Subtipo: Préstamo (Sala / Equipo) ---------- */ ?>
          <fieldset id="bloque_prestamo" hidden>
            <legend>Préstamo</legend>

            <div class="field">
              <label for="motivo_prestamo">Motivo / objeto del préstamo</label>
              <textarea name="motivo_prestamo" id="motivo_prestamo" rows="2" data-required><?= old_val($old,'motivo_prestamo') ?></textarea>
            </div>

            <div class="field">
              <label for="cantidad_alumnos">Cantidad de alumnos</label>
              <input type="number" name="cantidad_alumnos" id="cantidad_alumnos" min="1" data-required value="<?= old_val($old,'cantidad_alumnos') ?>">
            </div>

            <div class="field">
              <label for="para_que">¿Para qué actividad?</label>
              <textarea name="para_que" id="para_que" rows="2" data-required><?= old_val($old,'para_que') ?></textarea>
            </div>

            <div class="field">
              <label for="id_sala_prestamo">Sala o ubicación</label>
              <select name="id_sala_prestamo" id="id_sala_prestamo" data-required>
                <option value="">-- Seleccione --</option>
                <?php foreach ($salasDisponibles as $s): ?>
                  <option value="<?= (int)$s['id_sala'] ?>" <?= old_selected($old,'id_sala_prestamo',$s['id_sala']) ?>><?= htmlspecialchars($s['nombre_sala']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label for="fecha_requerida">Fecha requerida</label>
              <input type="date" name="fecha_requerida" id="fecha_requerida" data-required value="<?= old_val($old,'fecha_requerida') ?>">
            </div>

            <div class="field">
              <label for="duracion_prestamo">Horario / duración</label>
              <select name="duracion_prestamo" id="duracion_prestamo" data-required>
                <option value="">-- Seleccione --</option>
                <?php foreach ($duracionesValidas as $val => $label): ?>
                  <option value="<?= htmlspecialchars($val) ?>" <?= old_selected($old,'duracion_prestamo',$val) ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label for="fecha_devolucion_prevista">Fecha en la que devolverás lo prestado</label>
              <input type="date" name="fecha_devolucion_prevista" id="fecha_devolucion_prevista" data-required value="<?= old_val($old,'fecha_devolucion_prevista') ?>">
              <p class="text-muted fs-85">El ticket permanecerá abierto hasta que el técnico confirme que recibió de vuelta lo prestado, aunque ya haya pasado esta fecha.</p>
            </div>
          </fieldset>
        </div>

        <?php /* ============== Campos Daño ============== */ ?>
        <div id="danio_fields" hidden>
          <div class="field">
            <label for="id_sala_danio">Sala</label>
            <select name="id_sala_danio" id="id_sala_danio" data-required data-change="profesor.salaDanio">
              <option value="">-- Seleccione --</option>
              <?php foreach ($salasDisponibles as $s): ?>
                <option value="<?= (int)$s['id_sala'] ?>" <?= old_selected($old,'id_sala_danio',$s['id_sala']) ?>><?= htmlspecialchars($s['nombre_sala']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php /* Tarea 3: equipo(s) opcional(es) — nunca obligatorio. Si el
               profesor no sabe cuál es, el beca/técnico lo identifica al
               tomar el ticket (Tarea 2). */ ?>
          <div class="field">
            <label>Equipos con falla (opcional)</label>
            <p class="hint">Toca en el plano de la sala los equipos que fallan, como al elegir sillas. Si no sabes cuál es, déjalo sin marcar: el técnico o el becario lo identificará al revisar.</p>
            <div class="sala-selector-cont" id="checklist_equipos_danio">
              <p class="text-muted m-0">Selecciona primero la sala.</p>
            </div>
          </div>

          <div class="field">
            <label for="descripcion_problema">Descripción del problema</label>
            <textarea name="descripcion_problema" id="descripcion_problema" rows="3" data-required><?= old_val($old,'descripcion_problema') ?></textarea>
          </div>
        </div>

        <button type="submit" class="btn">Crear ticket</button>
      </form>
    </section>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
