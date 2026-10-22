<?php
/**
 * Campos de cierre dentro de un formulario de cambio de estado: explicación de la solución y fotos del
 * trabajo realizado. Si el ticket la exige (TicketEvidencia::exige), al elegir «Solucionado» ambos son
 * obligatorios: tickets.js lo avisa antes de enviar y el servidor lo vuelve a validar.
 * El <form> que lo incluye debe llevar enctype="multipart/form-data".
 *
 * Variables:
 *   $ccId        prefijo único para los id de los campos
 *   $ccExige     bool, el ticket exige explicación + foto
 *   $ccSolucion  texto de solución ya guardado (o null)
 *   $ccFotos     fotos ya subidas (TicketEvidencia::deTickets()[tabla][id])
 *   $ccSoloFotos true = el formulario ya trae su propio campo «solucion» (ticket normal): solo se agregan las fotos
 */
$ccFotos = $ccFotos ?? [];
$ccExige = !empty($ccExige);
?>
<div class="tk-cierre" data-tk-cierre data-exige="<?= $ccExige ? '1' : '0' ?>" data-fotos="<?= count($ccFotos) ?>" data-min="<?= TicketEvidencia::MIN_SOLUCION ?>">
  <?php if (empty($ccSoloFotos)): ?>
    <div class="field">
      <label for="<?= h($ccId) ?>-sol">Explicación de la solución<?= $ccExige ? ' <span class="hint-requerido">(obligatoria para Solucionado)</span>' : '' ?></label>
      <textarea id="<?= h($ccId) ?>-sol" name="solucion" rows="3" maxlength="2000" placeholder="¿Qué encontraste y qué hiciste? Ej.: se instaló el programa X en los 20 equipos y se verificó la licencia."><?= h((string)($ccSolucion ?? '')) ?></textarea>
    </div>
  <?php endif; ?>
  <div class="field">
    <label for="<?= h($ccId) ?>-fotos">Fotos del trabajo realizado<?= $ccExige ? ' <span class="hint-requerido">(al menos una para Solucionado)</span>' : ' <span class="hint">(opcional)</span>' ?></label>
    <input id="<?= h($ccId) ?>-fotos" type="file" name="evidencia[]" accept="image/jpeg,image/png,image/webp" multiple>
    <p class="hint m-0"><?= $ccFotos ? 'Ya hay ' . count($ccFotos) . ' foto(s); puedes agregar más.' : 'Desde el celular puedes tomar la foto con la cámara.' ?> Máx. <?= TicketEvidencia::MAX_FOTOS_POR_ENVIO ?> por envío. Van al PDF del ticket.</p>
  </div>
  <?php $evFotos = $ccFotos; $evQuitarId = null; include __DIR__ . '/_tk_evidencias.php'; ?>
  <p class="tk-cierre-aviso hint hint-peligro m-0" hidden></p>
</div>
<?php unset($ccId, $ccExige, $ccSolucion, $ccFotos, $ccSoloFotos); ?>
