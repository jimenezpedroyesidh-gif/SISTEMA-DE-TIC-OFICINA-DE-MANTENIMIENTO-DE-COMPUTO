<?php
/**
 * Bandeja unificada del panel admin (Departamento / Otras dependencias): solicitantes/QR y profesores en UNA
 * tabla, partida en Pendientes y Firmados, 5 por página cada una con botones numéricos (TicketBandeja).
 * Entradas: $bandeja (TicketBandeja::desdeListas), $prefijoPagina ('depto' | 'ext'), $seccionAncla, $interaccion.
 */
$listasBandeja = [
    ['clave' => 'pendientes', 'titulo' => 'Pendientes', 'vacio' => 'No hay tickets pendientes.'],
    ['clave' => 'firmados', 'titulo' => 'Firmados', 'vacio' => 'Todavía no hay tickets firmados.'],
];
foreach ($listasBandeja as $lista):
    $res = $bandeja[$lista['clave']];
    $ancla = $seccionAncla . '-' . $lista['clave']; ?>
  <div class="bandeja" id="<?= h($ancla) ?>">
    <div class="bandeja-head">
      <h3 class="panel-subtitle m-0"><?= h($lista['titulo']) ?> <span class="bandeja-total"><?= (int)$res['total'] ?></span></h3>
      <span class="hint m-0"><?= h(TicketBandeja::rango($res)) ?></span>
    </div>
    <table class="data tk-lista">
      <thead><tr><th>Ticket</th><th>Solicitud</th><th>Personas</th><th>Estado</th><th>Acciones</th></tr></thead>
      <tbody>
        <?php if (!$res['datos']): ?><tr><td colspan="5" class="text-muted"><?= h($lista['vacio']) ?></td></tr><?php endif; ?>
        <?php foreach ($res['datos'] as $fila) {
            if ($fila['_tipo'] === 'prof') { $p = $fila; include PARCIALES_DIR . '/_fila_ticket_admin_profesor.php'; }
            else { $t = $fila; include PARCIALES_DIR . '/_fila_ticket_admin_solicitante.php'; }
        } ?>
      </tbody>
    </table>
    <?= renderControlesPaginacion($res['pagina'], $res['total_paginas'], [], 'pagina_' . $prefijoPagina . '_' . ($lista['clave'] === 'pendientes' ? 'pend' : 'firm'), $ancla) ?>
  </div>
<?php endforeach; ?>
