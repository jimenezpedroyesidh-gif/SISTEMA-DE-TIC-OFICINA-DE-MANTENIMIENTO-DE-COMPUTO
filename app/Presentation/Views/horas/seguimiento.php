<?php /** Vista de «Seguimiento de horas» (public/seguimiento_horas.php). Solo presentación: los datos los prepara SeguimientoHorasPage. */ ?>
<?php $paginaTitulo = 'Seguimiento de horas'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php $tituloHeader = 'Seguimiento de horas'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <div class="app-dashboard-welcome">
      <h1>Seguimiento de horas</h1>
      <p><?= $acceso['todas'] ? 'Horas de las becas trabajo' : 'Tus horas como beca trabajo' ?> · datos del sistema de asistencia facial</p>
    </div>

    <section class="panel-box barra-filtro">
    <?php if ($periodo['error'] !== null): ?><div class="error mb-3"><?= h($periodo['error']) ?></div><?php endif; ?>
    <form method="get" class="barra-filtro-form" aria-label="Elegir mes">
      <div class="field">
        <label for="horas-mes">Mes</label>
        <select name="mes" id="horas-mes">
          <?php foreach ($mesesEs as $num => $nombre): ?>
            <option value="<?= $num ?>" <?= $num === $mes ? 'selected' : '' ?>><?= $nombre ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="horas-anio">Año</label>
        <input type="number" name="anio" id="horas-anio" value="<?= (int)$anio ?>" min="2020" max="2100">
      </div>
      <?php if ($acceso['todas'] && $becaConsultada && !$esPropia): ?>
        <input type="hidden" name="ver_id" value="<?= (int)$becaConsultada['id'] ?>">
      <?php endif; ?>
      <button type="submit" class="btn btn-secondary btn-sm">Consultar mes</button>
    </form>
    <form method="get" class="barra-filtro-form mt-2" aria-label="Elegir rango de fechas">
      <div class="field">
        <label for="horas-desde">Desde</label>
        <input type="date" name="desde" id="horas-desde" required value="<?= h($periodo['rango'] ? $periodo['inicio'] : ($_GET['desde'] ?? '')) ?>">
      </div>
      <div class="field">
        <label for="horas-hasta">Hasta</label>
        <input type="date" name="hasta" id="horas-hasta" required value="<?= h($periodo['rango'] ? $periodo['fin'] : ($_GET['hasta'] ?? '')) ?>">
      </div>
      <?php if ($acceso['todas'] && $becaConsultada && !$esPropia): ?>
        <input type="hidden" name="ver_id" value="<?= (int)$becaConsultada['id'] ?>">
      <?php endif; ?>
      <button type="submit" class="btn btn-secondary btn-sm">Consultar rango</button>
    </form>
    <p class="hint mt-2">Mostrando: <strong><?= h($periodo['etiqueta']) ?></strong>. Para el informe de un periodo exacto usa «Desde / Hasta» y descarga el PDF.</p>
    </section>

    <?php if ($acceso['todas']): ?>
    <section class="panel active" id="listado-becas">
      <h2>Becas trabajo · horas <?= $periodo['rango'] ? '' : 'de ' ?><?= h($periodo['etiqueta']) ?></h2>
      <table class="data">
        <thead><tr><th>Nombre</th><th>Código</th><th>Horas del periodo</th><th>Acciones</th></tr></thead>
        <tbody>
        <?php foreach ($becas as $b): ?>
          <tr>
            <td data-label="Nombre"><?= h($b['nombre_completo']) ?></td>
            <td data-label="Código"><?= h($b['codigo'] ?: '—') ?></td>
            <td data-label="Horas"><?= $b['horas_mes'] !== null ? h($b['horas_mes']) . ' h' : '<span class="text-muted">Sin datos</span>' ?></td>
            <td data-label="Acciones">
              <a class="btn btn-ghost btn-sm" href="?ver_id=<?= (int)$b['id'] ?>&amp;<?= h($periodo['query']) ?>">Ver</a>
              <?php if (!empty($b['codigo'])): ?>
                <a class="btn btn-ghost btn-sm" href="seguimiento_horas_pdf.php?codigo=<?= urlencode($b['codigo']) ?>&amp;<?= h($periodo['query']) ?>" target="_blank">PDF</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($becas)): ?>
          <tr><td colspan="4" class="text-muted">No hay becas trabajo registradas.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
      <?php if (!empty($becas)): ?>
        <a class="btn btn-ghost btn-sm mt-2" href="seguimiento_horas_pdf.php?todas=1&amp;<?= h($periodo['query']) ?>" target="_blank">Descargar PDF de todas (un solo archivo)</a>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($becaConsultada): ?>
    <section class="panel active mt-4">
      <h2><?= $esPropia ? 'Mis horas' : h($becaConsultada['nombre_completo']) ?> — <?= h($periodo['etiqueta']) ?></h2>

      <?php if (empty($becaConsultada['codigo'])): ?>
        <p class="error"><?= $esPropia ? 'No tienes' : 'Esta beca no tiene' ?> código institucional registrado — sin código no se puede cruzar con la asistencia facial. Se completa en <a href="perfil.php">Mi perfil</a><?= rolActual() === Rol::ADMIN ? ' o en Usuarios' : '' ?>.</p>
      <?php else: ?>
        <table class="data">
          <thead>
          <tr><th>Fecha</th><th>Hora llegada</th><th>Hora salida</th><th>Horas</th><th>Actividad realizada</th></tr>
          </thead>
          <tbody>
          <?php foreach ($seguimiento as $d): ?>
            <tr>
              <td data-label="Fecha"><?= h($d['fecha']) ?></td>
              <td data-label="Hora llegada"><?= h($d['hora_llegada'] ?: '—') ?></td>
              <td data-label="Hora salida"><?= h($d['hora_salida'] ?: '—') ?></td>
              <td data-label="Horas"><?= $d['horas'] !== null ? h($d['horas']) : '—' ?></td>
              <td data-label="Actividad"><?= h($d['actividad'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($seguimiento)): ?>
            <tr><td colspan="5" class="text-muted">Sin registros de asistencia en este periodo.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
        <p class="mt-2"><strong>Total del periodo: <?= h($totalHoras) ?> horas</strong></p>
        <a class="btn btn-primary" href="seguimiento_horas_pdf.php?<?= $esPropia ? '' : 'codigo=' . urlencode($becaConsultada['codigo']) . '&amp;' ?><?= h($periodo['query']) ?>" target="_blank">Descargar PDF</a>
      <?php endif; ?>
    </section>
    <?php elseif ($acceso['todas']): ?>
      <p class="text-muted mt-3">Elige una beca con «Ver» para revisar el detalle día a día.</p>
    <?php endif; ?>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
