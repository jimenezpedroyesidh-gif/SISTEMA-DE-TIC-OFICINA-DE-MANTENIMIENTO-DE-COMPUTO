<?php
/**
 * Bandeja de un grupo del panel técnico (Departamento de Sistemas / Otras dependencias): UNA sola tabla con
 * tickets normales y de profesor juntos, partida en «Pendientes» y «Firmados», 5 por página cada una con
 * botones numéricos (como una bandeja de correo). Cada fila se pinta con su parcial según '_tipo'.
 *
 * Variables: $grupo = ['id', 'titulo', 'ayuda'], $bandeja = TicketBandeja::cargar(), más lo que usan las filas
 * ($tecnico_id, $becasActivas, $todasLasSalas, $comentariosPorTicket, $interaccion, $equiposYa*, $equiposPorSalaTk).
 */
$columnasBandeja = ['Ticket', 'Solicitud', 'Personas', 'Estado', 'Acciones'];
$listasBandeja = [
    ['clave' => 'pendientes', 'titulo' => 'Pendientes', 'vacio' => 'No hay tickets pendientes. ¡Todo al día!', 'param' => 'pagina_' . $grupo['id'] . '_pend'],
    ['clave' => 'firmados', 'titulo' => 'Firmados', 'vacio' => 'Todavía no hay tickets firmados.', 'param' => 'pagina_' . $grupo['id'] . '_firm'],
];
?>
<section class="panel-box" id="tickets-<?= h($grupo['id']) ?>">
  <div class="sec-title">
    <h2 class="panel-title"><?= h($grupo['titulo']) ?> <span class="hint">(<?= h($grupo['ayuda']) ?>)</span></h2>
  </div>

  <?php foreach ($listasBandeja as $lista):
      $res = $bandeja[$lista['clave']];
      $ancla = 'bandeja-' . $grupo['id'] . '-' . $lista['clave']; ?>
  <div class="bandeja" id="<?= h($ancla) ?>">
    <div class="bandeja-head">
      <h3 class="panel-subtitle m-0"><?= h($lista['titulo']) ?> <span class="bandeja-total"><?= (int)$res['total'] ?></span></h3>
      <span class="hint m-0"><?= h(TicketBandeja::rango($res)) ?></span>
    </div>
    <table class="data tk-lista">
      <thead>
        <tr><?php foreach ($columnasBandeja as $col): ?><th><?= h($col) ?></th><?php endforeach; ?></tr>
      </thead>
      <tbody>
        <?php if (!$res['datos']): ?>
          <tr><td colspan="<?= count($columnasBandeja) ?>" class="text-muted"><?= h($lista['vacio']) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($res['datos'] as $fila) {
            if ($fila['_tipo'] === 'prof') { $p = $fila; include PARCIALES_DIR . '/_fila_ticket_profesor.php'; }
            else { $t = $fila; include PARCIALES_DIR . '/_fila_ticket_normal.php'; }
        } ?>
      </tbody>
    </table>
    <?= renderControlesPaginacion($res['pagina'], $res['total_paginas'], [], $lista['param'], $ancla) ?>
  </div>
  <?php endforeach; ?>
</section>
