<?php
/**
 * Vista de «Acciones correctivas» (historial_mantenimiento.php?vista=acciones). Solo presentación.
 * Tareas que salieron del análisis de semestre, con responsable, prioridad y fecha objetivo. Cada una se
 * convierte en un mantenimiento de sala (o en una campaña si no tiene sala) y queda hecha al terminarlo.
 */
$paginaTitulo = 'Acciones correctivas'; include LAYOUT_DIR . '/app_page_head.php';
$tituloHeader = 'Acciones correctivas'; include LAYOUT_DIR . '/app_page_body_start.php';
$chipAccion = ['PENDIENTE' => 'espera', 'PROGRAMADA' => 'proceso', 'HECHA' => 'solucionado', 'DESCARTADA' => 'danio'];
$chipPrioridad = ['ALTA' => 'danio', 'MEDIA' => 'incompleto', 'BAJA' => 'espera'];
$hoy = date('Y-m-d');
?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1>Acciones correctivas · semestre <?= h($destino) ?></h1>
        <p>Lo que se decidió a partir del análisis del semestre anterior. Se definen desde <a href="historial_mantenimiento.php">Historial y análisis</a>.</p>
      </div>
      <div class="page-acciones">
        <form method="GET" class="flex-row items-center gap-2">
          <input type="hidden" name="vista" value="acciones">
          <label for="f-destino" class="sr-only">Semestre</label>
          <select id="f-destino" name="destino">
            <?php foreach ($periodosDestino as $p): ?><option value="<?= h($p) ?>" <?= $p === $destino ? 'selected' : '' ?>><?= h($p) ?></option><?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-secondary btn-sm">Ver</button>
        </form>
      </div>
    </div>

<?php if ($error): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
<?php if ($exito): ?><div class="exito mb-3"><?= h($exito) ?></div><?php endif; ?>

<section class="panel-box">
  <?php if (!$acciones): ?>
    <p class="text-muted">No hay acciones para el semestre <?= h($destino) ?>. Revisa el informe en <a href="historial_mantenimiento.php">Historial y análisis</a> y usa «Definir acción» en la sala o el equipo.</p>
  <?php else: ?>
  <div class="tabla-scroll">
    <table class="data">
      <thead><tr><th>Acción</th><th>Sala / equipo</th><th>Causa</th><th>Prioridad</th><th>Responsable</th><th>Fecha objetivo</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($acciones as $a): $vencida = in_array($a['estado'], ['PENDIENTE', 'PROGRAMADA'], true) && $a['fecha_objetivo'] < $hoy; ?>
          <tr>
            <td data-label="Acción"><strong><?= h(MantenimientoHistorialService::TIPOS_ACCION[$a['tipo_accion']] ?? $a['tipo_accion']) ?></strong><?= $a['descripcion'] ? '<br><span class="hint">' . h($a['descripcion']) . '</span>' : '' ?></td>
            <td data-label="Sala / equipo"><?= h($a['nombre_sala'] ?? 'Varias salas') ?><?= $a['equipo_codigo'] ? '<br><span class="hint">' . h($a['equipo_codigo']) . '</span>' : '' ?></td>
            <td data-label="Causa"><?= h($a['causa']) ?><br><span class="hint">Análisis de <?= h($a['periodo_origen']) ?></span></td>
            <td data-label="Prioridad"><span class="chip <?= $chipPrioridad[$a['prioridad']] ?? '' ?>"><?= h(MantenimientoHistorialService::PRIORIDADES[$a['prioridad']] ?? $a['prioridad']) ?></span></td>
            <td data-label="Responsable"><?= $a['responsable_nombre'] ? h($a['responsable_nombre']) : '<span class="text-muted">Sin asignar</span>' ?></td>
            <td data-label="Fecha objetivo"><?= h(date('d/m/Y', strtotime((string)$a['fecha_objetivo']))) ?><?= $vencida ? '<br><span class="chip danio">Vencida</span>' : '' ?></td>
            <td data-label="Estado">
              <span class="chip <?= $chipAccion[$a['estado']] ?? '' ?>"><?= h(MantenimientoHistorialService::ESTADOS_ACCION[$a['estado']] ?? $a['estado']) ?></span>
              <?php if ($a['id_programado']): ?>
                <br><a class="hint" href="<?= $a['id_campana'] ? 'mantenimiento_campanas.php?id=' . (int)$a['id_campana'] : 'mantenimientos_programados.php?sala=' . (int)$a['id_sala'] ?>">
                  <?= $a['id_campana'] ? 'Campaña' : 'Mantenimiento #' . (int)$a['id_programado'] ?><?= $a['mantenimiento_fecha'] ? ' · ' . h(date('d/m/Y', strtotime((string)$a['mantenimiento_fecha']))) : '' ?>
                </a>
              <?php endif; ?>
            </td>
            <td data-label="">
              <?php if ($a['estado'] === 'PENDIENTE' && $puedeProgramar): ?>
                <a class="btn btn-sm" href="<?= $a['id_sala'] ? 'mantenimientos_programados.php?desde_accion=' . (int)$a['id_accion'] : 'mantenimiento_campanas.php?desde_accion=' . (int)$a['id_accion'] ?>"><?= $a['id_sala'] ? 'Programar' : 'Crear campaña' ?></a>
              <?php endif; ?>
              <?php if (in_array($a['estado'], ['PENDIENTE', 'PROGRAMADA'], true)): ?>
                <form method="POST" class="mt-1">
                  <?= csrfField() ?>
                  <input type="hidden" name="id_accion" value="<?= (int)$a['id_accion'] ?>">
                  <button type="submit" name="estado_accion" value="HECHA" class="btn btn-ghost btn-sm">Marcar hecha</button>
                  <button type="submit" name="estado_accion" value="DESCARTADA" class="btn btn-ghost btn-sm">Descartar</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
