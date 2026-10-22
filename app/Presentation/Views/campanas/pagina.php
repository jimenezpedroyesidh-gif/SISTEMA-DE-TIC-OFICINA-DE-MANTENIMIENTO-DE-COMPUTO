<?php
/**
 * Vista de «Campañas de mantenimiento» (public/mantenimiento_campanas.php). Solo presentación: los datos los
 * prepara CampanasPage / MantenimientoCampanaService. Lista de campañas, panel de avance de la elegida y
 * formulario de creación (ventana flotante, solo técnico).
 */
$paginaTitulo = 'Campañas de mantenimiento'; include LAYOUT_DIR . '/app_page_head.php';
$tituloHeader = 'Campañas de mantenimiento'; include LAYOUT_DIR . '/app_page_body_start.php';
$fh = static fn(?string $f): string => $f ? date('d/m/Y H:i', strtotime($f)) : '—';
$volvio = $error !== '' && isset($_POST['crear_campana']);
$abrirForm = $volvio || $prefillAccion;
$pf = $prefillAccion ?? [];
$v = static fn(string $campo, string $def = ''): string => $volvio ? (string)($_POST[$campo] ?? $def) : (string)($pf[$campo] ?? $def);
$salasMarcadas = $volvio ? array_map('intval', (array)($_POST['salas'] ?? [])) : [];
?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1>Campañas de mantenimiento</h1>
        <p>Una sola actividad (actualizar un programa, instalar una aplicación, revisión preventiva…) en varias salas. Cada sala se ejecuta y se cierra en «Mantenimientos».</p>
      </div>
      <?php if ($puedeProgramar): ?>
      <div class="page-acciones">
        <button type="button" class="btn btn-sm" data-vf-abrir="vf-campana">+ Nueva campaña</button>
      </div>
      <?php endif; ?>
    </div>

<?php if ($error && !$volvio): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
<?php if ($exito): ?><div class="exito mb-3"><?= h($exito) ?></div><?php endif; ?>

<?php if ($campana): ?>
  <?php
    $total = count($intervenciones);
    $cuenta = static fn(array $estados): int => count(array_filter($intervenciones, fn($i) => in_array($i['estado'], $estados, true)));
    $finalizadas = $cuenta(['REALIZADO']);
    $canceladas = $cuenta(['CANCELADO']);
    $bloqueadas = $cuenta(['PENDIENTE']);
    $pendientes = $cuenta(['PROGRAMADO', 'REPROGRAMADO', 'EN_PROCESO']);
    $reprogramadas = count(array_filter($intervenciones, fn($i) => (int)$i['veces_reprogramada'] > 0));
    $base = $total - $canceladas;
    $avance = $base > 0 ? (int)round($finalizadas * 100 / $base) : 0;
  ?>
  <section class="panel-box">
    <h2 class="panel-title">
      <?= h($campana['nombre']) ?>
      <span class="chip <?= $campana['estado'] === 'ACTIVA' ? 'proceso' : ($campana['estado'] === 'FINALIZADA' ? 'solucionado' : 'danio') ?>"><?= h(etiquetaValor($campana['estado'])) ?></span>
    </h2>
    <p class="hint">
      <?= h(etiquetaValor($campana['tipo'])) ?> · inicia <?= h($fh($campana['fecha_inicio'])) ?> ·
      <?= (int)$campana['secuencial'] === 1 ? 'una sala a la vez (hay que finalizar la anterior)' : 'salas independientes' ?>
      · coordina <?= $campana['responsable_nombre'] ? h($campana['responsable_nombre']) : 'sin asignar' ?>
    </p>
    <p><?= nl2br(h($campana['descripcion'])) ?></p>

    <div class="stat-row">
      <div class="stat-card"><span class="stat-label">Avance</span><span class="stat-value"><?= $avance ?> %</span><progress class="sala-progreso" max="100" value="<?= $avance ?>" aria-label="Avance de la campaña"></progress></div>
      <div class="stat-card"><span class="stat-label">Pendientes</span><span class="stat-value"><?= $pendientes ?></span><span class="hint">programadas o en proceso</span></div>
      <div class="stat-card"><span class="stat-label">Finalizadas</span><span class="stat-value"><?= $finalizadas ?></span><span class="hint">de <?= $total ?><?= $canceladas ? " · $canceladas canceladas" : '' ?></span></div>
      <div class="stat-card"><span class="stat-label">Bloqueadas</span><span class="stat-value"><?= $bloqueadas ?></span><span class="hint">esperan la sala anterior</span></div>
      <div class="stat-card"><span class="stat-label">Reprogramadas</span><span class="stat-value"><?= $reprogramadas ?></span><span class="hint">cambiaron de fecha</span></div>
    </div>

    <div class="tabla-scroll">
      <table class="data">
        <thead><tr><th>Orden</th><th>Sala</th><th>Fecha</th><th>Equipos</th><th>Estado</th></tr></thead>
        <tbody>
          <?php foreach ($intervenciones as $i): $c = $conflictos[(int)$i['id_programado']] ?? null; ?>
            <tr>
              <td data-label="Orden"><?= (int)$i['orden_campana'] ?></td>
              <td data-label="Sala"><?= h($i['abreviatura'] ? $i['abreviatura'] . ' — ' . $i['nombre_sala'] : $i['nombre_sala']) ?></td>
              <td data-label="Fecha">
                <?= h($fh($i['fecha_programada'])) ?>
                <?php if ((int)$i['veces_reprogramada'] > 0): ?><br><span class="hint">Reprogramada <?= (int)$i['veces_reprogramada'] ?> <?= (int)$i['veces_reprogramada'] === 1 ? 'vez' : 'veces' ?></span><?php endif; ?>
              </td>
              <td data-label="Equipos">
                <?= (int)$i['equipos_realizados'] ?>/<?= (int)$i['total_equipos'] ?>
                <progress class="sala-progreso" max="<?= max(1, (int)$i['total_equipos']) ?>" value="<?= (int)$i['equipos_realizados'] ?>" aria-label="Equipos realizados"></progress>
              </td>
              <td data-label="Estado">
                <span class="chip <?= $chipEstado[$i['estado']] ?? '' ?>"><?= $i['estado'] === 'PENDIENTE' ? 'Bloqueada' : h(etiquetaValor($i['estado'])) ?></span>
                <?php if ($c): ?><br><span class="chip danio">Cruce</span> <span class="hint"><?= h(MantenimientoAgenda::texto($c)) ?></span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="mt-2">
      <a class="btn btn-secondary btn-sm" href="mantenimientos_programados.php?campana=<?= (int)$campana['id_campana'] ?>">Ejecutar, reprogramar o cerrar salas</a>
    </p>
    <?php if ($puedeProgramar && $campana['estado'] === 'ACTIVA'): ?>
      <details class="mt-2">
        <summary>Cancelar campaña</summary>
        <form method="POST" class="mt-2">
          <?= csrfField() ?>
          <input type="hidden" name="id_campana" value="<?= (int)$campana['id_campana'] ?>">
          <div class="field">
            <label for="motivo-cancelar">Motivo</label>
            <input type="text" id="motivo-cancelar" name="motivo" maxlength="200" required>
          </div>
          <p class="hint">Las salas que no han empezado quedan canceladas. Las que están en proceso siguen abiertas para cerrarlas con su resultado.</p>
          <button type="submit" name="cancelar_campana" class="btn btn-sm">Cancelar campaña</button>
        </form>
      </details>
    <?php endif; ?>
  </section>
<?php endif; ?>

<section class="panel-box">
  <h2 class="panel-title">Campañas</h2>
  <?php if (!$campanas): ?>
    <p class="text-muted">Todavía no hay campañas.<?= $puedeProgramar ? ' Crea una con «+ Nueva campaña».' : '' ?></p>
  <?php else: ?>
  <div class="tabla-scroll">
    <table class="data">
      <thead><tr><th>Campaña</th><th>Inicio</th><th>Avance</th><th>Bloqueadas</th><th>Reprogramadas</th><th>Estado</th></tr></thead>
      <tbody>
        <?php foreach ($campanas as $c): $base = (int)$c['total'] - (int)$c['canceladas']; ?>
          <tr>
            <td data-label="Campaña"><a href="mantenimiento_campanas.php?id=<?= (int)$c['id_campana'] ?>"><?= h($c['nombre']) ?></a><br><span class="hint"><?= h(etiquetaValor($c['tipo'])) ?><?= (int)$c['secuencial'] === 1 ? ' · una sala a la vez' : '' ?></span></td>
            <td data-label="Inicio"><?= h($fh($c['fecha_inicio'])) ?></td>
            <td data-label="Avance"><?= (int)$c['realizadas'] ?>/<?= $base ?> salas <progress class="sala-progreso" max="<?= max(1, $base) ?>" value="<?= (int)$c['realizadas'] ?>" aria-label="Salas finalizadas"></progress></td>
            <td data-label="Bloqueadas"><?= (int)$c['bloqueadas'] ?></td>
            <td data-label="Reprogramadas"><?= (int)$c['reprogramadas'] ?></td>
            <td data-label="Estado"><span class="chip <?= $c['estado'] === 'ACTIVA' ? 'proceso' : ($c['estado'] === 'FINALIZADA' ? 'solucionado' : 'danio') ?>"><?= h(etiquetaValor($c['estado'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php if ($puedeProgramar): ?>
<?= vfAbrir('vf-campana', 'Nueva campaña de mantenimiento', 'Se crea una intervención por sala en su primer espacio libre (clases, turnos, préstamos y otros mantenimientos).', $abrirForm, 'lg') ?>
  <?php if ($volvio): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
  <form method="POST" id="form-campana">
    <?= csrfField() ?>
    <?php $idAccionForm = (int)($volvio ? ($_POST['id_accion_origen'] ?? 0) : ($pf['id_accion'] ?? 0)); ?>
    <?php if ($idAccionForm): ?><input type="hidden" name="id_accion_origen" value="<?= $idAccionForm ?>"><p class="hint">Campaña para la acción correctiva #<?= $idAccionForm ?>.</p><?php endif; ?>
    <div class="field">
      <label for="c-nombre">Nombre</label>
      <input type="text" id="c-nombre" name="nombre" maxlength="150" required value="<?= h($v('nombre')) ?>" placeholder="Ej. Actualizar Java en las salas del 4.º piso">
    </div>
    <div class="form-grid">
      <div class="field">
        <label for="c-tipo">Tipo</label>
        <select id="c-tipo" name="tipo">
          <?php foreach (MantenimientoService::TIPOS as $t): ?><option value="<?= h($t) ?>" <?= $v('tipo') === $t ? 'selected' : '' ?>><?= h(etiquetaValor($t)) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="c-alcance">Alcance en cada sala</label>
        <select id="c-alcance" name="alcance_equipo">
          <option value="TODOS">General de sala (cómputo, videobeam y parlantes)</option>
          <option value="COMPUTO" <?= $v('alcance_equipo') === 'COMPUTO' ? 'selected' : '' ?>>Solo cómputo (sin videobeam)</option>
          <option value="VIDEOBEAM" <?= $v('alcance_equipo') === 'VIDEOBEAM' ? 'selected' : '' ?>>Solo el videobeam (sin checklist)</option>
        </select>
      </div>
    </div>
    <div class="field">
      <label for="c-descripcion">Descripción</label>
      <textarea id="c-descripcion" name="descripcion" required><?= h($v('descripcion')) ?></textarea>
    </div>
    <div class="field">
      <label for="c-actividades">Actividades a realizar (una por línea)</label>
      <textarea id="c-actividades" name="actividades" rows="3"><?= h($v('actividades')) ?></textarea>
    </div>
    <div class="field">
      <label for="c-enlace">Enlace de referencia (opcional)</label>
      <input type="url" id="c-enlace" name="enlace_referencia" value="<?= h($v('enlace_referencia')) ?>" placeholder="https://… guía o video de lo que se va a hacer">
    </div>
    <div class="form-grid">
      <div class="field">
        <label for="c-inicio">Empezar desde</label>
        <input type="datetime-local" id="c-inicio" name="fecha_inicio" required value="<?= h($v('fecha_inicio')) ?>">
      </div>
      <div class="field">
        <label for="c-duracion">Duración estimada por sala</label>
        <select id="c-duracion" name="duracion_minutos">
          <?php foreach ($duraciones as $min => $txt): ?><option value="<?= (int)$min ?>" <?= (int)$v('duracion_minutos', '60') === $min ? 'selected' : '' ?>><?= h($txt) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field">
      <label for="c-responsable">Coordinador (opcional)</label>
      <select id="c-responsable" name="id_responsable">
        <option value="">-- Sin asignar --</option>
        <?php foreach ($coordinadores as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (int)$v('id_responsable') === (int)$r['id'] ? 'selected' : '' ?>><?= h($r['nombre_completo']) ?></option><?php endforeach; ?>
      </select>
      <p class="hint">Con coordinador, el cronograma evita que le queden dos salas a la misma hora.</p>
    </div>
    <label class="flex-row items-center gap-2">
      <input class="w-auto" type="checkbox" name="secuencial" value="1" <?= $v('secuencial') !== '' ? 'checked' : '' ?>>
      Finalizar una sala antes de continuar con la siguiente
    </label>
    <p class="hint">Activada: la siguiente sala queda bloqueada hasta que la anterior se cierre como realizada (checklist completa y evidencia). Desactivada: cada sala se atiende por su cuenta.</p>

    <fieldset class="field">
      <legend>Salas (en el orden en que las marques)</legend>
      <p class="hint"><button type="button" class="btn btn-ghost btn-sm" id="c-todas">Marcar todas</button> <button type="button" class="btn btn-ghost btn-sm" id="c-ninguna">Quitar todas</button></p>
      <div id="c-salas">
        <?php foreach ($salasElegibles as $s): $marcada = in_array((int)$s['id_sala'], $salasMarcadas, true); ?>
          <label class="flex-row items-center gap-2 fw-normal">
            <input class="w-auto" type="checkbox" name="salas[]" value="<?= (int)$s['id_sala'] ?>" <?= $marcada ? 'checked' : '' ?>>
            <span class="chip" data-orden hidden></span>
            <?= h($s['abreviatura'] ? $s['abreviatura'] . ' — ' . $s['nombre_sala'] : $s['nombre_sala']) ?><?= $s['bloque'] ? ' <span class="hint">· ' . h($s['bloque']) . '</span>' : '' ?>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <button type="submit" name="crear_campana" class="btn">Crear campaña y cronograma</button>
  </form>
<?= vfCerrar() ?>
<script src="<?= h(asset('assets/campanas.js')) ?>"></script>
<?php endif; ?>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
