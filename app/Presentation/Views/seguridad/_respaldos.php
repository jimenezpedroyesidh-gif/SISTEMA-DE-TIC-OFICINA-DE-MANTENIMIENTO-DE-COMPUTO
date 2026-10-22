<?php
/** Vista parcial de «Seguridad»: respaldos cifrados (generar, descargar, verificar, eliminar) y guía de automatización. Solo presentación. */
$tam = static function (int $b): string {
    if ($b >= 1048576) return number_format($b / 1048576, 1, ',', '.') . ' MB';
    return number_format(max(1, $b / 1024), 0, ',', '.') . ' KB';
};
?>
    <?php if ($faltaExtension): ?><div class="error mb-3"><?= h($faltaExtension) ?></div><?php endif; ?>

    <section class="panel-box">
      <div class="conexion-cabecera">
        <h2 class="panel-title">Respaldos generados <span class="hint">se conservan los <?= (int)$conservar ?> más recientes</span></h2>
        <button type="button" class="btn btn-sm" data-vf-abrir="vf-generar-respaldo"<?= $faltaExtension ? ' disabled' : '' ?>>+ Generar respaldo</button>
      </div>
      <?php if (!$respaldos): ?>
        <p class="text-muted">Todavía no hay respaldos. Genera el primero y guárdalo también fuera del servidor (disco externo o nube institucional).</p>
      <?php else: ?>
      <div class="tabla-scroll">
        <table class="data">
          <thead><tr><th>Fecha</th><th>Generado por</th><th>Contenido</th><th>Tamaño</th><th>Verificación</th><th>Acciones</th></tr></thead>
          <tbody>
            <?php foreach ($respaldos as $r): $idR = (int)$r['id_respaldo']; ?>
              <tr>
                <td data-label="Fecha"><?= h(date('d/m/Y H:i', strtotime((string)$r['fecha']))) ?><br><span class="hint"><?= h($r['archivo']) ?></span></td>
                <td data-label="Generado por"><?= $r['nombre_completo'] ? h($r['nombre_completo']) : '<span class="text-muted">Tarea programada</span>' ?></td>
                <td data-label="Contenido"><?= (int)$r['tablas'] ?> tablas · <?= number_format((int)$r['filas'], 0, ',', '.') ?> filas · <?= (int)$r['adjuntos'] ?> archivos</td>
                <td data-label="Tamaño"><?= h($tam((int)$r['tamano'])) ?></td>
                <td data-label="Verificación">
                  <?php if ($r['verificacion_ok'] === null): ?><span class="chip espera">Sin verificar</span>
                  <?php elseif ((int)$r['verificacion_ok'] === 1): ?><span class="chip solucionado">Restaurable</span>
                  <?php else: ?><span class="chip danio">Con fallas</span><?php endif; ?>
                  <?php if ($r['verificacion_detalle']): ?><br><span class="hint"><?= h($r['verificacion_detalle']) ?></span><?php endif; ?>
                </td>
                <td data-label="Acciones">
                  <details class="menu-acciones" data-menu>
                    <summary class="btn btn-ghost btn-sm">Opciones ▾</summary>
                    <div class="menu-acciones-lista">
                      <a class="menu-acciones-item" href="seguridad.php?vista=respaldos&amp;descargar=<?= $idR ?>">Descargar (cifrado)</a>
                      <button type="button" class="menu-acciones-item" data-vf-abrir="vf-verificar-<?= $idR ?>">Verificar y probar restauración</button>
                      <form method="POST" class="m-0" data-confirm="¿Eliminar este respaldo del servidor? Si no tienes otra copia, se pierde.">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= $idR ?>">
                        <button type="submit" name="eliminar_respaldo" class="menu-acciones-item">Eliminar</button>
                      </form>
                    </div>
                  </details>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>

    <div class="panel-grid">
      <section class="panel-box">
        <h2 class="panel-title">Respaldo automático diario</h2>
        <p>El mismo respaldo se puede generar sin entrar al sistema con <code>tools/respaldo.php</code>; la frase se pasa en la variable <code>RESPALDO_CLAVE</code> (nunca en el código).</p>
        <p class="panel-subtitle">Windows (Programador de tareas)</p>
        <p><code>cmd /c "set RESPALDO_CLAVE=tu frase larga&amp;&amp; C:\xampp\php\php.exe C:\xampp\htdocs\tickets\tools\respaldo.php"</code></p>
        <p class="panel-subtitle">Linux (cron, todos los días a las 11 p. m.)</p>
        <p><code>0 23 * * * RESPALDO_CLAVE='tu frase larga' php /var/www/tickets/tools/respaldo.php</code></p>
        <p class="hint">Regla 3-2-1: tres copias, en dos medios distintos y una fuera de la universidad. Copia también <code>storage/clave_conexiones.key</code> a tu bóveda (no viaja en el respaldo).</p>
      </section>
      <section class="panel-box">
        <h2 class="panel-title">Cómo restaurar</h2>
        <ol class="pl-18">
          <li>Descifra el archivo: <code>RESPALDO_CLAVE='tu frase' php tools/restaurar_respaldo.php respaldo.ufpsbk carpeta_destino</code>.</li>
          <li>En una base vacía importa <code>base_datos.sql</code> (phpMyAdmin → Importar, o <code>mysql -u root base &lt; base_datos.sql</code>).</li>
          <li>Copia <code>archivos/storage/adjuntos</code> y <code>archivos/public/assets/uploads</code> sobre el proyecto.</li>
          <li>Restaura <code>storage/clave_conexiones.key</code> desde tu bóveda y entra a «Seguridad» para revisar el diagnóstico.</li>
        </ol>
        <p class="hint">«Verificar» hace todo esto en una base temporal que luego borra, para confirmar que el respaldo sí sirve.</p>
      </section>
    </div>

    <?= vfAbrir('vf-generar-respaldo', 'Generar respaldo cifrado', 'Incluye toda la base de datos y los archivos subidos. Puede tardar unos segundos.', $abrirForm === 'generar') ?>
      <?php if ($abrirForm === 'generar'): ?><div class="error mb-3"><?= h($flash['mensaje']) ?></div><?php endif; ?>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="form" value="generar">
        <div class="field">
          <label for="frase">Frase de cifrado (mínimo 12 caracteres)</label>
          <input type="password" id="frase" name="frase" minlength="12" required autocomplete="new-password">
        </div>
        <div class="field">
          <label for="frase_confirmar">Repite la frase</label>
          <input type="password" id="frase_confirmar" name="frase_confirmar" minlength="12" required autocomplete="new-password">
        </div>
        <p class="hint-peligro">El sistema no guarda la frase. Sin ella el respaldo no se puede abrir: anótala en la bóveda de contraseñas de la oficina.</p>
        <button type="submit" name="generar_respaldo" class="btn btn-sm mt-3">Generar ahora</button>
      </form>
    <?= vfCerrar() ?>

    <?php foreach ($respaldos as $r): $idR = (int)$r['id_respaldo']; ?>
      <?= vfAbrir('vf-verificar-' . $idR, 'Verificar respaldo', 'Comprueba la huella del archivo, lo descifra y prueba restaurarlo en una base temporal.', $abrirForm === 'verificar-' . $idR) ?>
        <?php if ($abrirForm === 'verificar-' . $idR): ?><div class="error mb-3"><?= h($flash['mensaje']) ?></div><?php endif; ?>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="form" value="verificar-<?= $idR ?>">
          <input type="hidden" name="id" value="<?= $idR ?>">
          <div class="field">
            <label for="frase-<?= $idR ?>">Frase de cifrado de este respaldo</label>
            <input type="password" id="frase-<?= $idR ?>" name="frase" required autocomplete="off">
          </div>
          <button type="submit" name="verificar_respaldo" class="btn btn-sm mt-3">Verificar</button>
        </form>
      <?= vfCerrar() ?>
    <?php endforeach; ?>
