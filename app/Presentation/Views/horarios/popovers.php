<?php
/**
 * Vista de horarios — ventanas emergentes (editar, crear en celda, excepción).
 * Solo presentación: recibe los datos ya preparados por HorariosPage.php /
 * HorarioCalendario (tiposHorario, periodosDisponibles, salas, profesores, personalTurnos). Sin SQL ni reglas de negocio.
 */
?>
<?php if ($puedeModificar): ?>
<div id="popover-editar-horario" class="hg-popover" hidden>
  <div class="hg-popover-backdrop" data-click="horarios.cerrar"></div>
  <div class="hg-popover-panel">
    <button type="button" class="hg-popover-cerrar" data-click="horarios.cerrar">✕</button>
    <form method="POST" id="form-editar-bloque">
      <?= csrfField() ?>
      <input type="hidden" name="id_horario" id="pe-id">
      <div class="field">
        <label for="pe-tipo">Tipo</label>
        <select name="tipo" id="pe-tipo" required>
          <?php foreach ($tiposHorario as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="pe-periodo">Periodo académico</label>
        <select name="periodo" id="pe-periodo" required>
          <?php foreach ($periodosDisponibles as $p): ?><option value="<?= h($p) ?>"><?= h($p) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="pe-sala">Sala</label>
        <select name="id_sala" id="pe-sala">
          <option value="">-- Turno general (sin sala fija) --</option>
          <?php foreach ($salas as $s): ?><option value="<?= (int)$s['id_sala'] ?>"><?= h($s['abreviatura']) ?> — <?= h($s['nombre_sala']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="pe-usuario">Persona</label>
        <select name="id_usuario" id="pe-usuario">
          <option value="">-- Sin asignar --</option>
          <optgroup label="Profesores">
            <?php foreach ($profesores as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['nombre_completo']) ?></option><?php endforeach; ?>
          </optgroup>
          <optgroup label="Becas-trabajo / Técnicos">
            <?php foreach ($personalTurnos as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['nombre_completo']) ?> (<?= h($p['role']) ?>)</option><?php endforeach; ?>
          </optgroup>
        </select>
      </div>
      <div class="field">
        <label for="pe-dia">Día</label>
        <select name="dia_semana" id="pe-dia" required>
          <?php foreach ($diasSemana as $n => $nombre): if ($n > 6) continue; ?><option value="<?= $n ?>"><?= h($nombre) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field-row">
        <div class="field"><label for="pe-inicio">Inicio</label><input type="time" name="hora_inicio" id="pe-inicio" required></div>
        <div class="field"><label for="pe-fin">Fin</label><input type="time" name="hora_fin" id="pe-fin" required></div>
      </div>
      <div class="field"><label for="pe-detalle">Detalle</label><input type="text" name="detalle" id="pe-detalle" maxlength="150"></div>
      <div class="hg-popover-acciones">
        <button type="submit" name="editar_horario" class="btn btn-sm">Guardar cambios (todas las semanas)</button>
        <button type="submit" name="borrar_horario" class="btn btn-danger btn-sm" data-confirm-click="¿Borrar esta franja?">Borrar</button>
      </div>
    </form>
  </div>
</div>

<?php /* Crear franja tocando una celda vacía del calendario */ ?>
<div id="popover-crear-horario" class="hg-popover" hidden>
  <div class="hg-popover-backdrop" data-click="horarios.cerrarCrear"></div>
  <div class="hg-popover-panel">
    <button type="button" class="hg-popover-cerrar" data-click="horarios.cerrarCrear">✕</button>
    <p class="hg-popover-titulo">Nueva franja</p>
    <form method="POST" id="form-crear-bloque">
      <?= csrfField() ?>
      <div class="field">
        <label for="pc-tipo">Tipo</label>
        <select name="tipo" id="pc-tipo" required>
          <?php foreach ($tiposHorario as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="pc-periodo">Periodo académico</label>
        <select name="periodo" id="pc-periodo" required>
          <?php foreach ($periodosDisponibles as $p): ?><option value="<?= h($p) ?>"><?= h($p) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="pc-sala">Sala</label>
        <select name="id_sala" id="pc-sala">
          <option value="">-- Turno general (sin sala fija) --</option>
          <?php foreach ($salas as $s): ?><option value="<?= (int)$s['id_sala'] ?>"><?= h($s['abreviatura']) ?> — <?= h($s['nombre_sala']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="pc-usuario">Persona</label>
        <select name="id_usuario" id="pc-usuario">
          <option value="">-- Sin asignar --</option>
          <optgroup label="Profesores">
            <?php foreach ($profesores as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['nombre_completo']) ?></option><?php endforeach; ?>
          </optgroup>
          <optgroup label="Becas-trabajo / Técnicos">
            <?php foreach ($personalTurnos as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['nombre_completo']) ?> (<?= h($p['role']) ?>)</option><?php endforeach; ?>
          </optgroup>
        </select>
      </div>
      <div class="field">
        <label for="pc-dia">Día</label>
        <select name="dia_semana" id="pc-dia" required>
          <?php foreach ($diasSemana as $n => $nombre): if ($n > 6) continue; ?><option value="<?= $n ?>"><?= h($nombre) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field-row">
        <div class="field"><label for="pc-inicio">Inicio</label><input type="time" name="hora_inicio" id="pc-inicio" required></div>
        <div class="field"><label for="pc-fin">Fin</label><input type="time" name="hora_fin" id="pc-fin" required></div>
      </div>
      <div class="field"><label for="pc-detalle">Detalle</label><input type="text" name="detalle" id="pc-detalle" maxlength="150" placeholder="Opcional"></div>
      <div class="hg-popover-acciones">
        <button type="submit" name="crear_horario" class="btn btn-sm">Crear franja</button>
      </div>
    </form>
    <?php if (($_SESSION['role'] ?? '') === Rol::TECNICO): ?>
      <p class="hint mt-2">¿Es para mantenimiento? <a id="pc-link-mant" href="mantenimientos_programados.php?nuevo=1">Programar un mantenimiento en esta franja</a> (revisa cruces y genera la checklist).</p>
    <?php endif; ?>
  </div>
</div>

<?php /* Excepción puntual "solo esta semana" */ ?>
<div id="popover-excepcion-horario" class="hg-popover" hidden>
  <div class="hg-popover-backdrop" data-click="horarios.cerrarExcepcion"></div>
  <div class="hg-popover-panel">
    <button type="button" class="hg-popover-cerrar" data-click="horarios.cerrarExcepcion">✕</button>
    <p class="hg-popover-titulo">Cambiar solo esta semana</p>
    <p class="hg-popover-subtitulo" id="pex-fecha-texto"></p>
    <form method="POST" id="form-excepcion-bloque">
      <?= csrfField() ?>
      <input type="hidden" name="id_horario" id="pex-id-horario">
      <input type="hidden" name="fecha" id="pex-fecha">
      <div class="field">
        <label for="pex-tipo">Qué cambia esta semana</label>
        <select name="tipo_excepcion" id="pex-tipo" required data-change="horarios.toggleCamposExcepcion">
          <option value="CANCELADO">Cancelar esta fecha (no habrá clase/turno)</option>
          <option value="CAMBIO_SALA">Cambiar de sala solo esta fecha</option>
          <option value="CAMBIO_HORA">Cambiar de hora solo esta fecha</option>
        </select>
      </div>
      <div class="field" id="pex-campo-sala" hidden>
        <label for="pex-sala">Sala nueva (esta semana)</label>
        <select name="nueva_id_sala" id="pex-sala">
          <?php foreach ($salas as $s): ?><option value="<?= (int)$s['id_sala'] ?>"><?= h($s['abreviatura']) ?> — <?= h($s['nombre_sala']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field-row" id="pex-campo-hora" hidden>
        <div class="field"><label for="pex-inicio">Inicio (esta semana)</label><input type="time" name="nueva_hora_inicio" id="pex-inicio"></div>
        <div class="field"><label for="pex-fin">Fin (esta semana)</label><input type="time" name="nueva_hora_fin" id="pex-fin"></div>
      </div>
      <div class="field"><label for="pex-motivo">Motivo (opcional)</label><input type="text" name="motivo" id="pex-motivo" maxlength="255" placeholder="Ej: sala en mantenimiento por daño"></div>
      <div class="hg-popover-acciones">
        <button type="submit" name="guardar_excepcion" class="btn btn-sm">Guardar (solo esta semana)</button>
        <button type="submit" name="borrar_excepcion" id="pex-btn-quitar" class="btn btn-ghost btn-sm" hidden data-confirm-click="¿Quitar la excepción? Esa fecha vuelve a mostrar el horario normal.">Quitar excepción</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
