<?php
/**
 * Ficha de solución técnica → «Despliegue por equipo»: estado real de la solución en cada computadora (por sala),
 * acciones sobre los equipos marcados (verificar, registrar implementación, revertir, volver a pendiente) y
 * «Programar en un mantenimiento» (crea un mantenimiento de sala con esos equipos). Variables del controlador:
 * $s, $porSala, $mantenimientos, $evidenciasPorDespliegue, $personal, $duraciones, $permisos, $volvio, $formulario.
 */
$gestiona = $permisos['gestionar'];
$puedeProgramar = $gestiona && $s['estado'] === 'ACTIVA' && $s['id_vigente'];
$salaPedida = $volvio && $formulario === 'programar' ? (int)($_POST['id_sala'] ?? 0) : 0;
$tecnicos = array_values(array_filter($personal, fn($p) => $p['role'] === Rol::TECNICO));
?>
<?php if (!$porSala): ?>
  <section class="panel-box">
    <p class="text-muted">Todavía no hay equipos para esta solución. Define dónde se aplica en <a href="<?= h($urlFicha) ?>&amp;sec=resumen">Resumen y alcance</a>.</p>
  </section>
<?php endif; ?>

<?php foreach ($porSala as $idSalaD => $grupo): ?>
  <section class="panel-box">
    <h2 class="panel-title">
      <?= h($grupo['nombre']) ?>
      <?php if ($puedeProgramar && $grupo['programables']): ?>
        <button type="button" class="btn btn-sm w-auto" data-vf-abrir="vf-programar-<?= (int)$idSalaD ?>">Programar en un mantenimiento (<?= (int)$grupo['programables'] ?>)</button>
      <?php endif; ?>
    </h2>
    <?php if ($gestiona): // el formulario envuelve la tabla: las casillas «Marcar» van con la acción de abajo ?>
    <form method="POST" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="accion_st" value="despliegue">
      <input type="hidden" name="id_solucion" value="<?= (int)$s['id_solucion'] ?>">
    <?php endif; ?>
    <div class="tabla-scroll">
      <table class="data">
        <thead><tr><?= $gestiona ? '<th>Marcar</th>' : '' ?><th>Equipo</th><th>Estado</th><th>Versión</th><th>Última verificación</th><th>Último cambio</th><th>Evidencias</th></tr></thead>
        <tbody>
          <?php foreach ($grupo['filas'] as $d): $evs = $evidenciasPorDespliegue[(int)$d['id_despliegue']] ?? []; ?>
            <tr>
              <?php if ($gestiona): ?>
                <td data-label="Marcar"><input class="w-auto" type="checkbox" name="despliegues[]" value="<?= (int)$d['id_despliegue'] ?>" aria-label="Marcar <?= h($d['equipo_codigo']) ?>" <?= $d['estado'] === 'PROGRAMADO' ? 'disabled' : '' ?>></td>
              <?php endif; ?>
              <td data-label="Equipo"><strong><?= h($d['equipo_codigo']) ?></strong><?= !(int)$d['en_objetivo'] ? '<br><span class="hint">ya no es objetivo</span>' : '' ?></td>
              <td data-label="Estado">
                <span class="chip <?= h($chipDespliegue[$d['estado_visible']] ?? '') ?>"><?= h($estadosDespliegue[$d['estado_visible']] ?? $d['estado_visible']) ?></span>
                <?php if ($d['estado'] === 'PROGRAMADO' && $d['id_programado']): ?>
                  <br><span class="hint">Mantenimiento #<?= (int)$d['id_programado'] ?><?= $d['estado_checklist'] ? ' · checklist: ' . h(etiquetaValor($d['estado_checklist'])) : '' ?></span>
                <?php endif; ?>
              </td>
              <td data-label="Versión"><?= $d['version_numero'] !== null ? h($d['version_numero']) : '—' ?></td>
              <td data-label="Última verificación">
                <?php if ($d['ultima_resultado']): ?><?= h(SolucionDespliegueService::RESULTADOS[$d['ultima_resultado']] ?? $d['ultima_resultado']) ?><br><span class="hint"><?= h($fh($d['ultima_fecha'])) ?></span>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
              </td>
              <td data-label="Último cambio"><?= $d['usuario_nombre'] ? h($d['usuario_nombre']) . '<br>' : '' ?><span class="hint"><?= h($fh($d['actualizado_en'])) ?></span><?= $d['observaciones'] ? '<br><span class="hint">' . h($d['observaciones']) . '</span>' : '' ?></td>
              <td data-label="Evidencias">
                <?php if ($evs): ?><details><summary><?= count($evs) ?> de ejecución</summary><?php $galeria = $evs; include __DIR__ . '/_galeria.php'; ?></details>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($gestiona): ?>
      <fieldset class="card-dashed mt-2">
        <legend>Con los equipos marcados</legend>
        <div class="form-grid">
          <div class="field">
            <label for="acc-<?= (int)$idSalaD ?>">Acción</label>
            <select id="acc-<?= (int)$idSalaD ?>" name="accion" required>
              <?php foreach (SolucionDespliegueService::ACCIONES as $k => $txt): ?><option value="<?= h($k) ?>"><?= h($txt) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="res-<?= (int)$idSalaD ?>">Resultado (solo para verificación)</label>
            <select id="res-<?= (int)$idSalaD ?>" name="resultado">
              <?php foreach (SolucionDespliegueService::RESULTADOS as $k => $txt): ?><option value="<?= h($k) ?>"><?= h($txt) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field field-full">
            <label for="obs-<?= (int)$idSalaD ?>">Observaciones</label>
            <textarea id="obs-<?= (int)$idSalaD ?>" name="observaciones" rows="2" placeholder="Qué se revisó, qué se encontró o por qué se revierte (obligatorio para verificar y revertir)"></textarea>
          </div>
          <div class="field field-full">
            <label for="evi-<?= (int)$idSalaD ?>">Evidencias de la verificación (fotos o PDF, opcional)</label>
            <input type="file" id="evi-<?= (int)$idSalaD ?>" name="evidencias[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf">
          </div>
        </div>
        <p class="hint">Lo programado en un mantenimiento no se puede marcar: se actualiza solo cuando ese mantenimiento se cierra en «Mantenimientos».</p>
        <button type="submit" class="btn btn-sm w-auto">Aplicar a los marcados</button>
      </fieldset>
    </form>
    <?php endif; ?>
  </section>

  <?php if ($puedeProgramar && $grupo['programables']): $abierta = $salaPedida === (int)$idSalaD; $pv = static fn(string $c, string $def = ''): string => $abierta ? (string)($_POST[$c] ?? $def) : $def; ?>
  <?= vfAbrir('vf-programar-' . (int)$idSalaD, 'Programar en un mantenimiento · ' . $grupo['nombre'], 'Se crea un mantenimiento de sala con estos equipos («ciertos equipos»). Se ejecuta, se revisa y se cierra en Mantenimientos con su checklist y evidencia.', $abierta, 'lg') ?>
    <?php if ($abierta): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="accion_st" value="programar">
      <input type="hidden" name="id_solucion" value="<?= (int)$s['id_solucion'] ?>">
      <input type="hidden" name="id_sala" value="<?= (int)$idSalaD ?>">
      <fieldset class="field">
        <legend>Equipos (versión <?= h($s['version_vigente']) ?>)</legend>
        <div class="lista-equipos">
          <?php foreach ($grupo['filas'] as $d): if (!$d['programable']) continue; ?>
            <label><input type="checkbox" name="despliegues[]" value="<?= (int)$d['id_despliegue'] ?>" <?= !$abierta || in_array((string)$d['id_despliegue'], (array)($_POST['despliegues'] ?? []), true) ? 'checked' : '' ?>> <?= h($d['equipo_codigo']) ?></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <div class="form-grid">
        <div class="field">
          <label for="pf-<?= (int)$idSalaD ?>">Fecha y hora</label>
          <input type="datetime-local" id="pf-<?= (int)$idSalaD ?>" name="fecha_programada" required value="<?= h($pv('fecha_programada')) ?>">
        </div>
        <div class="field">
          <label for="pd-<?= (int)$idSalaD ?>">Duración estimada</label>
          <select id="pd-<?= (int)$idSalaD ?>" name="duracion_minutos">
            <?php foreach ($duraciones as $min => $txt): ?><option value="<?= (int)$min ?>" <?= (int)$pv('duracion_minutos', '60') === (int)$min ? 'selected' : '' ?>><?= h($txt) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="pr-<?= (int)$idSalaD ?>">Coordinador (opcional)</label>
          <select id="pr-<?= (int)$idSalaD ?>" name="id_responsable">
            <option value="">-- Sin asignar --</option>
            <?php foreach ($tecnicos as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$pv('id_responsable') === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['nombre_completo']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="pa-<?= (int)$idSalaD ?>">Asignar los equipos a (opcional)</label>
          <select id="pa-<?= (int)$idSalaD ?>" name="id_asignado">
            <option value="">-- Que los tome quien llegue --</option>
            <?php foreach ($personal as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$pv('id_asignado') === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['nombre_completo']) ?> (<?= h(etiquetaRol($p['role'])) ?>)</option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <label class="flex-row items-center gap-2">
        <input class="w-auto" type="checkbox" name="confirmar_choque" value="1" <?= $pv('confirmar_choque') === '1' ? 'checked' : '' ?>>
        Programar igual si la sala tiene clase, préstamo u otro mantenimiento a esa hora
      </label>
      <p class="hint">Las instrucciones de instalación, ejecución y verificación se copian como actividades del mantenimiento. Quien tenga los equipos los ve en «Mis tareas técnicas» con los archivos de la versión.</p>
      <button type="submit" class="btn">Programar mantenimiento</button>
    </form>
  <?= vfCerrar() ?>
  <?php endif; ?>
<?php endforeach; ?>

<section class="panel-box">
  <h2 class="panel-title">Mantenimientos de esta solución</h2>
  <?php if (!$mantenimientos): ?>
    <p class="text-muted">Todavía no se ha programado en ningún mantenimiento.</p>
  <?php else: ?>
  <div class="tabla-scroll">
    <table class="data">
      <thead><tr><th>Mantenimiento</th><th>Fecha</th><th>Versión</th><th>Equipos</th><th>Estado</th></tr></thead>
      <tbody>
        <?php foreach ($mantenimientos as $m): ?>
          <tr>
            <td data-label="Mantenimiento"><a href="mantenimientos_programados.php?sala=<?= (int)$m['id_sala'] ?>">#<?= (int)$m['id_programado'] ?> · <?= h($m['abreviatura'] ?: $m['nombre_sala']) ?></a><br><span class="hint">Coordina <?= h($m['responsable_nombre'] ?? 'sin asignar') ?></span></td>
            <td data-label="Fecha"><?= h($fh($m['fecha_programada'])) ?></td>
            <td data-label="Versión"><?= h($m['version_numero']) ?></td>
            <td data-label="Equipos"><?= (int)$m['equipos_realizados'] ?>/<?= (int)$m['total_equipos'] ?> <progress class="sala-progreso" max="<?= max(1, (int)$m['total_equipos']) ?>" value="<?= (int)$m['equipos_realizados'] ?>" aria-label="Equipos realizados"></progress></td>
            <td data-label="Estado">
              <span class="chip <?= h(MantenimientoService::CHIP_ESTADO[$m['estado']] ?? '') ?>"><?= h(etiquetaValor($m['estado'])) ?></span>
              <?php if ($m['sincronizado_en']): ?><br><span class="hint">Resultado pasado a los equipos</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
