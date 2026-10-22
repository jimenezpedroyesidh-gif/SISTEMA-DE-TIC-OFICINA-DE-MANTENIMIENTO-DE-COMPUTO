<?php
/**
 * Lista COMPACTA de «Mis tickets» del solicitante/secretaria (activos y solucionados comparten columnas):
 * Ticket · Solicitud · Estado · Acciones. Conversación con soporte (responder / reiterar cuando vence el
 * tiempo de respuesta), solución con fotos, PDF y —en solucionados— calificar la atención.
 *
 * Variables:
 *   $filasTabla          filas de TicketConsultas::obtenerTicketsPorUsuario()['datos']
 *   $conSolucion         true = tabla de solucionados (calificar atención)
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
        $idT = (int)$t['id'];
        $mensajesT = $interaccion['conversacion']['tickets'][$idT] ?? [];
        $fotosT = $interaccion['evidencias']['tickets'][$idT] ?? [];
        $ultimoT = $mensajesT ? end($mensajesT) : null;
        $pref = 'mts-' . $idT;
    ?>
    <tr id="tk-<?= $idT ?>">
      <td data-label="Ticket" class="tk-col-id">
        <div class="tk-id"><span class="tk-num">#<?= $idT ?></span></div>
        <div class="tk-fecha"><?= h(date('d/m/Y H:i', strtotime((string)$t['fecha_creacion']))) ?></div>
      </td>
      <td data-label="Solicitud" class="tk-col-sol">
        <div class="tk-titulo"><?= h((string)($t['equipo'] ?: $t['nombre_dependencia'])) ?></div>
        <div class="tk-sub"><?= h(trim($t['nombre_dependencia'] . (($t['ubicacion'] ?? '') !== '' ? ' · ' . $t['ubicacion'] : ''), ' ·')) ?><?= !empty($t['radicado_datarsoft']) ? ' · Radicado ' . h($t['radicado_datarsoft']) : '' ?></div>
        <p class="tk-desc"><?= h((string)$t['descripcion']) ?></p>
      </td>
      <td data-label="Estado">
        <span class="chip <?= claseChipHistorialTicket((string)$t['estado']) ?>"><?= h($t['estado']) ?></span>
        <div class="tk-indicadores">
          <span class="tk-ind"><?= !empty($t['tecnico_nombre']) ? 'Atiende: ' . h($t['tecnico_nombre']) : 'Sin asignar aún' ?></span>
          <?php if ($ultimoT && $ultimoT['es_personal']): ?><button type="button" class="tk-ind alerta" data-vf-abrir="<?= $pref ?>-chat">Soporte te escribió</button><?php endif; ?>
          <?php if ($fotosT): ?><span class="tk-ind"><?= count($fotosT) ?> foto(s)</span><?php endif; ?>
        </div>
      </td>
      <td data-label="Acciones" class="tk-col-acc">
        <div class="tk-acciones">
          <button type="button" class="btn btn-sm" data-vf-abrir="<?= $pref ?>-chat">Conversación<?= $mensajesT ? ' (' . count($mensajesT) . ')' : '' ?></button>
          <?php if ($fotosT || !empty($t['solucion'])): ?><button type="button" class="btn btn-ghost btn-sm" data-vf-abrir="<?= $pref ?>-sol">Ver solución</button><?php endif; ?>
          <a href="ticket_pdf.php?id=<?= $idT ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">PDF</a>
          <?php if ($conSolucion) include PARCIALES_DIR . '/_accion_encuesta.php'; ?>
        </div>
        <?php $cv = ['id' => $pref . '-chat', 'tabla' => TablaTicket::NORMAL, 'ticket' => $t, 'mensajes' => $mensajesT, 'modo' => 'solicitante', 'estado' => $estadosConv[$idT] ?? null]; include PARCIALES_DIR . '/_tk_conversacion.php'; ?>
        <?php if ($fotosT || !empty($t['solucion'])): ?>
          <?= vfAbrir($pref . '-sol', 'Ticket #' . $idT . ' · Solución', 'Lo que hizo soporte, con las fotos del trabajo.', false, 'md') ?>
            <?php if (!empty($t['solucion'])): ?><p class="nota-panel"><?= nl2br(h($t['solucion'])) ?></p><?php endif; ?>
            <?php $evFotos = $fotosT; $evQuitarId = null; include PARCIALES_DIR . '/_tk_evidencias.php'; ?>
          <?= vfCerrar() ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
