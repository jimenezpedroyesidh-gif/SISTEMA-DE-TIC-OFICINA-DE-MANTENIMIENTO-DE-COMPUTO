<?php
/**
 * Fila COMPACTA de un ticket de solicitante / QR en el panel admin (solo supervisión): Ticket · Solicitud ·
 * Personas · Estado · Acciones (conversación en lectura, solución y fotos, PDF).
 * Espera en scope: $t, $interaccion (TicketInteraccionService::precargar).
 */
$idT = (int)$t['id'];
$mensajesT = $interaccion['conversacion']['tickets'][$idT] ?? [];
$fotosT = $interaccion['evidencias']['tickets'][$idT] ?? [];
$pref = 'ads-' . $idT;
?>
<tr id="tk-<?= $idT ?>">
  <td data-label="Ticket" class="tk-col-id">
    <div class="tk-id">
      <span class="tk-num">#<?= $idT ?></span>
      <span class="badge <?= ($t['origen'] ?? '') === 'QR_ANONIMO' ? 'danio' : 'requerimiento' ?>"><?= ($t['origen'] ?? '') === 'QR_ANONIMO' ? 'QR' : 'Solicitante' ?></span>
    </div>
    <div class="tk-fecha"><?= h(date('d/m/Y H:i', strtotime((string)$t['fecha_creacion']))) ?></div>
  </td>
  <td data-label="Solicitud" class="tk-col-sol">
    <div class="tk-titulo"><?= h(trim(($t['nombre_sala'] ?? '') !== '' ? $t['nombre_sala'] . ($t['equipo'] ? ' · ' . $t['equipo'] : '') : ($t['equipo'] ?: (string)$t['nombre_dependencia']))) ?></div>
    <div class="tk-sub"><?= h((string)$t['nombre_dependencia']) ?><?= !empty($t['radicado_datarsoft']) ? ' · Radicado ' . h($t['radicado_datarsoft']) : '' ?></div>
    <p class="tk-desc"><?= h((string)$t['descripcion']) ?></p>
    <details class="tk-detalle">
      <summary>Ver detalle</summary>
      <dl>
        <dt>Descripción</dt><dd><?= nl2br(h((string)$t['descripcion'])) ?></dd>
        <?php if (!empty($t['marca_modelo'])): ?><dt>Marca / modelo</dt><dd><?= h($t['marca_modelo']) ?></dd><?php endif; ?>
        <?php if (!empty($t['numero_inventario'])): ?><dt>Inventario</dt><dd><?= h($t['numero_inventario']) ?></dd><?php endif; ?>
        <?php if (!empty($t['mantenimiento_generado'])): ?><dt>Mantenimiento</dt><dd>#<?= (int)$t['mantenimiento_generado'] ?> (<?= (int)$t['mant_realizados'] ?>/<?= (int)$t['mant_total_equipos'] ?> equipos · <?= h($t['mantenimiento_estado']) ?>)</dd><?php endif; ?>
        <?php if (!empty($t['solucion'])): ?><dt>Solución</dt><dd><?= nl2br(h($t['solucion'])) ?></dd><?php endif; ?>
      </dl>
    </details>
  </td>
  <td data-label="Personas">
    <div class="tk-personas">
      <span><span class="tk-persona-rol">Solicita</span>
        <?php if ($t['solicitante']): ?><?= h($t['solicitante']) ?>
        <?php elseif (!empty($t['nombre_contacto'])): ?><?= h($t['nombre_contacto']) ?><?php if (!empty($t['correo_contacto'])): ?> · <a href="mailto:<?= h($t['correo_contacto']) ?>"><?= h($t['correo_contacto']) ?></a><?php endif; ?>
        <?php else: ?><span class="text-muted">Anónimo (QR)</span><?php endif; ?>
      </span>
    </div>
  </td>
  <td data-label="Estado">
    <span class="chip <?= claseChipHistorialTicket((string)$t['estado']) ?>"><?= h($t['estado']) ?></span>
    <div class="tk-indicadores">
      <?php if ($t['estado'] === TicketWorkflow::SOLUCIONADO): ?><span class="tk-ind alerta">Falta aprobación del técnico</span><?php endif; ?>
      <?php if ($mensajesT): ?><span class="tk-ind"><?= count($mensajesT) ?> mensaje(s)</span><?php endif; ?>
      <?php if ($fotosT): ?><span class="tk-ind"><?= count($fotosT) ?> foto(s)</span><?php endif; ?>
    </div>
  </td>
  <td data-label="Acciones" class="tk-col-acc">
    <div class="tk-acciones">
      <a class="btn btn-ghost btn-sm" href="ticket_pdf.php?id=<?= $idT ?>" target="_blank" rel="noopener">PDF</a>
      <?php
      $menuItems = [
          ['vf' => $pref . '-chat', 'texto' => 'Conversación (' . count($mensajesT) . ')'],
          ($fotosT || !empty($t['solucion'])) ? ['vf' => $pref . '-fotos', 'texto' => 'Solución y fotos'] : null,
      ];
      include __DIR__ . '/_tk_menu.php';
      ?>
    </div>
    <?php $cv = ['id' => $pref . '-chat', 'tabla' => TablaTicket::NORMAL, 'ticket' => $t, 'mensajes' => $mensajesT, 'modo' => 'lectura']; include __DIR__ . '/_tk_conversacion.php'; ?>
    <?php if ($fotosT || !empty($t['solucion'])): ?>
      <?= vfAbrir($pref . '-fotos', 'Ticket #' . $idT . ' · Solución y evidencia', '', false, 'md') ?>
        <?php if (!empty($t['solucion'])): ?><p class="nota-panel"><b>Solución:</b><br><?= nl2br(h($t['solucion'])) ?></p><?php endif; ?>
        <?php $evFotos = $fotosT; $evQuitarId = null; include __DIR__ . '/_tk_evidencias.php'; ?>
      <?= vfCerrar() ?>
    <?php endif; ?>
  </td>
</tr>
