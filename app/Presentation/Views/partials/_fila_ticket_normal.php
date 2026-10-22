<?php
/**
 * Fila COMPACTA de un ticket normal (tabla `tickets`) en el panel del técnico: Ticket · Solicitud ·
 * Personas · Estado · Acciones. La acción principal queda a la vista (Tomar / Editar / Aprobar / pasos del
 * traslado) y el resto va en «Más ▾» y en ventanas flotantes. El formulario de edición (3 modos de
 * resolución + cambio de pieza/traslado) es el mismo de siempre, ahora con las fotos de evidencia.
 *
 * Espera en scope: $t, $tecnico_id, $becasActivas, $todasLasSalas, $comentariosPorTicket,
 * $equiposYaQrTk, $equiposPorSalaTk y $interaccion (TicketInteraccionService::precargar).
 */
$idT = (int)$t['id'];
$puedeEditar = (!$t['firmado'] && (int)$t['tecnico_id'] === $tecnico_id);
$esQrAnonimo = ($t['origen'] ?? '') === 'QR_ANONIMO';
$conSolicitante = in_array($t['origen'] ?? '', ['QR_ANONIMO', 'PANEL_INTERNO'], true);
$mensajesT = $interaccion['conversacion']['tickets'][$idT] ?? [];
$fotosT = $interaccion['evidencias']['tickets'][$idT] ?? [];
$comentariosTicket = $comentariosPorTicket['tickets'][$idT] ?? [];
$ultimoMsgT = $mensajesT ? end($mensajesT) : null;
$esperaRespuestaT = $ultimoMsgT && !$ultimoMsgT['es_personal'];
$equiposYaEsteTk = $equiposYaQrTk[$idT] ?? [];
$cerradoT = in_array($t['estado'], TicketConversacion::ESTADOS_CERRADOS, true);
$conPieza = !empty($t['id_componente_saliente']) || !empty($t['id_componente_entrante']);
$origenElegibleParaMant = in_array($t['origen'] ?? '', ['QR_ANONIMO', 'BECA_DIRECTO', 'TECNICO_DIRECTO'], true);
$abiertoParaAsignar = empty($t['firmado']) && !in_array($t['estado'], [TicketWorkflow::APROBADO, TicketWorkflow::CERRADO, TicketWorkflow::DESCARTADO, TicketWorkflow::SOLUCIONADO], true)
    && (empty($t['tecnico_id']) || (int)$t['tecnico_id'] === (int)$tecnico_id) && !empty($becasActivas);
$etiquetaOrigen = ['QR_ANONIMO' => 'QR', 'PANEL_INTERNO' => 'Solicitante', 'BECA_DIRECTO' => 'Beca', 'TECNICO_DIRECTO' => 'Técnico'][$t['origen'] ?? ''] ?? 'Ticket';
$tituloT = trim(($t['nombre_sala'] ?? '') !== '' ? $t['nombre_sala'] . ($t['equipo'] ? ' · ' . $t['equipo'] : '') : ($t['equipo'] ?: ($t['nombre_dependencia'] ?? 'Ticket')));
$pref = 'tk-' . $idT;
?>
<tr id="tk-<?= $idT ?>">
  <td data-label="Ticket" class="tk-col-id">
    <div class="tk-id">
      <span class="tk-num">#<?= $idT ?></span>
      <span class="badge <?= $esQrAnonimo ? 'danio' : 'requerimiento' ?>"><?= h($etiquetaOrigen) ?></span>
    </div>
    <div class="tk-fecha"><?= h(date('d/m/Y H:i', strtotime((string)$t['fecha_creacion']))) ?></div>
  </td>
  <td data-label="Solicitud" class="tk-col-sol">
    <div class="tk-titulo"><?= h($tituloT) ?></div>
    <div class="tk-sub"><?= h(trim(($t['nombre_dependencia'] ?? '') . (($t['ubicacion'] ?? '') !== '' ? ' · ' . $t['ubicacion'] : ''), ' ·')) ?></div>
    <p class="tk-desc"><?= h((string)$t['descripcion']) ?></p>
    <details class="tk-detalle">
      <summary>Ver detalle</summary>
      <dl>
        <dt>Descripción</dt><dd><?= nl2br(h((string)$t['descripcion'])) ?></dd>
        <?php if (!empty($t['radicado_datarsoft'])): ?><dt>Radicado DatArSoft</dt><dd><?= h($t['radicado_datarsoft']) ?></dd><?php endif; ?>
        <?php if (!empty($t['id_sala_destino'])): ?><dt>Mover a</dt><dd><?= h($t['nombre_sala_destino'] ?? ('sala #' . (int)$t['id_sala_destino'])) ?></dd><?php endif; ?>
        <?php if (!empty($t['marca_modelo'])): ?><dt>Marca / modelo</dt><dd><?= h($t['marca_modelo']) ?></dd><?php endif; ?>
        <?php if (!empty($t['numero_inventario'])): ?><dt>Inventario</dt><dd><?= h($t['numero_inventario']) ?></dd><?php endif; ?>
        <?php if ($equiposYaEsteTk): ?><dt>Equipos</dt><dd><?= h(implode(', ', array_column($equiposYaEsteTk, 'codigo'))) ?></dd><?php endif; ?>
        <?php if (!empty($t['solucion'])): ?><dt>Solución</dt><dd><?= nl2br(h($t['solucion'])) ?></dd><?php endif; ?>
      </dl>
    </details>
  </td>
  <td data-label="Personas">
    <div class="tk-personas">
      <span><span class="tk-persona-rol">Solicita</span>
        <?php if ($t['solicitante_nombre']): ?><?= h($t['solicitante_nombre']) ?>
        <?php elseif (!empty($t['nombre_contacto'])): ?><?= h($t['nombre_contacto']) ?><?php if (!empty($t['correo_contacto'])): ?> · <a href="mailto:<?= h($t['correo_contacto']) ?>"><?= h($t['correo_contacto']) ?></a><?php endif; ?>
        <?php else: ?><span class="text-muted">Anónimo (QR)</span><?php endif; ?>
      </span>
      <span><span class="tk-persona-rol">Atiende</span> <?= $t['tecnico_nombre'] ? h($t['tecnico_nombre']) : '<span class="text-muted">Sin asignar</span>' ?></span>
      <?php if (!empty($t['beca_id'])): ?><span><span class="tk-persona-rol">Beca</span> <?= h($t['beca_nombre'] ?? ('#' . (int)$t['beca_id'])) ?></span><?php endif; ?>
    </div>
  </td>
  <td data-label="Estado">
    <span class="chip <?= claseChipHistorialTicket((string)$t['estado']) ?>"><?= h($t['estado']) ?></span>
    <div class="tk-indicadores">
      <?php if ($mensajesT): ?><button type="button" class="tk-ind<?= $esperaRespuestaT ? ' alerta' : '' ?>" data-vf-abrir="<?= $pref ?>-chat"><?= $esperaRespuestaT ? 'Mensaje sin responder' : count($mensajesT) . ' mensaje(s)' ?></button><?php endif; ?>
      <?php if ($fotosT): ?><span class="tk-ind"><?= count($fotosT) ?> foto(s)</span><?php endif; ?>
      <?php if ($conPieza && $t['estado'] === TicketWorkflow::SOLUCIONADO): ?><span class="tk-ind alerta">Cambio de pieza</span><?php endif; ?>
      <?php if (!empty($t['mantenimiento_generado'])): ?><span class="tk-ind">Mant. #<?= (int)$t['mantenimiento_generado'] ?> · <?= (int)$t['mant_realizados'] ?>/<?= (int)$t['mant_total_equipos'] ?></span><?php endif; ?>
    </div>
  </td>
  <td data-label="Acciones" class="tk-col-acc">
    <div class="tk-acciones">
      <?php if (TicketBandeja::estaFirmado($t)): ?>
        <?php /* firmado/cerrado: sin acción principal, solo consulta desde «Más» */ ?>
      <?php elseif (empty($t['tecnico_id'])): ?>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="ticket_id" value="<?= $idT ?>"><button class="btn btn-sm" name="tomar_ticket">Tomar</button></form>
      <?php elseif ($t['estado'] === TicketWorkflow::SOLUCIONADO && $conPieza): ?>
        <form method="POST" data-confirm="Aprobar ejecuta el movimiento real de inventario. ¿Continuar?"><?= csrfField() ?><input type="hidden" name="ticket_id" value="<?= $idT ?>"><button class="btn btn-info btn-sm" name="aprobar_inventario_ticket">Aprobar cambio</button></form>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="ticket_id" value="<?= $idT ?>"><button class="btn btn-danger btn-sm" name="rechazar_inventario_ticket">Rechazar</button></form>
      <?php elseif ($t['estado'] === TicketWorkflow::SOLUCIONADO): ?>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="ticket_id" value="<?= $idT ?>"><button class="btn btn-info btn-sm" name="aprobar_ticket_tecnico">Aprobar</button></form>
        <form method="POST" data-confirm="¿Rechazar? El ticket vuelve a trabajo para corregirlo."><?= csrfField() ?><input type="hidden" name="ticket_id" value="<?= $idT ?>"><button class="btn btn-danger btn-sm" name="rechazar_ticket_tecnico">Rechazar</button></form>
      <?php elseif ($t['estado'] === TicketWorkflow::LISTO_SIN_VALIDAR): ?>
        <?php if (!$t['traslado_aprobado']): ?>
          <form method="POST"><?= csrfField() ?><input type="hidden" name="ticket_id" value="<?= $idT ?>"><button class="btn btn-info btn-sm" name="aprobar_traslado_ticket" title="Paso 1 de 2">Aprobar traslado</button></form>
        <?php elseif (!$t['reubicado']): ?>
          <form method="POST"><?= csrfField() ?><input type="hidden" name="ticket_id" value="<?= $idT ?>"><button class="btn btn-secondary btn-sm" name="marcar_reubicado_ticket">Ya lo reubiqué</button></form>
        <?php else: ?>
          <form method="POST"><?= csrfField() ?><input type="hidden" name="ticket_id" value="<?= $idT ?>"><button class="btn btn-info btn-sm" name="aprobar_reubicacion_final" title="Paso 2 de 2">Aprobar y cerrar</button></form>
        <?php endif; ?>
      <?php elseif ($puedeEditar): ?>
        <button type="button" class="btn btn-sm" data-vf-abrir="<?= $pref ?>-ed">Editar</button>
      <?php else: ?>
        <span class="chip">Asignado</span>
      <?php endif; ?>
      <?php
      $menuItems = [
          $conSolicitante ? ['vf' => $pref . '-chat', 'texto' => 'Conversación con quien reportó' . ($mensajesT ? ' (' . count($mensajesT) . ')' : '')] : null,
          ($puedeEditar && $t['estado'] === TicketWorkflow::LISTO_SIN_VALIDAR) ? ['vf' => $pref . '-ed', 'texto' => 'Editar ticket'] : null,
          ($fotosT && !$puedeEditar) ? ['vf' => $pref . '-fotos', 'texto' => 'Ver fotos y solución (' . count($fotosT) . ')'] : null,
          ['vf' => $pref . '-com', 'texto' => 'Notas internas (' . count($comentariosTicket) . ')'],
          $abiertoParaAsignar ? ['vf' => $pref . '-beca', 'texto' => !empty($t['beca_id']) ? 'Cambiar / quitar beca' : 'Asignar a una beca'] : null,
          ($esQrAnonimo && $puedeEditar && !$equiposYaEsteTk && !empty($equiposPorSalaTk[(int)$t['id_sala']])) ? ['vf' => $pref . '-eq', 'texto' => 'Agregar equipos afectados'] : null,
          (!empty($t['id_sala']) && $origenElegibleParaMant && $puedeEditar && empty($t['mantenimiento_generado'])) ? ['href' => 'mantenimientos_programados.php?elevar_ticket=' . $idT, 'texto' => 'Elevar a mantenimiento de sala'] : null,
          (!$t['firmado'] && !$cerradoT) ? ['vf' => $pref . '-desc', 'texto' => 'Descartar (duplicado / inválido)'] : null,
          $puedeEditar ? ['form' => 'liberar_ticket', 'texto' => 'Liberar ticket', 'campos' => ['ticket_id' => $idT]] : null,
          ['href' => 'ticket_pdf.php?id=' . $idT, 'texto' => 'Ver PDF', 'nueva' => true],
      ];
      include __DIR__ . '/_tk_menu.php';
      ?>
    </div>

    <?php /* ---------- Ventanas flotantes de la fila ---------- */ ?>
    <?php if ($conSolicitante): ?>
      <?php $cv = ['id' => $pref . '-chat', 'tabla' => TablaTicket::NORMAL, 'ticket' => $t, 'mensajes' => $mensajesT, 'modo' => 'personal', 'puede' => TicketConversacion::personalPuedeEscribir($t, (int)$tecnico_id)]; include __DIR__ . '/_tk_conversacion.php'; ?>
    <?php endif; ?>

    <?php if ($fotosT && !$puedeEditar): ?>
      <?= vfAbrir($pref . '-fotos', 'Ticket #' . $idT . ' · Solución y evidencia', '', false, 'md') ?>
        <?php if (!empty($t['solucion'])): ?><p class="nota-panel"><b>Solución:</b><br><?= nl2br(h($t['solucion'])) ?></p><?php endif; ?>
        <?php $evFotos = $fotosT; $evQuitarId = null; include __DIR__ . '/_tk_evidencias.php'; ?>
      <?= vfCerrar() ?>
    <?php endif; ?>

    <?= vfAbrir($pref . '-com', 'Notas internas · ticket #' . $idT, 'Solo entre técnico, beca y admin (quien reportó no las ve).', false, 'md') ?>
      <?php foreach ($comentariosTicket as $c): ?>
        <p class="nota-panel"><b><?= h($c['nombre_completo']) ?></b> (<?= h($c['role']) ?>) — <?= h($c['fecha_creacion']) ?><br><?= nl2br(h($c['comentario'])) ?></p>
      <?php endforeach; ?>
      <?php if ($puedeEditar): ?>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="tabla_ticket" value="tickets">
          <input type="hidden" name="ticket_id" value="<?= $idT ?>">
          <textarea name="comentario" rows="2" placeholder="Escribe una nota interna..."></textarea>
          <button class="btn btn-sm" name="agregar_comentario">Agregar nota</button>
        </form>
      <?php else: ?>
        <p class="hint">Debes tomar este ticket para poder agregar notas.</p>
      <?php endif; ?>
    <?= vfCerrar() ?>

    <?php if ($abiertoParaAsignar): ?>
      <?= vfAbrir($pref . '-beca', 'Ticket #' . $idT . ' · Beca ejecutora', 'La beca lo resuelve y tú lo apruebas.', false, 'md') ?>
        <?php $tablaAsignar = TablaTicket::NORMAL; $filaAsignar = $t; include __DIR__ . '/_asignar_beca.php'; ?>
      <?= vfCerrar() ?>
    <?php endif; ?>

    <?php if ($esQrAnonimo && $puedeEditar && !$equiposYaEsteTk && !empty($equiposPorSalaTk[(int)$t['id_sala']])): ?>
      <?= vfAbrir($pref . '-eq', 'Ticket #' . $idT . ' · Equipos afectados', 'Marca los equipos de la sala que tienen el problema.', false, 'md') ?>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="tabla_ticket" value="tickets">
          <input type="hidden" name="ticket_id" value="<?= $idT ?>">
          <div class="checklist checklist-scroll">
            <?php foreach ($equiposPorSalaTk[(int)$t['id_sala']] as $eq): ?>
              <label class="d-block py-1"><input type="checkbox" name="ids_equipo[]" value="<?= (int)$eq['id_equipo'] ?>"> <?= h($eq['codigo'] . ' — ' . ($eq['descripcion'] ?? '')) ?></label>
            <?php endforeach; ?>
          </div>
          <button class="btn btn-sm mt-2" type="submit" name="agregar_equipos_ticket">Guardar equipo(s)</button>
        </form>
      <?= vfCerrar() ?>
    <?php endif; ?>

    <?php if (!$t['firmado'] && !$cerradoT): ?>
      <?= vfAbrir($pref . '-desc', 'Descartar ticket #' . $idT, 'Para reportes inválidos o duplicados. No se puede deshacer.', false, 'md') ?>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="ticket_id" value="<?= $idT ?>">
          <label>Motivo (reporte inválido, duplicado, etc.)</label>
          <textarea name="motivo_descarte" rows="2"></textarea>
          <button class="btn btn-danger btn-sm" name="descartar_ticket" data-confirm-click="¿Descartar este ticket? No se puede deshacer.">Descartar</button>
        </form>
      <?= vfCerrar() ?>
    <?php endif; ?>

    <?php if ($puedeEditar): ?>
            <?php
              // 3 modos según lo que el ticket ya sabe:
              //  - equipo_especifico: id_equipo puntual -> se muestran las
              //    piezas ya asignadas (solo lectura) en vez de re-pedirlas.
              //  - sala_general: se sabe la sala pero no un equipo puntual
              //    (p. ej. reporte QR "sobre la sala en general") -> el
              //    técnico elige a qué equipo de esa sala le dio la
              //    solución, y el tipo de resolución (cambio de pieza /
              //    traslado a oficina / solución en sitio).
              //  - libre: ticket sin ningún vínculo real a inventario
              //    (id_equipo e id_sala vacíos) -> se ofrece ligarlo a una
              //    sala real (por si en realidad sí es sobre un salón con
              //    inventario, como "SA401-PC01, torre no enciende" escrito
              //    en el formulario de texto libre) y, si se liga, se
              //    reusa el mismo flujo de sala_general; si no se liga,
              //    se mantiene el texto libre de siempre.
              if (!empty($t['id_equipo'])) {
                  $modoTicket = 'equipo_especifico';
              } elseif (!empty($t['id_sala'])) {
                  $modoTicket = 'sala_general';
              } else {
                  $modoTicket = 'libre';
              }
            ?>
            <?= vfAbrir('tk-ed-' . (int)$t['id'], 'Ticket #' . (int)$t['id'] . ' · Editar / cambiar estado', 'Registra la solución y actualiza el estado del ticket.', false, 'lg') ?>
              <form method="POST" class="form-resolucion-ticket" enctype="multipart/form-data">
              <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <input type="hidden" name="modo_resolucion" class="input-modo-resolucion" value="<?= h($modoTicket) ?>">

                <?php if ($modoTicket === 'equipo_especifico'): ?>
                  <p class="hint">
                    <b>Equipo:</b> <?= h($t['equipo']) ?><br>
                    <?php if (!empty($t['componentes_actuales'])): ?>
                      <b>Piezas ya registradas para este equipo:</b> <?= h($t['componentes_actuales']) ?>
                    <?php else: ?>
                      Este equipo todavía no tiene piezas registradas en el inventario.
                    <?php endif; ?>
                  </p>

                  <label>Solución</label>
                  <textarea name="solucion" required><?= h($t['solucion']) ?></textarea>

                  <details class="mt-2" data-widget-traslado>
                    <summary>Propuesta de cambio de pieza <span class="hint-requerido">(opcional — obligatorio solo si el Estado se pone en "Trasladado a bodega/oficina")</span></summary>
                    <?php $prefix = "t{$t['id']}_"; $requerido = false; $idSalaContexto = $t['id_sala'] ?? null; include PARCIALES_DIR . '/buscador_componente_widget.php'; ?>
                  </details>

                <?php elseif (in_array($modoTicket, ['sala_general', 'libre'], true)): ?>
                  <?php if ($modoTicket === 'sala_general'): ?>
                    <p class="hint">Este ticket es de la sala en general, no de un equipo puntual. Indica a qué equipo de la sala le diste la solución para que quede en su historial.</p>
                  <?php else: ?>
                    <?php /* Este ticket viene del formulario clásico de
                         solicitante, sin sala/equipo real vinculado, pero
                         en la práctica casi siempre describe un equipo
                         real de un salón (ej. "SA401-PC01, torre no
                         enciende"). Se ofrece ligarlo a una sala real; si
                         se elige una, se reusa exactamente el mismo flujo
                         de "sala_general" de abajo (equipo + tipo de
                         resolución + componente). Si no aplica (una
                         oficina sin inventario registrado), se deja el
                         texto libre de siempre. */ ?>
                    <p class="hint">Si este reporte es en realidad sobre un salón/laboratorio con inventario registrado, selecciónalo para poder ligar la solución (y, si aplica, el traslado de una pieza) a ese equipo. Si no aplica, déjalo en blanco y usa los campos de abajo.</p>
                    <label>¿Es sobre esta sala? (opcional)</label>
                    <select class="select-sala-libre" data-change="tickets.salaLibre">
                      <option value="">-- No aplica / dependencia sin inventario --</option>
                      <?php foreach ($todasLasSalas as $s): ?>
                        <option value="<?= (int)$s['id_sala'] ?>"><?= h($s['nombre_sala']) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="id_sala_libre" class="input-id-sala-libre" value="">
                  <?php endif; ?>

                  <div class="bloque-con-sala"<?= $modoTicket === 'libre' ? ' hidden' : '' ?>>
                    <label>Equipo que recibió la solución</label>
                    <select name="id_equipo_afectado" class="select-equipo-afectado" data-id-sala="<?= (int)$t['id_sala'] ?>" <?= $modoTicket === 'sala_general' ? 'required' : 'disabled' ?>>
                      <option value="">Cargando equipos de la sala…</option>
                    </select>

                    <label>Tipo de resolución</label>
                    <select name="tipo_resolucion_general" class="select-tipo-resolucion" <?= $modoTicket === 'sala_general' ? 'required' : 'disabled' ?>>
                      <option value="">-- Selecciona --</option>
                      <option value="cambio_pieza">Cambio de pieza</option>
                      <option value="traslado_oficina">Traslado a bodega/oficina de mantenimiento</option>
                      <option value="solucion">Solución en sitio</option>
                    </select>

                    <?php /* Un solo campo "solución" (name="solucion") para todo
                         este bloque: si se repite el mismo name dentro de
                         varios bloques ocultos, el navegador manda ambos
                         valores y el que "gana" en $_POST deja de ser el
                         que el técnico realmente llenó. Solo se
                         oculta/exige según el tipo. En modo "libre" arranca
                         deshabilitado (ver .bloque-libre-sin-sala abajo, que
                         trae su PROPIO "solucion" — deshabilitar uno de los
                         dos evita que el navegador mande los dos a la vez). */ ?>
                    <div class="bloque-tipo-resolucion" data-tipo="cambio_pieza" hidden>
                      <?php $prefix = "t{$t['id']}_"; $requerido = true; $requeridoEntrante = false; $idSalaContexto = $t['id_sala'] ?? null; include PARCIALES_DIR . '/buscador_componente_widget.php'; ?>
                    </div>

                    <div class="bloque-tipo-resolucion" data-tipo="traslado_oficina" hidden>
                      <p class="hint">El ticket pasará a "Trasladado a bodega/oficina" y seguirá el mismo proceso de dos aprobaciones que ya usan los demás traslados.</p>
                      <?php /* Se pregunta qué pieza física se lleva (la torre,
                           normalmente), para que el equipo quede marcado
                           como no operativo y quede historial del
                           componente. Es obligatorio: sin eso, el
                           inventario nunca se movería. Usa un `name`
                           distinto (id_componente_saliente_traslado) al
                           del bloque "Cambio de pieza" de arriba para que
                           no colisionen si ambos existen ocultos en el
                           mismo <form> a la vez. */ ?>
                      <?php $prefix = "t{$t['id']}_trasl_"; $requerido = true; $soloSaliente = true; $nombreSaliente = 'id_componente_saliente_traslado'; $idSalaContexto = $t['id_sala'] ?? null; include PARCIALES_DIR . '/buscador_componente_widget.php'; ?>
                    </div>

                    <div class="campo-solucion-general" hidden>
                      <label>Solución</label>
                      <textarea name="solucion" <?= $modoTicket === 'libre' ? 'disabled' : '' ?>><?= h($t['solucion']) ?></textarea>
                    </div>
                  </div>

                  <?php if ($modoTicket === 'libre'): ?>
                    <div class="bloque-libre-sin-sala">
                      <label>Marca / Modelo</label>
                      <input type="text" name="marca_modelo" value="<?= h($t['marca_modelo']) ?>">

                      <label>Inventario</label>
                      <input type="text" name="numero_inventario" value="<?= h($t['numero_inventario']) ?>">

                      <label>Solución</label>
                      <textarea name="solucion" class="textarea-solucion-libre" required><?= h($t['solucion']) ?></textarea>
                    </div>
                  <?php endif; ?>

                <?php else: ?>
                  <label>Marca / Modelo</label>
                  <input type="text" name="marca_modelo" value="<?= h($t['marca_modelo']) ?>" required>

                  <label>Inventario</label>
                  <input type="text" name="numero_inventario" value="<?= h($t['numero_inventario']) ?>" required>

                  <label>Solución</label>
                  <textarea name="solucion" required><?= h($t['solucion']) ?></textarea>
                <?php endif; ?>

                <?php $ccId = 'tk-' . (int)$t['id'] . '-c'; $ccExige = TicketEvidencia::exige(TablaTicket::NORMAL, $t); $ccFotos = $fotosT; $ccSoloFotos = true; include __DIR__ . '/_tk_campos_cierre.php'; ?>

                <label>Estado</label>
                <select name="estado" required>
                  <option value="En espera" <?= $t['estado']===TicketWorkflow::EN_ESPERA?'selected':'' ?>>En espera</option>
                  <option value="En proceso" <?= $t['estado']===TicketWorkflow::EN_PROCESO?'selected':'' ?>>En proceso</option>
                  <option value="Solucionado" <?= $t['estado']===TicketWorkflow::SOLUCIONADO?'selected':'' ?>>Solucionado</option>
                  <option value="Listo pero sin validar" <?= $t['estado']===TicketWorkflow::LISTO_SIN_VALIDAR?'selected':'' ?>>Trasladado a bodega/oficina (no se pudo arreglar en sitio)</option>
                </select>

                <div class="mt-2">
                  <button class="btn" type="submit" name="update_ticket">Actualizar</button>
                </div>
              </form>
            <?= vfCerrar() ?>
    <?php endif; ?>
  </td>
</tr>
