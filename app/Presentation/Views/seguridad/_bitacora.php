<?php
/** Vista parcial de «Seguridad»: bitácora de accesos con filtros, exportación y verificación de integridad. Solo presentación. */
$hayFiltro = $filtros['desde'] !== '' || $filtros['hasta'] !== '' || $filtros['evento'] !== '' || $filtros['q'] !== '';
$qsExport = http_build_query(array_filter(['vista' => 'bitacora', 'desde' => $filtros['desde'], 'hasta' => $filtros['hasta'], 'evento' => $filtros['evento'], 'q' => $filtros['q'], 'exportar' => 'csv']));
?>
    <section class="panel-box barra-filtro">
      <form method="GET" class="barra-filtro-form" aria-label="Filtrar la bitácora">
        <input type="hidden" name="vista" value="bitacora">
        <div class="field">
          <label for="q">Buscar</label>
          <input type="search" id="q" name="q" value="<?= h($filtros['q']) ?>" placeholder="Nombre, correo, IP…">
        </div>
        <div class="field">
          <label for="evento">Evento</label>
          <select id="evento" name="evento">
            <option value="">Todos</option>
            <?php foreach (BitacoraAccesos::EVENTOS as $e => $nombre): ?>
              <option value="<?= $e ?>" <?= $filtros['evento'] === $e ? 'selected' : '' ?>><?= h($nombre) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="desde">Desde</label>
          <input type="date" id="desde" name="desde" value="<?= h($filtros['desde']) ?>">
        </div>
        <div class="field">
          <label for="hasta">Hasta</label>
          <input type="date" id="hasta" name="hasta" value="<?= h($filtros['hasta']) ?>">
        </div>
        <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
        <?php if ($hayFiltro): ?><a class="btn btn-ghost btn-sm" href="seguridad.php?vista=bitacora">Quitar filtros</a><?php endif; ?>
      </form>
    </section>

    <section class="panel-box">
      <div class="conexion-cabecera">
        <h2 class="panel-title">Registros <span class="hint"><?= (int)$bitacora['total'] ?> en total · se conservan <?= (int)$retencionDias ?> días</span></h2>
        <details class="menu-acciones" data-menu>
          <summary class="btn btn-sm">Acciones ▾</summary>
          <div class="menu-acciones-lista">
            <a class="menu-acciones-item" href="seguridad.php?<?= h($qsExport) ?>">Exportar a Excel (CSV)</a>
            <form method="POST" class="m-0">
              <?= csrfField() ?>
              <button type="submit" name="verificar_bitacora" class="menu-acciones-item">Verificar integridad</button>
            </form>
            <form method="POST" class="m-0" data-confirm="¿Borrar los registros con más de <?= (int)$retencionDias ?> días? Quedará constancia de la depuración.">
              <?= csrfField() ?>
              <button type="submit" name="depurar_bitacora" class="menu-acciones-item">Depurar por retención</button>
            </form>
          </div>
        </details>
      </div>
      <?php if (!$bitacora['filas']): ?>
        <p class="text-muted"><?= $hayFiltro ? 'Ningún registro coincide con el filtro.' : 'Aún no hay registros: se llenan con cada inicio de sesión y cambio sensible.' ?></p>
      <?php else: ?>
      <div class="tabla-scroll">
        <table class="data">
          <thead><tr><th>Fecha</th><th>Evento</th><th>Usuario</th><th>Correo escrito</th><th>IP</th><th>Detalle</th></tr></thead>
          <tbody>
            <?php foreach ($bitacora['filas'] as $r): $critico = in_array($r['evento'], BitacoraAccesos::CRITICOS, true); ?>
              <tr>
                <td data-label="Fecha"><?= h(date('d/m/Y H:i:s', strtotime((string)$r['fecha']))) ?></td>
                <td data-label="Evento"><span class="<?= $critico ? 'chip danio' : ($r['evento'] === 'LOGIN_OK' ? 'chip solucionado' : 'chip') ?>"><?= h(BitacoraAccesos::EVENTOS[$r['evento']] ?? $r['evento']) ?></span></td>
                <td data-label="Usuario"><?= $r['nombre_completo'] ? h($r['nombre_completo']) . '<br><span class="hint">' . h(etiquetaRol((string)$r['role'])) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                <td data-label="Correo escrito"><?= $r['correo'] ? h($r['correo']) : '<span class="text-muted">—</span>' ?></td>
                <td data-label="IP"><?= h((string)$r['ip']) ?><?php if ($r['agente']): ?><br><span class="hint" title="<?= h($r['agente']) ?>"><?= h(mb_substr((string)$r['agente'], 0, 40)) ?>…</span><?php endif; ?></td>
                <td data-label="Detalle"><?= $r['detalle'] ? h($r['detalle']) : '<span class="text-muted">—</span>' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= renderControlesPaginacion($filtros['pagina'], $bitacora['paginas']) ?>
      <?php endif; ?>
      <p class="hint">Cada registro guarda la huella (SHA-256) del anterior: si alguien edita o borra registros directamente en la base de datos, «Verificar integridad» lo detecta.</p>
    </section>
