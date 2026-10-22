<?php
/**
 * Vista de horarios — grilla semanal por sala.
 * Solo presentación: recibe los datos ya preparados por HorariosPage.php /
 * HorarioCalendario (bloques, pestanasSala, diasSemana, enlaces de semana). Sin SQL ni reglas de negocio.
 */
?>
<?php foreach ($bloques as $iB => $bB) $bloques[$iB]['vf'] = 'hg-det-' . $iB; // ventana de detalle de cada bloque ?>
<section class="panel-box hg-calendario-panel">
  <div class="hg-semana-nav">
    <a class="btn btn-secondary btn-sm" href="<?= h($linkSemanaAnterior) ?>">← Semana anterior</a>
    <span class="hg-semana-texto"><?= h($textoRangoSemana) ?><?= $esSemanaActual ? ' <span class="hg-semana-actual-tag">(semana actual)</span>' : '' ?></span>
    <a class="btn btn-secondary btn-sm" href="<?= h($linkSemanaSiguiente) ?>">Semana siguiente →</a>
    <?php if (!$esSemanaActual): ?><a class="btn btn-ghost btn-sm" href="<?= h($linkSemanaHoy) ?>">Hoy</a><?php endif; ?>
  </div>

  <?php if (empty($pestanasSala)): ?>
    <p class="text-muted">No hay horarios registrados para este periodo<?= $filtroActivoH ? ' con estos filtros' : '' ?>.</p>
  <?php else: ?>

  <div class="horario-tabs hg-sala-tabs">
    <?php foreach ($pestanasSala as $idx => $sala): ?>
      <?php $nBloquesSala = $sala['id'] === HorarioCalendario::PESTANA_GENERAL ? count($bloques) : count(array_filter($bloques, fn($b) => $b['id_sala'] === $sala['id'])); ?>
      <button type="button" class="btn btn-ghost btn-sm sala-tab <?= $idx === 0 ? 'active' : '' ?>" data-sala="<?= h($sala['id']) ?>" data-click="horarios.sala" title="<?= h($sala['nombre']) ?>"><?= h($sala['abrev']) ?> <span class="hint"><?= $nBloquesSala ?></span></button>
    <?php endforeach; ?>
  </div>

  <?php foreach ($pestanasSala as $idx => $sala): $esGeneral = $sala['id'] === HorarioCalendario::PESTANA_GENERAL; ?>
  <?php if ($esGeneral): ?><p class="hint hg-general-nota" data-sala-nota="<?= h($sala['id']) ?>">Vista general: todas las salas en una sola semana. Cada bloque indica su sala; toca la pestaña de una sala para ver solo su horario.</p><?php endif; ?>
  <?php
  // Bloques de esta pestaña (General = todas las salas) dentro de lun-sáb y del rango visible.
  $bloquesSala = [];
  foreach ($bloques as $b) {
      if ((!$esGeneral && $b['id_sala'] !== $sala['id']) || $b['dia'] < 1 || $b['dia'] > 6) continue; // domingo no se dibuja
      if (min(minutosDesdeHHMM($b['hora_fin']), $horaFinGrid * 60) <= max(minutosDesdeHHMM($b['hora_inicio']), $horaInicioGrid * 60)) continue; // fuera del rango visible
      $bloquesSala[] = $b;
  }
  // Celdas vacías clicables: la secretaria solo en sus salas (o en General / sin sala, y el servidor valida).
  $celdasEditables = $puedeModificar && ($alcance === null || $sala['id'] <= 0 || in_array($sala['id'], $alcance['salas'], true));
  ?>
  <div class="horario-grid-wrap" data-sala-panel="<?= h($sala['id']) ?>" <?= $idx === 0 ? '' : 'hidden' ?>>
    <div class="horario-grid" style="<?= h(HorarioCalendario::filasGrid($bloquesSala, $horaInicioGrid, $horaFinGrid)) ?>">
      <div class="hg-esquina"></div>
      <?php foreach ($diasSemana as $n => $nombre): if ($n > 6) continue;
          $esDiaHoy = $esSemanaActual && $n === $diaHoyISO;
      ?>
        <div class="hg-dia-header <?= $esDiaHoy ? 'hg-dia-header-hoy' : '' ?>" style="grid-column:<?= $n + 1 ?>"><?= mb_substr($nombre, 0, 3) ?></div>
      <?php endforeach; ?>
      <?php for ($h = $horaInicioGrid; $h < $horaFinGrid; $h++):
          $etiqueta = sprintf('%02d:00', $h);
      ?>
        <div class="hg-hora hg-hora-entera" style="grid-row:<?= ($h - $horaInicioGrid) + 2 ?>"><?= $etiqueta ?></div>
      <?php endfor; ?>

      <?php
      // Celdas vacías: forman el enmallado (líneas de hora/día) y, si hay
      // permiso de modificar, cada una se puede tocar para crear una franja
      // nueva ya con día/hora precargados (1 hora exacta). Van ANTES que los
      // bloques en el HTML para que los bloques (que sí tienen contenido)
      // queden pintados encima y capturen el clic en vez de la celda vacía
      // de abajo.
      if ($celdasEditables):
        foreach ($diasSemana as $n => $nombreDia): if ($n > 6) continue;
          for ($h = $horaInicioGrid; $h < $horaFinGrid; $h++):
            $horaIniCelda = sprintf('%02d:00', $h);
            $horaFinCelda = sprintf('%02d:00', $h + 1);
      ?>
        <div class="hg-celda hg-celda-clickable hg-celda-hora-entera"
             style="grid-column:<?= $n + 1 ?>; grid-row:<?= ($h - $horaInicioGrid) + 2 ?>"
             data-dia="<?= $n ?>" data-inicio="<?= $horaIniCelda ?>" data-fin="<?= $horaFinCelda ?>" data-sala="<?= max(0, $sala['id']) ?>"
             data-fecha="<?= h((clone $lunesSemana)->modify('+' . ($n - 1) . ' days')->format('Y-m-d')) ?>"
             title="Crear franja: <?= mb_substr($nombreDia, 0, 3) ?> <?= $horaIniCelda ?>"
             data-click="horarios.crear"></div>
      <?php
          endfor;
        endforeach;
      else:
        // Sin permiso de modificar: igual se pinta el enmallado, pero sin
        // interacción (no clicable).
        foreach ($diasSemana as $n => $nombreDia): if ($n > 6) continue;
          for ($h = $horaInicioGrid; $h < $horaFinGrid; $h++):
      ?>
        <div class="hg-celda hg-celda-hora-entera" style="grid-column:<?= $n + 1 ?>; grid-row:<?= ($h - $horaInicioGrid) + 2 ?>"></div>
      <?php
          endfor;
        endforeach;
      endif;
      ?>

      <?php
      $claseTipoBloque = ['CLASE' => 'bloque-clase', 'TURNO_BECA' => 'bloque-beca', 'TURNO_TECNICO' => 'bloque-tecnico', 'MANTENIMIENTO' => 'bloque-mantenimiento', 'PRESTAMO' => 'bloque-prestamo'];
      // Atributos de datos de un bloque (o de una línea dentro de un grupo): resaltado «ahora» y edición.
      $datosBloque = static function (array $b) use ($periodoActual): string {
          $editable = !empty($b['editable']);
          $a = 'data-dia="' . (int)$b['dia'] . '" data-inicio="' . h($b['hora_inicio']) . '" data-fin="' . h($b['hora_fin']) . '" data-usuario="' . h($b['id_usuario'] ?? '') . '"';
          if (!$editable && isset($b['vf'])) {
              $a .= ' data-vf-abrir="' . h($b['vf']) . '" role="button" tabindex="0" title="Ver detalle"'; // mantenimientos, préstamos y todo si no se puede editar
          }
          if ($editable) {
              $a .= ' data-id-horario="' . (int)$b['id'] . '" data-tipo="' . h($b['tipo']) . '" data-id-sala="' . h($b['id_sala_raw'] ?? '') . '"'
                  . ' data-id-usuario="' . h($b['id_usuario'] ?? '') . '" data-detalle="' . h($b['detalle'] ?? '') . '"'
                  . ' data-periodo="' . h($b['periodo'] ?? $periodoActual) . '" data-click="horarios.editar"';
          }
          return $a;
      };
      // Contenido de un bloque: botón «solo esta semana», horas, persona y detalle.
      $contenidoBloque = static function (array $b, bool $enGrupo) use ($lunesSemana, $esGeneral): void {
          $editable = !empty($b['editable']);
          $fechaBloque = (clone $lunesSemana)->modify('+' . ($b['dia'] - 1) . ' days')->format('Y-m-d');
          if ($editable): ?>
            <button type="button" class="hg-btn-excepcion" title="Cambiar solo esta semana"
                    data-id-horario="<?= (int)$b['id'] ?>" data-fecha="<?= h($fechaBloque) ?>"
                    data-id-excepcion="<?= $b['id_excepcion'] ?? '' ?>"
                    data-excepcion-tipo="<?= h($b['excepcion_tipo'] ?? '') ?>"
                    data-excepcion-sala="<?= ($b['excepcion_tipo'] ?? null) === 'CAMBIO_SALA' ? ($b['id_sala_raw'] ?? '') : '' ?>"
                    data-excepcion-inicio="<?= ($b['excepcion_tipo'] ?? null) === 'CAMBIO_HORA' ? h($b['hora_inicio']) : '' ?>"
                    data-excepcion-fin="<?= ($b['excepcion_tipo'] ?? null) === 'CAMBIO_HORA' ? h($b['hora_fin']) : '' ?>"
                    data-excepcion-motivo="<?= h($b['excepcion_motivo'] ?? '') ?>"
                    data-click="horarios.excepcion"></button>
          <?php endif; ?>
          <?php if ($esGeneral && ($b['sala_abrev'] ?? '') !== ''): ?><span class="hg-sala-tag" title="<?= h($b['sala_nombre'] ?? '') ?>"><?= h($b['sala_abrev']) ?></span><?php endif; ?>
          <strong><?= h($b['hora_inicio']) ?>–<?= h($b['hora_fin']) ?></strong>
          <?php if (!empty($b['nota_excepcion'])): ?><span class="hg-nota-excepcion"><?= h($b['nota_excepcion']) ?></span><?php endif; ?>
          <?php if ($b['origen'] === 'mantenimiento'): ?>
            <span><?= h($b['detalle']) ?></span>
          <?php elseif ($b['origen'] === 'prestamo'): ?>
            <?php if ($b['detalle']): ?>
              <span><?= h($b['detalle']) ?></span>
              <?php if ($b['usuario_nombre']): ?><span class="hg-detalle"><?= h($b['usuario_nombre']) ?></span><?php endif; ?>
            <?php else: ?>
              <span>Ocupado (préstamo)</span>
            <?php endif; ?>
          <?php else: ?>
            <span class="hg-persona"><?= $b['usuario_nombre'] ? h($b['usuario_nombre']) : ($b['tipo'] === 'CLASE' ? 'Sin profesor' : 'Sin asignar') ?></span>
            <?php if ($b['detalle'] && !$enGrupo): ?><span class="hg-detalle"><?= h($b['detalle']) ?></span><?php endif; ?>
          <?php endif;
      };
      foreach (HorarioCalendario::agruparSolapados($bloquesSala) as $g):
          $inicioMin = max(minutosDesdeHHMM($g['inicio']), $horaInicioGrid * 60);
          $finMin = min(minutosDesdeHHMM($g['fin']), $horaFinGrid * 60);
          $filaIni = intdiv($inicioMin - $horaInicioGrid * 60, 60) + 2;
          $filaFin = max($filaIni + 1, intdiv($finMin - $horaInicioGrid * 60 + 59, 60) + 2);
          if (count($g['items']) === 1):
              $b = $g['items'][0];
              $editable = !empty($b['editable']);
      ?>
      <div class="hg-bloque <?= $claseTipoBloque[$b['tipo']] ?? '' ?> <?= $editable ? 'hg-editable' : '' ?> <?= !empty($b['nota_excepcion']) ? 'hg-con-excepcion' : '' ?>"
           style="grid-column:<?= $b['dia'] + 1 ?>; grid-row:<?= $filaIni ?> / <?= $filaFin ?>;"
           <?= $datosBloque($b) ?>>
        <?php $contenidoBloque($b, false); ?>
      </div>
      <?php else:
          // Varias franjas a la vez (p. ej. varias becas en el mismo turno): un recuadro con una línea por persona.
          $tiposGrupo = array_unique(array_map(fn($x) => $x['tipo'], $g['items']));
          $claseGrupo = count($tiposGrupo) === 1 ? ($claseTipoBloque[$tiposGrupo[0]] ?? '') : 'bloque-mixto';
      ?>
      <div class="hg-bloque hg-grupo <?= $claseGrupo ?>" style="grid-column:<?= $g['dia'] + 1 ?>; grid-row:<?= $filaIni ?> / <?= $filaFin ?>;"
           data-dia="<?= (int)$g['dia'] ?>" data-inicio="<?= h($g['inicio']) ?>" data-fin="<?= h($g['fin']) ?>" data-usuario="">
        <span class="hg-grupo-titulo" title="<?= count($g['items']) ?> franjas a la vez"><?= count($g['items']) ?> · <?= h($g['inicio']) ?>–<?= h($g['fin']) ?></span>
        <ul class="hg-grupo-lista">
          <?php foreach ($g['items'] as $b): $editable = !empty($b['editable']); ?>
            <li class="hg-linea <?= $claseTipoBloque[$b['tipo']] ?? '' ?> <?= $editable ? 'hg-editable' : '' ?> <?= !empty($b['nota_excepcion']) ? 'hg-con-excepcion' : '' ?>" <?= $datosBloque($b) ?>>
              <?php $contenidoBloque($b, true); ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</section>

<?php if (!empty($prestamosLista)): ?>
<section class="panel-box">
  <h2 class="panel-title">Préstamos de la semana <span class="hint">salas y equipos prestados, en orden de fecha</span></h2>
  <div class="tabla-scroll">
    <table class="data">
      <thead><tr><th>Día</th><th>Horario</th><th>Sala</th><th>Préstamo</th><th>Solicitado por</th><th>Estado</th><th>Acciones</th></tr></thead>
      <tbody>
        <?php foreach ($bloques as $b): if ($b['origen'] !== 'prestamo') continue; ?>
          <tr>
            <td data-label="Día"><?= h(HorarioService::DIAS[$b['dia']] . ' ' . date('j', strtotime($b['fecha']))) ?></td>
            <td data-label="Horario"><?= h($b['hora_inicio'] . '–' . $b['hora_fin']) ?></td>
            <td data-label="Sala"><?= h($b['sala_nombre'] ?: '—') ?></td>
            <td data-label="Préstamo"><?= h($b['detalle'] ?: 'Préstamo') ?></td>
            <td data-label="Solicitado por"><?= h($b['usuario_nombre'] ?: '—') ?></td>
            <td data-label="Estado"><?= !empty($b['estado']) ? '<span class="chip ' . (in_array($b['estado'], [TicketWorkflow::APROBADO, TicketWorkflow::CERRADO], true) ? 'solucionado' : 'espera') . '">' . h($b['estado']) . '</span>' : '—' ?></td>
            <td data-label="Acciones"><button type="button" class="btn btn-ghost btn-sm" data-vf-abrir="<?= h($b['vf']) ?>">Ver detalle</button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
<?php $bloquesDetalle = array_values(array_filter($bloques, fn($b) => empty($b['editable']))); include __DIR__ . '/detalle.php'; ?>
