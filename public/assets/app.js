/* =========================================================
   Biblioteca JavaScript compartida.
   - UFPS.esc            escapa texto antes de ponerlo en innerHTML / document.write
   - UFPS.fetchJson      GET/POST con same-origin y error legible
   - UFPS.listarEquipos  consume listar_equipos_ajax.php?id_sala=
   - UFPS.llenarSelectEquipos  pinta el <select> de equipos de una sala
   - UFPS.qr*            descargar / imprimir códigos QR (botones data-qr-accion)
   - confirmación de formularios con data-confirm
   No depende de PHP: los datos llegan por atributos data-* o parámetros.
   ========================================================= */
(function (global) {
  'use strict';

  const UFPS = global.UFPS = global.UFPS || {};

  /** Escapa & < > " ' (para innerHTML, document.write y atributos). */
  UFPS.esc = function (s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  };

  /** Convierte un texto en un trozo seguro para nombre de archivo. */
  UFPS.slug = function (s) {
    return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '-');
  };

  UFPS.debounce = function (fn, ms) {
    let t;
    return function () {
      const self = this, args = arguments;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(self, args); }, ms);
    };
  };

  /** fetch + JSON. Rechaza si la respuesta no es 2xx o no es JSON. */
  UFPS.fetchJson = function (url, opts) {
    opts = opts || {};
    if (!opts.credentials) opts.credentials = 'same-origin';
    return fetch(url, opts).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  };

  /* ---------- Equipos de una sala (listar_equipos_ajax.php) ---------- */

  /** Promesa con [{id_equipo, codigo, descripcion}, ...]. */
  UFPS.listarEquipos = function (idSala) {
    return UFPS.fetchJson('listar_equipos_ajax.php?id_sala=' + encodeURIComponent(idSala));
  };

  UFPS.textoEquipo = function (eq) {
    return eq.codigo + (eq.descripcion ? ' — ' + eq.descripcion : '');
  };

  /**
   * Rellena un <select> con los equipos de la sala.
   * opciones: { sinSala, cargando, lista, error } (textos de la 1.ª opción).
   * Devuelve la promesa (para encadenar, p. ej. preseleccionar un valor).
   */
  UFPS.llenarSelectEquipos = function (select, idSala, opciones) {
    const o = opciones || {};
    function unica(texto) {
      select.innerHTML = '';
      const op = document.createElement('option');
      op.value = ''; op.textContent = texto;
      select.appendChild(op);
    }
    unica(o.cargando || 'Cargando…');
    if (!idSala) { unica(o.sinSala || '-- Primero selecciona la sala --'); return Promise.resolve([]); }
    return UFPS.listarEquipos(idSala).then(function (lista) {
      unica(o.lista || '-- Selecciona el equipo --');
      lista.forEach(function (eq) {
        const op = document.createElement('option');
        op.value = eq.id_equipo;
        op.textContent = UFPS.textoEquipo(eq);
        select.appendChild(op);
      });
      return lista;
    }).catch(function () {
      unica(o.error || 'No se pudo cargar la lista de equipos');
      return [];
    });
  };

  /* ---------- Códigos QR (qrcodejs) ---------- */

  /** Dibuja un QR de texto dentro de un elemento. */
  UFPS.qrCrear = function (el, texto, tam) {
    tam = tam || 200;
    return new global.QRCode(el, { text: texto, width: tam, height: tam, correctLevel: global.QRCode.CorrectLevel.M });
  };

  /** data: URL del QR ya dibujado en el contenedor (img o canvas). */
  UFPS.qrDataUrl = function (contenedor) {
    if (typeof contenedor === 'string') contenedor = document.getElementById(contenedor);
    if (!contenedor) return null;
    const img = contenedor.querySelector('img');
    const canvas = contenedor.querySelector('canvas');
    return img ? img.src : (canvas ? canvas.toDataURL('image/png') : null);
  };

  UFPS.qrDescargar = function (contenedorId, nombre, prefijo) {
    const dataUrl = UFPS.qrDataUrl(contenedorId);
    if (!dataUrl) return;
    const a = document.createElement('a');
    a.href = dataUrl;
    a.download = (prefijo || 'qr') + '-' + UFPS.slug(nombre) + '.png';
    document.body.appendChild(a);
    a.click();
    a.remove();
  };

  /** Abre una ventana con el QR (y su título ya escapado) y lanza imprimir. */
  UFPS.qrImprimir = function (contenedorId, titulo) {
    const dataUrl = UFPS.qrDataUrl(contenedorId);
    if (!dataUrl) return;
    const t = UFPS.esc(titulo);
    const v = global.open('', '_blank');
    v.document.write(
      '<html><head><title>Imprimir QR - ' + t + '</title></head>' +
      '<body style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100vh;font-family:Helvetica,Arial,sans-serif;">' +
      '<h2>' + t + '</h2>' +
      '<img src="' + dataUrl + '" style="width:280px;height:280px;">' +
      '</body></html>'
    );
    v.document.close();
    v.focus();
    v.print();
  };

  /**
   * Imprime en una ventana el QR de varios equipos. datos = [{codigo, url}].
   */
  UFPS.qrImprimirLote = function (datos, titulo) {
    if (!datos || !datos.length) return;
    const temp = document.createElement('div');
    temp.hidden = true;
    document.body.appendChild(temp);
    let pendientes = datos.length, tarjetas = new Array(datos.length);
    datos.forEach(function (eq, i) {
      const div = document.createElement('div');
      temp.appendChild(div);
      UFPS.qrCrear(div, eq.url, 200);
      // qrcodejs pinta de forma síncrona; el timeout asegura que el nodo esté listo.
      setTimeout(function () {
        const dataUrl = UFPS.qrDataUrl(div) || '';
        tarjetas[i] = '<div style="display:inline-block;text-align:center;margin:12px;page-break-inside:avoid;">' +
          '<h3>' + UFPS.esc(eq.codigo) + '</h3>' +
          '<img src="' + dataUrl + '" style="width:200px;height:200px;">' +
          '</div>';
        pendientes--;
        if (pendientes === 0) {
          const t = UFPS.esc(titulo);
          const v = global.open('', '_blank');
          v.document.write(
            '<html><head><title>Imprimir QR - ' + t + '</title></head>' +
            '<body style="font-family:Helvetica,Arial,sans-serif;text-align:center;">' +
            '<h2>' + t + '</h2>' + tarjetas.join('') + '</body></html>'
          );
          v.document.close();
          v.focus();
          v.print();
          document.body.removeChild(temp);
        }
      }, 50);
    });
  };

  /* ---------- Eventos delegados (sin onclick con datos de la BD) ----------
     <button data-qr-accion="descargar|imprimir" data-qr-id="..." data-qr-nombre="..." data-qr-prefijo="qr-equipo">
     <button data-qr-accion="lote" data-qr-json="id-del-script-json" data-qr-nombre="Sala X">
     <form data-confirm="¿Seguro?">
  */
  document.addEventListener('click', function (ev) {
    const b = ev.target.closest ? ev.target.closest('[data-qr-accion]') : null;
    if (!b) return;
    const accion = b.getAttribute('data-qr-accion');
    const nombre = b.getAttribute('data-qr-nombre') || '';
    if (accion === 'descargar') UFPS.qrDescargar(b.getAttribute('data-qr-id'), nombre, b.getAttribute('data-qr-prefijo') || 'qr');
    else if (accion === 'imprimir') UFPS.qrImprimir(b.getAttribute('data-qr-id'), nombre);
    else if (accion === 'lote') {
      const fuente = document.getElementById(b.getAttribute('data-qr-json'));
      if (fuente) UFPS.qrImprimirLote(JSON.parse(fuente.textContent), nombre);
    }
  });

  /* ---------- Atributos declarativos (sin <script> en línea) ----------
     <div data-qr-texto="URL">                     → dibuja el QR al cargar la página
     <select data-equipos-destino="id-del-select"
             data-equipos-cargando|-sin-sala|-lista="texto">  → al cambiar, rellena ese select con los equipos de la sala */
  document.addEventListener('change', function (ev) {
    const sel = ev.target;
    const destino = sel.getAttribute ? sel.getAttribute('data-equipos-destino') : null;
    if (!destino) return;
    const d = sel.dataset;
    UFPS.llenarSelectEquipos(document.getElementById(destino), sel.value,
      { cargando: d.equiposCargando, sinSala: d.equiposSinSala, lista: d.equiposLista });
  });

  function iniciarQrDeclarativos() {
    if (!global.QRCode) return;
    document.querySelectorAll('[data-qr-texto]').forEach(function (el) {
      if (!el.firstChild) UFPS.qrCrear(el, el.getAttribute('data-qr-texto'));
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciarQrDeclarativos);
  else iniciarQrDeclarativos();

  /* ---------- Acciones declarativas (sustituyen onclick/onchange del HTML) ----------
     <button data-click="modulo.accion" data-arg="valor">   → UFPS.acciones['modulo.accion'](elemento, evento)
     <select data-change="modulo.accion">
     Cada archivo JS registra las suyas con UFPS.accion('modulo.accion', function (el, ev) {...}).
     <button data-confirm-click="¿Seguro?">   → pide confirmación antes de la acción por defecto (p. ej. enviar el form) */
  UFPS.acciones = UFPS.acciones || {};
  UFPS.accion = function (nombre, fn) { UFPS.acciones[nombre] = fn; };

  document.addEventListener('click', function (ev) {
    const c = ev.target.closest ? ev.target.closest('[data-confirm-click]') : null;
    if (c && !global.confirm(c.getAttribute('data-confirm-click'))) { ev.preventDefault(); ev.stopImmediatePropagation(); return; }
    const el = ev.target.closest ? ev.target.closest('[data-click]') : null;
    const fn = el && UFPS.acciones[el.getAttribute('data-click')];
    if (fn) fn(el, ev);
  }, true);

  document.addEventListener('change', function (ev) {
    const el = ev.target.closest ? ev.target.closest('[data-change]') : null;
    const fn = el && UFPS.acciones[el.getAttribute('data-change')];
    if (fn) fn(el, ev);
  });

  document.addEventListener('submit', function (ev) {
    const msg = ev.target && ev.target.getAttribute ? ev.target.getAttribute('data-confirm') : null;
    if (msg && !global.confirm(msg)) ev.preventDefault();
  });

  // Formulario de usuario: el campo «Área de la beca trabajo» solo se muestra con el rol beca trabajo.
  function areaBecaSegunRol() {
    const rol = document.getElementById('role'), campo = document.querySelector('[data-campo-area-beca]');
    if (rol && campo) campo.hidden = rol.value !== 'beca_trabajo';
  }
  document.addEventListener('change', function (ev) { if (ev.target && ev.target.id === 'role') areaBecaSegunRol(); });
  document.addEventListener('DOMContentLoaded', areaBecaSegunRol);

  // Usuarios: tras cambiar un permiso, la página vuelve con el menú flotante de esa persona abierto.
  function abrirPopoverPendiente() {
    const pop = document.querySelector('[popover][data-popover-abrir]');
    if (pop && typeof pop.showPopover === 'function') { try { pop.showPopover(); } catch (e) { /* ya abierto */ } }
  }
  document.addEventListener('DOMContentLoaded', abrirPopoverPendiente);

  // Fichas por salón: una sala abierta ocupa toda la pantalla. Solo una a la vez, sin desplazar la página de fondo;
  // se cierra con «Cerrar», Esc o el botón atrás del celular (se agrega una entrada al historial al abrirla).
  function fichaSalaAbierta() { return document.querySelector('details.ficha-sala[open]'); }
  function marcarFondo() { document.body.classList.toggle('modal-abierto', !!fichaSalaAbierta()); }
  document.addEventListener('toggle', function (ev) {
    const d = ev.target;
    if (!d || !d.classList || !d.classList.contains('ficha-sala')) return;
    if (d.open) {
      document.querySelectorAll('details.ficha-sala[open]').forEach(function (otra) { if (otra !== d) otra.open = false; });
      d.scrollTop = 0;
      if (!(history.state && history.state.fichaSala === d.id)) { try { history.pushState({ fichaSala: d.id }, '', '#' + d.id); } catch (e) { /* sin historial */ } }
    } else if (history.state && history.state.fichaSala === d.id) {
      try { history.back(); } catch (e) { /* sin historial */ }
    }
    marcarFondo();
  }, true);
  global.addEventListener('popstate', function () {
    const d = fichaSalaAbierta();
    if (d && !(history.state && history.state.fichaSala === d.id)) { d.open = false; marcarFondo(); }
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') return;
    if (document.querySelector('.vf:not([hidden]), .sala-modal:not([hidden])')) return; // primero se cierra lo que esté encima
    try { if (document.querySelector('[popover]:popover-open')) return; } catch (e) { /* navegador sin popover */ }
    const d = fichaSalaAbierta();
    if (d) d.open = false;
  });
  // Enlace directo a una sala (p. ej. «Volver a la sala» desde la ficha de un componente): ficha_equipo.php?todas=1#ficha-sala-ID
  function abrirFichaSalaDelEnlace() {
    const id = (location.hash || '').slice(1);
    if (!/^ficha-sala-\d+$/.test(id)) return;
    const d = document.getElementById(id);
    if (d && d.tagName === 'DETAILS' && !d.open) d.open = true;
  }
  document.addEventListener('DOMContentLoaded', function () { abrirFichaSalaDelEnlace(); marcarFondo(); });
})(window);
