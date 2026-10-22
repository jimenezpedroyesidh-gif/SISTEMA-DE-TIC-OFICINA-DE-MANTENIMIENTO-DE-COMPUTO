<?php /** Pestaña Tendencias. */ ?>
  <?php /* Tendencias (Módulo 3): tickets por semana + tiempo promedio general */ ?>
  <section class="panel" id="tendencias">
    <h2>Tendencias</h2>
    <div class="stat-row">
      <div class="stat-card">
        <span class="stat-value"><?= $tiempoPromedioGeneral !== null ? round($tiempoPromedioGeneral, 1) . ' h' : '—' ?></span>
        <span class="stat-label">Tiempo promedio de resolución (tickets normales cerrados)</span>
      </div>
    </div>
    <div class="charts">
      <div class="chart-card">
        <h3>Tickets creados por semana (últimas 8 semanas)</h3>
        <?php if (empty($tendenciaSemanal)): ?>
          <p class="text-muted">Sin datos todavía.</p>
        <?php else: ?>
          <canvas id="chartTendenciaSemanal"></canvas>
        <?php endif; ?>
      </div>
    </div>
    <p class="hint">Horas promedio por técnico y ranking de salas/modelos que más fallan ya están en la pestaña Estadísticas — esta sección se enfoca en la evolución en el tiempo.</p>
  </section>

