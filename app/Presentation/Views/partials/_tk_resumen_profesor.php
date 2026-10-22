<?php
/**
 * Resumen compacto de un ticket de profesor (celda «Solicitud»): título, sala, descripción en dos líneas y el
 * detalle completo plegado en «Ver detalle».
 *
 * Variables: $p (fila de tickets_profesor; opcionalmente horario_* de la consulta del panel).
 */
$diasCortosRes = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
$duracionesRes = TicketProfesorCreacionService::DURACIONES_PRESTAMO;
$esReq = $p['tipo_ticket'] === 'requerimiento';
$subtipo = (string)($p['subtipo_requerimiento'] ?? '');
$esPrestamo = in_array($subtipo, ['PRESTAMO_SALA', 'PRESTAMO_EQUIPO'], true);

if ($esReq) {
    $tituloRes = [
        'INSTALACION_SOFTWARE' => 'Instalación de software', 'CAMBIO_SALA' => 'Cambio de sala',
        'PRESTAMO_SALA' => 'Préstamo de sala', 'PRESTAMO_EQUIPO' => 'Préstamo de equipo',
    ][$subtipo] ?? ($subtipo !== '' ? etiquetaValor($subtipo) : 'Requerimiento');
    if ($subtipo === 'INSTALACION_SOFTWARE' && !empty($p['programa'])) $tituloRes .= ': ' . $p['programa'];
    $salaRes = $subtipo === 'CAMBIO_SALA'
        ? trim(($p['sala_actual_nombre'] ?? '') . ' → ' . ($p['sala_requerimiento'] ?? ''), ' →')
        : (string)($p['sala_requerimiento'] ?? '');
    $textoRes = $p['descripcion_actividad'] ?: ($p['motivo'] ?: ($p['para_que'] ?? ''));
} else {
    $tituloRes = 'Daño o error en sala';
    $salaRes = trim(($p['sala_danio'] ?: $p['salon']) . (($p['salon'] && $p['salon'] !== $p['sala_danio']) ? ' · ' . $p['salon'] : ''));
    $textoRes = (string)($p['descripcion_problema'] ?? '');
}
$detalle = [];
if ($esReq) {
    if (!empty($p['programa']) && $subtipo !== 'INSTALACION_SOFTWARE') $detalle['Programa'] = $p['programa'];
    if (!empty($p['horario_dia'])) {
        $detalle['Franja'] = ($diasCortosRes[$p['horario_dia']] ?? '') . ' ' . substr((string)$p['horario_hora_inicio'], 0, 5) . '–' . substr((string)$p['horario_hora_fin'], 0, 5) . ' · ' . ($p['horario_sala'] ?? '');
    }
    if (!empty($p['fecha_requerida'])) $detalle['Fecha requerida'] = $p['fecha_requerida'];
    if (!empty($p['motivo'])) $detalle[$esPrestamo ? 'Motivo / objeto' : 'Motivo'] = $p['motivo'];
    if ($esPrestamo) {
        if (!empty($p['cantidad_alumnos'])) $detalle['Alumnos'] = $p['cantidad_alumnos'];
        if (!empty($p['para_que'])) $detalle['Para qué'] = $p['para_que'];
        if (!empty($p['duracion_prestamo'])) $detalle['Duración'] = $duracionesRes[$p['duracion_prestamo']] ?? $p['duracion_prestamo'];
        $detalle['Devolución'] = ($p['fecha_devolucion_prevista'] ?? '-') . ($p['devuelto'] ? ' · ya devuelto' : ' · pendiente');
    }
    if (!empty($p['descripcion_actividad'])) $detalle['Actividad'] = $p['descripcion_actividad'];
} else {
    $detalle['Problema'] = $p['descripcion_problema'];
}
if (!empty($p['solucion'])) $detalle['Solución'] = $p['solucion'];
?>
<div class="tk-titulo"><?= h($tituloRes) ?></div>
<?php if ($salaRes !== ''): ?><div class="tk-sub"><?= h($salaRes) ?></div><?php endif; ?>
<?php if ($textoRes !== ''): ?><p class="tk-desc"><?= h($textoRes) ?></p><?php endif; ?>
<?php if ($esPrestamo): ?>
  <span class="tk-ind <?= $p['devuelto'] ? '' : 'alerta' ?>"><?= $p['devuelto'] ? 'Devuelto' : 'Devolución pendiente' ?></span>
<?php endif; ?>
<details class="tk-detalle">
  <summary>Ver detalle</summary>
  <dl>
    <?php foreach ($detalle as $et => $val): ?>
      <dt><?= h($et) ?></dt><dd><?= nl2br(h((string)$val)) ?></dd>
    <?php endforeach; ?>
  </dl>
</details>
<?php unset($diasCortosRes, $duracionesRes, $esReq, $subtipo, $esPrestamo, $tituloRes, $salaRes, $textoRes, $detalle); ?>
