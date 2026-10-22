<?php
/**
 * Ficha de solución técnica → «Versiones y archivos». Cada versión con sus archivos (descarga protegida y huella
 * SHA-256), quién la subió y quién la revisó. El técnico sube versiones nuevas; el admin las aprueba, rechaza o
 * retira. Variables del controlador: $s, $versiones, $archivosPorVersion, $permisos, $userId, $volvio, $formulario.
 */
$volvioVer = $volvio && $formulario === 'version';
$tamano = static fn(int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : max(1, (int)round($b / 1024)) . ' KB';
?>
<section class="panel-box">
  <h2 class="panel-title">
    Versiones
    <?php if ($permisos['gestionar'] && $s['estado'] !== 'RETIRADA'): ?><button type="button" class="btn btn-sm w-auto" data-vf-abrir="vf-version">+ Nueva versión</button><?php endif; ?>
  </h2>
  <p class="hint">La versión vigente es la aprobada más reciente. Los archivos no se pueden cambiar: un ajuste es una versión nueva. Antes de entregar un archivo el sistema revisa que su SHA-256 siga siendo el registrado; para comprobarlo en el equipo: <code>Get-FileHash archivo -Algorithm SHA256</code> (PowerShell).</p>
  <?php if (!$versiones): ?>
    <p class="text-muted">Todavía no hay versiones.<?= $permisos['gestionar'] ? ' Sube la primera con «+ Nueva versión».' : '' ?></p>
  <?php endif; ?>
</section>

<?php foreach ($versiones as $v): $archivos = $archivosPorVersion[(int)$v['id_version']] ?? []; $esVigente = (int)$v['id_version'] === (int)$s['id_vigente']; ?>
  <section class="panel-box">
    <h2 class="panel-title">
      Versión <?= h($v['numero']) ?>
      <span>
        <span class="chip <?= h(SolucionService::CHIP_VERSION[$v['estado']] ?? '') ?>"><?= h(SolucionService::ESTADOS_VERSION[$v['estado']] ?? $v['estado']) ?></span>
        <?php if ($esVigente): ?><span class="chip proceso">Vigente</span><?php endif; ?>
      </span>
    </h2>
    <p class="hint">
      Subida por <?= h($v['autor_nombre']) ?> el <?= h($fh($v['creado_en'])) ?>
      <?php if ($v['revisor_nombre']): ?> · revisada por <?= h($v['revisor_nombre']) ?> el <?= h($fh($v['fecha_revision'])) ?><?php endif; ?>
    </p>
    <p><?= nl2br(h($v['notas_cambios'])) ?></p>
    <?php if ($v['observacion_revision']): ?><p class="nota-panel"><strong>Revisión:</strong> <?= h($v['observacion_revision']) ?></p><?php endif; ?>

    <div class="tabla-scroll">
      <table class="data">
        <thead><tr><th>Archivo</th><th>Tipo</th><th>Tamaño</th><th>SHA-256</th></tr></thead>
        <tbody>
          <?php foreach ($archivos as $a): ?>
            <tr>
              <td data-label="Archivo"><a href="descargar_solucion.php?archivo=<?= (int)$a['id_archivo'] ?>"><?= h($a['nombre_original']) ?></a></td>
              <td data-label="Tipo"><?= h(SolucionArchivos::CATEGORIAS[$a['categoria']] ?? $a['categoria']) ?></td>
              <td data-label="Tamaño"><?= h($tamano((int)$a['tamano_bytes'])) ?></td>
              <td data-label="SHA-256"><code title="<?= h($a['sha256']) ?>"><?= h(substr((string)$a['sha256'], 0, 16)) ?>…</code></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($permisos['autorizar'] && $v['estado'] === 'EN_REVISION'): ?>
      <?php if ((int)$v['id_autor'] === $userId): ?>
        <p class="hint">La subiste tú: otra persona con permiso de administrador debe revisarla.</p>
      <?php else: ?>
        <form method="POST" class="card-dashed mt-2">
          <?= csrfField() ?>
          <input type="hidden" name="accion_st" value="revisar_version">
          <input type="hidden" name="id_version" value="<?= (int)$v['id_version'] ?>">
          <div class="field">
            <label for="rev-<?= (int)$v['id_version'] ?>">Observación de la revisión (obligatoria para rechazar)</label>
            <input type="text" id="rev-<?= (int)$v['id_version'] ?>" name="observacion" maxlength="500" placeholder="Ej. Probado en PC-A101-01, sin errores">
          </div>
          <div class="flex-row flex-wrap gap-2">
            <button type="submit" name="decision" value="APROBADA" class="btn btn-sm w-auto" data-confirm-click="¿Aprobar la versión <?= h($v['numero']) ?>? Pasará a ser la vigente.">Aprobar</button>
            <button type="submit" name="decision" value="RECHAZADA" class="btn btn-danger btn-sm w-auto">Rechazar</button>
          </div>
        </form>
      <?php endif; ?>
    <?php elseif ($permisos['autorizar'] && $v['estado'] === 'APROBADA'): ?>
      <details class="mt-2">
        <summary>Retirar esta versión</summary>
        <form method="POST" class="mt-2">
          <?= csrfField() ?>
          <input type="hidden" name="accion_st" value="retirar_version">
          <input type="hidden" name="id_version" value="<?= (int)$v['id_version'] ?>">
          <div class="field">
            <label for="ret-<?= (int)$v['id_version'] ?>">Motivo</label>
            <input type="text" id="ret-<?= (int)$v['id_version'] ?>" name="motivo" maxlength="255" required placeholder="Ej. Bloquea la impresión en Windows 11 23H2">
          </div>
          <p class="hint">Vuelve a regir la versión aprobada anterior; los equipos con esta versión aparecen como desactualizados.</p>
          <button type="submit" class="btn btn-danger btn-sm w-auto">Retirar versión</button>
        </form>
      </details>
    <?php endif; ?>
  </section>
<?php endforeach; ?>

<?php if ($permisos['gestionar'] && $s['estado'] !== 'RETIRADA'): ?>
<?= vfAbrir('vf-version', 'Nueva versión', 'Queda en revisión hasta que el administrador la apruebe.', $volvioVer, 'lg') ?>
  <?php if ($volvioVer): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
  <form method="POST" enctype="multipart/form-data">
    <?= csrfField() ?>
    <input type="hidden" name="accion_st" value="version">
    <input type="hidden" name="id_solucion" value="<?= (int)$s['id_solucion'] ?>">
    <div class="field">
      <label for="ver-numero">Número de versión</label>
      <input type="text" id="ver-numero" name="numero" maxlength="20" required value="<?= h($volvioVer ? (string)($_POST['numero'] ?? '') : '') ?>" placeholder="Ej. 1.0">
    </div>
    <div class="field">
      <label for="ver-notas">Qué cambia</label>
      <textarea id="ver-notas" name="notas_cambios" rows="3" required placeholder="Primera versión / corrige… / agrega…"><?= h($volvioVer ? (string)($_POST['notas_cambios'] ?? '') : '') ?></textarea>
    </div>
    <div class="field">
      <label for="ver-archivos">Archivos (hasta <?= (int)SolucionArchivos::MAX_ARCHIVOS_VERSION ?>)</label>
      <input type="file" id="ver-archivos" name="archivos[]" multiple required accept="<?= h('.' . implode(',.', array_keys(SolucionArchivos::EXTENSIONES))) ?>">
      <p class="hint">Scripts (.ps1, .bat, .cmd, .vbs, .py, .sh), instaladores (.msi, .exe, .zip, .7z…), configuración (.reg, .pol, .admx, .xml, .json…) y documentos (.pdf, .docx, .md…). Máximo <?= (int)(SolucionArchivos::TAMANO_MAXIMO / 1048576) ?> MB por archivo (y el límite de subida del servidor).</p>
    </div>
    <button type="submit" class="btn">Enviar a revisión</button>
  </form>
<?= vfCerrar() ?>
<?php endif; ?>
