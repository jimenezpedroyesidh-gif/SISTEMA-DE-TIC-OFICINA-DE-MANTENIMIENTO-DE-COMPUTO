<?php
/**
 * Vista de «Indicadores de la mesa de ayuda» (public/indicadores.php). Solo presentación: los datos los prepara
 * IndicadoresPage / IndicadoresService. Tarjetas de resumen, tablas con bordes y exportación en un menú.
 */
$paginaTitulo = 'Indicadores'; include LAYOUT_DIR . '/app_page_head.php';
$tituloHeader = 'Indicadores de la mesa de ayuda'; include LAYOUT_DIR . '/app_page_body_start.php';

$k = $datos['kpi'];
$meta = (int)$datos['meta'];
$dur = static fn(?int $s): string => CalendarioHabil::textoDuracion($s);
$pct = static fn(?float $v): string => IndicadoresService::textoPct($v);
/** Chip de meta: cumple (verde) / no cumple (rojo) / sin datos. */
$chipMeta = static function (?float $v, int $meta, bool $menorEsMejor = false): string {
    if ($v === null) return '<span class="chip">Sin datos</span>';
    $ok = $menorEsMejor ? $v <= $meta : $v >= $meta;
    return $ok ? '<span class="chip solucionado">Cumple la meta</span>' : '<span class="chip danio">Bajo la meta</span>';
};
$fd = static fn(string $f): string => date('d/m/Y', strtotime($f));
?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1>Indicadores de la mesa de ayuda</h1>
        <p>Del <?= h($fd($filtros['desde'])) ?> al <?= h($fd($filtros['hasta'])) ?><?= $textoFiltro !== '' ? ' · ' . h($textoFiltro) : '' ?> · tiempos en horas hábiles.</p>
      </div>
      <div class="page-acciones">
        <details class="menu-acciones" data-menu>
          <summary class="btn btn-sm">Exportar ▾</summary>
          <div class="menu-acciones-lista">
            <a class="menu-acciones-item" href="indicadores.php?<?= h($qsFiltros) ?>&amp;exportar=csv">Excel (CSV) con el detalle</a>
            <a class="menu-acciones-item" href="indicadores.php?<?= h($qsFiltros) ?>&amp;exportar=pdf" target="_blank" rel="noopener">Informe PDF</a>
          </div>
        </details>
      </div>
    </div>


    <section class="panel-box barra-filtro">
      <form method="GET" class="barra-filtro-form" aria-label="Filtrar indicadores">
        <div class="field">
          <label for="desde">Desde</label>
          <input type="date" id="desde" name="desde" value="<?= h($filtros['desde']) ?>">
        </div>
        <div class="field">
          <label for="hasta">Hasta</label>
          <input type="date" id="hasta" name="hasta" value="<?= h($filtros['hasta']) ?>">
        </div>
        <div class="field">
          <label for="dependencia">Dependencia</label>
          <select id="dependencia" name="dependencia">
            <option value="">Todas</option>
            <?php foreach ($dependencias as $d): ?>
              <option value="<?= h($d) ?>" <?= $filtros['dependencia'] === $d ? 'selected' : '' ?>><?= h($d) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="sala">Sala</label>
          <select id="sala" name="sala">
            <option value="0">Todas</option>
            <?php foreach ($salas as $s): ?>
              <option value="<?= (int)$s['id_sala'] ?>" <?= $filtros['id_sala'] === (int)$s['id_sala'] ? 'selected' : '' ?>><?= h($s['nombre_sala']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
        <?php if (!empty($_GET)): ?><a class="btn btn-ghost btn-sm" href="indicadores.php">Quitar filtros</a><?php endif; ?>
      </form>
    </section>

    <div class="stat-row stat-row-4">
      <div class="stat-card">
        <span class="stat-label">Tickets recibidos</span>
        <span class="stat-value"><?= (int)$k['recibidos'] ?></span>
        <span class="hint"><?= (int)$k['incidentes'] ?> incidentes · <?= (int)$k['requerimientos'] ?> requerimientos</span>
      </div>
      <div class="stat-card">
        <span class="stat-label">Resueltos</span>
        <span class="stat-value"><?= (int)$k['resueltos'] ?></span>
        <span class="hint"><?= (int)$k['abiertos'] ?> abiertos · <?= (int)$k['descartados'] ?> descartados</span>
      </div>
      <div class="stat-card">
        <span class="stat-label">Cumplimiento SLA · solución</span>
        <span class="stat-value"><?= h($pct($k['cumpl_solucion'])) ?></span>
        <span class="hint">Meta ≥ <?= $meta ?> % · <?= (int)$k['n_solucion'] ?> medidos <?= $chipMeta($k['cumpl_solucion'], $meta) ?></span>
      </div>
      <div class="stat-card">
        <span class="stat-label">Satisfacción (1 a 5)</span>
        <span class="stat-value"><?= $k['satisfaccion'] === null ? '—' : h(number_format($k['satisfaccion'], 2, ',', '.')) ?></span>
        <span class="hint"><?= h($pct($k['pct_satisfechos'])) ?> satisfechos · <?= (int)$k['n_encuestas'] ?> encuestas (<?= h($pct($k['tasa_encuesta'])) ?> respondió)</span>
      </div>
      <div class="stat-card">
        <span class="stat-label">Primera respuesta</span>
        <span class="stat-value"><?= h($dur($k['prom_respuesta'])) ?></span>
        <span class="hint">Promedio · mediana <?= h($dur($k['mediana_respuesta'])) ?> · SLA <?= h($pct($k['cumpl_respuesta'])) ?></span>
      </div>
      <div class="stat-card">
        <span class="stat-label">Tiempo de solución</span>
        <span class="stat-value"><?= h($dur($k['prom_solucion'])) ?></span>
        <span class="hint">Promedio sin pausas · mediana <?= h($dur($k['mediana_solucion'])) ?></span>
      </div>
      <div class="stat-card">
        <span class="stat-label">Solución al primer contacto</span>
        <span class="stat-value"><?= h($pct($k['fcr'])) ?></span>
        <span class="hint">Sin reaperturas y con un solo responsable</span>
      </div>
      <div class="stat-card">
        <span class="stat-label">Reapertura</span>
        <span class="stat-value"><?= h($pct($k['reapertura'])) ?></span>
        <span class="hint">Ideal &lt; 5 % <?= $chipMeta($k['reapertura'], 5, true) ?></span>
      </div>
      <div class="stat-card">
        <span class="stat-label">Preventivos realizados</span>
        <span class="stat-value"><?= h($pct($datos['preventivos']['cumplimiento'])) ?></span>
        <span class="hint"><?= (int)$datos['preventivos']['realizados'] ?> de <?= (int)$datos['preventivos']['programados'] ?> programados · <?= h($pct($datos['preventivos']['a_tiempo_pct'])) ?> a tiempo</span>
      </div>
      <div class="stat-card">
        <span class="stat-label">Equipos operativos</span>
        <span class="stat-value"><?= h($pct($datos['disponibilidad_total'])) ?></span>
        <span class="hint">Foto de hoy en las salas filtradas</span>
      </div>
      <div class="stat-card">
        <span class="stat-label">Pendientes hoy</span>
        <span class="stat-value"><?= (int)$datos['pendientes'] ?></span>
        <span class="hint"><?= count($datos['criticos']) ?> vencidos o por vencer</span>
      </div>
      <div class="stat-card">
        <span class="stat-label">Cumplimiento SLA · respuesta</span>
        <span class="stat-value"><?= h($pct($k['cumpl_respuesta'])) ?></span>
        <span class="hint">Meta ≥ <?= $meta ?> % · <?= (int)$k['n_respuesta'] ?> medidos <?= $chipMeta($k['cumpl_respuesta'], $meta) ?></span>
      </div>
    </div>

    <section class="panel-box">
      <h2 class="panel-title">Requieren atención ahora <span class="hint">vencidos o por vencer, del más atrasado al menos</span></h2>
      <?php if (!$datos['criticos']): ?>
        <p class="text-muted">Ningún ticket abierto está vencido ni por vencer.</p>
      <?php else: ?>
      <div class="tabla-scroll">
        <table class="data">
          <thead><tr><th>Ticket</th><th>Sala / dependencia</th><th>Prioridad</th><th>Responsable</th><th>Vence</th><th>Avance del tiempo</th><th>Estado</th></tr></thead>
          <tbody>
            <?php foreach ($datos['criticos'] as $t): ?>
              <tr>
                <td data-label="Ticket"><strong>#<?= (int)$t['id'] ?></strong> <span class="hint"><?= $t['tabla'] === TablaTicket::NORMAL ? 'Solicitante / QR' : 'Profesor' ?></span><br><span class="hint"><?= h(mb_substr((string)$t['resumen'], 0, 80)) ?></span></td>
                <td data-label="Sala / dependencia"><?= h($t['nombre_sala'] ?: ($t['dependencia'] ?: '—')) ?></td>
                <td data-label="Prioridad"><?= h(SlaService::PRIORIDADES[$t['prioridad_sla']]) ?></td>
                <td data-label="Responsable"><?= $t['responsable'] ? h($t['responsable']) : '<span class="texto-peligro">Sin responsable</span>' ?></td>
                <td data-label="Vence"><?= h(date('d/m/Y H:i', strtotime((string)$t['venc_solucion']))) ?></td>
                <td data-label="Avance del tiempo"><progress class="sala-progreso" max="100" value="<?= (int)min(100, round($t['consumo'] * 100)) ?>"></progress> <span class="hint"><?= (int)round($t['consumo'] * 100) ?> %</span></td>
                <td data-label="Estado"><span class="<?= SlaService::SEMAFORO[$t['semaforo']][1] ?>"><?= h(SlaService::SEMAFORO[$t['semaforo']][0]) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>

    <div class="panel-grid">
      <section class="panel-box">
        <h2 class="panel-title">Cumplimiento por prioridad</h2>
        <div class="tabla-scroll">
          <table class="data">
            <thead><tr><th>Prioridad</th><th>Tickets</th><th>Resueltos</th><th>Cumplimiento</th><th>Solución prom.</th></tr></thead>
            <tbody>
              <?php foreach ($datos['prioridades'] as $p => $x): ?>
                <tr>
                  <td data-label="Prioridad"><?= h(SlaService::PRIORIDADES[$p]) ?></td>
                  <td data-label="Tickets"><?= (int)$x['n'] ?></td>
                  <td data-label="Resueltos"><?= (int)$x['resueltos'] ?></td>
                  <td data-label="Cumplimiento"><?= h($pct($x['cumplimiento'])) ?></td>
                  <td data-label="Solución prom."><?= h($dur($x['prom_solucion'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="panel-box">
        <h2 class="panel-title">Pendientes por antigüedad <span class="hint">hoy, <?= (int)$datos['pendientes'] ?> abiertos</span></h2>
        <div class="tabla-scroll">
          <table class="data">
            <thead><tr><th>Antigüedad</th><th>Tickets</th><th>Proporción</th></tr></thead>
            <tbody>
              <?php foreach (IndicadoresService::BUCKETS as $b => $nombre): $n = (int)$datos['antiguedad'][$b]; ?>
                <tr>
                  <td data-label="Antigüedad"><?= h($nombre) ?></td>
                  <td data-label="Tickets"><?= $n ?></td>
                  <td data-label="Proporción"><progress class="sala-progreso" max="100" value="<?= $datos['pendientes'] ? (int)round($n * 100 / $datos['pendientes']) : 0 ?>"></progress></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <section class="panel-box">
      <h2 class="panel-title">Desempeño por responsable</h2>
      <?php if (!$datos['tecnicos']): ?>
        <p class="text-muted">Sin tickets en el periodo.</p>
      <?php else: ?>
      <div class="tabla-scroll">
        <table class="data">
          <thead><tr><th>Responsable</th><th>Tickets</th><th>Resueltos</th><th>Solución prom.</th><th>Cumplimiento SLA</th><th>Satisfacción</th></tr></thead>
          <tbody>
            <?php foreach ($datos['tecnicos'] as $x): ?>
              <tr>
                <td data-label="Responsable"><?= h($x['nombre']) ?></td>
                <td data-label="Tickets"><?= (int)$x['asignados'] ?></td>
                <td data-label="Resueltos"><?= (int)$x['resueltos'] ?></td>
                <td data-label="Solución prom."><?= h($dur($x['prom_solucion'])) ?></td>
                <td data-label="Cumplimiento SLA"><?= h($pct($x['cumplimiento'])) ?></td>
                <td data-label="Satisfacción"><?= $x['satisfaccion'] === null ? '—' : h(number_format($x['satisfaccion'], 1, ',', '.')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>

    <div class="panel-grid">
      <section class="panel-box">
        <h2 class="panel-title">Tickets por categoría</h2>
        <?php if (!$datos['categorias']): ?><p class="text-muted">Sin datos en el periodo.</p><?php else: ?>
        <table class="data">
          <thead><tr><th>Categoría</th><th>Tickets</th></tr></thead>
          <tbody>
            <?php foreach ($datos['categorias'] as $cat => $n): ?>
              <tr><td data-label="Categoría"><?= h(IndicadoresService::CATEGORIAS[(string)$cat] ?? (string)$cat) ?></td><td data-label="Tickets"><?= (int)$n ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </section>
      <section class="panel-box">
        <h2 class="panel-title">Salas con más tickets</h2>
        <?php if (!$datos['salas']): ?><p class="text-muted">Sin datos en el periodo.</p><?php else: ?>
        <table class="data">
          <thead><tr><th>Sala</th><th>Tickets</th></tr></thead>
          <tbody>
            <?php foreach ($datos['salas'] as $sala => $n): ?>
              <tr><td data-label="Sala"><?= h((string)$sala) ?></td><td data-label="Tickets"><?= (int)$n ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </section>
    </div>

    <div class="panel-grid">
      <section class="panel-box">
        <h2 class="panel-title">Equipos operativos por sala <span class="hint">de menor a mayor disponibilidad</span></h2>
        <?php if (!$datos['disponibilidad']): ?><p class="text-muted">No hay salas con equipos registrados.</p><?php else: ?>
        <div class="tabla-scroll">
          <table class="data">
            <thead><tr><th>Sala</th><th>Operativos</th><th>Disponibilidad</th></tr></thead>
            <tbody>
              <?php foreach ($datos['disponibilidad'] as $x): $pd = $x['total'] > 0 ? round($x['operativos'] * 100 / $x['total'], 1) : null; ?>
                <tr>
                  <td data-label="Sala"><?= h($x['nombre_sala']) ?></td>
                  <td data-label="Operativos"><?= (int)$x['operativos'] ?> de <?= (int)$x['total'] ?></td>
                  <td data-label="Disponibilidad"><progress class="sala-progreso" max="100" value="<?= (int)round((float)$pd) ?>"></progress> <span class="hint"><?= h($pct($pd)) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </section>
      <section class="panel-box">
        <h2 class="panel-title">Equipos reincidentes <span class="hint">2 o más incidentes en el periodo</span></h2>
        <?php if (!$datos['reincidentes']): ?><p class="text-muted">Ningún equipo repite incidentes en el periodo.</p><?php else: ?>
        <table class="data">
          <thead><tr><th>Equipo</th><th>Sala</th><th>Incidentes</th></tr></thead>
          <tbody>
            <?php foreach ($datos['reincidentes'] as $x): ?>
              <tr><td data-label="Equipo"><?= h($x['codigo']) ?></td><td data-label="Sala"><?= h((string)$x['nombre_sala']) ?></td><td data-label="Incidentes"><?= (int)$x['incidentes'] ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </section>
    </div>

    <p class="hint">Cómo se mide: el reloj corre solo en el horario de atención (sin festivos) y se detiene mientras el ticket está «en espera del usuario». Primera respuesta = primer cambio de estado, asignación o comentario del personal. Configura los tiempos y el horario en <a href="sla.php?vista=acuerdos">Acuerdos de servicio</a>.</p>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
