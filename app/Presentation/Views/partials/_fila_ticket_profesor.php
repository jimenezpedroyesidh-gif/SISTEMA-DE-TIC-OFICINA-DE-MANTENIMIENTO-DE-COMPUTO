<?php
/**
 * Fila COMPACTA de un ticket de profesor en el panel del técnico (bandejas Departamento / Externos).
 * Cinco columnas: Ticket · Solicitud · Personas · Estado · Acciones. Lo secundario (conversación, notas,
 * beca, equipos, elevar, descartar, liberar, PDF) va en el menú «Más ▾» y en ventanas flotantes, para que
 * la fila se lea de un vistazo en celular y en escritorio.
 *
 * La bandeja ya separa pendientes y firmados (TicketBandeja), así que la fila nunca se oculta.
 * Espera en scope: $p, $tecnico_id, $becasActivas, $comentariosPorTicket, $equiposYaProfTk,
 * $equiposPorSalaTk y $interaccion (TicketInteraccionService::precargar). Lo incluye seccion_grupo.php.
 */
$idP = (int)$p['id'];
$puedeEditar = (!$p['firmado'] && (int)$p['tecnico_id'] === $tecnico_id);
$mensajesP = $interaccion['conversacion']['tickets_profesor'][$idP] ?? [];
$fotosP = $interaccion['evidencias']['tickets_profesor'][$idP] ?? [];
$comentariosProf = $comentariosPorTicket['tickets_profesor'][$idP] ?? [];
$ultimoMsg = $mensajesP ? end($mensajesP) : null;
$esperaRespuesta = $ultimoMsg && !$ultimoMsg['es_personal'];
$cerradoP = in_array($p['estado'], TicketConversacion::ESTADOS_CERRADOS, true);
$prestamoP = in_array($p['subtipo_requerimiento'] ?? '', ['PRESTAMO_SALA', 'PRESTAMO_EQUIPO'], true);
$esQrProfesorTk = ($p['origen'] ?? '') === 'QR_PROFESOR';
$equiposYaEsteProf = $equiposYaProfTk[$idP] ?? [];
$puedeElevar = !empty($p['id_sala']) && ($p['tipo_ticket'] === 'danio' || ($p['subtipo_requerimiento'] ?? '') === 'INSTALACION_SOFTWARE');
$abiertoParaAsignar = empty($p['firmado']) && !in_array($p['estado'], [TicketWorkflow::APROBADO, TicketWorkflow::CERRADO, TicketWorkflow::DESCARTADO, TicketWorkflow::SOLUCIONADO], true)
    && (empty($p['tecnico_id']) || (int)$p['tecnico_id'] === (int)$tecnico_id) && !empty($becasActivas);
$pref = 'tkp-' . $idP;
?>
<tr id="tkp-<?= $idP ?>">
  <td data-label="Ticket" class="tk-col-id">
    <div class="tk-id">
      <span class="tk-num" title="Ticket de profesor #<?= $idP ?>">P-<?= $idP ?></span>
      <span class="badge <?= $p['tipo_ticket'] === 'requerimiento' ? 'requerimiento' : 'danio' ?>"><?= h(TicketConsultas::etiquetaTipoTicketProfesor($p['tipo_ticket'])) ?></span>
    </div>
    <div class="tk-fecha"><?= h(date('d/m/Y H:i', strtotime((string)$p['fecha_creacion']))) ?><?= $esQrProfesorTk ? ' · QR' : '' ?></div>
  </td>
  <td data-label="Solicitud" class="tk-col-sol"><?php include __DIR__ . '/_tk_resumen_profesor.php'; ?></td>
  <td data-label="Personas">
    <div class="tk-personas">
      <span><span class="tk-persona-rol">Profesor</span> <?= h($p['profesor_nombre']) ?></span>
      <span><span class="tk-persona-rol">Atiende</span> <?= $p['tecnico_nombre'] ? h($p['tecnico_nombre']) : '<span class="text-muted">Sin asignar</span>' ?></span>
      <?php if (!empty($p['beca_id'])): ?><span><span class="tk-persona-rol">Beca</span> <?= h($p['beca_nombre'] ?? ('#' . (int)$p['beca_id'])) ?></span><?php endif; ?>
    </div>
  </td>
  <td data-label="Estado">
    <span class="chip <?= claseChipHistorialTicket((string)$p['estado']) ?>"><?= h($p['estado']) ?></span>
    <div class="tk-indicadores">
      <?php if ($mensajesP): ?><button type="button" class="tk-ind<?= $esperaRespuesta ? ' alerta' : '' ?>" data-vf-abrir="<?= $pref ?>-chat"><?= $esperaRespuesta ? 'Mensaje sin responder' : count($mensajesP) . ' mensaje(s)' ?></button><?php endif; ?>
      <?php if ($fotosP): ?><span class="tk-ind"><?= count($fotosP) ?> foto(s)</span><?php endif; ?>
      <?php if (!empty($p['mantenimiento_generado'])): ?><span class="tk-ind">Mant. #<?= (int)$p['mantenimiento_generado'] ?> · <?= (int)$p['mant_realizados'] ?>/<?= (int)$p['mant_total_equipos'] ?></span><?php endif; ?>
      <?php if (!empty($p['sugerido_mantenimiento_general']) && empty($p['mantenimiento_generado'])): ?><span class="tk-ind alerta">Beca sugiere mantenimiento</span><?php endif; ?>
    </div>
  </td>
  <td data-label="Acciones" class="tk-col-acc">
    <div class="tk-acciones">
      <?php if (TicketBandeja::estaFirmado($p)): ?>
        <?php /* firmado/cerrado: sin acción principal, solo consulta desde «Más» */ ?>
      <?php elseif (empty($p['tecnico_id'])): ?>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="ticket_id" value="<?= $idP ?>"><button class="btn btn-sm" name="tomar_profesor">Tomar</button></form>
      <?php elseif ($p['estado'] === TicketWorkflow::SOLUCIONADO): ?>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="ticket_id" value="<?= $idP ?>"><button class="btn btn-info btn-sm" name="aprobar_profesor_tecnico">Aprobar</button></form>
        <form method="POST" data-confirm="¿Rechazar? El ticket vuelve a «En proceso» para corregirlo."><?= csrfField() ?><input type="hidden" name="ticket_id" value="<?= $idP ?>"><button class="btn btn-danger btn-sm" name="rechazar_profesor_tecnico">Rechazar</button></form>
      <?php elseif ($puedeEditar): ?>
        <button type="button" class="btn btn-sm" data-vf-abrir="<?= $pref ?>-estado">Actualizar</button>
      <?php else: ?>
        <span class="chip">Asignado</span>
      <?php endif; ?>
      <?php
      $menuItems = [
          ['vf' => $pref . '-chat', 'texto' => 'Conversación con el profesor' . ($mensajesP ? ' (' . count($mensajesP) . ')' : '')],
          ($fotosP && !$puedeEditar) ? ['vf' => $pref . '-fotos', 'texto' => 'Ver fotos y solución (' . count($fotosP) . ')'] : null,
          ['vf' => $pref . '-com', 'texto' => 'Notas internas (' . count($comentariosProf) . ')'],
          $abiertoParaAsignar ? ['vf' => $pref . '-beca', 'texto' => !empty($p['beca_id']) ? 'Cambiar / quitar beca' : 'Asignar a una beca'] : null,
          ($esQrProfesorTk && $puedeEditar && !$equiposYaEsteProf && !empty($equiposPorSalaTk[(int)$p['id_sala']])) ? ['vf' => $pref . '-eq', 'texto' => 'Agregar equipos afectados'] : null,
          ($puedeEditar && $p['tipo_ticket'] === 'danio' && !empty($p['id_sala'])) ? ['href' => 'create_ticket_beca.php?id_ticket_profesor_origen=' . $idP . '#form-cambio_pieza', 'texto' => 'Cambio de pieza para este ticket'] : null,
          ($puedeElevar && $puedeEditar && empty($p['mantenimiento_generado']) && $p['estado'] !== TicketWorkflow::SOLUCIONADO) ? ['href' => 'mantenimientos_programados.php?elevar_ticket_profesor=' . $idP, 'texto' => 'Elevar a mantenimiento de sala'] : null,
          ($prestamoP && !$p['devuelto'] && $puedeEditar) ? ['form' => 'confirmar_devolucion_profesor', 'texto' => 'Confirmar devolución', 'campos' => ['ticket_id' => $idP], 'confirm' => '¿Confirmas que ya recibiste de vuelta lo prestado?'] : null,
          ($p['tipo_ticket'] === 'danio' && !$p['firmado'] && !$cerradoP) ? ['vf' => $pref . '-desc', 'texto' => 'Descartar (duplicado / inválido)'] : null,
          $puedeEditar ? ['form' => 'liberar_profesor', 'texto' => 'Liberar ticket', 'campos' => ['ticket_id' => $idP]] : null,
          ['href' => 'ticket_profesor_pdf.php?id=' . $idP, 'texto' => 'Ver PDF', 'nueva' => true],
      ];
      include __DIR__ . '/_tk_menu.php';
      ?>
    </div>
    <?php if ($equiposYaEsteProf): ?>
      <p class="hint m-0">Equipos: <?= h(implode(', ', array_column($equiposYaEsteProf, 'codigo'))) ?></p>
    <?php endif; ?>

    <?php /* ---------- Ventanas flotantes de la fila ---------- */ ?>
    <?php $cv = ['id' => $pref . '-chat', 'tabla' => TablaTicket::PROFESOR, 'ticket' => $p, 'mensajes' => $mensajesP, 'modo' => 'personal', 'puede' => TicketConversacion::personalPuedeEscribir($p, (int)$tecnico_id), 'ancla' => 'tkp-' . $idP]; include __DIR__ . '/_tk_conversacion.php'; ?>

    <?php if ($puedeEditar): ?>
      <?= vfAbrir($pref . '-estado', 'Ticket de profesor #' . $idP . ' · Actualizar', TicketEvidencia::exige(TablaTicket::PROFESOR, $p) ? 'Para «Solucionado» explica qué hiciste y adjunta fotos: quedan en el PDF.' : 'Cambia el estado y, si quieres, deja la explicación.', false, 'md') ?>
        <form method="POST" enctype="multipart/form-data">
          <?= csrfField() ?>
          <input type="hidden" name="id" value="<?= $idP ?>">
          <div class="field">
            <label for="<?= $pref ?>-est">Estado</label>
            <select id="<?= $pref ?>-est" name="estado">
              <?php foreach (TicketEstadoService::ESTADOS_ACTUALIZABLES_TECNICO_PROFESOR as $op): ?>
                <option value="<?= h($op) ?>" <?= $p['estado'] === $op ? 'selected' : '' ?>><?= h($op) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php $ccId = $pref . '-c'; $ccExige = TicketEvidencia::exige(TablaTicket::PROFESOR, $p); $ccSolucion = $p['solucion'] ?? ''; $ccFotos = $fotosP; include __DIR__ . '/_tk_campos_cierre.php'; ?>
          <button class="btn" type="submit" name="update_profesor">Guardar</button>
        </form>
      <?= vfCerrar() ?>
    <?php endif; ?>

    <?php if ($fotosP && !$puedeEditar): ?>
      <?= vfAbrir($pref . '-fotos', 'Ticket de profesor #' . $idP . ' · Solución y evidencia', '', false, 'md') ?>
        <?php if (!empty($p['solucion'])): ?><p class="nota-panel"><b>Solución:</b><br><?= nl2br(h($p['solucion'])) ?></p><?php endif; ?>
        <?php $evFotos = $fotosP; $evQuitarId = null; include __DIR__ . '/_tk_evidencias.php'; ?>
      <?= vfCerrar() ?>
    <?php endif; ?>

    <?= vfAbrir($pref . '-com', 'Notas internas · ticket de profesor #' . $idP, 'Solo entre técnico, beca y admin (el profesor no las ve).', false, 'md') ?>
      <?php foreach ($comentariosProf as $c): ?>
        <p class="nota-panel"><b><?= h($c['nombre_completo']) ?></b> (<?= h($c['role']) ?>) — <?= h($c['fecha_creacion']) ?><br><?= nl2br(h($c['comentario'])) ?></p>
      <?php endforeach; ?>
      <?php if ($puedeEditar): ?>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="tabla_ticket" value="tickets_profesor">
          <input type="hidden" name="ticket_id" value="<?= $idP ?>">
          <textarea name="comentario" rows="2" placeholder="Escribe una nota interna..."></textarea>
          <button class="btn btn-sm" name="agregar_comentario">Agregar nota</button>
        </form>
      <?php else: ?>
        <p class="hint">Debes tomar este ticket para poder agregar notas.</p>
      <?php endif; ?>
    <?= vfCerrar() ?>

    <?php if ($abiertoParaAsignar): ?>
      <?= vfAbrir($pref . '-beca', 'Ticket de profesor #' . $idP . ' · Beca ejecutora', 'La beca lo resuelve y tú lo apruebas.', false, 'md') ?>
        <?php $tablaAsignar = TablaTicket::PROFESOR; $filaAsignar = $p; include __DIR__ . '/_asignar_beca.php'; ?>
      <?= vfCerrar() ?>
    <?php endif; ?>

    <?php if ($esQrProfesorTk && $puedeEditar && !$equiposYaEsteProf && !empty($equiposPorSalaTk[(int)$p['id_sala']])): ?>
      <?= vfAbrir($pref . '-eq', 'Ticket de profesor #' . $idP . ' · Equipos afectados', 'Marca los equipos de la sala que tienen el problema.', false, 'md') ?>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="tabla_ticket" value="tickets_profesor">
          <input type="hidden" name="ticket_id" value="<?= $idP ?>">
          <div class="checklist checklist-scroll">
            <?php foreach ($equiposPorSalaTk[(int)$p['id_sala']] as $eq): ?>
              <label class="d-block py-1"><input type="checkbox" name="ids_equipo[]" value="<?= (int)$eq['id_equipo'] ?>"> <?= h($eq['codigo'] . ' — ' . ($eq['descripcion'] ?? '')) ?></label>
            <?php endforeach; ?>
          </div>
          <button class="btn btn-sm mt-2" type="submit" name="agregar_equipos_ticket">Guardar equipo(s)</button>
        </form>
      <?= vfCerrar() ?>
    <?php endif; ?>

    <?php if ($p['tipo_ticket'] === 'danio' && !$p['firmado'] && !$cerradoP): ?>
      <?= vfAbrir($pref . '-desc', 'Descartar ticket de profesor #' . $idP, 'Para reportes inválidos o duplicados. No se puede deshacer.', false, 'md') ?>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="ticket_id" value="<?= $idP ?>">
          <label>Motivo (reporte inválido, duplicado, etc.)</label>
          <textarea name="motivo_descarte" rows="2"></textarea>
          <button class="btn btn-danger btn-sm" name="descartar_profesor" data-confirm-click="¿Descartar este ticket? No se puede deshacer.">Descartar</button>
        </form>
      <?= vfCerrar() ?>
    <?php endif; ?>
  </td>
</tr>
