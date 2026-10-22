<?php /** Vista pública de «Consultar estado de mi reporte» (public/consultar_estado.php). Solo presentación: recibe datos ya preparados por ConsultarEstadoPage. */ ?>
<?php $paginaPublica = true; $paginaTitulo = 'Consultar estado de mi reporte'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php include LAYOUT_DIR . '/app_publico_inicio.php'; ?>
<div class="card">
  <h2>Consultar estado de mi reporte</h2>
  <p class="text-muted mb-3">Si reportaste un daño escaneando el QR de una sala sin iniciar sesión, ingresa aquí el código que te mostramos al enviarlo.</p>

  <?= buscadorHtml(['id' => 'codigo', 'name' => 'codigo', 'valor' => $codigo, 'placeholder' => 'Código de seguimiento (ej: A1B2C3D4)', 'etiqueta' => 'Código de seguimiento', 'boton' => 'Consultar', 'limpiar' => 'consultar_estado.php', 'requerido' => true]) ?>

  <?php if ($error): ?>
    <div class="error mt-3"><?= h($error) ?></div>
  <?php elseif ($ticket): ?>
    <div class="exito mt-3">
      <p><strong>Sala:</strong> <?= h($ticket['nombre_sala'] ?? $ticket['nombre_dependencia']) ?></p>
      <p><strong>Reportado:</strong> <?= h($ticket['fecha_creacion']) ?></p>
      <p><strong>Estado actual:</strong> <?= h(ReporteQrService::descripcionEstadoPublico($ticket['estado'])) ?></p>
      <?php if ($ticket['fecha_terminacion']): ?>
        <p><strong>Finalizado:</strong> <?= h($ticket['fecha_terminacion']) ?></p>
      <?php endif; ?>
      <?php if (!empty($ticket['solucion']) && in_array($ticket['estado'], [TicketWorkflow::SOLUCIONADO, TicketWorkflow::APROBADO, TicketWorkflow::CERRADO], true)): ?>
        <p><strong>Solución:</strong> <?= nl2br(h($ticket['solucion'])) ?></p>
      <?php endif; ?>
    </div>
    <?php if ($ticketVivo): ?>
      <section class="mt-3" id="conversacion">
        <h3 class="panel-subtitle">Conversación con soporte</h3>
        <?php if ($mensajeConversacion): ?><div class="mb-2 <?= $mensajeConversacion['ok'] ? 'exito' : 'error' ?>"><?= h($mensajeConversacion['mensaje']) ?></div><?php endif; ?>
        <?php $cv = ['id' => 'conv-publica', 'tabla' => TablaTicket::NORMAL, 'ticket' => $ticketVivo, 'mensajes' => $mensajesConversacion, 'modo' => 'publico', 'estado' => $estadoConversacion, 'codigo' => $codigo, 'ventana' => false, 'ancla' => 'conversacion']; include PARCIALES_DIR . '/_tk_conversacion.php'; ?>
      </section>
    <?php endif; ?>
    <?php if ($mensajeEncuesta): ?>
      <div class="mt-3 <?= $mensajeEncuesta['ok'] ? 'exito' : 'error' ?>"><?= h($mensajeEncuesta['mensaje']) ?></div>
    <?php endif; ?>
    <?php if ($puedeCalificar): ?>
      <form method="POST" class="mt-3">
        <?= csrfField() ?>
        <input type="hidden" name="codigo" value="<?= h($codigo) ?>">
        <fieldset class="field">
          <legend><strong>¿Cómo calificas la atención de tu reporte?</strong></legend>
          <?php foreach (EncuestaService::CALIFICACIONES as $v => $txt): ?>
            <span class="field-check"><label><input type="radio" name="calificacion" value="<?= $v ?>" required> <?= $v ?> · <?= h($txt) ?></label></span>
          <?php endforeach; ?>
        </fieldset>
        <div class="field">
          <label for="comentario">Comentario (opcional)</label>
          <textarea id="comentario" name="comentario" maxlength="500" rows="2" placeholder="¿Qué podemos mejorar?"></textarea>
        </div>
        <button type="submit" name="calificar" class="btn">Enviar calificación</button>
      </form>
    <?php elseif ($encuesta): ?>
      <p class="hint mt-3">Ya calificaste esta atención con <?= (int)$encuesta['calificacion'] ?> de 5. ¡Gracias!</p>
    <?php endif; ?>
  <?php endif; ?>

  <p class="mt-3"><a href="index.php">← Volver al inicio</a></p>
</div>
</div>
</body>
</html>
