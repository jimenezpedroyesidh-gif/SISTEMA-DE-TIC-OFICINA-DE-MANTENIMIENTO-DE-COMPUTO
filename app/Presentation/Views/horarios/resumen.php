<?php
/**
 * Vista de horarios — título, leyenda y contadores.
 * Solo presentación: recibe los datos ya preparados por HorariosPage.php /
 * HorarioCalendario (bloques, filtroPeriodo). Solo en la vista por salas (admin, técnico, secretaria con permiso). Sin SQL ni reglas de negocio.
 */
?>
<div class="hg-info-secundaria">
  <h2 class="panel-title">Horario semanal por sala — periodo <?= h($filtroPeriodo === 'TODOS' ? 'histórico (todos)' : $filtroPeriodo) ?></h2>
  <div class="hg-leyenda">
    <span class="hg-leyenda-item"><i class="hg-swatch bloque-clase"></i> Clase (profesor)</span>
    <span class="hg-leyenda-item"><i class="hg-swatch bloque-beca"></i> Turno beca-trabajo</span>
    <span class="hg-leyenda-item"><i class="hg-swatch bloque-tecnico"></i> Turno técnico</span>
    <span class="hg-leyenda-item"><i class="hg-swatch bloque-mantenimiento"></i> Mantenimiento de sala</span>
    <span class="hg-leyenda-item"><i class="hg-swatch bloque-prestamo"></i> Préstamo de sala/equipo</span>
    <span class="hg-leyenda-item"><i class="hg-swatch hg-swatch-ahora"></i> En curso ahora</span>
  </div>
</div>

<div class="stat-row hg-resumen">
  <div class="stat-card">
    <span class="stat-value"><?= count(array_filter($bloques, fn($b) => $b['tipo'] === 'CLASE')) ?></span>
    <span class="stat-label">Clases esta semana</span>
  </div>
  <div class="stat-card">
    <span class="stat-value"><?= count(array_filter($bloques, fn($b) => $b['tipo'] === 'TURNO_BECA')) ?></span>
    <span class="stat-label">Turnos de beca-trabajo</span>
  </div>
  <div class="stat-card">
    <span class="stat-value"><?= count(array_filter($bloques, fn($b) => $b['tipo'] === 'TURNO_TECNICO')) ?></span>
    <span class="stat-label">Turnos de técnico</span>
  </div>
  <div class="stat-card">
    <span class="stat-value"><?= count(array_filter($bloques, fn($b) => $b['tipo'] === 'MANTENIMIENTO')) ?></span>
    <span class="stat-label">Mantenimientos programados</span>
  </div>
  <div class="stat-card">
    <span class="stat-value"><?= count(array_filter($bloques, fn($b) => $b['tipo'] === 'PRESTAMO')) ?></span>
    <span class="stat-label">Préstamos de sala/equipo</span>
  </div>
</div>
