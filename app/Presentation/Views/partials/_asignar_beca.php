<?php
/**
 * Parcial: asignar / quitar una beca de trabajo como EJECUTORA del ticket.
 * Se incluye desde _fila_ticket_normal.php y _fila_ticket_profesor.php, dentro de su ventana flotante «Beca ejecutora»
 * (siempre desde tickets.php, que ya cargó h(), csrfField() y
 * $becasActivas).
 *
 * Variables que espera:
 *   $tablaAsignar  'tickets' | 'tickets_profesor'
 *   $filaAsignar   fila del ticket (con beca_id, beca_nombre, tecnico_id,
 *                  estado, firmado)
 *   $tecnico_id    id del técnico logueado
 *
 * Se muestra solo si el ticket está abierto y es libre o del propio técnico.
 * El ticket en "Solucionado" no se reasigna (primero se aprueba/rechaza).
 */
$abiertoParaAsignar = empty($filaAsignar['firmado'])
    && !in_array($filaAsignar['estado'], [TicketWorkflow::APROBADO, TicketWorkflow::CERRADO, TicketWorkflow::DESCARTADO, TicketWorkflow::SOLUCIONADO], true)
    && (empty($filaAsignar['tecnico_id']) || (int)$filaAsignar['tecnico_id'] === (int)$tecnico_id);
$becaActualId = (int)($filaAsignar['beca_id'] ?? 0);
?>
<?php if ($becaActualId): ?>
  <p class="hint mt-6px m-0">Beca asignada: <strong><?= h($filaAsignar['beca_nombre'] ?? ('#' . $becaActualId)) ?></strong></p>
<?php endif; ?>
<?php if ($abiertoParaAsignar && !empty($becasActivas)): ?>
  <div class="mt-6px">
    <form class="mt-1" method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="tabla_ticket" value="<?= h($tablaAsignar) ?>">
      <input type="hidden" name="ticket_id" value="<?= (int)$filaAsignar['id'] ?>">
      <select name="beca_id" required>
        <option value="">— elegir beca —</option>
        <?php foreach ($becasActivas as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= $becaActualId === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['nombre_completo']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm" name="asignar_beca">Asignar</button>
    </form>
    <?php if ($becaActualId && (int)$filaAsignar['tecnico_id'] === (int)$tecnico_id): ?>
      <form class="mt-1" method="POST" data-confirm="¿Quitar la beca de este ticket?">
        <?= csrfField() ?>
        <input type="hidden" name="tabla_ticket" value="<?= h($tablaAsignar) ?>">
        <input type="hidden" name="ticket_id" value="<?= (int)$filaAsignar['id'] ?>">
        <button class="btn btn-danger btn-sm" name="quitar_beca">Quitar beca</button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>
