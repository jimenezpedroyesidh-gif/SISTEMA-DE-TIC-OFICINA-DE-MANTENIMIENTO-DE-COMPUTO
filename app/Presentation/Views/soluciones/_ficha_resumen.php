<?php
/**
 * Ficha de solución técnica → «Resumen y alcance»: qué es, qué resuelve y dónde debe aplicarse (salas completas o
 * equipos puntuales). Variables del controlador: $s, $objetivos, $salasElegibles, $permisos, $volvio, $formulario.
 */
$volvioObj = $volvio && $formulario === 'objetivos';
$salasMarcadas = $volvioObj ? array_map('intval', (array)($_POST['salas'] ?? [])) : [];
$salasConObjetivo = array_map('intval', array_column(array_filter($objetivos, fn($o) => $o['id_equipo'] === null), 'id_sala'));
?>
<?php if ($s['version_vigente'] === null && $s['estado'] !== 'RETIRADA'): ?>
  <p class="nota-info mb-3">Esta solución todavía no tiene una versión aprobada. El técnico sube los archivos en <a href="<?= h($urlFicha) ?>&amp;sec=versiones">Versiones y archivos</a> y el administrador la aprueba; desde ese momento queda activa y se puede programar.</p>
<?php endif; ?>
<div class="panel-grid">
  <section class="panel-box">
    <h2 class="panel-title">Descripción</h2>
    <p><?= nl2br(h($s['descripcion'])) ?></p>
    <h3 class="sec-title">Objetivo</h3>
    <p><?= nl2br(h($s['objetivo'])) ?></p>
  </section>

  <section class="panel-box">
    <h2 class="panel-title">
      Dónde se aplica
      <?php if ($permisos['gestionar'] && $s['estado'] !== 'RETIRADA'): ?><button type="button" class="btn btn-sm w-auto" data-vf-abrir="vf-objetivos">+ Agregar salas o equipos</button><?php endif; ?>
    </h2>
    <?php if (!$objetivos): ?>
      <p class="text-muted">Todavía no se definió dónde debe aplicarse. Agrega salas completas (sus equipos de cómputo) o equipos puntuales.</p>
    <?php else: ?>
    <div class="tabla-scroll">
      <table class="data">
        <thead><tr><th>Lugar</th><th>Equipos</th><th>Agregado</th><?php if ($permisos['gestionar']): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
          <?php foreach ($objetivos as $o): ?>
            <tr>
              <td data-label="Lugar">
                <?php if ($o['equipo_codigo'] !== null): ?>Equipo <strong><?= h($o['equipo_codigo']) ?></strong><br><span class="hint">registrado en <?= h($o['nombre_sala']) ?></span>
                <?php else: ?>Sala <strong><?= h($o['abreviatura'] ? $o['abreviatura'] . ' — ' . $o['nombre_sala'] : $o['nombre_sala']) ?></strong><br><span class="hint">equipos de cómputo operativos</span><?php endif; ?>
              </td>
              <td data-label="Equipos"><?= (int)$o['equipos'] ?></td>
              <td data-label="Agregado"><?= h($o['creador_nombre']) ?><br><span class="hint"><?= h($fh($o['creado_en'])) ?></span></td>
              <?php if ($permisos['gestionar']): ?>
                <td class="acciones">
                  <form method="POST" class="m-0" data-confirm="¿Quitar este lugar? Los equipos donde ya está aplicada siguen en la lista de despliegue.">
                    <?= csrfField() ?>
                    <input type="hidden" name="accion_st" value="quitar_objetivo">
                    <input type="hidden" name="id_solucion" value="<?= (int)$s['id_solucion'] ?>">
                    <input type="hidden" name="id_objetivo" value="<?= (int)$o['id_objetivo'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm">Quitar</button>
                  </form>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint">Un equipo que entra a una de estas salas queda incluido solo; si sale, deja de contar para la cobertura.</p>
    <?php endif; ?>
  </section>
</div>

<?php if ($permisos['gestionar'] && $s['estado'] !== 'RETIRADA'): ?>
<?= vfAbrir('vf-objetivos', 'Agregar dónde se aplica', 'Una sala completa incluye sus equipos de cómputo operativos (con torre, portátil o todo en uno).', $volvioObj, 'lg') ?>
  <?php if ($volvioObj): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="accion_st" value="objetivos">
    <input type="hidden" name="id_solucion" value="<?= (int)$s['id_solucion'] ?>">
    <fieldset class="field">
      <legend>Salas completas</legend>
      <div class="lista-equipos">
        <?php foreach ($salasElegibles as $sala): $ya = in_array((int)$sala['id_sala'], $salasConObjetivo, true); ?>
          <label>
            <input type="checkbox" name="salas[]" value="<?= (int)$sala['id_sala'] ?>" <?= $ya ? 'disabled' : (in_array((int)$sala['id_sala'], $salasMarcadas, true) ? 'checked' : '') ?>>
            <?= h($sala['abreviatura'] ?: $sala['nombre_sala']) ?><?= $ya ? ' ✓' : '' ?>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="hint">Las marcadas con ✓ ya están incluidas.</p>
    </fieldset>
    <div class="field">
      <label for="obj-equipos">Equipos puntuales (opcional)</label>
      <textarea id="obj-equipos" name="equipos" rows="2" placeholder="Códigos de inventario separados por coma o en líneas: PC-A101-01, PC-A101-02"><?= h($volvioObj ? (string)($_POST['equipos'] ?? '') : '') ?></textarea>
      <p class="hint">Útil para una configuración especial en pocos equipos. El código es el que aparece en el plano de la sala y en su QR.</p>
    </div>
    <button type="submit" class="btn">Agregar</button>
  </form>
<?= vfCerrar() ?>
<?php endif; ?>
