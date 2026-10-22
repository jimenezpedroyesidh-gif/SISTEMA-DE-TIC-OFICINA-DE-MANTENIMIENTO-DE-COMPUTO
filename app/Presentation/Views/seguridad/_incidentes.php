<?php
/** Vista parcial de «Seguridad»: registro y seguimiento de incidentes de seguridad. Solo presentación. */
/** Campos del formulario de incidente (nuevo o gestión). */
$camposIncidente = static function (string $pref, ?array $i) use ($responsables): void { ?>
  <div class="field field-full">
    <label for="<?= $pref ?>-titulo">Título</label>
    <input type="text" id="<?= $pref ?>-titulo" name="titulo" maxlength="150" required value="<?= h($i['titulo'] ?? '') ?>" placeholder="Ej: Correo falso pidiendo la clave del correo institucional">
  </div>
  <div class="field">
    <label for="<?= $pref ?>-tipo">Tipo</label>
    <select id="<?= $pref ?>-tipo" name="tipo">
      <?php foreach (IncidenteService::TIPOS as $v => $txt): ?><option value="<?= $v ?>" <?= ($i['tipo'] ?? 'OTRO') === $v ? 'selected' : '' ?>><?= h($txt) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="<?= $pref ?>-sev">Severidad</label>
    <select id="<?= $pref ?>-sev" name="severidad">
      <?php foreach (SlaService::PRIORIDADES as $v => $txt): ?><option value="<?= $v ?>" <?= ($i['severidad'] ?? 'ALTA') === $v ? 'selected' : '' ?>><?= h($txt) ?></option><?php endforeach; ?>
    </select>
  </div>
  <fieldset class="field-full">
    <legend>¿Qué se vio afectado?</legend>
    <div class="d-flex gap-2 flex-wrap">
      <span class="field-check"><label><input type="checkbox" name="afecta_confidencialidad" value="1" <?= !empty($i['afecta_confidencialidad']) ? 'checked' : '' ?>> Confidencialidad (alguien vio lo que no debía)</label></span>
      <span class="field-check"><label><input type="checkbox" name="afecta_integridad" value="1" <?= !empty($i['afecta_integridad']) ? 'checked' : '' ?>> Integridad (datos alterados o borrados)</label></span>
      <span class="field-check"><label><input type="checkbox" name="afecta_disponibilidad" value="1" <?= !empty($i['afecta_disponibilidad']) ? 'checked' : '' ?>> Disponibilidad (servicio caído)</label></span>
    </div>
  </fieldset>
  <div class="field field-full">
    <label for="<?= $pref ?>-resp">Responsable</label>
    <select id="<?= $pref ?>-resp" name="id_responsable">
      <option value="">Sin asignar</option>
      <?php foreach ($responsables as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)($i['id_responsable'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['nombre_completo']) ?> (<?= h(etiquetaRol((string)$u['role'])) ?>)</option><?php endforeach; ?>
    </select>
  </div>
  <div class="field field-full">
    <label for="<?= $pref ?>-desc">Qué pasó</label>
    <textarea id="<?= $pref ?>-desc" name="descripcion" rows="3" maxlength="5000"><?= h($i['descripcion'] ?? '') ?></textarea>
  </div>
  <div class="field field-full">
    <label for="<?= $pref ?>-cont">Contención (qué se hizo para frenarlo)</label>
    <textarea id="<?= $pref ?>-cont" name="contencion" rows="2" maxlength="5000" placeholder="Ej: se cambió la clave, se aisló el equipo de la red…"><?= h($i['contencion'] ?? '') ?></textarea>
  </div>
  <div class="field field-full">
    <label for="<?= $pref ?>-causa">Causa raíz</label>
    <textarea id="<?= $pref ?>-causa" name="causa" rows="2" maxlength="5000"><?= h($i['causa'] ?? '') ?></textarea>
  </div>
  <div class="field field-full">
    <label for="<?= $pref ?>-lec">Lección aprendida (qué cambia para que no se repita)</label>
    <textarea id="<?= $pref ?>-lec" name="leccion" rows="2" maxlength="5000"><?= h($i['leccion'] ?? '') ?></textarea>
  </div>
<?php };
?>
    <section class="panel-box">
      <div class="conexion-cabecera">
        <nav class="segmento" aria-label="Filtrar incidentes por estado">
          <a href="seguridad.php?vista=incidentes" class="segmento-op<?= $filtroEstado === '' ? ' segmento-activo' : '' ?>">Todos</a>
          <?php foreach (IncidenteService::ESTADOS as $e => $nombre): ?>
            <a href="seguridad.php?vista=incidentes&amp;estado=<?= $e ?>" class="segmento-op<?= $filtroEstado === $e ? ' segmento-activo' : '' ?>"><?= h($nombre) ?></a>
          <?php endforeach; ?>
        </nav>
        <button type="button" class="btn btn-sm" data-vf-abrir="vf-nuevo-incidente">+ Registrar incidente</button>
      </div>
      <?php if (!$incidentes): ?>
        <p class="text-muted"><?= $filtroEstado !== '' ? 'No hay incidentes en este estado.' : 'No hay incidentes registrados. Los tickets con la categoría «Incidente de seguridad» aparecen aquí solos.' ?></p>
      <?php else: ?>
      <div class="tabla-scroll">
        <table class="data">
          <thead><tr><th>#</th><th>Detectado</th><th>Incidente</th><th>Tipo y severidad</th><th>Afecta</th><th>Responsable</th><th>Estado</th><th>Acciones</th></tr></thead>
          <tbody>
            <?php foreach ($incidentes as $i):
                $cid = array_filter(['Confidencialidad' => $i['afecta_confidencialidad'], 'Integridad' => $i['afecta_integridad'], 'Disponibilidad' => $i['afecta_disponibilidad']]);
            ?>
              <tr>
                <td data-label="#"><?= (int)$i['id_incidente'] ?></td>
                <td data-label="Detectado"><?= h(date('d/m/Y H:i', strtotime((string)$i['fecha_deteccion']))) ?></td>
                <td data-label="Incidente"><strong><?= h($i['titulo']) ?></strong><br><span class="hint"><?= $i['id_ticket'] ? 'Desde el ticket #' . (int)$i['id_ticket'] . ($i['tabla_ticket'] === TablaTicket::PROFESOR ? ' (profesor)' : '') : 'Registrado por ' . h((string)($i['reporto'] ?? 'el admin')) ?></span></td>
                <td data-label="Tipo y severidad"><?= h(IncidenteService::TIPOS[$i['tipo']] ?? $i['tipo']) ?><br><strong><?= h(SlaService::PRIORIDADES[$i['severidad']] ?? $i['severidad']) ?></strong></td>
                <td data-label="Afecta"><?= $cid ? h(implode(' · ', array_keys($cid))) : '<span class="text-muted">Sin clasificar</span>' ?></td>
                <td data-label="Responsable"><?= $i['responsable'] ? h($i['responsable']) : '<span class="texto-peligro">Sin asignar</span>' ?></td>
                <td data-label="Estado"><span class="<?= IncidenteService::CHIP_ESTADO[$i['estado']] ?>"><?= h(IncidenteService::ESTADOS[$i['estado']]) ?></span><?php if ($i['fecha_cierre']): ?><br><span class="hint">cerrado <?= h(date('d/m/Y', strtotime((string)$i['fecha_cierre']))) ?></span><?php endif; ?></td>
                <td data-label="Acciones"><button type="button" class="btn btn-ghost btn-sm" data-vf-abrir="vf-incidente-<?= (int)$i['id_incidente'] ?>">Gestionar</button></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
      <p class="hint">Flujo: Reportado → En análisis → Contenido (con acciones de contención) → Cerrado (con causa y lección aprendida). Los incidentes que comprometan datos personales deben informarse a la Superintendencia de Industria y Comercio (Ley 1581).</p>
    </section>

    <?= vfAbrir('vf-nuevo-incidente', 'Registrar incidente de seguridad', 'Regístralo apenas se detecte, aunque todavía falten datos: se completa después.', $abrirForm === 'nuevo', 'lg') ?>
      <?php if ($abrirForm === 'nuevo'): ?><div class="error mb-3"><?= h($flash['mensaje']) ?></div><?php endif; ?>
      <form method="POST" class="form-grid">
        <?= csrfField() ?>
        <input type="hidden" name="form" value="nuevo">
        <?php $camposIncidente('nuevo', null); ?>
        <button type="submit" name="registrar_incidente" class="btn btn-sm">Registrar</button>
      </form>
    <?= vfCerrar() ?>

    <?php foreach ($incidentes as $i): $idI = (int)$i['id_incidente']; ?>
      <?= vfAbrir('vf-incidente-' . $idI, 'Incidente #' . $idI, 'Actualiza la clasificación y el avance. Para cerrarlo escribe la causa y la lección aprendida.', $abrirForm === 'inc-' . $idI, 'lg') ?>
        <?php if ($abrirForm === 'inc-' . $idI): ?><div class="error mb-3"><?= h($flash['mensaje']) ?></div><?php endif; ?>
        <form method="POST" class="form-grid">
          <?= csrfField() ?>
          <input type="hidden" name="form" value="inc-<?= $idI ?>">
          <input type="hidden" name="id" value="<?= $idI ?>">
          <div class="field field-full">
            <label for="inc-<?= $idI ?>-estado">Estado</label>
            <select id="inc-<?= $idI ?>-estado" name="estado">
              <?php foreach (IncidenteService::ESTADOS as $e => $nombre): ?><option value="<?= $e ?>" <?= $i['estado'] === $e ? 'selected' : '' ?>><?= h($nombre) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php $camposIncidente('inc-' . $idI, $i); ?>
          <button type="submit" name="actualizar_incidente" class="btn btn-sm">Guardar cambios</button>
        </form>
      <?= vfCerrar() ?>
    <?php endforeach; ?>
