<?php
/**
 * Vista de horarios — «mi semana» en un solo lugar (profesor, beca de mantenimiento, beca de secretaría/redes).
 * Sin pestañas por sala ni desplegables: todo lo de la persona en una sola grilla (computador) o día por día
 * (celular). Arriba, lo que está pasando ahora y lo próximo. Cada bloque se toca para ver todo su detalle.
 * Solo presentación: los datos los prepara HorarioCalendario.
 */
$claseTipo = ['CLASE' => 'bloque-clase', 'TURNO_BECA' => 'bloque-beca', 'TURNO_TECNICO' => 'bloque-tecnico', 'MANTENIMIENTO' => 'bloque-mantenimiento', 'PRESTAMO' => 'bloque-prestamo'];
$titulo = static function (array $b): string {
    if ($b['origen'] === 'horario') return $b['tipo'] === 'CLASE' ? ($b['detalle'] ?: 'Clase') : 'Mi turno';
    if ($b['origen'] === 'mantenimiento') return (string)strtok((string)$b['detalle'], ':');
    return $b['detalle'] ?: 'Préstamo';
};
// Identificador de la ventana de detalle de cada bloque.
foreach ($bloques as $i => $b) $bloques[$i]['vf'] = 'hg-det-' . $i;
$visibles = array_values(array_filter($bloques, static fn($b) => $b['dia'] >= 1 && $b['dia'] <= 7));

// Ahora y próximo (solo en la semana actual).
$ahoraTs = time();
$actual = null; $proximo = null;
if ($esSemanaActual) {
    foreach ($visibles as $b) {
        $ini = strtotime($b['fecha'] . ' ' . $b['hora_inicio']);
        $fin = strtotime($b['fecha'] . ' ' . $b['hora_fin']);
        if ($ini <= $ahoraTs && $ahoraTs < $fin && $actual === null) $actual = $b;
        if ($ini > $ahoraTs && $proximo === null) $proximo = $b;
    }
}
$cuenta = static fn(string $tipo): int => count(array_filter($visibles, static fn($b) => $b['tipo'] === $tipo));
$resumenTexto = [
    Rol::PROFESOR => $cuenta('CLASE') . ' clase(s)' . ($cuenta('PRESTAMO') ? ' · ' . $cuenta('PRESTAMO') . ' préstamo(s)' : '') . ($cuenta('MANTENIMIENTO') ? ' · ' . $cuenta('MANTENIMIENTO') . ' mantenimiento(s) en tus salas' : ''),
    'beca_mantenimiento' => $cuenta('TURNO_BECA') . ' turno(s) · ' . $cuenta('MANTENIMIENTO') . ' mantenimiento(s) programados',
    'beca_personal' => $cuenta('TURNO_BECA') . ' turno(s)',
][$modoVista] ?? '';
$lineaBloque = static function (?array $b) use ($titulo): string {
    if ($b === null) return '';
    return HorarioService::DIAS[$b['dia']] . ' · ' . $b['hora_inicio'] . '–' . $b['hora_fin'] . ' · ' . $titulo($b) . ($b['sala_abrev'] ? ' · ' . $b['sala_abrev'] : '');
};
?>
<div class="stat-row stat-row-4 hg-resumen">
  <?php if ($actual): ?>
    <button type="button" class="stat-card hg-tarjeta-ahora" data-vf-abrir="<?= h($actual['vf']) ?>">
      <span class="stat-label">Ahora</span>
      <span class="stat-value"><?= h($titulo($actual)) ?></span>
      <span class="hint"><?= h($actual['hora_inicio'] . '–' . $actual['hora_fin'] . ($actual['sala_nombre'] ? ' · ' . $actual['sala_nombre'] : '')) ?></span>
    </button>
  <?php endif; ?>
  <?php if ($proximo): ?>
    <button type="button" class="stat-card" data-vf-abrir="<?= h($proximo['vf']) ?>">
      <span class="stat-label">Lo próximo</span>
      <span class="stat-value"><?= h($titulo($proximo)) ?></span>
      <span class="hint"><?= h($lineaBloque($proximo)) ?></span>
    </button>
  <?php elseif (!$actual): ?>
    <div class="stat-card">
      <span class="stat-label">Lo próximo</span>
      <span class="stat-value">—</span>
      <span class="hint"><?= $esSemanaActual ? 'No tienes nada más esta semana.' : 'Estás viendo otra semana.' ?></span>
    </div>
  <?php endif; ?>
  <div class="stat-card">
    <span class="stat-label">Esta semana</span>
    <span class="stat-value"><?= count($visibles) ?></span>
    <span class="hint"><?= h($resumenTexto) ?></span>
  </div>
</div>

<section class="panel-box hg-calendario-panel hg-personal">
  <div class="hg-semana-nav">
    <a class="btn btn-secondary btn-sm" href="<?= h($linkSemanaAnterior) ?>">← Anterior</a>
    <span class="hg-semana-texto"><?= h($textoRangoSemana) ?><?= $esSemanaActual ? ' <span class="hg-semana-actual-tag">(semana actual)</span>' : '' ?></span>
    <a class="btn btn-secondary btn-sm" href="<?= h($linkSemanaSiguiente) ?>">Siguiente →</a>
    <?php if (!$esSemanaActual): ?><a class="btn btn-ghost btn-sm" href="<?= h($linkSemanaHoy) ?>">Hoy</a><?php endif; ?>
  </div>

  <div class="hg-leyenda">
    <?php if ($modoVista === Rol::PROFESOR): ?>
      <span class="hg-leyenda-item"><i class="hg-swatch bloque-clase"></i> Mis clases</span>
      <span class="hg-leyenda-item"><i class="hg-swatch bloque-prestamo"></i> Mis préstamos</span>
      <span class="hg-leyenda-item"><i class="hg-swatch bloque-mantenimiento"></i> Mantenimiento en mis salas</span>
    <?php else: ?>
      <span class="hg-leyenda-item"><i class="hg-swatch bloque-beca"></i> Mi turno</span>
      <?php if ($modoVista === 'beca_mantenimiento'): ?><span class="hg-leyenda-item"><i class="hg-swatch bloque-mantenimiento"></i> Mantenimientos de la semana</span><?php endif; ?>
    <?php endif; ?>
    <span class="hg-leyenda-item"><i class="hg-swatch hg-swatch-ahora"></i> En curso ahora</span>
    <span class="hg-leyenda-item hint">Toca un bloque para ver todo el detalle.</span>
  </div>

  <?php if (!$visibles): ?>
    <p class="text-muted"><?= $modoVista === Rol::PROFESOR ? 'No tienes clases registradas esta semana.' : 'No tienes turnos asignados esta semana.' ?> Si crees que falta algo, avísale al técnico o al administrador.</p>
  <?php else: ?>

  <?php /* Computador: una sola grilla semanal con todo lo de la persona. */ ?>
  <?php
  $enGrilla = array_values(array_filter($visibles, static fn($b) => $b['dia'] <= 6
      && min(minutosDesdeHHMM($b['hora_fin']), $horaFinGrid * 60) > max(minutosDesdeHHMM($b['hora_inicio']), $horaInicioGrid * 60)));
  ?>
  <div class="horario-grid-wrap hg-solo-escritorio">
    <div class="horario-grid" style="<?= h(HorarioCalendario::filasGrid($enGrilla, $horaInicioGrid, $horaFinGrid)) ?>">
      <div class="hg-esquina"></div>
      <?php foreach ($diasSemana as $n => $nombre): if ($n > 6) continue; $esDiaHoy = $esSemanaActual && $n === $diaHoyISO; ?>
        <div class="hg-dia-header <?= $esDiaHoy ? 'hg-dia-header-hoy' : '' ?>" style="grid-column:<?= $n + 1 ?>"><?= mb_substr($nombre, 0, 3) ?> <?= (int)date('j', strtotime((clone $lunesSemana)->modify('+' . ($n - 1) . ' days')->format('Y-m-d'))) ?></div>
      <?php endforeach; ?>
      <?php for ($h = $horaInicioGrid; $h < $horaFinGrid; $h++): ?>
        <div class="hg-hora hg-hora-entera" style="grid-row:<?= ($h - $horaInicioGrid) + 2 ?>"><?= sprintf('%02d:00', $h) ?></div>
      <?php endfor; ?>
      <?php foreach ($diasSemana as $n => $nombreDia): if ($n > 6) continue; for ($h = $horaInicioGrid; $h < $horaFinGrid; $h++): ?>
        <div class="hg-celda hg-celda-hora-entera" style="grid-column:<?= $n + 1 ?>; grid-row:<?= ($h - $horaInicioGrid) + 2 ?>"></div>
      <?php endfor; endforeach; ?>
      <?php
      foreach (HorarioCalendario::agruparSolapados($enGrilla) as $g):
          $inicioMin = max(minutosDesdeHHMM($g['inicio']), $horaInicioGrid * 60);
          $finMin = min(minutosDesdeHHMM($g['fin']), $horaFinGrid * 60);
          $filaIni = intdiv($inicioMin - $horaInicioGrid * 60, 60) + 2;
          $filaFin = max($filaIni + 1, intdiv($finMin - $horaInicioGrid * 60 + 59, 60) + 2);
      ?>
        <div class="hg-bloque hg-grupo <?= count($g['items']) === 1 ? ($claseTipo[$g['items'][0]['tipo']] ?? '') : 'bloque-mixto' ?>" style="grid-column:<?= $g['dia'] + 1 ?>; grid-row:<?= $filaIni ?> / <?= $filaFin ?>;"
             data-dia="<?= (int)$g['dia'] ?>" data-inicio="<?= h($g['inicio']) ?>" data-fin="<?= h($g['fin']) ?>" data-usuario="<?= (int)$userId ?>">
          <?php foreach ($g['items'] as $b): ?>
            <button type="button" class="hg-abrir <?= $claseTipo[$b['tipo']] ?? '' ?>" data-vf-abrir="<?= h($b['vf']) ?>" title="Ver detalle"
                    data-dia="<?= (int)$b['dia'] ?>" data-inicio="<?= h($b['hora_inicio']) ?>" data-fin="<?= h($b['hora_fin']) ?>" data-usuario="<?= $b['origen'] === 'horario' ? (int)$userId : '' ?>">
              <strong><?= h($b['hora_inicio']) ?>–<?= h($b['hora_fin']) ?></strong>
              <span><?= h($titulo($b)) ?></span>
              <span class="hg-detalle"><?= h($b['sala_abrev'] ?: ($b['tipo'] !== 'CLASE' && $b['detalle'] ? $b['detalle'] : 'Sin sala')) ?><?= !empty($b['nota_excepcion']) ? ' · cambio esta semana' : '' ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php /* Celular: día por día, en orden de hora (sin desplazarse de lado). */ ?>
  <div class="hg-agenda">
    <?php foreach ($diasSemana as $n => $nombreDia):
        $delDia = array_values(array_filter($visibles, static fn($b) => $b['dia'] === $n));
        if ($n === 7 && !$delDia) continue;
        $fechaDia = (clone $lunesSemana)->modify('+' . ($n - 1) . ' days');
        $esHoy = $esSemanaActual && $n === $diaHoyISO;
    ?>
      <section class="hg-agenda-dia<?= $esHoy ? ' hg-agenda-hoy' : '' ?>">
        <h3 class="hg-agenda-titulo"><?= h($nombreDia) ?> <?= (int)$fechaDia->format('j') ?><?= $esHoy ? ' · hoy' : '' ?></h3>
        <?php if (!$delDia): ?>
          <p class="hg-agenda-libre">Libre</p>
        <?php endif; ?>
        <?php foreach ($delDia as $b): ?>
          <button type="button" class="hg-agenda-item <?= $claseTipo[$b['tipo']] ?? '' ?>" data-vf-abrir="<?= h($b['vf']) ?>"
                  data-dia="<?= (int)$b['dia'] ?>" data-inicio="<?= h($b['hora_inicio']) ?>" data-fin="<?= h($b['hora_fin']) ?>" data-usuario="<?= $b['origen'] === 'horario' ? (int)$userId : '' ?>">
            <span class="hg-agenda-hora"><?= h($b['hora_inicio']) ?><br><?= h($b['hora_fin']) ?></span>
            <span class="hg-agenda-texto">
              <strong><?= h($titulo($b)) ?></strong>
              <span class="hint"><?= h($b['sala_nombre'] ?: ($b['origen'] === 'horario' && $b['tipo'] !== 'CLASE' && $b['detalle'] ? $b['detalle'] : 'Sin sala fija')) ?><?= !empty($b['nota_excepcion']) ? ' · ' . h($b['nota_excepcion']) : '' ?></span>
            </span>
          </button>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<?php $bloquesDetalle = $visibles; include __DIR__ . '/detalle.php'; ?>
