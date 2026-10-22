<?php
/**
 * Acción «Calificar atención» de una fila de ticket resuelto (paneles de solicitante y profesor).
 *
 * Variables: $t (fila del ticket, con 'id' y 'estado'), $respuestasEncuesta (id => respuesta, de EncuestaService::respuestasDe()).
 */
$idEncuesta = (int)$t['id'];
?>
<?php if (isset($respuestasEncuesta[$idEncuesta])): ?>
  <span class="hint m-0">Calificado: <?= (int)$respuestasEncuesta[$idEncuesta]['calificacion'] ?>/5</span>
<?php elseif (in_array($t['estado'], EncuestaService::ESTADOS_CALIFICABLES, true)): ?>
  <button type="button" class="btn btn-ghost btn-sm" data-vf-abrir="vf-encuesta-<?= $idEncuesta ?>">Calificar atención</button>
<?php endif; ?>
