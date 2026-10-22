/* Salas y códigos QR: detección de la ubicación de GLPI mientras se escribe el nombre de la sala.
   Misma regla que GlpiUbicaciones::detectar() en el servidor: coincidencia exacta del nombre de la
   ubicación (último tramo, p. ej. «SA401» de «Aula Sur > SA401») con el nombre o la abreviatura; si no,
   una única ubicación que la contenga. Si el usuario elige a mano otra ubicación, ya no se toca. */
(function () {
  'use strict';
  function norm(s) {
    return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]/g, '');
  }
  function detectar(form) {
    const sel = form.querySelector('select[data-glpi-auto]');
    if (!sel || sel.dataset.manual === '1') return;
    const claves = Array.prototype.map.call(form.querySelectorAll('[data-glpi-fuente]'), function (i) { return norm(i.value); })
      .filter(function (k) { return k.length >= 2; });
    const aviso = form.querySelector('[data-glpi-aviso]');
    const opciones = Array.prototype.filter.call(sel.options, function (o) { return o.value !== ''; });
    let elegida = null;
    opciones.forEach(function (o) { if (!elegida && claves.indexOf(norm(o.dataset.nombre)) !== -1) elegida = o; });
    if (!elegida && claves.length) {
      const cand = opciones.filter(function (o) {
        const n = norm(o.dataset.nombre);
        return n.length >= 3 && claves.some(function (k) { return n.indexOf(k) !== -1 || k.indexOf(n) !== -1; });
      });
      if (cand.length === 1) elegida = cand[0];
    }
    sel.value = elegida ? elegida.value : '';
    if (aviso) {
      aviso.textContent = elegida
        ? 'Detectada en GLPI: ' + (elegida.parentNode.label ? elegida.parentNode.label + ' > ' : '') + elegida.dataset.nombre + ' (puedes cambiarla).'
        : 'Sin coincidencia en GLPI todavía: escribe el nombre o elige la ubicación.';
    }
  }
  document.addEventListener('input', function (e) {
    if (e.target.hasAttribute && e.target.hasAttribute('data-glpi-fuente')) detectar(e.target.closest('form'));
  });
  document.addEventListener('change', function (e) {
    if (e.target.matches && e.target.matches('select[data-glpi-auto]')) e.target.dataset.manual = '1';
  });
})();
