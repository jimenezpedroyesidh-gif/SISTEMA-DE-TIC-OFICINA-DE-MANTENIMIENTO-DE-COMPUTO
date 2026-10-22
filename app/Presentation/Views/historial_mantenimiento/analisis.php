<?php
/**
 * Vista de «Historial y análisis de mantenimiento» (public/historial_mantenimiento.php). Solo presentación: los
 * datos los prepara HistorialMantenimientoPage / MantenimientoHistorialService.
 */
$paginaTitulo = 'Historial de mantenimiento'; include LAYOUT_DIR . '/app_page_head.php';
$tituloHeader = 'Historial y análisis'; include LAYOUT_DIR . '/app_page_body_start.php';
$k = $datos['kpi'];
$dur = static fn(?int $s): string => CalendarioHabil::textoDuracion($s);
$fd = static fn(string $f): string => date('d/m/Y', strtotime($f));
$pct = static fn(int $n, int $t): string => $t > 0 ? (string)round($n * 100 / $t) . ' %' : '—';
$catTxt = static fn(?string $c): string => $c === null ? '—' : ($c === 'SIN_CLASIFICAR' ? 'Sin clasificar' : etiquetaValor($c));
$hayDetalle = $filtros['id_sala'] || $filtros['id_equipo'] > 0;
$totalFallas = array_sum($datos['fallasPorCategoria']);
$totalTipos = array_sum($datos['porTipo']);
?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1>Historial y análisis de mantenimiento</h1>
        <p>Del <?= h($fd($filtros['desde_fecha'])) ?> al <?= h($fd($filtros['hasta_fecha'])) ?> · incluye los tickets ya archivados en el cierre de semestre.</p>
      </div>
      <div class="page-acciones">
        <a class="btn btn-sm" href="historial_mantenimiento.php?<?= h(http_build_query($qsFiltros + ['exportar' => 'csv'])) ?>">Exportar (CSV)</a>
      </div>
    </div>

<?php if ($error): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
<?php if ($exito): ?><div class="exito mb-3"><?= h($exito) ?> <a href="historial_mantenimiento.php?vista=acciones">Ver acciones</a></div><?php endif; ?>
<?php if ($filtros['equipo_no_existe']): ?><div class="error mb-3">No hay ningún equipo con el código «<?= h($filtros['equipo']) ?>».</div><?php endif; ?>

    <section class="panel-box barra-filtro">
      <form method="GET" class="barra-filtro-form" aria-label="Filtrar historial">
        <div class="field">
          <label for="f-periodo">Semestre</label>
          <select id="f-periodo" name="periodo">
            <?php foreach ($periodos as $p): ?><option value="<?= h($p) ?>" <?= $filtros['periodo'] === $p ? 'selected' : '' ?>><?= h($p) ?></option><?php endforeach; ?>
            <option value="rango" <?= $filtros['periodo'] === 'rango' ? 'selected' : '' ?>>Rango de fechas…</option>
          </select>
        </div>
        <div class="field">
          <label for="f-desde">Desde (rango)</label>
          <input type="date" id="f-desde" name="desde" value="<?= h($filtros['desde_fecha']) ?>">
        </div>
        <div class="field">
          <label for="f-hasta">Hasta (rango)</label>
          <input type="date" id="f-hasta" name="hasta" value="<?= h($filtros['hasta_fecha']) ?>">
        </div>
        <div class="field">
          <label for="f-sala">Sala</label>
          <select id="f-sala" name="sala">
            <option value="0">Todas</option>
            <?php foreach ($salas as $s): ?><option value="<?= (int)$s['id_sala'] ?>" <?= $filtros['id_sala'] === (int)$s['id_sala'] ? 'selected' : '' ?>><?= h($s['nombre_sala']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <?php if ($bloques): ?>
        <div class="field">
          <label for="f-bloque">Bloque</label>
          <select id="f-bloque" name="bloque">
            <option value="">Todos</option>
            <?php foreach ($bloques as $b): ?><option value="<?= h($b) ?>" <?= $filtros['bloque'] === $b ? 'selected' : '' ?>><?= h($b) ?></option><?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="field">
          <label for="f-equipo">Equipo (código)</label>
          <input type="text" id="f-equipo" name="equipo" value="<?= h($filtros['equipo']) ?>" placeholder="Ej. SC403-PC05">
        </div>
        <div class="field">
          <label for="f-resp">Responsable</label>
          <select id="f-resp" name="responsable">
            <option value="0">Todos</option>
            <?php foreach ($personal as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $filtros['id_responsable'] === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['nombre_completo']) ?> (<?= $u['role'] === Rol::TECNICO ? 'técnico' : 'beca' ?>)</option><?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="f-cat">Tipo de incidencia</label>
          <select id="f-cat" name="categoria">
            <option value="">Todas</option>
            <?php foreach (MantenimientoHistorialService::CATEGORIAS as $c): ?><option value="<?= h($c) ?>" <?= $filtros['categoria'] === $c ? 'selected' : '' ?>><?= h(etiquetaValor($c)) ?></option><?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
        <?php if (!empty($_GET)): ?><a class="btn btn-ghost btn-sm" href="historial_mantenimiento.php">Quitar filtros</a><?php endif; ?>
      </form>
    </section>

    <div class="stat-row stat-row-4">
      <div class="stat-card"><span class="stat-label">Intervenciones</span><span class="stat-value"><?= (int)$k['intervenciones'] ?></span><span class="hint">mantenimientos realizados + reportes + solicitudes</span></div>
      <div class="stat-card"><span class="stat-label">Mantenimientos realizados</span><span class="stat-value"><?= (int)$k['mant_realizados'] ?></span><span class="hint">de <?= (int)$k['mant_programados'] ?> programados · <?= h($pct((int)$k['mant_resueltos'], (int)$k['mant_realizados'])) ?> resueltos</span></div>
      <div class="stat-card"><span class="stat-label">Fallas reportadas</span><span class="stat-value"><?= (int)$k['fallas'] ?></span><span class="hint">QR, profesores y personal</span></div>
      <div class="stat-card"><span class="stat-label">Solicitudes de profesores</span><span class="stat-value"><?= (int)$k['solicitudes'] ?></span><span class="hint">software, préstamos, cambios de sala</span></div>
      <div class="stat-card"><span class="stat-label">Equipos afectados</span><span class="stat-value"><?= (int)$k['equipos_afectados'] ?></span><span class="hint"><?= (int)$k['traslados'] ?> traslados de equipos o piezas</span></div>
      <div class="stat-card"><span class="stat-label">Tiempo de atención</span><span class="stat-value"><?= h($dur($k['prom_atencion'])) ?></span><span class="hint">promedio hasta la primera respuesta · <?= (int)$k['n_atencion'] ?> medidos</span></div>
      <div class="stat-card"><span class="stat-label">Tiempo de resolución</span><span class="stat-value"><?= h($dur($k['prom_resolucion'])) ?></span><span class="hint">promedio hasta la solución · <?= (int)$k['n_resolucion'] ?> medidos</span></div>
      <div class="stat-card"><span class="stat-label">Ejecución de mantenimientos</span><span class="stat-value"><?= h($dur($k['prom_ejecucion'])) ?></span><span class="hint">de la fecha programada al cierre</span></div>
    </div>

    <section class="panel-box">
      <h2 class="panel-title">Salas que requieren más intervenciones <span class="hint">informe del periodo · <?= h($filtros['periodo_analizado']) ?></span></h2>
      <?php if (!$datos['porSala']): ?>
        <p class="text-muted">No hay mantenimientos ni reportes de salas en este periodo.</p>
      <?php else: ?>
      <div class="tabla-scroll">
        <table class="data">
          <thead><tr><th>Sala</th><th>Mant.</th><th>Fallas</th><th>Solicitudes</th><th>Equipos revisados</th><th>Traslados</th><th>Total</th><th>Causa principal</th><th>Equipos reincidentes</th><th>Resolución prom.</th><th>Acción</th></tr></thead>
          <tbody>
            <?php foreach ($datos['porSala'] as $s): ?>
              <tr>
                <td data-label="Sala"><a href="historial_mantenimiento.php?<?= h(http_build_query(array_merge($qsFiltros, ['sala' => $s['id_sala']]))) ?>"><?= h($s['abreviatura'] ? $s['abreviatura'] . ' — ' . $s['nombre_sala'] : $s['nombre_sala']) ?></a><?= $s['bloque'] ? '<br><span class="hint">' . h($s['bloque']) . '</span>' : '' ?></td>
                <td data-label="Mant."><?= (int)$s['mantenimientos'] ?></td>
                <td data-label="Fallas"><?= (int)$s['fallas'] ?></td>
                <td data-label="Solicitudes"><?= (int)$s['solicitudes'] ?></td>
                <td data-label="Equipos revisados"><?= (int)$s['equipos_revisados'] ?></td>
                <td data-label="Traslados"><?= (int)$s['traslados'] ?></td>
                <td data-label="Total"><strong><?= (int)$s['total'] ?></strong></td>
                <td data-label="Causa principal"><?= h($catTxt($s['causa_principal'])) ?><?= $s['causa_veces'] ? ' <span class="hint">(' . (int)$s['causa_veces'] . ')</span>' : '' ?></td>
                <td data-label="Equipos reincidentes"><?= $s['reincidentes'] ? h(implode(', ', array_slice($s['reincidentes'], 0, 4))) . (count($s['reincidentes']) > 4 ? '…' : '') : '—' ?></td>
                <td data-label="Resolución prom."><?= h($dur($s['prom_resolucion'])) ?></td>
                <td data-label="Acción">
                  <?php
                    $accSala = (int)$s['id_sala'];
                    $accEquipo = '';
                    $accTipo = $s['accion_sugerida'];
                    $accCausa = $s['causa_principal']
                        ? $s['causa_veces'] . ' fallas de ' . mb_strtolower($catTxt($s['causa_principal'])) . ' en ' . $filtros['periodo_analizado'] . ($s['reincidentes'] ? ' · reincidentes: ' . implode(', ', array_slice($s['reincidentes'], 0, 4)) : '')
                        : $s['total'] . ' intervenciones en ' . $filtros['periodo_analizado'];
                    include __DIR__ . '/_form_accion.php';
                  ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>

    <div class="panel-grid">
      <section class="panel-box">
        <h2 class="panel-title">Fallas más frecuentes</h2>
        <?php if (!$datos['fallasPorCategoria']): ?><p class="text-muted">Sin fallas reportadas.</p><?php else: ?>
        <div class="tabla-scroll">
          <table class="data">
            <thead><tr><th>Tipo de falla</th><th>Reportes</th><th>Proporción</th></tr></thead>
            <tbody>
              <?php foreach ($datos['fallasPorCategoria'] as $cat => $n): ?>
                <tr>
                  <td data-label="Tipo de falla"><?= h($catTxt((string)$cat)) ?></td>
                  <td data-label="Reportes"><?= (int)$n ?></td>
                  <td data-label="Proporción"><progress class="sala-progreso" max="100" value="<?= (int)round($n * 100 / max(1, $totalFallas)) ?>"></progress> <span class="hint"><?= h($pct((int)$n, $totalFallas)) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </section>

      <section class="panel-box">
        <h2 class="panel-title">Por tipo de reporte</h2>
        <?php if (!$datos['porTipo']): ?><p class="text-muted">Sin reportes.</p><?php else: ?>
        <div class="tabla-scroll">
          <table class="data">
            <thead><tr><th>Reporte</th><th>Cantidad</th><th>Proporción</th></tr></thead>
            <tbody>
              <?php foreach ($datos['porTipo'] as $tipo => $n): ?>
                <tr>
                  <td data-label="Reporte"><?= h((string)$tipo) ?></td>
                  <td data-label="Cantidad"><?= (int)$n ?></td>
                  <td data-label="Proporción"><span class="hint"><?= h($pct((int)$n, $totalTipos)) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </section>
    </div>

    <section class="panel-box">
      <h2 class="panel-title">Equipos con mayor reincidencia <span class="hint">2 o más reportes, checklists incompletas o traslados</span></h2>
      <?php if (!$datos['reincidentes']): ?>
        <p class="text-muted">Ningún equipo se repite en este periodo.</p>
      <?php else: ?>
      <div class="tabla-scroll">
        <table class="data">
          <thead><tr><th>Equipo</th><th>Sala</th><th>Reportes</th><th>Incompleto en mant.</th><th>Traslados</th><th>Total</th><th>Acción</th></tr></thead>
          <tbody>
            <?php foreach ($datos['reincidentes'] as $r): ?>
              <tr>
                <td data-label="Equipo"><a href="historial_mantenimiento.php?<?= h(http_build_query(array_merge($qsFiltros, ['equipo' => $r['codigo']]))) ?>"><?= h($r['codigo']) ?></a></td>
                <td data-label="Sala"><?= h($r['nombre_sala']) ?></td>
                <td data-label="Reportes"><?= (int)$r['incidencias'] ?></td>
                <td data-label="Incompleto en mant."><?= (int)$r['incompletos'] ?></td>
                <td data-label="Traslados"><?= (int)$r['traslados'] ?></td>
                <td data-label="Total"><strong><?= (int)$r['total'] ?></strong></td>
                <td data-label="Acción">
                  <?php
                    $accSala = (int)$r['id_sala'];
                    $accEquipo = (string)$r['codigo'];
                    $accTipo = 'REEMPLAZAR_EQUIPO';
                    $accCausa = 'Equipo ' . $r['codigo'] . ' reincidente: ' . (int)$r['total'] . ' eventos en ' . $filtros['periodo_analizado'];
                    include __DIR__ . '/_form_accion.php';
                  ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>

    <section class="panel-box">
      <h2 class="panel-title">
        <?= $hayDetalle ? 'Historial completo' : 'Últimos movimientos' ?>
        <span class="hint"><?= $hayDetalle ? 'mantenimientos, reportes, solicitudes, diagnósticos, soluciones y traslados' : 'elige una sala o un equipo para ver todo su historial' ?></span>
      </h2>
      <?php $lineas = array_slice($datos['timeline'], 0, $hayDetalle ? 300 : 20); ?>
      <?php if (!$lineas): ?>
        <p class="text-muted">Nada registrado en este periodo.</p>
      <?php else: ?>
      <div class="tabla-scroll">
        <table class="data">
          <thead><tr><th>Fecha</th><th>Qué pasó</th><th>Sala</th><th>Estado</th><th>Diagnóstico / solución</th><th>Responsable</th><th>Tiempos</th></tr></thead>
          <tbody>
            <?php foreach ($lineas as $t): ?>
              <tr>
                <td data-label="Fecha"><?= h(date('d/m/Y H:i', strtotime((string)$t['fecha']))) ?></td>
                <td data-label="Qué pasó">
                  <span class="chip <?= ['mantenimiento' => 'solucionado', 'falla' => 'danio', 'solicitud' => 'espera', 'traslado' => 'proceso'][$t['clase']] ?? '' ?>"><?= h(ucfirst($t['clase'])) ?></span>
                  <?= h($t['titulo']) ?>
                  <?php if ($t['detalle'] !== ''): ?><br><span class="hint"><?= h(mb_substr($t['detalle'], 0, 160)) ?></span><?php endif; ?>
                  <?php if ($t['equipos'] !== ''): ?><br><span class="hint">Equipos: <?= h($t['equipos']) ?></span><?php endif; ?>
                </td>
                <td data-label="Sala"><?= h((string)$t['sala']) ?></td>
                <td data-label="Estado"><?= h(etiquetaValor((string)$t['estado'])) ?><?= $t['categoria'] ? '<br><span class="hint">' . h(etiquetaValor($t['categoria'])) . '</span>' : '' ?></td>
                <td data-label="Diagnóstico / solución"><?= $t['solucion'] !== '' ? h(mb_substr($t['solucion'], 0, 200)) : '<span class="text-muted">—</span>' ?></td>
                <td data-label="Responsable"><?= $t['responsable'] !== '' ? h($t['responsable']) : '<span class="text-muted">—</span>' ?></td>
                <td data-label="Tiempos"><?= $t['atencion'] !== null ? 'Atención ' . h($dur($t['atencion'])) : '' ?><?= $t['resolucion'] !== null ? '<br>Resolución ' . h($dur($t['resolucion'])) : '' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($hayDetalle && $datos['timelineTotal'] > count($lineas)): ?><p class="hint">Se muestran los <?= count($lineas) ?> más recientes de <?= (int)$datos['timelineTotal'] ?>; el CSV trae el detalle.</p><?php endif; ?>
      <?php endif; ?>
    </section>

    <p class="hint">Cómo se mide: tiempos en horas de calendario desde que se creó el reporte. Las salas se ordenan por total de intervenciones (mantenimientos realizados + fallas + solicitudes). Un equipo es reincidente si suma 2 o más reportes, checklists incompletas o traslados en el periodo.</p>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
