<?php /** Vista parcial de «Mantenimientos programados» (formulario programar). Solo presentación: recibe datos ya preparados por MantenimientosPage. */ ?>
<?php if ($puedeProgramar): ?>
<?php
  // Se abre sola al llegar desde «Elevar a mantenimiento de sala» o si el formulario volvió con un error.
  $abrirProgramar = $prefillIdTicketOrigen || $prefillIdTicketProfesorOrigen || $prefillAccion || $prefillDesdeHorario || ($error !== '' && isset($_POST['programar']));
  // Si el formulario vuelve con error se conservan los datos escritos.
  $volvio = $error !== '' && isset($_POST['programar']);
  $pv = static fn(string $campo, string $def = ''): string => $volvio ? (string)($_POST[$campo] ?? $def) : $def;
  if ($volvio && !$prefillIdSala) { $prefillIdSala = (int)($_POST['id_sala'] ?? 0); }
  $tipoPrefill = $pv('tipo', $prefillAccion['tipo'] ?? 'MANTENIMIENTO_PREVENTIVO');
  $subProgramar = $prefillAccion ? 'Acción correctiva #' . (int)$prefillAccion['id_accion'] . ' · fecha objetivo ' . date('d/m/Y', strtotime($prefillAccion['fecha_objetivo'])) : ($prefillIdTicketOrigen ? 'Mantenimiento elevado desde ticket #' . (int)$prefillIdTicketOrigen : ($prefillIdTicketProfesorOrigen ? 'Mantenimiento elevado desde ticket de profesor #' . (int)$prefillIdTicketProfesorOrigen : 'Mantenimiento de sala, de un equipo o instalación de software.'));
?>
<?= vfAbrir('vf-programar', 'Programar nuevo mantenimiento', $subProgramar, (bool)$abrirProgramar, 'lg') ?>
  <?php if ($error !== '' && isset($_POST['programar'])): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
  <form method="POST" id="form-programar-mant">
    <?= csrfField() ?>
    <?php if ($prefillIdTicketOrigen): ?>
      <input type="hidden" name="id_ticket_origen" value="<?= (int)$prefillIdTicketOrigen ?>">
    <?php endif; ?>
    <?php if ($prefillIdTicketProfesorOrigen): ?>
      <input type="hidden" name="id_ticket_profesor_origen" value="<?= (int)$prefillIdTicketProfesorOrigen ?>">
    <?php endif; ?>
    <?php $idAccionForm = $prefillAccion['id_accion'] ?? (int)($volvio ? ($_POST['id_accion_origen'] ?? 0) : 0); ?>
    <?php if ($idAccionForm): ?>
      <input type="hidden" name="id_accion_origen" value="<?= (int)$idAccionForm ?>">
    <?php endif; ?>
    <?php $alcancePrefill = $pv('alcance_equipo', 'TODOS'); $equiposPrefill = $volvio ? array_map('intval', (array)($_POST['ids_equipo'] ?? [])) : []; ?>
    <fieldset class="form-paso">
      <legend><span class="form-paso-num">1</span> Dónde y qué</legend>
      <div class="form-grid">
        <div class="field">
          <label for="id_sala">Sala</label>
          <select id="id_sala" name="id_sala" required>
            <option value="">-- Seleccione --</option>
            <?php foreach ($salas as $s): ?>
              <option value="<?= (int)$s['id_sala'] ?>" <?= $prefillIdSala === (int)$s['id_sala'] ? 'selected' : '' ?>><?= h($s['abreviatura']) ?> — <?= h($s['nombre_sala']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="tipo">Tipo</label>
          <select id="tipo" name="tipo" required>
            <?php foreach (MantenimientoService::TIPOS as $t): ?>
              <option value="<?= h($t) ?>" <?= $tipoPrefill === $t ? 'selected' : '' ?>><?= h(etiquetaValor($t)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </fieldset>

    <fieldset class="form-paso">
      <legend><span class="form-paso-num">2</span> Alcance</legend>
      <div class="opciones-tarjeta" role="radiogroup">
        <?php foreach (MantenimientoService::ALCANCE_TEXTO as $valorA => [$tituloA, $ayudaA]): ?>
          <label class="opcion-tarjeta">
            <input type="radio" name="alcance_equipo" value="<?= h($valorA) ?>" <?= $alcancePrefill === $valorA ? 'checked' : '' ?>>
            <span class="opcion-tarjeta-titulo"><?= h($tituloA) ?></span>
            <span class="opcion-tarjeta-ayuda"><?= h($ayudaA) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="hint" id="hint-alcance-software" hidden>La instalación de software es solo para equipos de cómputo: el videobeam no se puede elegir.</p>
      <div id="campo-equipos" class="mt-3" hidden>
        <div class="flex-row items-center gap-2 mb-2">
          <strong>Equipos a revisar</strong>
          <button type="button" class="btn btn-secondary btn-sm" id="btn-todos-equipos">Marcar todos</button>
          <span class="hint" id="contador-equipos"></span>
        </div>
        <div class="lista-equipos" id="lista-equipos" data-seleccion="<?= h(implode(',', $equiposPrefill)) ?>">
          <p class="hint">Primero elige la sala.</p>
        </div>
      </div>
    </fieldset>

    <fieldset class="form-paso">
      <legend><span class="form-paso-num">3</span> Detalle del trabajo</legend>
      <div class="field">
        <label for="descripcion">Descripción</label>
        <textarea id="descripcion" name="descripcion" required><?= h($pv('descripcion', $prefillAccion['descripcion'] ?? '')) ?></textarea>
      </div>
      <div class="field">
        <label for="actividades">Actividades a realizar (opcional, una por línea)</label>
        <textarea id="actividades" name="actividades" rows="3" placeholder="Limpiar equipos&#10;Actualizar antivirus&#10;Revisar cableado de red"><?= h($pv('actividades')) ?></textarea>
      </div>
      <div class="field">
        <label for="enlace_referencia">Enlace de referencia (opcional)</label>
        <input type="url" id="enlace_referencia" name="enlace_referencia" placeholder="https://youtube.com/... — video o guía de qué se va a hacer">
        <p class="text-muted fs-85">Ej. un video de YouTube o una guía explicando qué se va a revisar/instalar, para que quien tome el mantenimiento sepa exactamente qué hacer.</p>
      </div>
    </fieldset>

    <fieldset class="form-paso">
      <legend><span class="form-paso-num">4</span> Cuándo y quién</legend>
      <div class="form-grid">
        <div class="field">
          <label for="fecha_programada">Fecha programada</label>
          <input type="datetime-local" id="fecha_programada" name="fecha_programada" required value="<?= h($pv('fecha_programada', $prefillFecha)) ?>">
        </div>
        <div class="field">
          <label for="duracion_minutos">Duración estimada</label>
          <select id="duracion_minutos" name="duracion_minutos">
            <?php foreach ($duraciones as $min => $txt): ?>
              <option value="<?= (int)$min ?>" <?= $min === 60 ? 'selected' : '' ?>><?= h($txt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <p>
        <button type="button" class="btn btn-secondary btn-sm" id="btn-buscar-hueco">Buscar el primer espacio libre</button>
        <span class="hint" id="hint-hueco">Revisa clases, turnos, préstamos y otros mantenimientos de la sala (lunes a sábado, 6:00 a 22:00).</span>
      </p>
      <div id="aviso-choque-mant" class="error mb-3" hidden></div>
      <div class="field" id="campo-confirmar-choque" hidden>
        <label class="flex-row items-center gap-2">
          <input class="w-auto" type="checkbox" id="confirmar_choque" name="confirmar_choque" value="1">
          Confirmo que deseo programar igual, a pesar del choque de horario.
        </label>
      </div>
      <div class="mt-2">
        <label class="fw-normal">
          <input type="checkbox" name="confirmar_sin_equipos" value="1">
          Sé que la sala podría no tener equipos registrados y aun así quiero programar (quedará sin checklist).
        </label>
      </div>
      <div class="field">
        <label for="id_responsable">Coordinador general (opcional)</label>
        <select id="id_responsable" name="id_responsable">
          <option value="">-- Sin asignar --</option>
          <?php foreach ($coordinadoresPosibles as $r): ?>
            <option value="<?= (int)$r['id'] ?>"><?= h($r['nombre_completo']) ?> (<?= h(etiquetaRol((string)$r['role'])) ?>)</option>
          <?php endforeach; ?>
        </select>
        <p class="text-muted fs-85">Solo coordina el mantenimiento en general (fechas, cierre). No bloquea a otros becas — cada quien se suma tomando el equipo que le corresponda en la checklist.</p>
      </div>
    </fieldset>
    <?php if ($ticketsVinculables): ?>
    <fieldset class="form-paso" id="campo-vinculos">
      <legend><span class="form-paso-num">5</span> Reportes que se atienden (opcional)</legend>
      <p class="hint">Daños, reportes QR y solicitudes de profesores de esta sala que tú tomaste. Al cerrar el mantenimiento como realizado se cierran con él.</p>
      <?php foreach ($ticketsVinculables as $v): $clave = $v['tabla'] . ':' . (int)$v['id']; ?>
        <label class="flex-row items-center gap-2 fw-normal" data-vinculo-sala="<?= (int)$v['id_sala'] ?>">
          <input class="w-auto" type="checkbox" name="vinculos[]" value="<?= h($clave) ?>">
          #<?= (int)$v['id'] ?> · <?= h($v['texto']) ?>
        </label>
      <?php endforeach; ?>
      <p class="hint" id="vinculos-vacio" hidden>No tienes reportes abiertos de esta sala.</p>
    </fieldset>
    <?php endif; ?>
    <button type="submit" name="programar" class="btn">Programar</button>
  </form>
<?= vfCerrar() ?>
<?php endif; ?>
