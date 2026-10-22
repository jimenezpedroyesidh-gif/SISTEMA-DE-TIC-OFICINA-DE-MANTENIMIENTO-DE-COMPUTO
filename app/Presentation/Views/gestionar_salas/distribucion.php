<?php
/**
 * Vista de «Distribución del salón» (public/distribucion_sala.php). Solo presentación: los datos los
 * prepara DistribucionSalaPage. El editor (filas, mesas, puestos, numeración, punto de red) corre en
 * assets/distribucion_sala.js sobre el JSON de #dist-datos y se envía como un solo campo «estructura».
 */
$paginaTitulo = 'Distribución del salón'; $scriptsExtra = ['assets/distribucion_sala.js' => 'defer']; include LAYOUT_DIR . '/app_page_head.php';
$tituloHeader = 'Distribución del salón'; include LAYOUT_DIR . '/app_page_body_start.php';
?>
<?php if ($salaNoExiste): ?>
    <div class="app-dashboard-welcome">
      <h1>Distribución del salón</h1>
    </div>
    <div class="error">La sala no existe.</div>
<?php else: ?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1>Distribución · <?= h($sala['nombre_sala']) ?></h1>
        <p>Maqueta el salón como es en realidad: filas, mesas con sus puestos, pasillos y el puesto del docente. Luego ubica cada equipo y dale su número y punto de red.</p>
      </div>
      <div class="page-acciones">
        <a class="btn btn-ghost btn-sm" href="ficha_equipo.php?q=<?= urlencode($sala['nombre_sala']) ?>&todas=1">Ver plano de la sala</a>
        <details class="menu-acciones" data-menu>
          <summary class="btn btn-ghost btn-sm">Más ▾</summary>
          <div class="menu-acciones-lista">
            <?php if (!$esNueva): ?>
              <form method="POST" class="m-0" data-confirm="¿Borrar la distribución guardada? El plano volverá a repartir los equipos automáticamente por código.">
                <?= csrfField() ?>
                <input type="hidden" name="id_sala" value="<?= (int)$sala['id_sala'] ?>">
                <button type="submit" name="restablecer_distribucion" class="menu-acciones-item">Restablecer reparto automático</button>
              </form>
            <?php endif; ?>
          </div>
        </details>
      </div>
    </div>

    <?php if ($error): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
    <?php if ($exito): ?><div class="exito mb-3"><?= h($exito) ?></div><?php endif; ?>
    <?php if ($esNueva && !$error): ?>
      <p class="nota-info mb-3">Esta sala aún no tiene distribución guardada. Se propone una a partir del plano automático (equipos en orden de código): ajústala y guarda.</p>
    <?php elseif ($ultimaEdicion): ?>
      <p class="hint mb-3">Última modificación: <?= h(date('d/m/Y H:i', strtotime((string)$ultimaEdicion['actualizado_en']))) ?><?= !empty($ultimaEdicion['nombre_completo']) ? ' · ' . h($ultimaEdicion['nombre_completo']) : '' ?></p>
    <?php endif; ?>

    <script type="application/json" id="dist-datos"><?= jsJson(['estructura' => $estructura, 'equipos' => $equipos]) ?></script>
    <noscript><div class="error mb-3">El editor de distribución necesita JavaScript activado.</div></noscript>

    <form method="POST" class="dist" id="dist-form">
      <?= csrfField() ?>
      <input type="hidden" name="id_sala" value="<?= (int)$sala['id_sala'] ?>">
      <input type="hidden" name="estructura" id="dist-estructura" value="">

      <section class="dist-lienzo" aria-label="Plano editable del salón">
        <div class="dist-lienzo-barra">
          <span class="dist-resumen" id="dist-resumen" aria-live="polite"></span>
          <button type="button" class="btn btn-secondary btn-sm" data-dist="agregar-fila">+ Fila</button>
        </div>
        <div class="sala-plano dist-plano" id="dist-plano"></div>
        <p class="hint mt-2">Toca un puesto para elegir su equipo, número y punto de red. Bajo cada mesa: − / + puestos, <strong>⇥</strong> pasillo a su derecha y ✕ quitar mesa.</p>
      </section>

      <aside class="dist-panel">
        <div class="dist-bloque dist-bloque-puesto" id="dist-puesto" hidden>
          <h2 class="dist-bloque-tit" id="dist-puesto-tit">Puesto</h2>
          <div class="field">
            <label for="dist-equipo">Equipo en este puesto</label>
            <select id="dist-equipo"></select>
            <small class="hint">Si eliges un equipo que ya está en otro puesto, se intercambian.</small>
          </div>
          <div class="dist-campos">
            <div class="field">
              <label for="dist-numero">Número del puesto</label>
              <input type="text" id="dist-numero" maxlength="<?= DistribucionSala::MAX_NUMERO ?>" placeholder="Ej: 7">
            </div>
            <div class="field">
              <label for="dist-red">Punto de red</label>
              <input type="text" id="dist-red" maxlength="<?= DistribucionSala::MAX_RED ?>" placeholder="Ej: D-12">
            </div>
          </div>
          <div class="dist-acciones">
            <button type="button" class="btn btn-ghost btn-sm" data-dist="vaciar-puesto">Quitar equipo</button>
            <button type="button" class="btn btn-ghost btn-sm" data-dist="cerrar-puesto">Listo</button>
          </div>
        </div>

        <details class="dist-bloque" open>
          <summary class="dist-bloque-tit">Estructura rápida</summary>
          <p class="hint">Arma todas las filas iguales de una vez y luego ajusta las que sean distintas (p. ej. la última mesa con 3 puestos).</p>
          <div class="dist-campos">
            <div class="field"><label for="dist-p-filas">Filas</label><input type="number" id="dist-p-filas" min="1" max="<?= DistribucionSala::MAX_FILAS ?>" value="5"></div>
            <div class="field"><label for="dist-p-mesas">Mesas por fila</label><input type="number" id="dist-p-mesas" min="1" max="<?= DistribucionSala::MAX_MESAS ?>" value="2"></div>
            <div class="field"><label for="dist-p-puestos">Puestos por mesa</label><input type="number" id="dist-p-puestos" min="1" max="<?= DistribucionSala::MAX_PUESTOS ?>" value="4"></div>
            <div class="field"><label for="dist-p-pasillo">Pasillo después de la mesa</label><input type="number" id="dist-p-pasillo" min="0" max="<?= DistribucionSala::MAX_MESAS ?>" value="1"><small class="hint">0 = sin pasillo</small></div>
          </div>
          <button type="button" class="btn btn-secondary btn-sm" data-dist="plantilla">Aplicar estructura</button>
        </details>

        <details class="dist-bloque" open>
          <summary class="dist-bloque-tit">Puesto del docente</summary>
          <div class="dist-campos">
            <div class="field">
              <label for="dist-doc-ubic">Ubicación</label>
              <select id="dist-doc-ubic">
                <option value="adelante">Adelante (frente al tablero)</option>
                <option value="atras">Atrás (fondo del salón)</option>
                <option value="ninguno">Sin puesto docente</option>
              </select>
            </div>
            <div class="field">
              <label for="dist-doc-lado">Lado</label>
              <select id="dist-doc-lado">
                <option value="izq">Izquierdo</option>
                <option value="der">Derecho</option>
              </select>
            </div>
          </div>
        </details>

        <details class="dist-bloque" open>
          <summary class="dist-bloque-tit">Numeración y equipos</summary>
          <div class="dist-campos">
            <div class="field">
              <label for="dist-orden">Orden</label>
              <select id="dist-orden">
                <option value="filas">Por filas, del frente al fondo (izq → der)</option>
                <option value="zigzag">En zigzag (cada fila cambia de sentido)</option>
                <option value="fondo">Por filas, del fondo al frente</option>
              </select>
            </div>
            <div class="field"><label for="dist-inicio">Empezar en</label><input type="number" id="dist-inicio" min="0" max="999" value="1"></div>
            <div class="field"><label for="dist-prefijo">Prefijo (opcional)</label><input type="text" id="dist-prefijo" maxlength="4" placeholder="Ej: PC"></div>
          </div>
          <label class="dist-check"><input type="checkbox" id="dist-auto" checked> Renumerar solo al cambiar la estructura</label>
          <div class="dist-acciones">
            <button type="button" class="btn btn-secondary btn-sm" data-dist="numerar">Numerar puestos</button>
            <button type="button" class="btn btn-secondary btn-sm" data-dist="ubicar">Ubicar equipos sin puesto</button>
            <button type="button" class="btn btn-ghost btn-sm" data-dist="vaciar-todo">Vaciar todos</button>
          </div>
        </details>

        <div class="dist-bloque">
          <h2 class="dist-bloque-tit">Equipos sin puesto <span id="dist-sin-total"></span></h2>
          <p class="hint" id="dist-sin-ayuda">Elige un puesto y luego toca aquí el equipo para ubicarlo.</p>
          <div class="dist-sin" id="dist-sin"></div>
        </div>
      </aside>

      <div class="dist-guardar">
        <span class="dist-estado" id="dist-estado">Sin cambios</span>
        <button type="submit" name="guardar_distribucion" class="btn">Guardar distribución</button>
      </div>
    </form>
<?php endif; ?>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
