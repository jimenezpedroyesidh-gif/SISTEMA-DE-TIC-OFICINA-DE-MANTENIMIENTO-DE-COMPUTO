<?php
/**
 * Las dos columnas de la pantalla (también se devuelven solas con ?parcial=1 para la actualización automática).
 * Izquierda: mantenimientos programados / en proceso. Derecha: errores de sala por QR (con y sin sesión).
 */
$tiposMant = HorarioCalendario::TIPOS_MANTENIMIENTO;
$origenError = ['QR_PROFESOR' => 'QR · profesor con sesión', 'PANEL_PROFESOR' => 'Panel del profesor', 'QR_ANONIMO' => 'QR sin sesión'];
$claseEstado = static function (string $estado): string {
    return match ($estado) {
        TicketWorkflow::EN_ESPERA => 'tv-est-espera',
        TicketWorkflow::EN_PROCESO => 'tv-est-proceso',
        TicketWorkflow::SOLUCIONADO, TicketWorkflow::LISTO_SIN_VALIDAR => 'tv-est-validar',
        TicketWorkflow::RECHAZADO => 'tv-est-rechazado',
        default => '',
    };
};
$hace = static fn(string $f): string => NotificationService::haceCuanto($f);
$sinTomar = count(array_filter($errores, fn($e) => empty($e['tecnico']) && empty($e['beca'])));
$enProceso = count(array_filter($mantenimientos, fn($m) => $m['estado'] === 'EN_PROCESO'));
?>
<section class="tv-col" aria-labelledby="tv-t-mant">
  <h2 class="tv-col-titulo" id="tv-t-mant">
    Mantenimientos programados
    <span class="tv-contador"><?= count($mantenimientos) ?><?= $enProceso ? ' · ' . $enProceso . ' en proceso' : '' ?></span>
  </h2>
  <?php if (!$mantenimientos): ?>
    <p class="tv-vacio">No hay mantenimientos programados.</p>
  <?php endif; ?>
  <ul class="tv-lista">
    <?php foreach ($mantenimientos as $m):
        $total = (int)$m['total_equipos']; $hechos = (int)$m['realizados'];
        $ts = strtotime((string)$m['fecha_programada']);
        $vencido = $m['estado'] === 'PROGRAMADO' && $ts < strtotime('today');
    ?>
      <li class="tv-item<?= $m['estado'] === 'EN_PROCESO' ? ' tv-item-activo' : '' ?><?= $vencido ? ' tv-item-vencido' : '' ?>">
        <div class="tv-item-fila">
          <span class="tv-sala"><?= h($m['abreviatura'] ?: $m['nombre_sala']) ?></span>
          <span class="tv-chip <?= $m['estado'] === 'EN_PROCESO' ? 'tv-est-proceso' : 'tv-est-espera' ?>"><?= $m['estado'] === 'EN_PROCESO' ? TicketWorkflow::EN_PROCESO : ($vencido ? 'Atrasado' : 'Programado') ?></span>
        </div>
        <div class="tv-item-titulo"><?= h($tiposMant[$m['tipo']] ?? $m['tipo']) ?> · <?= h(date('d/m H:i', $ts)) ?></div>
        <div class="tv-item-meta">
          <span>Técnico: <strong><?= $m['responsable'] ? h($m['responsable']) : 'sin asignar' ?></strong></span>
          <?php if (!empty($m['participantes'])): ?><span>Trabajando: <?= h($m['participantes']) ?></span><?php endif; ?>
        </div>
        <?php if ($total > 0): ?>
          <div class="tv-avance" role="img" aria-label="<?= $hechos ?> de <?= $total ?> equipos realizados">
            <progress class="tv-avance-barra" max="<?= $total ?>" value="<?= $hechos ?>"></progress>
            <span class="tv-avance-txt"><?= $hechos ?>/<?= $total ?><?= (int)$m['incompletos'] ? ' · <b class="tv-incompleto">' . (int)$m['incompletos'] . ' incompleto(s)</b>' : '' ?></span>
          </div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>

<section class="tv-col" aria-labelledby="tv-t-err">
  <h2 class="tv-col-titulo" id="tv-t-err">
    Errores de sala (QR)
    <span class="tv-contador"><?= count($errores) ?><?= $sinTomar ? ' · <b class="tv-alerta">' . $sinTomar . ' sin tomar</b>' : '' ?></span>
  </h2>
  <?php if (!$errores): ?>
    <p class="tv-vacio">No hay errores de sala abiertos.</p>
  <?php endif; ?>
  <ul class="tv-lista">
    <?php foreach ($errores as $e): $tomado = !empty($e['tecnico']) || !empty($e['beca']); ?>
      <li class="tv-item<?= $tomado ? '' : ' tv-item-alerta' ?>">
        <div class="tv-item-fila">
          <span class="tv-sala"><?= h($e['abreviatura'] ?: ($e['nombre_sala'] ?: 'Sala')) ?></span>
          <span class="tv-chip <?= $claseEstado((string)$e['estado']) ?>"><?= h($e['estado']) ?></span>
        </div>
        <div class="tv-item-titulo"><?= h(mb_strimwidth((string)$e['descripcion'], 0, 110, '…')) ?></div>
        <div class="tv-item-meta">
          <span>Responde: <strong><?php
            $quien = array_filter([$e['tecnico'] ? 'Téc. ' . $e['tecnico'] : '', $e['beca'] ? 'Beca ' . $e['beca'] : '']);
            echo $quien ? h(implode(' · ', $quien)) : '<span class="tv-alerta">nadie aún</span>';
          ?></strong></span>
          <span><?= h($origenError[$e['origen']] ?? $e['origen']) ?> · <?= h($e['reportado_por']) ?> · <?= h($hace((string)$e['fecha_creacion'])) ?></span>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<p class="tv-pie">Actualizado <?= h($actualizado) ?> · se actualiza solo cada 30 segundos</p>
