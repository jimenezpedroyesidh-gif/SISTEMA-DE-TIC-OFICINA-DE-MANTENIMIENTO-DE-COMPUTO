<?php
/**
 * Vista «Mis tareas técnicas» de la beca (soluciones_tecnicas.php). Solo presentación: los datos los prepara
 * SolucionDespliegueService::datosTareas. Por cada mantenimiento activo con una solución técnica donde la beca tiene
 * equipos asignados o tomados: instrucciones, archivos de la versión, sus equipos y sus evidencias. La checklist se
 * marca en «Mis mantenimientos», como siempre.
 */
$paginaTitulo = 'Mis tareas técnicas'; include LAYOUT_DIR . '/app_page_head.php';
$tituloHeader = 'Mis tareas técnicas'; include LAYOUT_DIR . '/app_page_body_start.php';
$fh = static fn(?string $f): string => $f ? date('d/m/Y H:i', strtotime($f)) : '—';
$chipEquipo = MantenimientoService::CHIP_EQUIPO;
// El error de «Subir evidencia» vuelve en la fila de ese equipo; si el equipo no está en sus tareas, se muestra arriba.
$idsEnTareas = [];
foreach ($tareas as $t) {
    $idsEnTareas = array_merge($idsEnTareas, array_map('intval', array_column($t['equipos'], 'id_despliegue')));
}
$despliegueVolvio = $error !== '' && $formulario === 'evidencia_tarea' && in_array((int)($_POST['id_despliegue'] ?? 0), $idsEnTareas, true) ? (int)$_POST['id_despliegue'] : 0;
?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1>Mis tareas técnicas</h1>
        <p>Soluciones técnicas que te toca aplicar en los mantenimientos donde tienes equipos asignados o tomados: instrucciones, archivos y tus evidencias. Cada equipo se marca en la checklist de «Mis mantenimientos».</p>
      </div>
      <div class="page-acciones">
        <a class="btn btn-sm" href="mantenimientos_programados.php">Mis mantenimientos</a>
      </div>
    </div>

<?php if ($error && !$despliegueVolvio): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
<?php if ($exito): ?><div class="exito mb-3"><?= h($exito) ?></div><?php endif; ?>

<?php if (!$tareas): ?>
  <section class="panel-box">
    <p class="text-muted">No tienes tareas técnicas activas. Aparecen aquí cuando el técnico te asigna equipos de un mantenimiento que aplica una solución técnica, o cuando tomas uno de esos equipos en «Mis mantenimientos».</p>
  </section>
<?php endif; ?>

<?php foreach ($tareas as $t): ?>
  <section class="panel-box">
    <h2 class="panel-title">
      <?= h($t['codigo']) ?> · <?= h($t['nombre']) ?>
      <span class="chip <?= h(MantenimientoService::CHIP_ESTADO[$t['estado']] ?? '') ?>"><?= h(etiquetaValor($t['estado'])) ?></span>
    </h2>
    <p class="hint">
      Mantenimiento #<?= (int)$t['id_programado'] ?> · <?= h($t['abreviatura'] ? $t['abreviatura'] . ' — ' . $t['nombre_sala'] : $t['nombre_sala']) ?> ·
      <?= h($fh($t['fecha_programada'])) ?> · versión <?= h($t['version_numero']) ?> · coordina <?= h($t['responsable_nombre'] ?? 'sin asignar') ?>
    </p>
    <p><strong>Objetivo:</strong> <?= nl2br(h($t['objetivo'])) ?></p>

    <h3 class="sec-title">Archivos de la versión <?= h($t['version_numero']) ?></h3>
    <?php if (!$t['archivos']): ?>
      <p class="text-muted">Esta versión no tiene archivos.</p>
    <?php else: ?>
      <ul class="lista-compacta">
        <?php foreach ($t['archivos'] as $a): ?>
          <li><a href="descargar_solucion.php?archivo=<?= (int)$a['id_archivo'] ?>"><?= h($a['nombre_original']) ?></a> <span class="hint">· <?= h(SolucionArchivos::CATEGORIAS[$a['categoria']] ?? $a['categoria']) ?> · SHA-256 <code title="<?= h($a['sha256']) ?>"><?= h(substr((string)$a['sha256'], 0, 16)) ?>…</code></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <h3 class="sec-title">Instrucciones</h3>
    <?php if (!$t['procedimientos']): ?>
      <p class="text-muted">El técnico todavía no documentó los pasos: pregúntale antes de empezar.</p>
    <?php endif; ?>
    <?php foreach ($t['procedimientos'] as $p): ?>
      <details class="card-dashed mb-2"<?= $p['fase'] === 'INSTALACION' ? ' open' : '' ?>>
        <summary><strong><?= h($fases[$p['fase']] ?? $p['fase']) ?>:</strong> <?= h($p['titulo']) ?></summary>
        <p><?= nl2br(h($p['pasos'])) ?></p>
        <?php if ($p['enlace_video']): ?><p><a href="<?= h($p['enlace_video']) ?>" target="_blank" rel="noopener noreferrer">Ver video del procedimiento</a></p><?php endif; ?>
        <?php $galeria = $capturas[(int)$p['id_procedimiento']] ?? []; include __DIR__ . '/_galeria.php'; ?>
      </details>
    <?php endforeach; ?>

    <h3 class="sec-title">Tus equipos</h3>
    <div class="tabla-scroll">
      <table class="data">
        <thead><tr><th>Equipo</th><th>Checklist</th><th>Tus evidencias</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($t['equipos'] as $e): $idD = (int)$e['id_despliegue']; $abierta = $despliegueVolvio === $idD && $idD > 0; ?>
            <tr>
              <td data-label="Equipo"><strong><?= h($e['equipo_codigo']) ?></strong><?= $e['equipo_descripcion'] ? '<br><span class="hint">' . h($e['equipo_descripcion']) . '</span>' : '' ?></td>
              <td data-label="Checklist"><span class="chip <?= h($chipEquipo[$e['estado_checklist']] ?? '') ?>"><?= h(etiquetaValor($e['estado_checklist'])) ?></span></td>
              <td data-label="Tus evidencias"><?php $galeria = $evidenciasPropias[$idD] ?? []; ?><?= $galeria ? '' : '<span class="text-muted">Ninguna</span>' ?><?php include __DIR__ . '/_galeria.php'; ?></td>
              <td data-label="">
                <?php if ($idD): ?>
                <details<?= $abierta ? ' open' : '' ?>>
                  <summary>Subir evidencia</summary>
                  <?php if ($abierta): ?><div class="error mt-2"><?= h($error) ?></div><?php endif; ?>
                  <form method="POST" enctype="multipart/form-data" class="mt-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="accion_st" value="evidencia_tarea">
                    <input type="hidden" name="id_despliegue" value="<?= $idD ?>">
                    <div class="field">
                      <label for="nota-<?= $idD ?>">Qué hiciste o qué muestra</label>
                      <input type="text" id="nota-<?= $idD ?>" name="nota" maxlength="255" required value="<?= h($abierta ? (string)($_POST['nota'] ?? '') : '') ?>" placeholder="Ej. Instalado; el agente ya reporta en GLPI">
                    </div>
                    <div class="field">
                      <label for="fotos-<?= $idD ?>">Fotos o capturas</label>
                      <input type="file" id="fotos-<?= $idD ?>" name="evidencias[]" multiple required accept="image/jpeg,image/png,image/webp,application/pdf">
                    </div>
                    <button type="submit" class="btn btn-sm w-auto">Guardar evidencia</button>
                  </form>
                </details>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endforeach; ?>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
