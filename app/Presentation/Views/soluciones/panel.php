<?php
/**
 * Vista del «Panel de seguimiento» de soluciones técnicas (soluciones_tecnicas.php, técnico y admin). Solo
 * presentación: los datos los prepara SolucionDespliegueService::datosPanel. Cobertura por solución y por sala
 * (?sala=N: cada equipo × cada solución), equipos que piden atención, versiones por aprobar y mantenimientos en curso.
 */
$paginaTitulo = 'Soluciones técnicas'; include LAYOUT_DIR . '/app_page_head.php';
$tituloHeader = 'Soluciones técnicas'; include LAYOUT_DIR . '/app_page_body_start.php';
$pct = static fn(int $parte, int $total): int => $total > 0 ? (int)floor($parte * 100 / $total) : 0;
$nombreSala = static fn(array $s): string => $s['abreviatura'] ? $s['abreviatura'] . ' — ' . $s['nombre_sala'] : $s['nombre_sala'];
$motivos = ['INCIDENCIA' => ['Con incidencia', 'danio'], 'DESACTUALIZADA' => ['Versión desactualizada', 'incompleto'], 'SIN_VERIFICAR' => ['Sin verificar', 'proceso']];
?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1>Soluciones técnicas</h1>
        <p>Scripts, automatizaciones, agentes de inventario, políticas de Windows, restricciones y configuraciones especiales: dónde deben estar, dónde están, con qué versión y si funcionan.</p>
      </div>
      <div class="page-acciones">
        <a class="btn btn-sm" href="soluciones_tecnicas.php?vista=catalogo">Catálogo y versiones</a>
      </div>
    </div>

<?php if ($error): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
<?php if ($exito): ?><div class="exito mb-3"><?= h($exito) ?></div><?php endif; ?>

<?php if ($permisos['autorizar'] && $enRevision): ?>
  <section class="panel-box">
    <h2 class="panel-title">Versiones por aprobar <span class="chip espera"><?= count($enRevision) ?></span></h2>
    <div class="tabla-scroll">
      <table class="data">
        <thead><tr><th>Solución</th><th>Versión</th><th>Subida por</th><th>Fecha</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($enRevision as $v): ?>
            <tr>
              <td data-label="Solución"><strong><?= h($v['codigo']) ?></strong> · <?= h($v['nombre']) ?></td>
              <td data-label="Versión"><?= h($v['numero']) ?></td>
              <td data-label="Subida por"><?= h($v['autor_nombre']) ?></td>
              <td data-label="Fecha"><?= h(date('d/m/Y H:i', strtotime((string)$v['creado_en']))) ?></td>
              <td class="acciones"><a class="btn btn-sm" href="soluciones_tecnicas.php?id=<?= (int)$v['id_solucion'] ?>&amp;sec=versiones">Revisar</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>

<div class="stat-row">
  <div class="stat-card"><span class="stat-label">Soluciones activas</span><span class="stat-value"><?= count($activas) ?></span><span class="hint"><?= (int)$totales['objetivo'] ?> aplicaciones previstas</span></div>
  <div class="stat-card"><span class="stat-label">Cobertura al día</span><span class="stat-value"><?= $pct($totales['al_dia'], $totales['objetivo']) ?> %</span><progress class="sala-progreso" max="100" value="<?= $pct($totales['al_dia'], $totales['objetivo']) ?>" aria-label="Cobertura al día"></progress></div>
  <div class="stat-card"><span class="stat-label">Pendientes</span><span class="stat-value"><?= (int)$totales['pendientes'] ?></span><span class="hint"><?= (int)$totales['programados'] ?> ya programados</span></div>
  <div class="stat-card"><span class="stat-label">Sin verificar</span><span class="stat-value"><?= (int)$totales['por_verificar'] ?></span><span class="hint">implementadas, falta probarlas</span></div>
  <div class="stat-card"><span class="stat-label">Con incidencia</span><span class="stat-value"><?= (int)$totales['incidencias'] ?></span><span class="hint">verificación fallida o incompleta</span></div>
  <div class="stat-card"><span class="stat-label">Desactualizadas</span><span class="stat-value"><?= (int)$totales['desactualizados'] ?></span><span class="hint">con una versión anterior</span></div>
</div>

<section class="panel-box">
  <h2 class="panel-title">Cobertura por solución</h2>
  <?php if (!$activas): ?>
    <p class="text-muted">Todavía no hay soluciones activas. Una solución queda activa cuando el administrador aprueba su primera versión (<a href="soluciones_tecnicas.php?vista=catalogo">Catálogo</a>).</p>
  <?php else: ?>
  <div class="tabla-scroll">
    <table class="data">
      <thead><tr><th>Solución</th><th>Versión vigente</th><th>Al día</th><th>Pendientes</th><th>Programados</th><th>Sin verificar</th><th>Incidencias</th><th>Desactualizadas</th></tr></thead>
      <tbody>
        <?php foreach ($activas as $s): $c = $porSolucion[(int)$s['id_solucion']] ?? null; ?>
          <tr>
            <td data-label="Solución"><a href="soluciones_tecnicas.php?id=<?= (int)$s['id_solucion'] ?>"><strong><?= h($s['codigo']) ?></strong> · <?= h($s['nombre']) ?></a><br><span class="hint"><?= h($s['tipo_nombre']) ?></span></td>
            <td data-label="Versión vigente"><?= h($s['version_vigente'] ?? '—') ?></td>
            <?php if (!$c): ?>
              <td data-label="Al día" colspan="6"><span class="hint">Sin lugares de aplicación: <a href="soluciones_tecnicas.php?id=<?= (int)$s['id_solucion'] ?>">defínelos en la ficha</a>.</span></td>
            <?php else: ?>
              <td data-label="Al día"><?= (int)$c['al_dia'] ?>/<?= (int)$c['objetivo'] ?> <progress class="sala-progreso" max="<?= max(1, (int)$c['objetivo']) ?>" value="<?= (int)$c['al_dia'] ?>" aria-label="Equipos al día"></progress></td>
              <td data-label="Pendientes"><?= (int)$c['pendientes'] ?></td>
              <td data-label="Programados"><?= (int)$c['programados'] ?></td>
              <td data-label="Sin verificar"><?= (int)$c['por_verificar'] ?></td>
              <td data-label="Incidencias"><?= (int)$c['incidencias'] ? '<span class="chip danio">' . (int)$c['incidencias'] . '</span>' : '0' ?></td>
              <td data-label="Desactualizadas"><?= (int)$c['desactualizados'] ? '<span class="chip incompleto">' . (int)$c['desactualizados'] . '</span>' : '0' ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="panel-box">
  <h2 class="panel-title">Cobertura por sala <span class="hint">elige una sala para ver cada equipo</span></h2>
  <?php if (!$porSala): ?>
    <p class="text-muted">Ninguna solución activa tiene salas o equipos asignados todavía.</p>
  <?php else: ?>
  <div class="tabla-scroll">
    <table class="data">
      <thead><tr><th>Sala</th><th>Soluciones</th><th>Equipos</th><th>Al día</th><th>Pendientes</th><th>Sin verificar</th><th>Incidencias</th><th>Desactualizadas</th></tr></thead>
      <tbody>
        <?php foreach ($porSala as $idS => $c): if (!isset($salas[$idS])) continue; ?>
          <tr<?= $idS === $idSala ? ' class="fila-activa"' : '' ?>>
            <td data-label="Sala"><a href="soluciones_tecnicas.php?sala=<?= (int)$idS ?>#equipos-sala"><?= h($nombreSala($salas[$idS])) ?></a></td>
            <td data-label="Soluciones"><?= (int)$c['soluciones'] ?></td>
            <td data-label="Equipos"><?= (int)$c['equipos'] ?></td>
            <td data-label="Al día"><?= $pct($c['al_dia'], $c['objetivo']) ?> % <progress class="sala-progreso" max="<?= max(1, (int)$c['objetivo']) ?>" value="<?= (int)$c['al_dia'] ?>" aria-label="Aplicaciones al día"></progress></td>
            <td data-label="Pendientes"><?= (int)$c['pendientes'] + (int)$c['programados'] ?></td>
            <td data-label="Sin verificar"><?= (int)$c['por_verificar'] ?></td>
            <td data-label="Incidencias"><?= (int)$c['incidencias'] ? '<span class="chip danio">' . (int)$c['incidencias'] . '</span>' : '0' ?></td>
            <td data-label="Desactualizadas"><?= (int)$c['desactualizados'] ? '<span class="chip incompleto">' . (int)$c['desactualizados'] . '</span>' : '0' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php if ($idSala): ?>
<section class="panel-box" id="equipos-sala">
  <h2 class="panel-title">Equipos de <?= h($nombreSala($salas[$idSala])) ?> <a class="hint" href="soluciones_tecnicas.php">Cerrar</a></h2>
  <?php if (!$matriz['equipos']): ?>
    <p class="text-muted">Ninguna solución activa se aplica en esta sala.</p>
  <?php else: ?>
  <div class="tabla-scroll">
    <table class="data">
      <thead><tr><th>Equipo</th><?php foreach ($matriz['soluciones'] as $idSol => $sol): ?><th><a href="soluciones_tecnicas.php?id=<?= (int)$idSol ?>&amp;sec=despliegue" title="<?= h($sol['nombre']) ?>"><?= h($sol['codigo']) ?></a></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php foreach ($matriz['equipos'] as $eq): ?>
          <tr>
            <td data-label="Equipo"><strong><?= h($eq['codigo']) ?></strong></td>
            <?php foreach ($matriz['soluciones'] as $idSol => $sol): $celda = $eq['celdas'][$idSol] ?? null; ?>
              <td data-label="<?= h($sol['codigo']) ?>">
                <?php if ($celda === null): ?><span class="hint">No aplica</span>
                <?php else: ?><span class="chip <?= h($chipDespliegue[$celda['estado']] ?? '') ?>"><?= h($estadosDespliegue[$celda['estado']] ?? $celda['estado']) ?></span><?= $celda['version'] ? ' <span class="hint">v' . h($celda['version']) . '</span>' : '' ?><?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel-box">
  <h2 class="panel-title">Requieren atención <span class="hint">incidencias, versiones desactualizadas y equipos sin verificar</span></h2>
  <?php if (!$atencion): ?>
    <p class="text-muted">Nada pendiente de revisar.</p>
  <?php else: ?>
  <div class="tabla-scroll">
    <table class="data">
      <thead><tr><th>Equipo</th><th>Solución</th><th>Motivo</th><th>Versión</th><th>Desde</th><th>Observaciones</th></tr></thead>
      <tbody>
        <?php foreach ($atencion as $a): [$txtMotivo, $chipMotivo] = $motivos[$a['motivo']] ?? [$a['motivo'], '']; ?>
          <tr>
            <td data-label="Equipo"><strong><?= h($a['equipo_codigo']) ?></strong><br><span class="hint"><?= h($nombreSala($a)) ?></span></td>
            <td data-label="Solución"><a href="soluciones_tecnicas.php?id=<?= (int)$a['id_solucion'] ?>&amp;sec=despliegue"><?= h($a['codigo']) ?></a> · <?= h($a['nombre']) ?></td>
            <td data-label="Motivo"><span class="chip <?= h($chipMotivo) ?>"><?= h($txtMotivo) ?></span></td>
            <td data-label="Versión"><?= h($a['version_instalada'] ?? '—') ?><?= $a['motivo'] === 'DESACTUALIZADA' ? ' <span class="hint">→ vigente ' . h($a['version_vigente'] ?? 'ninguna') . '</span>' : '' ?></td>
            <td data-label="Desde"><?= h(date('d/m/Y', strtotime((string)$a['actualizado_en']))) ?></td>
            <td data-label="Observaciones"><?= h($a['observaciones'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="panel-box">
  <h2 class="panel-title">Mantenimientos en curso con soluciones técnicas</h2>
  <?php if (!$mantenimientos): ?>
    <p class="text-muted">No hay mantenimientos activos que apliquen soluciones. Se programan desde la ficha de cada solución (Despliegue por equipo).</p>
  <?php else: ?>
  <div class="tabla-scroll">
    <table class="data">
      <thead><tr><th>Mantenimiento</th><th>Solución</th><th>Fecha</th><th>Equipos</th><th>Estado</th></tr></thead>
      <tbody>
        <?php foreach ($mantenimientos as $m): ?>
          <tr>
            <td data-label="Mantenimiento"><a href="mantenimientos_programados.php?sala=<?= (int)$m['id_sala'] ?>">#<?= (int)$m['id_programado'] ?> · <?= h($nombreSala($m)) ?></a><br><span class="hint">Coordina <?= h($m['responsable_nombre'] ?? 'sin asignar') ?></span></td>
            <td data-label="Solución"><a href="soluciones_tecnicas.php?id=<?= (int)$m['id_solucion'] ?>&amp;sec=despliegue"><?= h($m['codigo']) ?></a> v<?= h($m['version_numero']) ?></td>
            <td data-label="Fecha"><?= h(date('d/m/Y H:i', strtotime((string)$m['fecha_programada']))) ?></td>
            <td data-label="Equipos"><?= (int)$m['equipos_realizados'] ?>/<?= (int)$m['total_equipos'] ?> <progress class="sala-progreso" max="<?= max(1, (int)$m['total_equipos']) ?>" value="<?= (int)$m['equipos_realizados'] ?>" aria-label="Equipos realizados"></progress></td>
            <td data-label="Estado"><span class="chip <?= h(MantenimientoService::CHIP_ESTADO[$m['estado']] ?? '') ?>"><?= h(etiquetaValor($m['estado'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
