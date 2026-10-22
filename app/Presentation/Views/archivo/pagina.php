<?php
/** Vista del cierre de semestre (public/archivo_semestral.php). Solo presentación: datos de ArchivoSemestralPage. */
$paginaTitulo = 'Cierre de semestre'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php $tituloHeader = 'Cierre de semestre'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1>Cierre de semestre</h1>
        <p>Al final de cada semestre puedes comprimir los tickets terminados: queda solo un resumen con lo importante y los paneles de los demás roles se aligeran. Los tickets sin contestar o sin solucionar nunca se archivan.</p>
      </div>
    </div>

<?php if ($error): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
<?php if ($exito): ?><div class="exito mb-3"><?= h($exito) ?></div><?php endif; ?>


<section class="panel-box">
  <h2 class="panel-title">Tickets por semestre</h2>
  <?php if (!$resumen): ?>
    <p class="text-muted">Todavía no hay tickets.</p>
  <?php else: ?>
  <div class="tabla-scroll">
    <table class="data">
      <thead><tr><th>Semestre</th><th>Terminados</th><th>Abiertos (se quedan)</th><th>Ya archivados</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($resumen as $p => $c): $p = (string)$p; ?>
        <tr<?= $p === $periodoSel ? ' class="fila-activa"' : '' ?>>
          <td data-label="Semestre"><strong><?= h($p) ?></strong><?= $p === HorarioPeriodo::actual() ? ' <span class="hint">(en curso)</span>' : '' ?></td>
          <td data-label="Terminados"><?= (int)$c['terminados'] ?></td>
          <td data-label="Abiertos"><?= (int)$c['abiertos'] ? '<span class="chip espera">' . (int)$c['abiertos'] . '</span>' : '0' ?></td>
          <td data-label="Ya archivados"><?= (int)$c['archivados'] ?></td>
          <td data-label="Acción"><a class="btn btn-ghost btn-sm" href="?periodo=<?= h(urlencode($p)) ?>#cerrar">Revisar</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php if ($previa): $tp = $previa['tickets']; $tpp = $previa['tickets_profesor']; ?>
<section class="panel-box mw-640" id="cerrar">
  <h2 class="panel-title">Cerrar el semestre <?= h($previa['periodo']) ?> <span class="hint"><?= h(date('d/m/Y', strtotime($previa['desde']))) ?> – <?= h(date('d/m/Y', strtotime($previa['hasta'] . ' -1 day'))) ?></span></h2>
  <ul>
    <li><strong><?= (int)$previa['total'] ?></strong> ticket(s) terminados se comprimirán (<?= (int)$tp['candidatos'] ?> de solicitantes/QR y <?= (int)$tpp['candidatos'] ?> de profesores).</li>
    <li><strong><?= (int)($tp['abiertos'] + $tpp['abiertos']) ?></strong> sin contestar o sin solucionar se quedan en los paneles.</li>
    <?php if ((int)$tp['retenidos'] + (int)$tpp['retenidos'] > 0): ?>
      <li><strong><?= (int)($tp['retenidos'] + $tpp['retenidos']) ?></strong> terminados se quedan porque aún tienen un mantenimiento, una solicitud de baja o un incidente de seguridad abierto.</li>
    <?php endif; ?>
    <li>Se conserva: sala, quién reportó y su correo, técnico y becario, descripción y solución (resumidas), fechas, tiempo de resolución, prioridad, calificación y equipos afectados.</li>
    <li>El historial de los componentes y los movimientos de inventario no se borran; la ficha de cada equipo sigue mostrando sus tickets archivados.</li>
  </ul>
  <?php if (!$previa['sePuede']): ?>
    <p class="nota-info">Este semestre todavía no termina. Se podrá cerrar desde <?= (int)ArchivoSemestralService::DIAS_ANTES_FIN ?> días antes de su fin.</p>
  <?php elseif ((int)$previa['total'] === 0): ?>
    <p class="text-muted">No hay tickets terminados por archivar en este semestre.</p>
  <?php else: ?>
    <form method="POST" data-confirm="Esto no se puede deshacer desde el sistema. ¿Archivar el semestre <?= h($previa['periodo']) ?>?">
      <?= csrfField() ?>
      <input type="hidden" name="periodo" value="<?= h($previa['periodo']) ?>">
      <div class="field field-check">
        <label><input type="checkbox" name="borrar_adjuntos" value="1" checked> <span>Borrar también las fotos adjuntas de esos tickets (libera espacio; las evidencias de movimientos de inventario se conservan).</span></label>
      </div>
      <div class="field">
        <label for="confirmacion">Para confirmar escribe <strong><?= h(ArchivoSemestralService::textoConfirmacion($previa['periodo'])) ?></strong></label>
        <input type="text" id="confirmacion" name="confirmacion" required autocomplete="off" placeholder="<?= h(ArchivoSemestralService::textoConfirmacion($previa['periodo'])) ?>">
      </div>
      <p class="hint">Recomendado: haz un respaldo en Seguridad → Respaldos antes de archivar.</p>
      <button type="submit" name="archivar_periodo" value="1" class="btn btn-danger">Archivar <?= (int)$previa['total'] ?> ticket(s)</button>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel-box">
  <h2 class="panel-title">Consultar el archivo</h2>
  <form method="GET" class="filtros-inline">
    <div class="field">
      <label for="b_periodo">Semestre</label>
      <select id="b_periodo" name="b_periodo">
        <option value="">Todos</option>
        <?php foreach (array_keys($resumen) as $p): $p = (string)$p; if (empty($resumen[$p]['archivados'])) continue; ?>
          <option value="<?= h($p) ?>"<?= $p === $buscarPeriodo ? ' selected' : '' ?>><?= h($p) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="q">Buscar</label>
      <input type="search" id="q" name="q" maxlength="100" value="<?= h($buscarTexto) ?>" placeholder="Sala, persona, equipo o n.º de ticket">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Buscar</button>
    <?php if ($buscarPeriodo !== '' || $buscarTexto !== ''): ?>
      <a class="btn btn-ghost btn-sm" href="?b_periodo=<?= h(urlencode($buscarPeriodo)) ?>&amp;q=<?= h(urlencode($buscarTexto)) ?>&amp;csv=1">Descargar CSV</a>
    <?php else: ?>
      <a class="btn btn-ghost btn-sm" href="?csv=1">Descargar todo (CSV)</a>
    <?php endif; ?>
  </form>
  <?php if ($buscarPeriodo !== '' || $buscarTexto !== ''): ?>
    <?php if (!$resultados): ?>
      <p class="text-muted">Sin resultados en el archivo.</p>
    <?php else: ?>
    <div class="tabla-scroll">
      <table class="data">
        <thead><tr><th>Ticket</th><th>Sala</th><th>Reportó</th><th>Atendió</th><th>Descripción</th><th>Fechas</th></tr></thead>
        <tbody>
        <?php foreach ($resultados as $a): ?>
          <tr>
            <td data-label="Ticket"><?= $a['tabla_origen'] === TablaTicket::NORMAL ? '#' : 'P-' ?><?= (int)$a['id_original'] ?><br><span class="hint"><?= h($a['tipo']) ?> · <?= h($a['estado_final']) ?></span></td>
            <td data-label="Sala"><?= h($a['sala_nombre'] ?? '—') ?><?= $a['equipos_afectados'] ? '<br><span class="hint">' . h($a['equipos_afectados']) . '</span>' : '' ?></td>
            <td data-label="Reportó"><?= h($a['solicitante'] ?? '—') ?><?= $a['correo_contacto'] ? '<br><span class="hint">' . h($a['correo_contacto']) . '</span>' : '' ?></td>
            <td data-label="Atendió"><?= h(implode(' · ', array_filter([$a['tecnico'], $a['beca']])) ?: '—') ?></td>
            <td data-label="Descripción"><?= h(mb_strimwidth((string)$a['descripcion'], 0, 160, '…')) ?><?= $a['solucion'] ? '<br><span class="hint">Solución: ' . h(mb_strimwidth((string)$a['solucion'], 0, 120, '…')) . '</span>' : '' ?></td>
            <td data-label="Fechas"><?= h(date('d/m/Y', strtotime((string)$a['fecha_creacion']))) ?><?= $a['fecha_cierre'] ? ' → ' . h(date('d/m/Y', strtotime((string)$a['fecha_cierre']))) : '' ?><?= $a['calificacion'] ? '<br><span class="hint">Calificación ' . (int)$a['calificacion'] . '/5</span>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php if ($lotes): ?>
<section class="panel-box">
  <h2 class="panel-title">Cierres realizados</h2>
  <div class="tabla-scroll">
    <table class="data">
      <thead><tr><th>Fecha</th><th>Semestre</th><th>Tickets</th><th>Fotos borradas</th><th>Admin</th></tr></thead>
      <tbody>
      <?php foreach ($lotes as $l): ?>
        <tr>
          <td data-label="Fecha"><?= h(date('d/m/Y H:i', strtotime((string)$l['creado_en']))) ?></td>
          <td data-label="Semestre"><?= h($l['periodo']) ?></td>
          <td data-label="Tickets"><?= (int)$l['total_tickets'] + (int)$l['total_profesor'] ?> <span class="hint">(<?= (int)$l['total_tickets'] ?> solicitantes/QR · <?= (int)$l['total_profesor'] ?> profesores)</span></td>
          <td data-label="Fotos borradas"><?= (int)$l['adjuntos_borrados'] ?><?= (int)$l['bytes_liberados'] ? ' <span class="hint">(' . round((int)$l['bytes_liberados'] / 1048576, 1) . ' MB)</span>' : '' ?></td>
          <td data-label="Admin"><?= h($l['admin_nombre'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
