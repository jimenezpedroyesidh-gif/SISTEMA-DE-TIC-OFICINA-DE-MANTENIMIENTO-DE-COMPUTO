<?php
/**
 * Vista de horarios — ventana flotante con TODO lo de un bloque (clase, turno, mantenimiento o préstamo):
 * qué es, materia o actividad, sala, día y fecha, horario, persona y estado. Se abre al tocar el bloque.
 * Recibe $bloquesDetalle (lista de bloques con la clave 'vf' = id de la ventana) y $modoVista.
 * Solo presentación.
 */
$tiposDetalle = ['CLASE' => 'Clase', 'TURNO_BECA' => 'Turno de beca-trabajo', 'TURNO_TECNICO' => 'Turno de técnico',
                 'MANTENIMIENTO' => 'Mantenimiento de sala', 'PRESTAMO' => 'Préstamo de sala o equipo'];
foreach ($bloquesDetalle as $b):
    $fechaTxt = HorarioService::DIAS[$b['dia']] . ' ' . date('j', strtotime($b['fecha'])) . ' de ' . HorarioCalendario::MESES[(int)date('n', strtotime($b['fecha']))];
    $persona = $b['tipo'] === 'CLASE' ? 'Profesor' : ($b['origen'] === 'mantenimiento' ? 'Responsable' : ($b['origen'] === 'prestamo' ? 'Solicitado por' : 'Persona'));
    $nombrePersona = $b['origen'] === 'mantenimiento' ? ($b['responsable'] ?? null) : ($b['usuario_nombre'] ?? null);
    $actividad = $b['origen'] === 'horario' ? ($b['detalle'] ?: ($b['tipo'] === 'CLASE' ? 'Sin materia registrada' : 'Turno de atención')) : ($b['detalle'] ?: '—');
?>
  <?= vfAbrir($b['vf'], $tiposDetalle[$b['tipo']] ?? 'Detalle', $fechaTxt . ' · ' . $b['hora_inicio'] . ' a ' . $b['hora_fin']) ?>
    <dl class="hg-ficha">
        <dt><?= $b['tipo'] === 'CLASE' ? 'Materia' : 'Actividad' ?></dt><dd><?= h($actividad) ?></dd>
        <dt>Sala</dt><dd><?= $b['sala_nombre'] ? h($b['sala_nombre']) . ($b['sala_abrev'] && $b['sala_abrev'] !== $b['sala_nombre'] ? ' (' . h($b['sala_abrev']) . ')' : '') : 'Sin sala fija (turno general)' ?></dd>
        <dt>Día</dt><dd><?= h($fechaTxt) ?></dd>
        <dt>Horario</dt><dd><?= h($b['hora_inicio']) ?> a <?= h($b['hora_fin']) ?><?= $b['origen'] === 'mantenimiento' ? ' <span class="hint">(hora de inicio programada)</span>' : '' ?></dd>
        <?php if ($nombrePersona): ?><dt><?= $persona ?></dt><dd><?= h($nombrePersona) ?></dd><?php endif; ?>
        <?php if (!empty($b['periodo']) && $b['origen'] === 'horario'): ?><dt>Periodo</dt><dd><?= h($b['periodo']) ?></dd><?php endif; ?>
        <?php if (!empty($b['estado'])): ?><dt>Estado</dt><dd><span class="chip <?= in_array($b['estado'], ['REALIZADO', TicketWorkflow::APROBADO, TicketWorkflow::CERRADO], true) ? 'solucionado' : 'espera' ?>"><?= h(etiquetaValor((string)$b['estado'])) ?></span></dd><?php endif; ?>
        <?php if (!empty($b['nota_excepcion'])): ?><dt>Solo esta semana</dt><dd><span class="texto-peligro"><?= h($b['nota_excepcion']) ?></span></dd><?php endif; ?>
    </dl>
    <div class="d-flex gap-2 flex-wrap mt-3">
      <?php if ($modoVista === 'profesor' && $b['origen'] === 'horario' && $b['id_sala_raw']): ?>
        <a class="btn btn-sm" href="create_ticket_profesor.php">Reportar un daño o pedir algo</a>
      <?php endif; ?>
      <?php if ($b['origen'] === 'mantenimiento' && in_array($modoVista, ['salas', 'beca_mantenimiento'], true)): ?>
        <a class="btn btn-sm" href="mantenimientos_programados.php">Ir a mantenimientos</a>
      <?php endif; ?>
      <?php if ($b['origen'] === 'prestamo' && $modoVista === 'salas'): ?>
        <a class="btn btn-sm" href="tickets.php">Ver el ticket del préstamo</a>
      <?php endif; ?>
      <button type="button" class="btn btn-ghost btn-sm" data-vf-cerrar>Cerrar</button>
    </div>
  <?= vfCerrar() ?>
<?php endforeach; ?>
