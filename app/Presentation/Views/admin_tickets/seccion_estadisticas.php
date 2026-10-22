<?php /** Pestaña Estadísticas. */ ?>
  <?php /* Estadísticas */ ?>
  <section class="panel" id="estadisticas">
    <h2>Estadísticas de tickets</h2>
    <div class="charts">
      <div class="chart-card">
        <h3>Estado general</h3>
        <canvas id="chartEstados"></canvas>
      </div>
      <div class="chart-card">
        <h3>Rendimiento por técnico</h3>
        <canvas id="chartTecnicos"></canvas>
      </div>
    </div>

    <h2 class="mt-4">Tiempo de resolución</h2>
    <div class="stat-row">
      <div class="stat-card">
        <span class="stat-value"><?= $tiempoTickets['promedio_horas'] !== null ? round($tiempoTickets['promedio_horas'],1) . ' h' : '—' ?></span>
        <span class="stat-label">Promedio tickets normales (<?= (int)$tiempoTickets['total_resueltos'] ?> resueltos)</span>
      </div>
      <div class="stat-card">
        <span class="stat-value"><?= $tiempoTicketsProfesor['promedio_horas'] !== null ? round($tiempoTicketsProfesor['promedio_horas'],1) . ' h' : '—' ?></span>
        <span class="stat-label">Promedio tickets de profesor (<?= (int)$tiempoTicketsProfesor['total_resueltos'] ?> resueltos)</span>
      </div>
    </div>
    <div class="charts">
      <div class="chart-card">
        <h3>Horas promedio por técnico</h3>
        <?php if (empty($tiempoPorTecnico)): ?>
          <p class="text-muted">Todavía no hay tickets cerrados con técnico asignado.</p>
        <?php else: ?>
          <canvas id="chartTiempoTecnico"></canvas>
        <?php endif; ?>
      </div>
      <div class="chart-card">
        <h3>Salas con más reportes</h3>
        <?php if (empty($rankingSalas)): ?>
          <p class="text-muted">Sin datos todavía.</p>
        <?php else: ?>
          <canvas id="chartRankingSalas"></canvas>
        <?php endif; ?>
      </div>
    </div>

    <h2 class="mt-4">Modelos que más fallan</h2>
    <p class="hint">Dos fuentes distintas — no deberían sumarse entre sí: la de texto es lo que cada solicitante escribió a mano (puede repetirse el mismo modelo con variaciones de escritura); la de inventario son piezas realmente retiradas por daño dentro del flujo estructurado.</p>
    <div class="charts">
      <div class="chart-card">
        <h3>Según texto libre del ticket (marca/modelo)</h3>
        <?php if (empty($rankingModelosTexto)): ?>
          <p class="text-muted">Sin datos todavía.</p>
        <?php else: ?>
          <canvas id="chartModelosTexto"></canvas>
        <?php endif; ?>
      </div>
      <div class="chart-card">
        <h3>Según inventario (componentes retirados por daño)</h3>
        <?php if (empty($rankingModelosComponentes)): ?>
          <p class="text-muted">Sin datos todavía.</p>
        <?php else: ?>
          <canvas id="chartModelosComponentes"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </section>

