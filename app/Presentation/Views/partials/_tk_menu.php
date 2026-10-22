<?php
/**
 * Menú «Más ▾» de una fila de ticket: agrupa las acciones secundarias para que la fila quede compacta
 * (en celular y en escritorio). Las ventanas flotantes (vfAbrir) van FUERA del menú, en la misma celda.
 *
 * Variables:
 *   $menuItems  lista de acciones; cada una es uno de:
 *     ['vf' => 'id-ventana', 'texto' => '…']                        abre una ventana flotante
 *     ['form' => 'nombre_boton', 'texto' => '…', 'campos' => [...], 'confirm' => '¿…?', 'peligro' => bool]
 *     ['href' => 'url', 'texto' => '…', 'nueva' => bool]              enlace (p. ej. PDF)
 *     ['nota' => '…']                                                 texto informativo
 *   $menuTexto  texto del botón (por defecto «Más ▾»)
 */
$menuItems = array_values(array_filter($menuItems ?? []));
if (!$menuItems) return;
?>
<details class="menu-acciones" data-menu>
  <summary class="btn btn-ghost btn-sm"><?= h($menuTexto ?? 'Más ▾') ?></summary>
  <div class="menu-acciones-lista">
    <?php foreach ($menuItems as $it): ?>
      <?php if (isset($it['vf'])): ?>
        <button type="button" class="menu-acciones-item" data-vf-abrir="<?= h($it['vf']) ?>"><?= h($it['texto']) ?></button>
      <?php elseif (isset($it['form'])): ?>
        <form method="POST" class="m-0"<?= !empty($it['confirm']) ? ' data-confirm="' . h($it['confirm']) . '"' : '' ?>>
          <?= csrfField() ?>
          <?php foreach (($it['campos'] ?? []) as $n => $v): ?><input type="hidden" name="<?= h($n) ?>" value="<?= h((string)$v) ?>"><?php endforeach; ?>
          <button class="menu-acciones-item<?= !empty($it['peligro']) ? ' tk-item-peligro' : '' ?>" name="<?= h($it['form']) ?>"><?= h($it['texto']) ?></button>
        </form>
      <?php elseif (isset($it['href'])): ?>
        <a class="menu-acciones-item" href="<?= h($it['href']) ?>"<?= !empty($it['nueva']) ? ' target="_blank" rel="noopener"' : '' ?>><?= h($it['texto']) ?></a>
      <?php elseif (isset($it['nota'])): ?>
        <span class="menu-acciones-item text-muted"><?= h($it['nota']) ?></span>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</details>
<?php unset($menuItems, $menuTexto); ?>
