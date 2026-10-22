<?php
/**
 * Ventana flotante «Conversación» de un ticket: hilo tipo chat entre soporte y quien reportó, más el
 * formulario para escribir según quién mira. Toda la conversación queda en el PDF del ticket.
 *
 * Variables ($cv):
 *   id        id de la ventana (único en la página)
 *   tabla     'tickets' | 'tickets_profesor'
 *   ticket    fila del ticket (id, estado…)
 *   mensajes  TicketConversacion::deTickets()[tabla][id] (puede ser [])
 *   modo      'personal'    técnico/beca que lo atiende (escribe si puede)
 *             'solicitante' profesor / solicitante dueño del ticket
 *             'publico'     quien reportó por QR sin sesión (consultar_estado.php; requiere 'codigo')
 *             'lectura'     admin u otro personal: solo lee
 *   puede     (modo personal) si puede escribir
 *   estado    (modo solicitante/publico) TicketConversacion::estadoSolicitante()
 *   codigo    (modo publico) código de seguimiento
 *   ancla     (opcional) id de la fila a la que vuelve la página tras enviar (por defecto tk-<id>)
 */
$cvMensajes = $cv['mensajes'] ?? [];
$cvModo = $cv['modo'] ?? 'lectura';
$cvId = (int)$cv['ticket']['id'];
$cvVentana = $cv['ventana'] ?? true;
$cvAccion = '#' . ($cv['ancla'] ?? ('tk-' . $cvId)); // vuelve a la fila (y a su pestaña) tras enviar
// Tras enviar un mensaje, la página vuelve con la conversación de ESE ticket abierta.
$cvAbierta = isset($_POST['conversacion_enviar']) && ($_POST['tabla_ticket'] ?? '') === $cv['tabla'] && (int)($_POST['ticket_id'] ?? 0) === $cvId;
?>
<?php if ($cvVentana): ?>
<?= vfAbrir($cv['id'], 'Conversación · ticket #' . $cvId, 'Queda como constancia en el PDF del ticket.', $cvAbierta, 'md') ?>
<?php endif; ?>
  <div class="chat" aria-live="polite">
    <?php if (!$cvMensajes): ?>
      <p class="text-muted m-0">
        <?= $cvModo === 'personal' ? 'Aún no hay mensajes. Escríbele a quien reportó para pedir detalles o avisar del avance.'
            : ($cvModo === 'lectura' ? 'Sin mensajes entre soporte y quien reportó.' : 'Aún no hay mensajes. Cuando soporte tome tu ticket podrá escribirte aquí.') ?>
      </p>
    <?php endif; ?>
    <?php foreach ($cvMensajes as $m): ?>
      <div class="chat-msg <?= $m['es_personal'] ? 'chat-soporte' : 'chat-solicitante' ?>">
        <div class="chat-meta">
          <b><?= h($m['autor']) ?></b> · <?= $m['es_personal'] ? 'Soporte' : 'Solicitante' ?> · <?= h(date('d/m/Y H:i', strtotime((string)$m['fecha_creacion']))) ?>
          <?php if (!empty($m['es_reiteracion'])): ?><span class="tk-ind alerta">Reiteración</span><?php endif; ?>
        </div>
        <div class="chat-texto"><?= nl2br(h($m['comentario'])) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($cvModo === 'personal'): ?>
    <?php if (!empty($cv['puede'])): ?>
      <form method="POST" class="chat-form" action="<?= h($cvAccion) ?>">
        <?= csrfField() ?>
        <input type="hidden" name="tabla_ticket" value="<?= h($cv['tabla']) ?>">
        <input type="hidden" name="ticket_id" value="<?= $cvId ?>">
        <label for="<?= h($cv['id']) ?>-txt" class="sr-only">Mensaje</label>
        <textarea id="<?= h($cv['id']) ?>-txt" name="mensaje" rows="3" maxlength="<?= TicketConversacion::MAX_TEXTO ?>" required placeholder="Escribe a quien reportó…"></textarea>
        <button class="btn btn-sm" name="conversacion_enviar">Enviar mensaje</button>
      </form>
    <?php else: ?>
      <p class="hint">Toma el ticket para poder escribirle a quien lo reportó.</p>
    <?php endif; ?>
  <?php elseif ($cvModo === 'solicitante' || $cvModo === 'publico'): ?>
    <?php $cvEstado = $cv['estado'] ?? ['puede' => false, 'tipo' => 'cerrado', 'motivo' => '']; ?>
    <?php if ($cvEstado['puede']): ?>
      <form method="POST" class="chat-form" action="<?= h($cvAccion) ?>">
        <?= csrfField() ?>
        <input type="hidden" name="tabla_ticket" value="<?= h($cv['tabla']) ?>">
        <input type="hidden" name="ticket_id" value="<?= $cvId ?>">
        <?php if ($cvModo === 'publico'): ?><input type="hidden" name="codigo" value="<?= h($cv['codigo'] ?? '') ?>"><?php endif; ?>
        <?php if ($cvEstado['tipo'] === 'reiteracion'): ?>
          <p class="hint hint-peligro m-0">Ya se cumplió el tiempo de respuesta acordado. Tu mensaje quedará registrado como <b>reiteración</b> y se avisará a soporte.</p>
        <?php endif; ?>
        <label for="<?= h($cv['id']) ?>-txt" class="sr-only">Mensaje</label>
        <textarea id="<?= h($cv['id']) ?>-txt" name="mensaje" rows="3" maxlength="<?= TicketConversacion::MAX_TEXTO ?>" required placeholder="<?= $cvEstado['tipo'] === 'reiteracion' ? 'Describe de nuevo o amplía tu solicitud…' : 'Responde a soporte…' ?>"></textarea>
        <button class="btn btn-sm" name="conversacion_enviar"><?= $cvEstado['tipo'] === 'reiteracion' ? 'Reiterar solicitud' : 'Enviar respuesta' ?></button>
      </form>
    <?php else: ?>
      <p class="hint"><?= h($cvEstado['motivo']) ?></p>
    <?php endif; ?>
  <?php endif; ?>
<?php if ($cvVentana): ?>
<?= vfCerrar() ?>
<?php endif; ?>
<?php unset($cv, $cvMensajes, $cvModo, $cvId, $cvEstado, $cvVentana, $cvAbierta, $cvAccion); ?>
