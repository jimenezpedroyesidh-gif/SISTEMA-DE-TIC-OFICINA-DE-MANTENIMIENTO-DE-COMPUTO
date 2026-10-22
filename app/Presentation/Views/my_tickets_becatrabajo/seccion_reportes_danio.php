<?php
/**
 * Vista parcial del panel de beca (oficina de mantenimiento): UNA sola lista COMPACTA con los reportes de
 * daño de sala (errores de sala de profesores —con sesión, desde su panel o el QR— y reportes por QR sin
 * sesión). Columnas: Reporte · Sala y problema · Personas · Estado · Acciones; lo secundario va en «Más ▾»
 * y en ventanas flotantes. Al marcar «Solucionado» se exige explicación + foto (quedan en el PDF).
 * Variables preparadas por BecaPanel: $reportesDanio, $resultReportes, $equiposYaProf, $equiposYaQr,
 * $equiposPorSala, $interaccion, $user_id.
 */
$hayFirmados = (bool)array_filter($reportesDanio, fn($r) => !empty($r['firmado']) && $r['estado'] !== TicketWorkflow::SOLUCIONADO);
?>
<section class="panel-box" id="reportes-danio">
  <div class="sec-title">
    <h2 class="panel-title">Reportes de daño de sala <span class="text-muted">(<?= (int)$resultReportes['total'] ?>)</span></h2>
    <?php if ($hayFirmados): ?><button class="btn btn-ghost btn-sm" type="button" data-click="tickets.toggleFirmados" data-arg="reportes-danio">Ver / ocultar firmados</button><?php endif; ?>
  </div>
  <?php if (!$reportesDanio): ?>
    <p class="text-muted m-0">No hay reportes de daño pendientes. Cuando un profesor o alguien por el QR de una sala reporte un error, aparecerá aquí.</p>
  <?php else: ?>
  <table class="data tk-lista">
    <thead>
      <tr><th>Reporte</th><th>Sala y problema</th><th>Personas</th><th>Estado</th><th>Acciones</th></tr>
    </thead>
    <tbody>
    <?php foreach ($reportesDanio as $r):
        $esProf = $r['_tipo'] === 'prof';
        $tablaR = $esProf ? TablaTicket::PROFESOR : TablaTicket::NORMAL;
        $idR = (int)$r['id'];
        $puedeEditarR = !$r['firmado'] && (int)$r['tecnico_id'] === (int)$user_id;
        $equiposYa = $esProf ? ($equiposYaProf[$idR] ?? []) : ($equiposYaQr[$idR] ?? []);
        $aceptaEquipos = !$esProf || ($r['origen'] ?? '') === 'QR_PROFESOR';
        $mensajesR = $interaccion['conversacion'][$tablaR][$idR] ?? [];
        $fotosR = $interaccion['evidencias'][$tablaR][$idR] ?? [];
        $ultimoR = $mensajesR ? end($mensajesR) : null;
        $esperaR = $ultimoR && !$ultimoR['es_personal'];
        $pref = 'rd-' . ($esProf ? 'p' : 'q') . $idR;
        $salaR = $esProf ? ($r['sala_danio'] ?: $r['salon']) : ($r['nombre_sala'] ?? $r['ubicacion']);
        $descR = (string)($esProf ? $r['descripcion_problema'] : $r['descripcion']);
    ?>
      <?php $ocultaR = $esProf && $r['firmado'] && $r['estado'] !== TicketWorkflow::SOLUCIONADO; ?>
      <tr id="<?= $pref ?>"<?= $esProf ? ' data-firmado="' . ($ocultaR ? '1' : '0') . '"' . ($ocultaR ? ' hidden' : '') : '' ?>>
        <td data-label="Reporte" class="tk-col-id">
          <div class="tk-id">
            <span class="tk-num"><?= $esProf ? 'P-' : 'QR-' ?><?= $idR ?></span>
            <span class="badge <?= $esProf ? 'danio' : 'requerimiento' ?>"><?= $esProf ? (($r['origen'] ?? '') === 'QR_PROFESOR' ? 'Profesor · QR' : 'Profesor') : 'QR sin sesión' ?></span>
          </div>
          <div class="tk-fecha"><?= h(date('d/m/Y H:i', strtotime((string)$r['fecha_creacion']))) ?></div>
        </td>
        <td data-label="Sala y problema" class="tk-col-sol">
          <div class="tk-titulo"><?= h((string)$salaR) ?><?= !$esProf && !empty($r['equipo']) ? ' · ' . h($r['equipo']) : '' ?></div>
          <p class="tk-desc"><?= h($descR) ?></p>
          <details class="tk-detalle">
            <summary>Ver detalle</summary>
            <dl>
              <dt>Problema</dt><dd><?= nl2br(h($descR)) ?></dd>
              <?php if ($esProf && $r['salon'] && $r['salon'] !== $r['sala_danio']): ?><dt>Salón</dt><dd><?= h($r['salon']) ?></dd><?php endif; ?>
              <?php if ($equiposYa): ?><dt>Equipos</dt><dd><?= h(implode(', ', array_column($equiposYa, 'codigo'))) ?></dd><?php endif; ?>
              <?php if (!empty($r['solucion'])): ?><dt>Solución</dt><dd><?= nl2br(h($r['solucion'])) ?></dd><?php endif; ?>
            </dl>
          </details>
        </td>
        <td data-label="Personas">
          <div class="tk-personas">
            <span><span class="tk-persona-rol">Reporta</span>
              <?php if ($esProf): ?><?= h($r['profesor_nombre']) ?>
              <?php elseif (!empty($r['nombre_contacto'])): ?><?= h($r['nombre_contacto']) ?><?php if (!empty($r['correo_contacto'])): ?> · <a href="mailto:<?= h($r['correo_contacto']) ?>"><?= h($r['correo_contacto']) ?></a><?php endif; ?>
              <?php else: ?><span class="text-muted">Anónimo</span><?php endif; ?>
            </span>
            <span><span class="tk-persona-rol">Atiende</span> <?= $r['tecnico_nombre'] ? h($r['tecnico_nombre']) : '<span class="text-muted">Nadie aún</span>' ?></span>
          </div>
        </td>
        <td data-label="Estado">
          <span class="chip <?= claseChipEstadoTicket($r['estado']) ?>"><?= h($r['estado']) ?></span>
          <div class="tk-indicadores">
            <?php if ($mensajesR): ?><button type="button" class="tk-ind<?= $esperaR ? ' alerta' : '' ?>" data-vf-abrir="<?= $pref ?>-chat"><?= $esperaR ? 'Mensaje sin responder' : count($mensajesR) . ' mensaje(s)' ?></button><?php endif; ?>
            <?php if ($fotosR): ?><span class="tk-ind"><?= count($fotosR) ?> foto(s)</span><?php endif; ?>
            <?php if (!empty($r['sugerido_mantenimiento_general'])): ?><span class="tk-ind">Mantenimiento sugerido</span><?php endif; ?>
          </div>
        </td>
        <td data-label="Acciones" class="tk-col-acc">
          <div class="tk-acciones">
            <?php if (empty($r['tecnico_id'])): ?>
              <form method="POST"><?= csrfField() ?><input type="hidden" name="ticket_id" value="<?= $idR ?>"><button class="btn btn-sm" name="<?= $esProf ? 'tomar_profesor' : 'tomar_qr' ?>">Tomar</button></form>
            <?php elseif ($puedeEditarR): ?>
              <button type="button" class="btn btn-sm" data-vf-abrir="<?= $pref ?>-ed">Actualizar</button>
            <?php else: ?>
              <span class="chip">Asignado</span>
            <?php endif; ?>
            <?php
            $menuItems = [
                ['vf' => $pref . '-chat', 'texto' => 'Conversación con quien reportó' . ($mensajesR ? ' (' . count($mensajesR) . ')' : '')],
                ($fotosR && !$puedeEditarR) ? ['vf' => $pref . '-fotos', 'texto' => 'Ver fotos (' . count($fotosR) . ')'] : null,
                ($aceptaEquipos && $puedeEditarR && !$equiposYa && !empty($equiposPorSala[(int)$r['id_sala']])) ? ['vf' => $pref . '-eq', 'texto' => 'Agregar equipos afectados'] : null,
                ($esProf && $puedeEditarR && !empty($r['id_sala']) && empty($r['sugerido_mantenimiento_general'])) ? ['form' => 'sugerir_mantenimiento_profesor', 'texto' => 'Sugerir mantenimiento general', 'campos' => ['ticket_id' => $idR]] : null,
                ($esProf && $puedeEditarR && !empty($r['id_sala'])) ? ['href' => 'create_ticket_beca.php?id_ticket_profesor_origen=' . $idR . '#form-cambio_pieza', 'texto' => 'Cambio de pieza para este reporte'] : null,
                $puedeEditarR ? ['form' => $esProf ? 'liberar_profesor' : 'liberar_qr', 'texto' => 'Liberar reporte', 'campos' => ['ticket_id' => $idR]] : null,
            ];
            include PARCIALES_DIR . '/_tk_menu.php';
            ?>
          </div>

          <?php $cv = ['id' => $pref . '-chat', 'tabla' => $tablaR, 'ticket' => $r, 'mensajes' => $mensajesR, 'modo' => 'personal', 'puede' => TicketConversacion::personalPuedeEscribir($r, (int)$user_id), 'ancla' => $pref]; include PARCIALES_DIR . '/_tk_conversacion.php'; ?>

          <?php if ($fotosR && !$puedeEditarR): ?>
            <?= vfAbrir($pref . '-fotos', 'Reporte #' . $idR . ' · Evidencia', '', false, 'md') ?>
              <?php if (!empty($r['solucion'])): ?><p class="nota-panel"><b>Solución:</b><br><?= nl2br(h($r['solucion'])) ?></p><?php endif; ?>
              <?php $evFotos = $fotosR; $evQuitarId = null; include PARCIALES_DIR . '/_tk_evidencias.php'; ?>
            <?= vfCerrar() ?>
          <?php endif; ?>

          <?php if ($aceptaEquipos && $puedeEditarR && !$equiposYa && !empty($equiposPorSala[(int)$r['id_sala']])): ?>
            <?= vfAbrir($pref . '-eq', 'Reporte #' . $idR . ' · Equipos afectados', 'Marca los equipos de la sala que tienen el problema.') ?>
              <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="tabla_ticket" value="<?= $tablaR ?>">
                <input type="hidden" name="ticket_id" value="<?= $idR ?>">
                <div class="checklist checklist-scroll">
                  <?php foreach ($equiposPorSala[(int)$r['id_sala']] as $eq): ?>
                    <label class="d-block py-1"><input type="checkbox" name="ids_equipo[]" value="<?= (int)$eq['id_equipo'] ?>"> <?= h($eq['codigo'] . ' — ' . ($eq['descripcion'] ?? '')) ?></label>
                  <?php endforeach; ?>
                </div>
                <button class="btn btn-sm" type="submit" name="agregar_equipos_ticket">Guardar equipos</button>
              </form>
            <?= vfCerrar() ?>
          <?php endif; ?>

          <?php if ($puedeEditarR): ?>
            <?= vfAbrir($pref . '-ed', 'Reporte #' . $idR . ' · Actualizar', 'Para «Solucionado» explica qué hiciste y adjunta fotos; luego el técnico lo aprueba.') ?>
              <form method="POST" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= $idR ?>">
                <div class="field">
                  <label for="<?= $pref ?>-est">Estado</label>
                  <select id="<?= $pref ?>-est" name="estado" required>
                    <?php foreach (TicketEstadoService::ESTADOS_ACTUALIZABLES_BECA as $opE): ?>
                      <option value="<?= h($opE) ?>" <?= $r['estado'] === $opE ? 'selected' : '' ?>><?= h($opE) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <?php $ccId = $pref . '-c'; $ccExige = TicketEvidencia::exige($tablaR, $r); $ccSolucion = $r['solucion'] ?? ''; $ccFotos = $fotosR; include PARCIALES_DIR . '/_tk_campos_cierre.php'; ?>
                <button class="btn" type="submit" name="<?= $esProf ? 'update_profesor' : 'update_qr' ?>">Guardar</button>
              </form>
            <?= vfCerrar() ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?= renderControlesPaginacion($resultReportes['pagina'], $resultReportes['total_paginas'], [], 'pagina', 'reportes-danio') ?>
  <?php endif; ?>
</section>
