/* Paneles de tickets: Administración, Solicitante y Profesor.
   Requiere assets/app.js. Datos de PHP por atributos:
     [data-seccion-inicial="activos|solucionados|sistemas"]  (SolicitantePanel)
     nav[data-panel-hash]                                    (AdminPanel: abre la sección de #hash) */
(function () {
  'use strict';

  // Administración: una sola <section> activa
  function showSection(id) {
    document.querySelectorAll('section').forEach(function (s) { s.classList.remove('active'); });
    const el = document.getElementById(id);
    if (el) el.classList.add('active');
  }
  UFPS.accion('panel.showSection', function (el) { showSection(el.getAttribute('data-arg')); });

  // Solicitante: activos / solucionados / sistemas
  function mostrarSeccion(seccion) {
    ['activos', 'solucionados', 'sistemas'].forEach(function (id) {
      const el = document.getElementById(id);
      if (el) el.classList.remove('active');
    });
    const destino = document.getElementById(seccion);
    if (destino) destino.classList.add('active');
  }
  UFPS.accion('panel.mostrarSeccion', function (el) { mostrarSeccion(el.getAttribute('data-arg')); });

  // Profesor: mostrar/ocultar solucionados
  UFPS.accion('panel.toggleSolucionados', function () {
    const sec = document.getElementById('tickets-solucionados');
    if (sec) sec.hidden = !sec.hidden;
  });

  document.addEventListener('DOMContentLoaded', function () {
    const ini = document.querySelector('[data-seccion-inicial]');
    if (ini) mostrarSeccion(ini.getAttribute('data-seccion-inicial'));
    if (document.querySelector('[data-panel-hash]')) {
      const h = (location.hash || '').replace('#', '');
      let el = h ? document.getElementById(h) : null;
      // #tk-N (fila de un ticket, p. ej. tras escribir en su conversación): abre la sección que la contiene.
      if (el && el.tagName !== 'SECTION') el = el.closest('section.panel');
      if (el) showSection(el.id);
    }
  });
})();
