<?php
/**
 * Vista del «Catálogo de soluciones técnicas» (soluciones_tecnicas.php?vista=catalogo, técnico y admin). Solo
 * presentación: los datos los prepara SolucionService::datosCatalogo. Filtros, lista y formulario de alta
 * (ventana flotante, solo el técnico).
 */
$paginaTitulo = 'Catálogo de soluciones técnicas'; include LAYOUT_DIR . '/app_page_head.php';
$tituloHeader = 'Soluciones técnicas'; include LAYOUT_DIR . '/app_page_body_start.php';
$volvio = $error !== '' && $formulario === 'crear' && $permisos['gestionar']; // la ventana solo existe para el técnico
?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1>Catálogo de soluciones técnicas</h1>
        <p>Cada solución tiene sus versiones (con archivos y huella SHA-256), sus procedimientos y los equipos donde se aplica. El técnico la documenta; el administrador aprueba cada versión.</p>
      </div>
      <div class="page-acciones">
        <a class="btn btn-secondary btn-sm" href="soluciones_tecnicas.php">Panel de seguimiento</a>
        <?php if ($permisos['gestionar']): ?><button type="button" class="btn btn-sm" data-vf-abrir="vf-nueva-solucion">+ Nueva solución</button><?php endif; ?>
      </div>
    </div>

<?php if ($error && !$volvio): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
<?php if ($exito): ?><div class="exito mb-3"><?= h($exito) ?></div><?php endif; ?>

<section class="panel-box">
  <form method="GET" class="filtros-inline">
    <input type="hidden" name="vista" value="catalogo">
    <div class="field">
      <label for="f-q">Buscar</label>
      <input type="search" id="f-q" name="q" maxlength="80" value="<?= h($filtros['texto']) ?>" placeholder="Código, nombre o descripción">
    </div>
    <div class="field">
      <label for="f-estado">Estado</label>
      <select id="f-estado" name="estado">
        <option value="">Todos</option>
        <?php foreach ($estadosSolucion as $k => $txt): ?><option value="<?= h($k) ?>" <?= $filtros['estado'] === $k ? 'selected' : '' ?>><?= h($txt) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="f-tipo">Tipo</label>
      <select id="f-tipo" name="tipo">
        <option value="0">Todos</option>
        <?php foreach ($tipos as $t): ?><option value="<?= (int)$t['id_tipo'] ?>" <?= $filtros['id_tipo'] === (int)$t['id_tipo'] ? 'selected' : '' ?>><?= h($t['nombre']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm w-auto">Filtrar</button>
    <?php if ($filtros['texto'] !== '' || $filtros['estado'] !== '' || $filtros['id_tipo']): ?><a class="btn btn-ghost btn-sm w-auto" href="soluciones_tecnicas.php?vista=catalogo">Limpiar</a><?php endif; ?>
  </form>

  <?php if (!$soluciones): ?>
    <p class="text-muted">No hay soluciones con esos filtros.<?= $permisos['gestionar'] ? ' Registra una con «+ Nueva solución».' : '' ?></p>
  <?php else: ?>
  <div class="tabla-scroll">
    <table class="data">
      <thead><tr><th>Solución</th><th>Tipo</th><th>Responsable</th><th>Versión vigente</th><th>Equipos al día</th><th>Estado</th></tr></thead>
      <tbody>
        <?php foreach ($soluciones as $s): $c = $s['cobertura']; ?>
          <tr>
            <td data-label="Solución"><a href="soluciones_tecnicas.php?id=<?= (int)$s['id_solucion'] ?>"><strong><?= h($s['codigo']) ?></strong> · <?= h($s['nombre']) ?></a></td>
            <td data-label="Tipo"><?= h($s['tipo_nombre']) ?></td>
            <td data-label="Responsable"><?= $s['responsable_nombre'] ? h($s['responsable_nombre']) : '<span class="text-muted">Sin asignar</span>' ?></td>
            <td data-label="Versión vigente">
              <?= $s['version_vigente'] !== null ? h($s['version_vigente']) : '<span class="text-muted">Sin aprobar</span>' ?>
              <?php if ((int)$s['en_revision']): ?><br><span class="chip espera"><?= (int)$s['en_revision'] ?> en revisión</span><?php endif; ?>
            </td>
            <td data-label="Equipos al día">
              <?php if ($c): ?><?= (int)$c['al_dia'] ?>/<?= (int)$c['objetivo'] ?> <progress class="sala-progreso" max="<?= max(1, (int)$c['objetivo']) ?>" value="<?= (int)$c['al_dia'] ?>" aria-label="Equipos al día"></progress>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td data-label="Estado"><span class="chip <?= h($chipSolucion[$s['estado']] ?? '') ?>"><?= h($estadosSolucion[$s['estado']] ?? $s['estado']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php if ($permisos['gestionar']): ?>
<?= vfAbrir('vf-nueva-solucion', 'Nueva solución técnica', 'Queda en borrador hasta que el administrador apruebe su primera versión.', $volvio, 'lg') ?>
  <?php if ($volvio): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="accion_st" value="crear">
    <?php $fsValores = $volvio ? $_POST : []; $fsPrefijo = 'ns'; include __DIR__ . '/_form_solucion.php'; ?>
    <button type="submit" class="btn">Registrar solución</button>
  </form>
<?= vfCerrar() ?>
<?php endif; ?>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
