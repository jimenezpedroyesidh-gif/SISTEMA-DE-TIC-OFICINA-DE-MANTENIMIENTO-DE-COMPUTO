/* Pantalla de la oficina de mantenimiento (televisor): reloj, pantalla completa y actualización automática.
   Cada 30 s pide pantalla.php?parcial=1 (HTML ya escapado en el servidor) y reemplaza solo las columnas.
   Si la sesión se cerró (el servidor redirige al login) recarga la página completa. */
(function () {
  'use strict';
  const INTERVALO_MS = 30000;
  const cont = document.getElementById('tv-columnas');
  const hora = document.getElementById('tv-hora');
  const fecha = document.getElementById('tv-fecha');
  const DIAS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

  function reloj() {
    const d = new Date();
    if (hora) hora.textContent = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    if (fecha) fecha.textContent = DIAS[d.getDay()] + ' ' + d.getDate() + '/' + String(d.getMonth() + 1).padStart(2, '0') + '/' + d.getFullYear();
  }

  function actualizar() {
    if (!cont) return;
    fetch(cont.getAttribute('data-url'), { credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) {
        if (r.redirected || !r.ok) { throw new Error('sesion'); }
        return r.text();
      })
      .then(function (html) { cont.innerHTML = html; cont.classList.remove('tv-sin-conexion'); })
      .catch(function (e) {
        if (e && e.message === 'sesion') { window.location.reload(); return; }
        cont.classList.add('tv-sin-conexion'); // sin red: se deja lo último y se reintenta en el siguiente ciclo
      });
  }

  const btn = document.getElementById('tv-fullscreen');
  if (btn) {
    btn.addEventListener('click', function () {
      if (document.fullscreenElement) { document.exitFullscreen(); }
      else if (document.documentElement.requestFullscreen) { document.documentElement.requestFullscreen(); }
    });
  }

  // Evita que el televisor apague la pantalla mientras la página está visible (si el navegador lo permite).
  if ('wakeLock' in navigator) {
    const pedir = function () { navigator.wakeLock.request('screen').catch(function () {}); };
    pedir();
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') pedir(); });
  }

  reloj();
  setInterval(reloj, 10000);
  setInterval(actualizar, INTERVALO_MS);
})();
