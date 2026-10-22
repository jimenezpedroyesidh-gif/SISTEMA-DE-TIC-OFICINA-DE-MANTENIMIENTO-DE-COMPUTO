<?php
/**
 * Ventanas flotantes de la encuesta de satisfacción de un panel (profesor, solicitante, secretaría).
 * Recibe: $ticketsEncuesta (filas con id y estado), $respuestasEncuesta (id => respuesta) (la tabla la decide el panel).
 * Se incluye DESPUÉS de la tabla; el botón «Calificar» de cada fila abre la ventana vf-encuesta-<id>.
 */
foreach ($ticketsEncuesta as $tEnc):
    $idEnc = (int)$tEnc['id'];
    if (isset($respuestasEncuesta[$idEnc]) || !in_array($tEnc['estado'], EncuestaService::ESTADOS_CALIFICABLES, true)) continue;
?>
  <?= vfAbrir('vf-encuesta-' . $idEnc, 'Califica la atención · ticket #' . $idEnc, 'Tu opinión ayuda a mejorar el servicio de soporte. Es voluntaria y se responde una sola vez.') ?>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="id_ticket" value="<?= $idEnc ?>">
      <fieldset class="field">
        <legend>¿Cómo fue la atención?</legend>
        <?php foreach (EncuestaService::CALIFICACIONES as $v => $txt): ?>
          <span class="field-check"><label><input type="radio" name="calificacion" value="<?= $v ?>" required> <?= $v ?> · <?= h($txt) ?></label></span>
        <?php endforeach; ?>
      </fieldset>
      <div class="field">
        <label for="enc-com-<?= $idEnc ?>">Comentario (opcional)</label>
        <textarea id="enc-com-<?= $idEnc ?>" name="comentario" maxlength="500" rows="2" placeholder="¿Qué podemos mejorar?"></textarea>
      </div>
      <button type="submit" name="calificar_servicio" class="btn btn-sm">Enviar calificación</button>
    </form>
  <?= vfCerrar() ?>
<?php endforeach; ?>
