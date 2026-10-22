<?php
/** Vista parcial de «Acuerdos de servicio»: tiempos por prioridad, horario de atención y días no hábiles. Solo presentación. */
$horas = static fn(float $h): string => CalendarioHabil::textoDuracion((int)round($h * 3600));
$numero = static fn(float $h): string => rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.');
$matriz = [1 => [1 => 'CRITICA', 2 => 'ALTA', 3 => 'MEDIA'], 2 => [1 => 'ALTA', 2 => 'MEDIA', 3 => 'BAJA'], 3 => [1 => 'MEDIA', 2 => 'BAJA', 3 => 'BAJA']];
?>
    <section class="panel-box">
      <div class="conexion-cabecera">
        <h2 class="panel-title">Tiempos comprometidos <span class="hint">horas hábiles desde que se crea el ticket</span></h2>
        <button type="button" class="btn btn-sm" data-vf-abrir="vf-politicas">Editar tiempos</button>
      </div>
      <div class="tabla-scroll">
        <table class="data">
          <thead><tr><th>Prioridad</th><th>Incidente · respuesta</th><th>Incidente · solución</th><th>Requerimiento · respuesta</th><th>Requerimiento · solución</th></tr></thead>
          <tbody>
            <?php foreach (SlaService::PRIORIDADES as $p => $nombre): ?>
              <tr>
                <td data-label="Prioridad"><strong><?= h($nombre) ?></strong></td>
                <?php foreach (['INCIDENTE', 'REQUERIMIENTO'] as $tipo): $pol = $politicas[$tipo][$p] ?? null; ?>
                  <td data-label="<?= h(SlaService::TIPOS[$tipo]) ?> · respuesta"><?= $pol ? h($horas($pol['respuesta'])) : '—' ?></td>
                  <td data-label="<?= h(SlaService::TIPOS[$tipo]) ?> · solución"><?= $pol ? h($horas($pol['solucion'])) : '—' ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="hint">Incidente = algo dejó de funcionar (daños, QR). Requerimiento = una solicitud (instalar software, préstamo, cambio de sala). Meta de cumplimiento: <?= (int)$meta ?> % · aviso «por vencer» al <?= (int)$umbral ?> % del tiempo.</p>
    </section>

    <div class="panel-grid">
      <section class="panel-box">
        <div class="conexion-cabecera">
          <h2 class="panel-title">Horario de atención</h2>
          <button type="button" class="btn btn-sm" data-vf-abrir="vf-horario">Editar horario</button>
        </div>
        <table class="data">
          <thead><tr><th>Día</th><th>Atención</th></tr></thead>
          <tbody>
            <?php foreach (CalendarioHabil::DIAS as $n => $dia): $r = $semana[$n]; ?>
              <tr><td data-label="Día"><?= h($dia) ?></td><td data-label="Atención"><?= $r ? h($r[0] . ' a ' . $r[1]) : '<span class="text-muted">No se atiende</span>' ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p class="hint">Fuera de este horario y en los días no hábiles el reloj del SLA no corre.</p>
      </section>

      <section class="panel-box">
        <h2 class="panel-title">Cómo se asigna la prioridad <span class="hint">impacto × urgencia</span></h2>
        <table class="data">
          <thead><tr><th>Impacto \ Urgencia</th><th>Alta</th><th>Media</th><th>Baja</th></tr></thead>
          <tbody>
            <?php foreach ([1 => 'Alto', 2 => 'Medio', 3 => 'Bajo'] as $i => $imp): ?>
              <tr>
                <td data-label="Impacto"><strong><?= $imp ?></strong></td>
                <?php foreach ([1 => 'Alta', 2 => 'Media', 3 => 'Baja'] as $u => $urg): ?><td data-label="Urgencia <?= $urg ?>"><?= h(SlaService::PRIORIDADES[$matriz[$i][$u]]) ?></td><?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p class="hint">Automática al crear el ticket: incidente de seguridad = Crítica · daño en un salón (QR o profesor) = Alta · red o infraestructura = Alta · otro incidente = Media · requerimiento de profesor = Media · otro requerimiento = Baja. El admin la ajusta por ticket en «Tickets abiertos».</p>
      </section>
    </div>

    <section class="panel-box">
      <div class="conexion-cabecera">
        <h2 class="panel-title">Días no hábiles <?= (int)$anioFestivos ?> <span class="hint"><?= count($festivos) ?> registrados</span></h2>
        <div class="d-flex gap-2 flex-wrap">
          <a class="btn btn-ghost btn-sm" href="sla.php?vista=acuerdos&amp;anio=<?= $anioFestivos - 1 ?>">← <?= $anioFestivos - 1 ?></a>
          <a class="btn btn-ghost btn-sm" href="sla.php?vista=acuerdos&amp;anio=<?= $anioFestivos + 1 ?>"><?= $anioFestivos + 1 ?> →</a>
          <details class="menu-acciones" data-menu>
            <summary class="btn btn-sm">Agregar ▾</summary>
            <div class="menu-acciones-lista">
              <form method="POST" class="m-0" data-confirm="¿Cargar los festivos de Colombia de <?= (int)$anioFestivos ?>? Los que ya estén no se duplican.">
                <?= csrfField() ?>
                <input type="hidden" name="anio" value="<?= (int)$anioFestivos ?>">
                <button type="submit" name="cargar_festivos" class="menu-acciones-item">Festivos de Colombia <?= (int)$anioFestivos ?></button>
              </form>
              <button type="button" class="menu-acciones-item" data-vf-abrir="vf-festivo">Otro día (cierre, vacaciones…)</button>
            </div>
          </details>
        </div>
      </div>
      <?php if (!$festivos): ?>
        <p class="text-muted">No hay días no hábiles para <?= (int)$anioFestivos ?>. Usa «Agregar → Festivos de Colombia» para cargarlos en un clic.</p>
      <?php else: ?>
      <div class="tabla-scroll">
        <table class="data">
          <thead><tr><th>Fecha</th><th>Día</th><th>Motivo</th><th>Acciones</th></tr></thead>
          <tbody>
            <?php foreach ($festivos as $f): ?>
              <tr>
                <td data-label="Fecha"><?= h(date('d/m/Y', strtotime($f['fecha']))) ?></td>
                <td data-label="Día"><?= h(CalendarioHabil::DIAS[(int)date('N', strtotime($f['fecha']))]) ?></td>
                <td data-label="Motivo"><?= h($f['descripcion']) ?></td>
                <td data-label="Acciones">
                  <form method="POST" class="form-inline" data-confirm="¿Quitar este día? El SLA volverá a contarlo como hábil.">
                    <?= csrfField() ?>
                    <input type="hidden" name="fecha" value="<?= h($f['fecha']) ?>">
                    <button type="submit" name="quitar_festivo" class="btn btn-ghost btn-sm">Quitar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>

    <?= vfAbrir('vf-politicas', 'Editar tiempos comprometidos', 'Horas hábiles. La respuesta no puede tardar más que la solución. Ej: 0.5 = media hora.', $abrirForm === 'politicas', 'lg') ?>
      <?php if ($abrirForm === 'politicas'): ?><div class="error mb-3"><?= h($flash['mensaje']) ?></div><?php endif; ?>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="form" value="politicas">
        <div class="tabla-scroll">
          <table class="data">
            <thead><tr><th>Prioridad</th><th>Incidente · respuesta</th><th>Incidente · solución</th><th>Requerimiento · respuesta</th><th>Requerimiento · solución</th></tr></thead>
            <tbody>
              <?php foreach (SlaService::PRIORIDADES as $p => $nombre): ?>
                <tr>
                  <td data-label="Prioridad"><strong><?= h($nombre) ?></strong></td>
                  <?php foreach (['INCIDENTE', 'REQUERIMIENTO'] as $tipo): $pol = $politicas[$tipo][$p] ?? ['respuesta' => 8, 'solucion' => 40]; ?>
                    <?php foreach (['respuesta', 'solucion'] as $campo): ?>
                      <td data-label="<?= h(SlaService::TIPOS[$tipo]) ?> · <?= $campo === 'respuesta' ? 'respuesta' : 'solución' ?>">
                        <input type="number" name="pol[<?= $tipo ?>][<?= $p ?>][<?= $campo ?>]" value="<?= h($numero((float)$pol[$campo])) ?>" min="0.25" max="9999" step="0.25" required aria-label="<?= h(SlaService::TIPOS[$tipo] . ' ' . $nombre . ' ' . $campo) ?> (horas)">
                      </td>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <button type="submit" name="guardar_politicas" class="btn btn-sm mt-3">Guardar tiempos</button>
      </form>
    <?= vfCerrar() ?>

    <?= vfAbrir('vf-horario', 'Horario de atención', 'Marca los días en que se atiende y su horario. También ajusta los avisos.', $abrirForm === 'horario', 'lg') ?>
      <?php if ($abrirForm === 'horario'): ?><div class="error mb-3"><?= h($flash['mensaje']) ?></div><?php endif; ?>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="form" value="horario">
        <table class="data">
          <thead><tr><th>Día</th><th>Se atiende</th><th>Abre</th><th>Cierra</th></tr></thead>
          <tbody>
            <?php foreach (CalendarioHabil::DIAS as $n => $dia): $r = $semana[$n]; ?>
              <tr>
                <td data-label="Día"><strong><?= h($dia) ?></strong></td>
                <td data-label="Se atiende"><input type="checkbox" name="dia[<?= $n ?>][activo]" value="1" <?= $r ? 'checked' : '' ?> aria-label="Se atiende el <?= h($dia) ?>"></td>
                <td data-label="Abre"><input type="time" name="dia[<?= $n ?>][inicio]" value="<?= h($r[0] ?? '07:00') ?>" aria-label="Abre el <?= h($dia) ?>"></td>
                <td data-label="Cierra"><input type="time" name="dia[<?= $n ?>][fin]" value="<?= h($r[1] ?? '18:00') ?>" aria-label="Cierra el <?= h($dia) ?>"></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="form-grid mt-3">
          <div class="field">
            <label for="umbral">Avisar «por vencer» al (%) del tiempo</label>
            <input type="number" id="umbral" name="umbral" min="50" max="95" value="<?= (int)$umbral ?>">
          </div>
          <div class="field">
            <label for="meta">Meta de cumplimiento del SLA (%)</label>
            <input type="number" id="meta" name="meta" min="50" max="100" value="<?= (int)$meta ?>">
          </div>
        </div>
        <button type="submit" name="guardar_horario" class="btn btn-sm mt-3">Guardar horario</button>
      </form>
    <?= vfCerrar() ?>

    <?= vfAbrir('vf-festivo', 'Agregar día no hábil', 'Cierres de la universidad, vacaciones colectivas o un festivo que falte.', $abrirForm === 'festivo') ?>
      <?php if ($abrirForm === 'festivo'): ?><div class="error mb-3"><?= h($flash['mensaje']) ?></div><?php endif; ?>
      <form method="POST" class="form-grid">
        <?= csrfField() ?>
        <input type="hidden" name="form" value="festivo">
        <div class="field">
          <label for="fecha">Fecha</label>
          <input type="date" id="fecha" name="fecha" required>
        </div>
        <div class="field">
          <label for="descripcion">Motivo</label>
          <input type="text" id="descripcion" name="descripcion" maxlength="120" required placeholder="Ej: Semana de receso">
        </div>
        <button type="submit" name="agregar_festivo" class="btn btn-sm">Agregar</button>
      </form>
    <?= vfCerrar() ?>
