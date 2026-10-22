<?php
/**
 * Vista del «Tablero de actividades» (public/actividades.php). Solo presentación: los datos los prepara
 * ActividadesPage. Tres columnas de pegatinas (Por hacer · En curso · Hecho); en el celular las columnas
 * se deslizan de lado. La secretaria crea/edita/borra en ventanas flotantes; la beca mueve las suyas.
 */
$paginaTitulo = 'Tablero de actividades'; include LAYOUT_DIR . '/app_page_head.php';
$tituloHeader = 'Tablero de actividades'; include LAYOUT_DIR . '/app_page_body_start.php';

/** Campos del formulario de actividad (nueva o edición). */
$camposActividad = static function (string $pref, ?array $a) use ($becasSecretaria): void {
    $muestras = ['amarillo' => 'peg-amarillo', 'azul' => 'peg-azul', 'verde' => 'peg-verde', 'rosa' => 'peg-rosa', 'lila' => 'peg-lila']; ?>
  <div class="field field-full">
    <label for="<?= $pref ?>-titulo">Título</label>
    <input type="text" id="<?= $pref ?>-titulo" name="titulo" maxlength="120" required value="<?= h($a['titulo'] ?? ($_POST['titulo'] ?? '')) ?>" placeholder="Ej: Organizar los soportes de matrícula">
  </div>
  <div class="field field-full">
    <label for="<?= $pref ?>-detalle">Detalle (opcional)</label>
    <textarea id="<?= $pref ?>-detalle" name="detalle" rows="3" maxlength="2000" placeholder="Qué hay que hacer, dónde, con quién…"><?= h($a['detalle'] ?? ($_POST['detalle'] ?? '')) ?></textarea>
  </div>
  <div class="field">
    <label for="<?= $pref ?>-asig">Asignar a</label>
    <select id="<?= $pref ?>-asig" name="id_asignado">
      <option value="">Cualquier beca de secretaría</option>
      <?php foreach ($becasSecretaria as $b): ?>
        <option value="<?= (int)$b['id'] ?>" <?= (int)($a['id_asignado'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['nombre_completo']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="<?= $pref ?>-fecha">Fecha límite (opcional)</label>
    <input type="date" id="<?= $pref ?>-fecha" name="fecha_limite" value="<?= h($a['fecha_limite'] ?? '') ?>">
  </div>
  <fieldset class="field-full peg-colores">
    <legend>Color de la pegatina</legend>
    <?php foreach (ActividadService::COLORES as $valorC => $nombreC): ?>
      <label class="peg-color-op" title="<?= h($nombreC) ?>">
        <input type="radio" name="color" value="<?= $valorC ?>" <?= ($a['color'] ?? 'amarillo') === $valorC ? 'checked' : '' ?>>
        <span class="peg-muestra <?= $muestras[$valorC] ?>" aria-hidden="true"></span><span class="sr-only"><?= h($nombreC) ?></span>
      </label>
    <?php endforeach; ?>
  </fieldset>
<?php };
$hoy = date('Y-m-d');
// Clases literales (el lint de CSS sin uso busca los nombres completos en el código).
$clasePeg = ['amarillo' => 'peg-amarillo', 'azul' => 'peg-azul', 'verde' => 'peg-verde', 'rosa' => 'peg-rosa', 'lila' => 'peg-lila'];
$claseCol = ['POR_HACER' => 'tablero-col-pendiente', 'EN_CURSO' => 'tablero-col-curso', 'HECHO' => 'tablero-col-hecho'];
$ordenEstados = array_keys(ActividadService::ESTADOS);
?>
<?php if ($paginaSinAcceso): ?>
    <div class="app-dashboard-welcome"><h1>Tablero de actividades</h1></div>
    <div class="error">El tablero es solo para la secretaría y los becas asignados a la secretaría.</div>
<?php else: ?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1>Tablero de actividades</h1>
        <p><?= $permisos['gestionar'] ? 'Crea las tareas de los becas de secretaría como pegatinas y sigue su avance.' : 'Tus tareas de la secretaría. Muévelas a «En curso» al empezar y a «Hecho» al terminar.' ?></p>
      </div>
      <div class="page-acciones">
        <?php if ($permisos['gestionar']): ?>
          <button type="button" class="btn btn-sm" data-vf-abrir="vf-nueva-actividad">+ Nueva actividad</button>
        <?php elseif ($permisos['esBeca']): ?>
          <nav class="segmento" aria-label="Filtrar actividades">
            <a href="actividades.php" class="segmento-op<?= $soloMias ? '' : ' segmento-activo' ?>"<?= $soloMias ? '' : ' aria-current="page"' ?>>Todas</a>
            <a href="actividades.php?mias=1" class="segmento-op<?= $soloMias ? ' segmento-activo' : '' ?>"<?= $soloMias ? ' aria-current="page"' : '' ?>>Mías</a>
          </nav>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($error && $formConError === ''): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
    <?php if ($exito): ?><div class="exito mb-3"><?= h($exito) ?></div><?php endif; ?>

    <div class="tablero" aria-label="Tablero de actividades">
      <?php foreach (ActividadService::ESTADOS as $estado => $nombreEstado): $lista = $porEstado[$estado]; $iEst = array_search($estado, $ordenEstados, true); ?>
        <section class="tablero-col <?= $claseCol[$estado] ?>" aria-labelledby="col-<?= $estado ?>">
          <header class="tablero-col-head">
            <h2 id="col-<?= $estado ?>"><?= h($nombreEstado) ?></h2>
            <span class="tablero-cuenta"><?= count($lista) ?></span>
          </header>
          <div class="tablero-lista">
            <?php if (!$lista): ?>
              <p class="tablero-vacio"><?= $estado === 'POR_HACER' ? ($permisos['gestionar'] ? 'Sin pendientes. Usa «+ Nueva actividad».' : 'Sin pendientes por ahora.') : 'Nada aquí todavía.' ?></p>
            <?php endif; ?>
            <?php foreach ($lista as $a):
                $idA = (int)$a['id_actividad'];
                $mia = (int)($a['id_asignado'] ?? 0) === $userId;
                $puedeMover = $permisos['gestionar'] || $a['id_asignado'] === null || $mia;
                $vencida = $a['fecha_limite'] && $a['fecha_limite'] < $hoy && $estado !== 'HECHO';
            ?>
              <article class="pegatina <?= $clasePeg[$a['color']] ?? 'peg-amarillo' ?><?= $mia ? ' pegatina-mia' : '' ?>" id="act-<?= $idA ?>">
                <div class="peg-head">
                  <?php if ($a['fecha_limite']): ?>
                    <span class="peg-fecha<?= $vencida ? ' peg-vencida' : '' ?>"><?= $vencida ? 'Venció' : 'Vence' ?> <?= h(date('d/m', strtotime((string)$a['fecha_limite']))) ?></span>
                  <?php else: ?><span></span><?php endif; ?>
                  <?php if ($permisos['gestionar']): ?>
                    <details class="menu-acciones" data-menu>
                      <summary class="peg-menu" aria-label="Opciones de la actividad">⋯</summary>
                      <div class="menu-acciones-lista">
                        <button type="button" class="menu-acciones-item" data-vf-abrir="vf-act-<?= $idA ?>">Editar</button>
                        <form method="POST" class="m-0" data-confirm="¿Eliminar esta actividad del tablero?">
                          <?= csrfField() ?>
                          <input type="hidden" name="id_actividad" value="<?= $idA ?>">
                          <button type="submit" name="borrar_actividad" class="menu-acciones-item">Eliminar</button>
                        </form>
                      </div>
                    </details>
                  <?php endif; ?>
                </div>
                <h3 class="peg-titulo"><?= h($a['titulo']) ?></h3>
                <?php if (trim((string)$a['detalle']) !== ''): ?><p class="peg-detalle"><?= nl2br(h($a['detalle'])) ?></p><?php endif; ?>
                <div class="peg-pie">
                  <span class="peg-quien">
                    <?php if ($a['asignado_nombre']): ?>
                      <span class="peg-avatar" aria-hidden="true"><?= h(inicialesUsuario($a['asignado_nombre'])) ?></span><?= $mia ? 'Tú' : h($a['asignado_nombre']) ?>
                    <?php else: ?>
                      <span class="peg-avatar peg-avatar-libre" aria-hidden="true">?</span>Sin asignar
                    <?php endif; ?>
                  </span>
                  <?php if ($puedeMover): ?>
                    <span class="peg-mover">
                      <?php foreach ([-1, 1] as $paso): $destino = $ordenEstados[$iEst + $paso] ?? null; if (!$destino) continue; ?>
                        <form method="POST" class="m-0">
                          <?= csrfField() ?>
                          <input type="hidden" name="id_actividad" value="<?= $idA ?>">
                          <input type="hidden" name="estado" value="<?= $destino ?>">
                          <button type="submit" name="mover_actividad" class="peg-btn" aria-label="Mover a <?= h(ActividadService::ESTADOS[$destino]) ?>" title="Mover a <?= h(ActividadService::ESTADOS[$destino]) ?>"><?= $paso < 0 ? '←' : '→' ?></button>
                        </form>
                      <?php endforeach; ?>
                    </span>
                  <?php endif; ?>
                </div>
              </article>
              <?php if ($permisos['gestionar']): ?>
                <?= vfAbrir('vf-act-' . $idA, 'Editar actividad', 'Cambia el texto, a quién está asignada, la fecha o el color.', $formConError === 'vf-act-' . $idA) ?>
                  <?php if ($formConError === 'vf-act-' . $idA): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
                  <form method="POST" class="form-grid">
                    <?= csrfField() ?>
                    <input type="hidden" name="id_actividad" value="<?= $idA ?>">
                    <?php $camposActividad('ed' . $idA, $a); ?>
                    <button type="submit" name="editar_actividad" class="btn field-full">Guardar cambios</button>
                  </form>
                <?= vfCerrar() ?>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </div>

    <?php if ($permisos['gestionar']): ?>
      <?= vfAbrir('vf-nueva-actividad', 'Nueva actividad', 'Aparecerá como pegatina en «Por hacer». Si la asignas, la beca recibe un aviso.', $formConError === 'vf-nueva-actividad') ?>
        <?php if ($formConError === 'vf-nueva-actividad'): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
        <?php if (!$becasSecretaria): ?><p class="nota-info mb-3">Todavía no hay becas en el área de secretaría. El administrador las asigna en Usuarios → editar → «Área de la beca trabajo».</p><?php endif; ?>
        <form method="POST" class="form-grid">
          <?= csrfField() ?>
          <?php $camposActividad('nueva', null); ?>
          <button type="submit" name="crear_actividad" class="btn field-full">Agregar al tablero</button>
        </form>
      <?= vfCerrar() ?>
    <?php endif; ?>
<?php endif; ?>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
