<?php
/**
 * Fila COMPACTA de un ticket de profesor en el panel admin (supervisión): Ticket · Solicitud · Personas ·
 * Estado · Acciones. El admin aprueba/rechaza requerimientos en «Solucionado»; en daños solo supervisa.
 * Puede leer la conversación con el profesor y ver la solución y las fotos de evidencia.
 * Espera en scope: $p, $interaccion (TicketInteraccionService::precargar).
 */
$idP = (int)$p['id'];
$mensajesP = $interaccion['conversacion']['tickets_profesor'][$idP] ?? [];
$fotosP = $interaccion['evidencias']['tickets_profesor'][$idP] ?? [];
$pref = 'adp-' . $idP;
?>
<tr id="adm-tk-<?= $idP ?>">
  <td data-label="Ticket" class="tk-col-id">
    <div class="tk-id">
      <span class="tk-num" title="Ticket de profesor #<?= $idP ?>">P-<?= $idP ?></span>
      <span class="badge <?= $p['tipo_ticket'] === 'requerimiento' ? 'requerimiento' : 'danio' ?>"><?= $p['tipo_ticket'] === 'requerimiento' ? 'Requerimiento' : 'Daño' ?></span>
    </div>
    <div class="tk-fecha"><?= h(date('d/m/Y H:i', strtotime((string)$p['fecha_creacion']))) ?></div>
  </td>
  <td data-label="Solicitud" class="tk-col-sol"><?php include __DIR__ . '/_tk_resumen_profesor.php'; ?></td>
  <td data-label="Personas">
    <div class="tk-personas">
      <span><span class="tk-persona-rol">Profesor</span> <?= h($p['profesor_nombre']) ?></span>
      <span><span class="tk-persona-rol">Atiende</span> <?= $p['tecnico_nombre'] ? h($p['tecnico_nombre']) : '<span class="text-muted">Sin asignar</span>' ?></span>
    </div>
  </td>
  <td data-label="Estado">
    <span class="chip <?= claseChipEstadoTicket($p['estado'], true) ?>"><?= h($p['estado']) ?></span>
    <div class="tk-indicadores">
      <?php if ($mensajesP): ?><span class="tk-ind"><?= count($mensajesP) ?> mensaje(s)</span><?php endif; ?>
      <?php if ($fotosP): ?><span class="tk-ind"><?= count($fotosP) ?> foto(s)</span><?php endif; ?>
    </div>
  </td>
  <td data-label="Acciones" class="tk-col-acc">
    <div class="tk-acciones">
      <?php if (!empty($p['tecnico_id']) && $p['estado'] === TicketWorkflow::SOLUCIONADO && $p['tipo_ticket'] === 'requerimiento'): ?>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="ticket_id" value="<?= $idP ?>"><button class="btn btn-info btn-sm" name="aprobar_profesor">Aprobar</button></form>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="ticket_id" value="<?= $idP ?>"><button class="btn btn-danger btn-sm" name="rechazar_profesor">Rechazar</button></form>
      <?php elseif ($p['estado'] === TicketWorkflow::SOLUCIONADO): ?>
        <span class="hint m-0">Lo aprueba el técnico</span>
      <?php endif; ?>
      <?php
      $menuItems = [
          ['vf' => $pref . '-chat', 'texto' => 'Conversación (' . count($mensajesP) . ')'],
          ($fotosP || !empty($p['solucion'])) ? ['vf' => $pref . '-fotos', 'texto' => 'Solución y fotos'] : null,
          ['href' => 'ticket_profesor_pdf.php?id=' . $idP, 'texto' => 'Ver PDF', 'nueva' => true],
      ];
      include __DIR__ . '/_tk_menu.php';
      ?>
    </div>
    <?php $cv = ['id' => $pref . '-chat', 'tabla' => TablaTicket::PROFESOR, 'ticket' => $p, 'mensajes' => $mensajesP, 'modo' => 'lectura']; include __DIR__ . '/_tk_conversacion.php'; ?>
    <?php if ($fotosP || !empty($p['solucion'])): ?>
      <?= vfAbrir($pref . '-fotos', 'Ticket de profesor #' . $idP . ' · Solución y evidencia', '', false, 'md') ?>
        <?php if (!empty($p['solucion'])): ?><p class="nota-panel"><b>Solución:</b><br><?= nl2br(h($p['solucion'])) ?></p><?php endif; ?>
        <?php $evFotos = $fotosP; $evQuitarId = null; include __DIR__ . '/_tk_evidencias.php'; ?>
      <?= vfCerrar() ?>
    <?php endif; ?>
  </td>
</tr>
