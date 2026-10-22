<?php
/** Vista parcial de «Cumplimiento de SLA»: tickets abiertos con su semáforo (pestaña por defecto). Solo presentación. */
$fecha = static fn(?string $f): string => $f ? date('d/m H:i', strtotime($f)) : '—';
?>
    <div class="stat-row stat-row-4">
      <?php foreach (['vencido' => 'Vencidos', 'por_vencer' => 'Por vencer', 'a_tiempo' => 'A tiempo', 'pausa' => 'En pausa'] as $s => $nombre): ?>
        <a class="stat-card" href="sla.php?semaforo=<?= $s ?>"<?= $filtroSem === $s ? ' aria-current="true"' : '' ?>>
          <span class="stat-label"><?= h($nombre) ?></span>
          <span class="stat-value"><?= (int)$conteo[$s] ?></span>
          <span class="<?= SlaService::SEMAFORO[$s][1] ?>"><?= h(SlaService::SEMAFORO[$s][0]) ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <section class="panel-box barra-filtro">
      <form method="GET" class="barra-filtro-form" aria-label="Filtrar tickets abiertos">
        <div class="field">
          <label for="q">Buscar</label>
          <input type="search" id="q" name="q" value="<?= h($q) ?>" placeholder="Número, sala, responsable…">
        </div>
        <div class="field">
          <label for="semaforo">Estado del tiempo</label>
          <select id="semaforo" name="semaforo">
            <option value="">Todos</option>
            <?php foreach (['vencido', 'por_vencer', 'a_tiempo', 'pausa'] as $s): ?>
              <option value="<?= $s ?>" <?= $filtroSem === $s ? 'selected' : '' ?>><?= h(SlaService::SEMAFORO[$s][0]) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="prioridad">Prioridad</label>
          <select id="prioridad" name="prioridad">
            <option value="">Todas</option>
            <?php foreach (SlaService::PRIORIDADES as $p => $nombre): ?>
              <option value="<?= $p ?>" <?= $filtroPrio === $p ? 'selected' : '' ?>><?= h($nombre) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
        <?php if ($filtroSem !== '' || $filtroPrio !== '' || $q !== ''): ?><a class="btn btn-ghost btn-sm" href="sla.php">Quitar filtros</a><?php endif; ?>
      </form>
    </section>

    <section class="panel-box">
      <h2 class="panel-title">Tickets abiertos <span class="hint"><?= (int)$pag['total'] ?> en la lista · los más atrasados primero</span></h2>
      <?php if (!$lista): ?>
        <p class="text-muted"><?= ($filtroSem !== '' || $filtroPrio !== '' || $q !== '') ? 'Ningún ticket coincide con el filtro.' : 'No hay tickets abiertos. ¡Todo al día!' ?></p>
      <?php else: ?>
      <div class="tabla-scroll">
        <table class="data">
          <thead>
            <tr><th>Ticket</th><th>Sala / dependencia</th><th>Tipo y prioridad</th><th>Responsable</th><th>Creado</th><th>Primera respuesta</th><th>Vence la solución</th><th>Tiempo usado</th><th>Estado</th><th>Acciones</th></tr>
          </thead>
          <tbody>
            <?php foreach ($lista as $t):
                $clave = $t['tabla'] . '-' . (int)$t['id'];
                $pausado = $t['semaforo'] === 'pausa';
            ?>
              <tr>
                <td data-label="Ticket"><strong>#<?= (int)$t['id'] ?></strong> <span class="hint"><?= $t['tabla'] === TablaTicket::NORMAL ? ($t['origen'] === 'QR_ANONIMO' ? 'QR anónimo' : 'Solicitante') : 'Profesor' ?></span><br><span class="hint"><?= h(mb_substr((string)$t['resumen'], 0, 90)) ?></span></td>
                <td data-label="Sala / dependencia"><?= h($t['nombre_sala'] ?: ($t['dependencia'] ?: '—')) ?></td>
                <td data-label="Tipo y prioridad"><?= h(SlaService::TIPOS[$t['tipo_sla']]) ?><br><strong><?= h(SlaService::PRIORIDADES[$t['prioridad_sla']]) ?></strong><?= !empty($t['prioridad_manual']) ? ' <span class="hint">(ajustada)</span>' : '' ?></td>
                <td data-label="Responsable"><?= $t['responsable'] ? h($t['responsable']) : '<span class="texto-peligro">Sin responsable</span>' ?></td>
                <td data-label="Creado"><?= h($fecha($t['fecha_creacion'])) ?></td>
                <td data-label="Primera respuesta">
                  <?php if ($t['primera_respuesta']): ?>
                    <?= h($fecha($t['primera_respuesta'])) ?><br><span class="hint"><?= h(CalendarioHabil::textoDuracion($t['seg_respuesta'])) ?><?= $t['cumple_respuesta'] === false ? ' · fuera de tiempo' : '' ?></span>
                  <?php else: ?>
                    <span class="<?= $t['cumple_respuesta'] === false ? 'texto-peligro' : 'text-muted' ?>">Pendiente</span><br><span class="hint">límite <?= h($fecha($t['venc_respuesta'])) ?></span>
                  <?php endif; ?>
                </td>
                <td data-label="Vence la solución"><?= h($fecha($t['venc_solucion'])) ?><br><span class="hint">meta <?= h(CalendarioHabil::textoDuracion((int)round($t['politica']['solucion'] * 3600))) ?><?= $t['seg_pausa'] > 0 ? ' + ' . h(CalendarioHabil::textoDuracion($t['seg_pausa'])) . ' en pausa' : '' ?></span></td>
                <td data-label="Tiempo usado"><progress class="sala-progreso" max="100" value="<?= (int)min(100, round($t['consumo'] * 100)) ?>"></progress> <span class="hint"><?= (int)round($t['consumo'] * 100) ?> %</span></td>
                <td data-label="Estado"><span class="<?= SlaService::SEMAFORO[$t['semaforo']][1] ?>"><?= h(SlaService::SEMAFORO[$t['semaforo']][0]) ?></span><br><span class="hint"><?= h((string)$t['estado']) ?></span></td>
                <td data-label="Acciones">
                  <details class="menu-acciones" data-menu>
                    <summary class="btn btn-ghost btn-sm">Opciones ▾</summary>
                    <div class="menu-acciones-lista">
                      <button type="button" class="menu-acciones-item" data-vf-abrir="vf-prio-<?= h($clave) ?>">Ajustar prioridad</button>
                      <button type="button" class="menu-acciones-item" data-vf-abrir="vf-pausa-<?= h($clave) ?>"><?= $pausado ? 'Reanudar el reloj' : 'Pausar (en espera del usuario)' ?></button>
                      <a class="menu-acciones-item" href="<?= $t['tabla'] === TablaTicket::NORMAL ? 'ticket_pdf.php' : 'ticket_profesor_pdf.php' ?>?id=<?= (int)$t['id'] ?>" target="_blank" rel="noopener">Ver ticket (PDF)</a>
                    </div>
                  </details>

                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= renderControlesPaginacion($pag['pagina'], $pag['total_paginas']) ?>
      <?php /* Ventanas flotantes fuera de la tabla (la tabla tiene desplazamiento propio). */ ?>
      <?php foreach ($lista as $t): $clave = $t['tabla'] . '-' . (int)$t['id']; $pausado = $t['semaforo'] === 'pausa'; ?>
        <?= vfAbrir('vf-prio-' . $clave, 'Prioridad del ticket #' . (int)$t['id'], 'La prioridad sale de cruzar el impacto con la urgencia (matriz ITIL) y define los tiempos del SLA.', $abrirForm === 'prio-' . $clave) ?>
          <?php if ($abrirForm === 'prio-' . $clave): ?><div class="error mb-3"><?= h($flash['mensaje']) ?></div><?php endif; ?>
          <form method="POST" class="form-grid">
            <?= csrfField() ?>
            <input type="hidden" name="form" value="prio-<?= h($clave) ?>">
            <input type="hidden" name="tabla" value="<?= h($t['tabla']) ?>">
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <div class="field">
              <label for="imp-<?= h($clave) ?>">Impacto</label>
              <select id="imp-<?= h($clave) ?>" name="impacto">
                <?php foreach (SlaService::IMPACTOS as $v => $txt): ?><option value="<?= $v ?>" <?= (int)($t['impacto'] ?? 2) === $v ? 'selected' : '' ?>><?= h($txt) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="urg-<?= h($clave) ?>">Urgencia</label>
              <select id="urg-<?= h($clave) ?>" name="urgencia">
                <?php foreach (SlaService::URGENCIAS as $v => $txt): ?><option value="<?= $v ?>" <?= (int)($t['urgencia'] ?? 2) === $v ? 'selected' : '' ?>><?= h($txt) ?></option><?php endforeach; ?>
              </select>
            </div>
            <p class="hint field-full">Prioridad actual: <strong><?= h(SlaService::PRIORIDADES[$t['prioridad_sla']]) ?></strong>. Alto + alta = Crítica · alto + media o medio + alta = Alta · medio + media = Media · lo demás = Baja.</p>
            <button type="submit" name="ajustar_prioridad" class="btn btn-sm">Guardar prioridad</button>
          </form>
        <?= vfCerrar() ?>

        <?= vfAbrir('vf-pausa-' . $clave, ($pausado ? 'Reanudar el reloj · #' : 'Pausar el reloj · #') . (int)$t['id'],
                    $pausado ? 'El tiempo vuelve a contar desde ahora.' : 'Úsalo solo cuando el ticket depende del usuario (no está en el salón, falta un dato, no contesta).', $abrirForm === 'pausa-' . $clave) ?>
          <?php if ($abrirForm === 'pausa-' . $clave): ?><div class="error mb-3"><?= h($flash['mensaje']) ?></div><?php endif; ?>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="form" value="pausa-<?= h($clave) ?>">
            <input type="hidden" name="tabla" value="<?= h($t['tabla']) ?>">
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <?php if (!$pausado): ?>
              <div class="field">
                <label for="mot-<?= h($clave) ?>">Motivo</label>
                <input type="text" id="mot-<?= h($clave) ?>" name="motivo" maxlength="255" required placeholder="Ej: el profesor no permite el ingreso hasta las 2 p. m.">
              </div>
            <?php endif; ?>
            <button type="submit" name="alternar_pausa" class="btn btn-sm"><?= $pausado ? 'Reanudar ahora' : 'Pausar el reloj' ?></button>
          </form>
        <?= vfCerrar() ?>
      <?php endforeach; ?>
      <?php endif; ?>
    </section>
