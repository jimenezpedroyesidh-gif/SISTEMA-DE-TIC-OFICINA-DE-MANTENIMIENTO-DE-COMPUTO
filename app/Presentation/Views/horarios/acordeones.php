<?php
/**
 * Vista de horarios — formularios en ventanas flotantes (nueva franja, filtros, vaciar periodo, permiso de secretaría).
 * Se abren desde los botones del encabezado de la página (pagina.php); así el calendario queda arriba y limpio.
 * Solo presentación: recibe los datos ya preparados por HorariosPage.php /
 * HorarioCalendario (tiposHorario, periodosDisponibles, salas, profesores, personalTurnos, secretarias). Sin SQL ni reglas de negocio.
 */
?>
<?php if ($puedeModificar): ?>
<?= vfAbrir('acc-nueva-franja', 'Nueva franja', 'Clase, turno de beca o turno de técnico que se repite cada semana del periodo.', false, 'lg') ?>
      <form method="POST" id="form-crear-horario" class="form-grid">
        <?= csrfField() ?>
        <div class="field">
          <label for="tipo">Tipo</label>
          <select id="tipo" name="tipo" required data-change="horarios.toggleCampos">
            <?php foreach ($tiposHorario as $k => $v): ?>
              <option value="<?= h($k) ?>"><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="periodo">Periodo académico</label>
          <select id="periodo" name="periodo" required>
            <?php foreach ($periodosDisponibles as $p): ?>
              <option value="<?= h($p) ?>" <?= $p === $periodoActual ? 'selected' : '' ?>><?= h($p) ?><?= $p === $periodoActual ? ' (actual)' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" id="campo-sala-horario">
          <label for="id_sala">Sala <span id="sala-opcional-hint" class="hint" hidden>(opcional para turnos generales)</span></label>
          <select id="id_sala" name="id_sala">
            <option value="">-- Turno general (sin sala fija) --</option>
            <?php foreach ($salas as $s): ?>
              <option value="<?= (int)$s['id_sala'] ?>"><?= h($s['abreviatura']) ?> — <?= h($s['nombre_sala']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field field-full">
          <label for="id_usuario">Profesor / becario / técnico (opcional — vacío = solo bloqueo de sala)</label>
          <select id="id_usuario" name="id_usuario">
            <option value="">-- Sin asignar --</option>
            <optgroup label="Profesores">
              <?php foreach ($profesores as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= h($p['nombre_completo']) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <optgroup label="Becas-trabajo / Técnicos">
              <?php foreach ($personalTurnos as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= h($p['nombre_completo']) ?> (<?= h($p['role']) ?>)</option>
              <?php endforeach; ?>
            </optgroup>
          </select>
        </div>
        <div class="field">
          <label for="dia_semana">Día</label>
          <select id="dia_semana" name="dia_semana" required>
            <?php foreach ($diasSemana as $n => $nombre): ?>
              <option value="<?= $n ?>"><?= h($nombre) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="hora_inicio">Hora inicio</label>
          <input type="time" id="hora_inicio" name="hora_inicio" required>
        </div>
        <div class="field">
          <label for="hora_fin">Hora fin</label>
          <input type="time" id="hora_fin" name="hora_fin" required>
        </div>
        <div class="field field-full">
          <label for="detalle">Detalle (materia, programa, nota del turno)</label>
          <input type="text" id="detalle" name="detalle" maxlength="150">
        </div>
        <button type="submit" name="crear_horario" class="btn field-full">Guardar franja</button>
      </form>
<?= vfCerrar() ?>

<?= vfAbrir('acc-filtrar', 'Filtrar horario', 'Periodo, sala, tipo de franja o día.') ?>
      <form method="GET" class="form-grid">
        <div class="field">
          <label for="periodo_filtro">Periodo académico</label>
          <select id="periodo_filtro" name="periodo">
            <?php foreach ($periodosDisponibles as $p): ?>
              <option value="<?= h($p) ?>" <?= $filtroPeriodo === $p ? 'selected' : '' ?>><?= h($p) ?><?= $p === $periodoActual ? ' (actual)' : '' ?></option>
            <?php endforeach; ?>
            <option value="TODOS" <?= $filtroPeriodo === 'TODOS' ? 'selected' : '' ?>>Todos (histórico)</option>
          </select>
        </div>
        <div class="field">
          <label for="sala">Sala</label>
          <select id="sala" name="sala">
            <option value="0">Todas</option>
            <?php foreach ($salas as $s): ?>
              <option value="<?= (int)$s['id_sala'] ?>" <?= $filtroSala === (int)$s['id_sala'] ? 'selected' : '' ?>><?= h($s['abreviatura']) ?> — <?= h($s['nombre_sala']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="tipo_filtro">Tipo</label>
          <select id="tipo_filtro" name="tipo">
            <option value="">Todos</option>
            <?php foreach ($tiposHorario as $k => $v): ?>
              <option value="<?= h($k) ?>" <?= $filtroTipo === $k ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-secondary field-full">Filtrar</button>
      </form>
<?= vfCerrar() ?>

<?php if ($alcance === null): ?>
<?= vfAbrir('acc-vaciar', 'Vaciar horario (inicio de semestre)', 'Acción irreversible: borra todas las franjas del periodo elegido.') ?>
      <p class="text-muted">Esto borra <strong>todas</strong> las franjas (clases, turnos de beca y de técnico) del periodo que elijas, en todas las salas, para poder cargar el horario nuevo desde cero. Los mantenimientos programados no se ven afectados. Esta acción no se puede deshacer.</p>
      <form method="POST" class="form-grid" data-confirm="¿Seguro que quieres borrar TODO el horario de ese periodo? No se puede deshacer.">
        <?= csrfField() ?>
        <div class="field">
          <label for="periodo_borrar">Periodo a vaciar</label>
          <select id="periodo_borrar" name="periodo_borrar" required>
            <?php foreach ($periodosDisponibles as $p): ?>
              <option value="<?= h($p) ?>" <?= $p === $periodoActual ? 'selected' : '' ?>><?= h($p) ?><?= $p === $periodoActual ? ' (actual)' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="confirmacion">Escribe BORRAR para confirmar</label>
          <input type="text" id="confirmacion" name="confirmacion" placeholder="BORRAR" required>
        </div>
        <button type="submit" name="vaciar_periodo" class="btn btn-danger field-full">Vaciar horario de ese periodo</button>
      </form>
<?= vfCerrar() ?>
<?php endif; ?>
<?php endif; ?>

<?php if ($rol === Rol::ADMIN): ?>
<?= vfAbrir('acc-permisos-secretaria', 'Permisos de edición para secretaría', 'Quién de la secretaría puede crear, editar y borrar franjas.', false, 'lg') ?>
      <p class="text-muted">Sin el permiso, la secretaria no ve los horarios. Con el permiso activo ve y edita <strong>solo</strong> los turnos de las becas asignadas a la secretaría y las <strong>salas que marques</strong> aquí. No puede vaciar el semestre.</p>
      <?php if (empty($secretarias)): ?>
        <p class="text-muted">No hay ningún usuario con rol <strong>secretaria</strong> todavía. Créalo desde <a href="usuarios.php">Usuarios</a> primero.</p>
      <?php else: ?>
        <div class="tabla-scroll">
        <table class="hg-tabla-permisos">
          <thead>
            <tr><th>Nombre</th><th>Correo</th><th>Puede editar horarios</th><th>Salas que puede ver y editar</th></tr>
          </thead>
          <tbody>
            <?php foreach ($secretarias as $s): ?>
              <tr>
                <td><?= h($s['nombre_completo']) ?></td>
                <td><?= h($s['correo']) ?></td>
                <td>
                  <form method="POST" class="permiso-fila">
                    <?= csrfField() ?>
                    <input type="hidden" name="id_usuario" value="<?= (int)$s['id'] ?>">
                    <input type="hidden" name="otorgar" value="<?= $s['permiso_horarios_extra'] ? 0 : 1 ?>">
                    <input type="hidden" name="cambiar_permiso_secretaria" value="1">
                    <button type="submit" class="switch<?= $s['permiso_horarios_extra'] ? ' is-on' : '' ?>" role="switch" aria-checked="<?= $s['permiso_horarios_extra'] ? 'true' : 'false' ?>"
                            aria-label="Puede editar horarios: <?= h($s['nombre_completo']) ?>" title="<?= $s['permiso_horarios_extra'] ? 'Activo — clic para quitar el permiso' : 'Inactivo — clic para dar el permiso' ?>">
                      <span class="switch-knob" aria-hidden="true"></span>
                    </button>
                    <span class="switch-texto"><?= $s['permiso_horarios_extra'] ? 'Sí' : 'No' ?></span>
                  </form>
                </td>
                <td>
                  <?php $salasSec = $salasPorSecretaria[(int)$s['id']] ?? []; ?>
                  <details class="hg-salas-secretaria">
                    <summary><?= count($salasSec) ? count($salasSec) . ' sala(s)' : 'Ninguna (solo turnos de sus becas)' ?> — cambiar</summary>
                    <form method="POST">
                      <?= csrfField() ?>
                      <input type="hidden" name="id_usuario" value="<?= (int)$s['id'] ?>">
                      <div class="hg-salas-check">
                        <?php foreach ($salas as $sl): ?>
                          <label><input type="checkbox" name="salas[]" value="<?= (int)$sl['id_sala'] ?>"<?= in_array((int)$sl['id_sala'], $salasSec, true) ? ' checked' : '' ?>> <?= h($sl['abreviatura'] ?: $sl['nombre_sala']) ?></label>
                        <?php endforeach; ?>
                      </div>
                      <button type="submit" name="guardar_salas_secretaria" value="1" class="btn btn-sm btn-secondary">Guardar salas</button>
                    </form>
                  </details>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
<?= vfCerrar() ?>
<?php endif; ?>
