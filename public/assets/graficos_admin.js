/* Gráficos Chart.js del panel de administración. Los datos llegan en <script type="application/json"
   id="graficos-datos"> (ver Views/admin_tickets/scripts_graficos.php); los colores salen de tokens.css. */
(function () {
  'use strict';
  const fuente = document.getElementById('graficos-datos');
  if (!fuente || typeof Chart === 'undefined') return;
  const D = JSON.parse(fuente.textContent);

  const cssVar = function (n) { return getComputedStyle(document.documentElement).getPropertyValue(n).trim(); };
  const COLOR_PRIMARY = cssVar('--color-primary'), COLOR_WARNING = cssVar('--color-warning'),
      COLOR_INFO = cssVar('--color-info'), COLOR_SUCCESS = cssVar('--color-success');

  function grafico(id, config) {
    const el = document.getElementById(id);
    if (el) new Chart(el, config);
  }
  function barra(id, d, etiqueta, color, horizontal, conLeyenda) {
    if (!d) return;
    const opciones = { responsive: true, plugins: { legend: { display: false } } };
    if (horizontal) opciones.indexAxis = 'y';
    grafico(id, {
      type: 'bar',
      data: { labels: d.labels, datasets: [{ label: etiqueta, data: d.data, backgroundColor: color }] },
      options: opciones
    });
  }

  grafico('chartEstados', {
    type: 'pie',
    data: { labels: D.estados.labels, datasets: [{ data: D.estados.data, backgroundColor: [COLOR_PRIMARY, COLOR_WARNING, COLOR_SUCCESS] }] }
  });

  grafico('chartTecnicos', {
    type: 'bar',
    data: {
      labels: D.tecnicos.labels,
      datasets: [
        { label: 'Pendientes', data: D.tecnicos.pendientes, backgroundColor: COLOR_WARNING },
        { label: 'En Proceso', data: D.tecnicos.en_proceso, backgroundColor: COLOR_INFO },
        { label: 'Resueltos', data: D.tecnicos.resueltos, backgroundColor: COLOR_SUCCESS }
      ]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
  });

  barra('chartTiempoTecnico', D.tiempoTecnico, 'Horas promedio', COLOR_INFO, true);
  barra('chartRankingSalas', D.rankingSalas, 'Reportes', COLOR_PRIMARY, true);
  barra('chartModelosTexto', D.modelosTexto, 'Tickets', COLOR_WARNING, true);
  barra('chartTendenciaSemanal', D.tendenciaSemanal, 'Tickets creados', COLOR_INFO, false);
  barra('chartModelosComponentes', D.modelosComponentes, 'Retiros por daño', COLOR_SUCCESS, true);
})();
