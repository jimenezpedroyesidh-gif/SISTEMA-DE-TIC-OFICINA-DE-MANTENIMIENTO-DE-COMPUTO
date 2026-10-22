<?php
/**
 * Vista parcial del panel de beca: checklist de equipos de los mantenimientos activos.
 * Mismo diseño que Mantenimientos: cada mantenimiento es una tarjeta con su avance y el botón
 * «Plano de equipos», que abre el plano de la sala (mantenimientos/checklist.php) con los puestos
 * tal como están en el salón; al tocar un equipo se toma, se marca o se libera.
 * Las acciones se envían a mantenimientos_programados.php?volver=panel, que regresa aquí con el
 * mismo plano y equipo abiertos. Variables preparadas por BecaPanel.
 */
if (empty($checklistPorProgramado)) return;
$rol = Rol::BECA;
$userId = (int)$user_id;
$puedeMarcarEquipos = true;   // el beca toma, marca y libera; asignar/revertir es del técnico
$responsablesPosibles = [];
$accionPlano = 'mantenimientos_programados.php?volver=panel';
?>
<section class="panel-box" id="checklist-equipos">
  <h2 class="panel-title">Checklist de equipos por sala</h2>
  <p class="hint">Abre el plano de la sala y toca un equipo libre para tomarlo. Una vez tomado, solo tú (o el técnico) pueden marcarlo; marcado como realizado, solo el técnico puede corregirlo.</p>

  <?php if (!empty($flashChecklist['error'])): ?><div class="error mb-3"><?= h($flashChecklist['error']) ?></div><?php endif; ?>
  <?php if (!empty($flashChecklist['exito'])): ?><div class="exito mb-3"><?= h($flashChecklist['exito']) ?></div><?php endif; ?>

  <div class="sala-grid">
  <?php foreach ($mantenimientosActivos as $m):
      $checklist = $checklistPorProgramado[$m['id_programado']] ?? [];
      if (empty($checklist)) continue;
      $totalCk = count($checklist);
      $hechosCk = count(array_filter($checklist, fn($c) => $c['estado'] === 'REALIZADO'));
      $pendientesCk = count(array_filter($checklist, fn($c) => $c['estado'] === 'PENDIENTE'));
      $mios = array_values(array_filter($checklist, fn($c) => $c['estado'] === 'TOMADO' && (int)$c['id_usuario_asignado'] === (int)$user_id));
  ?>
    <article class="sala-card">
      <div class="sala-card-head">
        <div>
          <h3 class="sala-card-titulo"><?= h($m['abreviatura'] ?: $m['nombre_sala']) ?><?= $m['abreviatura'] && $m['abreviatura'] !== $m['nombre_sala'] ? ' <span class="text-muted">· ' . h($m['nombre_sala']) . '</span>' : '' ?></h3>
          <p class="sala-card-sub"><?= h($m['descripcion']) ?></p>
        </div>
        <span class="chip <?= $m['estado'] === 'EN_PROCESO' ? 'proceso' : 'espera' ?>"><?= $m['estado'] === 'EN_PROCESO' ? TicketWorkflow::EN_PROCESO : 'Programado' ?></span>
      </div>
      <dl class="sala-card-datos">
        <dt>Fecha</dt><dd><?= h(date('d/m/Y H:i', strtotime((string)$m['fecha_programada']))) ?></dd>
        <dt>Avance</dt><dd><?= $hechosCk ?> de <?= $totalCk ?> realizados · <?= $pendientesCk ?> libre(s)</dd>
        <?php if ($mios): ?><dt>Tú tienes</dt><dd><?= h(implode(', ', array_map(fn($c) => codigoEnSala((string)$c['equipo_codigo']), $mios))) ?></dd><?php endif; ?>
      </dl>
      <progress class="sala-progreso" max="<?= $totalCk ?>" value="<?= $hechosCk ?>" aria-label="Avance de la checklist"></progress>
      <button type="button" class="btn btn-sm sala-abrir" data-sala-abrir="mant-<?= (int)$m['id_programado'] ?>" aria-haspopup="dialog">
        <?= iconoSvg('pc') ?> Plano de equipos (<?= $hechosCk ?>/<?= $totalCk ?>)
      </button>
    </article>
    <?php include dirname(__DIR__) . '/mantenimientos/checklist.php'; ?>
  <?php endforeach; ?>
  </div>
</section>
