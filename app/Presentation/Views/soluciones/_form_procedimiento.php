<?php
/**
 * Ventana con el formulario de un procedimiento: nuevo (sin $fpProc) o nueva redacción de uno vigente (la anterior
 * se conserva). Variables: $fpProc (procedimiento a reemplazar o null), $fpAbierta, $s, $fases, $error.
 */
$fpId = $fpProc ? (int)$fpProc['id_procedimiento'] : 0;
$fpVal = static fn(string $campo): string => $fpAbierta ? (string)($_POST[$campo] ?? '') : (string)($fpProc[$campo] ?? '');
?>
<?= vfAbrir('vf-proc-' . $fpId, $fpProc ? 'Actualizar procedimiento' : 'Nuevo procedimiento', $fpProc ? 'Se guarda como una redacción nueva; la anterior queda en el historial.' : 'Pasos claros para que cualquier técnico o beca los pueda seguir.', $fpAbierta, 'lg') ?>
  <?php if ($fpAbierta): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
  <form method="POST" enctype="multipart/form-data">
    <?= csrfField() ?>
    <input type="hidden" name="accion_st" value="procedimiento">
    <input type="hidden" name="id_solucion" value="<?= (int)$s['id_solucion'] ?>">
    <?php if ($fpId): ?><input type="hidden" name="id_reemplaza" value="<?= $fpId ?>"><?php endif; ?>
    <div class="form-grid">
      <div class="field">
        <label for="fp-fase-<?= $fpId ?>">Fase</label>
        <select id="fp-fase-<?= $fpId ?>" name="fase" required>
          <?php foreach ($fases as $k => $txt): ?><option value="<?= h($k) ?>" <?= $fpVal('fase') === $k ? 'selected' : '' ?>><?= h($txt) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="fp-titulo-<?= $fpId ?>">Título</label>
        <input type="text" id="fp-titulo-<?= $fpId ?>" name="titulo" maxlength="150" required value="<?= h($fpVal('titulo')) ?>" placeholder="Ej. Instalar GLPI Agent en silencio">
      </div>
    </div>
    <div class="field">
      <label for="fp-pasos-<?= $fpId ?>">Pasos</label>
      <textarea id="fp-pasos-<?= $fpId ?>" name="pasos" rows="8" required placeholder="1. Abrir PowerShell como administrador&#10;2. Ejecutar: .\instalar.ps1 -Servidor https://…&#10;3. Comprobar que el servicio quedó en ejecución"><?= h($fpVal('pasos')) ?></textarea>
    </div>
    <div class="field">
      <label for="fp-video-<?= $fpId ?>">Enlace a un video (opcional)</label>
      <input type="url" id="fp-video-<?= $fpId ?>" name="enlace_video" maxlength="500" value="<?= h($fpVal('enlace_video')) ?>" placeholder="https://…">
    </div>
    <div class="field">
      <label for="fp-capturas-<?= $fpId ?>">Capturas o PDF (opcional, hasta <?= (int)SolucionArchivos::MAX_EVIDENCIAS ?>)</label>
      <input type="file" id="fp-capturas-<?= $fpId ?>" name="capturas[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf">
    </div>
    <button type="submit" class="btn"><?= $fpProc ? 'Guardar nueva redacción' : 'Agregar procedimiento' ?></button>
  </form>
<?= vfCerrar() ?>
