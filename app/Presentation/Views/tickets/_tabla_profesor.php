<?php
/**
 * Lista COMPACTA de «Mis tickets» del profesor (activos y solucionados comparten columnas): Ticket ·
 * Solicitud · Estado · Acciones. Desde aquí el profesor conversa con soporte (y reitera cuando vence el
 * tiempo de respuesta), ve la explicación de la solución con sus fotos y descarga el PDF.
 *
 * Variables:
 *   $filasTabla          filas de tickets_profesor
 *   $conSolucion         true = tabla de solucionados (agrega calificar atención)
 *   $respuestasEncuesta  (solo con $conSolucion) respuestas ya dadas, por id de ticket
 *   $interaccion         TicketInteraccionService::precargar()
 *   $estadosConv         TicketInteraccionService::estadosSolicitante() de estas filas
 */
?>
<table class="data tk-lista">
  <thead>
    <tr><th>Ticket</th><th>Solicitud</th><th>Estado</th><th>Acciones</th></tr>
  </thead>
  <tbody>
    <?php if (!$filasTabla): ?>
      <tr><td colspan="4" class="text-muted"><?= $conSolucion ? 'Aún no tienes tickets solucionados.' : 'No tienes tickets activos.' ?></td></tr>
    <?php endif; ?>
    <?php foreach ($filasTabla as $t):
        $p = $t; // _tk_resumen_profesor.php usa $p
        $idP = (int)$t['id'];
        $mensajesP = $interaccion['conversacion']['tickets_profesor'][$idP] ?? [];
        $fotosP = $interaccion['evidencias']['tickets_profesor'][$idP] ?? [];
        $ultimoP = $mensajesP ? end($mensajesP) : null;
        $nuevoDeSoporte = $ultimoP && $ultimoP['es_personal'];
        $pref = 'mtp-' . $idP;
    ?>
    <tr id="tk-<?= $idP ?>">
      <td data-label="Ticket" class="tk-col-id">
        <div class="tk-id">
          <span class="tk-num">#<?= $idP ?></span>
          <span class="badge <?= $t['tipo_ticket'] === 'requerimiento' ? 'requerimiento' : 'danio' ?>"><?= h(TicketConsultas::etiquetaTipoTicketProfesor($t['tipo_ticket'])) ?></span>
        </div>
        <div class="tk-fecha"><?= h(date('d/m/Y H:i', strtotime((string)$t['fecha_creacion']))) ?></div>
      </td>
      <td data-label="Solicitud" class="tk-col-sol"><?php include PARCIALES_DIR . '/_tk_resumen_profesor.php'; ?></td>
      <td data-label="Estado">
        <span class="chip <?= claseChipHistorialTicket((string)$t['estado']) ?>"><?= h($t['estado']) ?></span>
        <div class="tk-indicadores">
          <span class="tk-ind"><?= $t['tecnico_nombre'] ? 'Atiende: ' . h($t['tecnico_nombre']) : 'Sin asignar aún' ?></span>
          <?php if ($nuevoDeSoporte): ?><button type="button" class="tk-ind alerta" data-vf-abrir="<?= $pref ?>-chat">Soporte te escribió</button><?php endif; ?>
          <?php if ($fotosP): ?><span class="tk-ind"><?= count($fotosP) ?> foto(s)</span><?php endif; ?>
        </div>
      </td>
      <td data-label="Acciones" class="tk-col-acc">
        <div class="tk-acciones">
          <button type="button" class="btn btn-sm" data-vf-abrir="<?= $pref ?>-chat">Conversación<?= $mensajesP ? ' (' . count($mensajesP) . ')' : '' ?></button>
          <?php if ($fotosP || !empty($t['solucion'])): ?><button type="button" class="btn btn-ghost btn-sm" data-vf-abrir="<?= $pref ?>-sol">Ver solución</button><?php endif; ?>
          <a href="ticket_profesor_pdf.php?id=<?= $idP ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">PDF</a>
          <?php if ($conSolucion) include PARCIALES_DIR . '/_accion_encuesta.php'; ?>
        </div>
        <?php $cv = ['id' => $pref . '-chat', 'tabla' => TablaTicket::PROFESOR, 'ticket' => $t, 'mensajes' => $mensajesP, 'modo' => 'solicitante', 'estado' => $estadosConv[$idP] ?? null]; include PARCIALES_DIR . '/_tk_conversacion.php'; ?>
        <?php if ($fotosP || !empty($t['solucion'])): ?>
          <?= vfAbrir($pref . '-sol', 'Ticket #' . $idP . ' · Solución', 'Lo que hizo soporte, con las fotos del trabajo.', false, 'md') ?>
            <?php if (!empty($t['solucion'])): ?><p class="nota-panel"><?= nl2br(h($t['solucion'])) ?></p><?php endif; ?>
            <?php $evFotos = $fotosP; $evQuitarId = null; include PARCIALES_DIR . '/_tk_evidencias.php'; ?>
          <?= vfCerrar() ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
