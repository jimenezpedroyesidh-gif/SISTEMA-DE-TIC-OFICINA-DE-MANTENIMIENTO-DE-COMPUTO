/* =========================================================
   JavaScript de la "cáscara" de las páginas privadas:
   Menú lateral, menú de usuario, buscador global y campanita de notificaciones.
   Endpoints que consume:
     - buscar_global.php?q=                 (GET, JSON)
     - notificacion_marcar_leida.php        (POST: csrf_token + marcar_todas)
   El token CSRF llega en data-csrf del .notif-widget.
   ========================================================= */
(function (global) {
  'use strict';

  /* ---------- Menú lateral y de usuario ----------
     Móvil/tablet (< 1024px): el menú es un panel que entra desde la izquierda.
     Al abrirlo se bloquea el scroll de la página, el foco pasa al primer enlace
     y se cierra con: el botón ✕, el fondo oscuro, la tecla Esc, al elegir un
     enlace o al ensanchar la ventana a escritorio. */
  const ESCRITORIO = global.matchMedia ? global.matchMedia('(min-width: 1024px)') : null;

  function toggleAppSidebar(forceOpen) {
    const sidebar = document.getElementById('appSidebar');
    const backdrop = document.getElementById('appSidebarBackdrop');
    if (!sidebar) return;
    let open = typeof forceOpen === 'boolean' ? forceOpen : !sidebar.classList.contains('open');
    if (open && ESCRITORIO && ESCRITORIO.matches) open = false; // en escritorio el menú siempre está visible
    sidebar.classList.toggle('open', open);
    if (backdrop) backdrop.classList.toggle('open', open);
    document.body.classList.toggle('menu-abierto', open);
    const burger = document.querySelector('.app-header-burger');
    if (burger) burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      const primero = sidebar.querySelector('.app-sidebar-link.active, .app-sidebar-sublink.active, .app-sidebar-link');
      if (primero) primero.focus({ preventScroll: true });
    } else if (burger && sidebar.contains(document.activeElement)) {
      burger.focus({ preventScroll: true });
    }
  }

  function toggleAppUserMenu(forceOpen) {
    const dd = document.getElementById('appUserDropdown');
    if (!dd) return;
    const open = typeof forceOpen === 'boolean' ? forceOpen : dd.hidden;
    dd.hidden = !open;
    const t = document.querySelector('.app-user-trigger');
    if (t) t.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  /* Grupos del menú (<details>) como acordeón: abrir uno cierra los demás. */
  document.addEventListener('toggle', function (e) {
    const d = e.target;
    if (!d || !d.classList || !d.classList.contains('app-sidebar-group') || !d.open) return;
    const nav = d.closest('[data-menu-acordeon]');
    if (!nav) return;
    nav.querySelectorAll('.app-sidebar-group[open]').forEach(function (otro) { if (otro !== d) otro.open = false; });
  }, true);

  /* Elegir un enlace en el menú móvil lo cierra (la página nueva carga con el menú cerrado). */
  document.addEventListener('click', function (e) {
    const a = e.target.closest ? e.target.closest('.app-sidebar a[href]') : null;
    if (a && !(ESCRITORIO && ESCRITORIO.matches)) toggleAppSidebar(false);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    const sidebar = document.getElementById('appSidebar');
    if (sidebar && sidebar.classList.contains('open')) toggleAppSidebar(false);
    toggleAppUserMenu(false);
    const nd = document.getElementById('notifDropdown');
    if (nd) nd.hidden = true;
  });

  if (ESCRITORIO) {
    const alCambiar = function (mq) { if (mq.matches) toggleAppSidebar(false); };
    if (ESCRITORIO.addEventListener) ESCRITORIO.addEventListener('change', alCambiar);
    else if (ESCRITORIO.addListener) ESCRITORIO.addListener(alCambiar);
  }

  /* ---------- Ventanas flotantes de formularios (.vf, ver vfAbrir() en app/Support/ui.php) ----------
     Se abren con [data-vf-abrir="id"], se cierran con la X, el fondo oscuro o Esc.
     El foco pasa al primer campo y vuelve al botón que la abrió al cerrar. */
  let vfOrigen = null;
  function vfAbrirVentana(id, origen) {
    const v = document.getElementById(id);
    if (!v) return;
    document.querySelectorAll('.vf:not([hidden])').forEach(function (o) { if (o !== v) o.hidden = true; });
    v.hidden = false;
    vfOrigen = origen || null;
    document.body.classList.add('modal-abierto');
    const campo = v.querySelector('input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea, .vf-cerrar');
    if (campo) setTimeout(function () { campo.focus({ preventScroll: true }); }, 30);
  }
  function vfCerrarVentana(v) {
    if (!v) return;
    v.hidden = true;
    if (!document.querySelector('.vf:not([hidden]), .sala-modal:not([hidden])')) document.body.classList.remove('modal-abierto');
    if (vfOrigen) { vfOrigen.focus({ preventScroll: true }); vfOrigen = null; }
  }
  document.addEventListener('click', function (e) {
    const t = e.target;
    const b = t.closest ? t.closest('[data-vf-abrir]') : null;
    if (b) {
      e.preventDefault();
      const menu = b.closest('details[data-menu]');
      if (menu) menu.open = false;
      vfAbrirVentana(b.getAttribute('data-vf-abrir'), b);
      return;
    }
    if (t.closest && t.closest('[data-vf-cerrar]')) { vfCerrarVentana(t.closest('.vf')); return; }
    if (t.classList && t.classList.contains('vf')) vfCerrarVentana(t); // clic en el fondo oscuro
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    const abierta = document.querySelector('.vf:not([hidden])');
    if (abierta) vfCerrarVentana(abierta);
  });
  document.addEventListener('DOMContentLoaded', function () {
    const auto = document.querySelector('.vf[data-vf-abierta]');
    if (auto) vfAbrirVentana(auto.id, null);
  });

  /* ---------- Menús «Acciones ▾» (details.menu-acciones[data-menu]) ----------
     Agrupan los botones de una vista para que no se vea cargada. Abrir uno cierra
     los demás; se cierran al hacer clic fuera, con Esc o al elegir una opción. */
  document.addEventListener('toggle', function (e) {
    const d = e.target;
    if (!d || !d.matches || !d.matches('details[data-menu]') || !d.open) return;
    document.querySelectorAll('details[data-menu][open]').forEach(function (o) { if (o !== d) o.open = false; });
  }, true);
  document.addEventListener('click', function (e) {
    const dentro = e.target.closest ? e.target.closest('details[data-menu]') : null;
    document.querySelectorAll('details[data-menu][open]').forEach(function (o) {
      if (o !== dentro) o.open = false;
      else if (e.target.closest('.menu-acciones-item')) o.open = false;
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.querySelectorAll('details[data-menu][open]').forEach(function (o) { o.open = false; });
  });

  /* ---------- Pestañas de sección (.tabs): marca la pestaña elegida ----------
     Solo agrega/quita la clase visual "active"; mostrar la sección sigue siendo
     tarea de paneles.js / crear_ticket_beca.js (data-click). */
  function marcarPestana(btn) {
    const barra = btn.closest('.tabs');
    if (!barra) return;
    barra.querySelectorAll('button').forEach(function (b) {
      const act = b === btn;
      b.classList.toggle('active', act);
      b.setAttribute('aria-selected', act ? 'true' : 'false');
    });
  }
  document.addEventListener('click', function (e) {
    const b = e.target.closest ? e.target.closest('.tabs > button') : null;
    if (b) marcarPestana(b);
  });
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.tabs').forEach(function (barra) {
      barra.setAttribute('role', 'tablist');
      const botones = barra.querySelectorAll('button[data-arg]');
      let activo = null;
      botones.forEach(function (b) {
        b.setAttribute('role', 'tab');
        const sec = document.getElementById(b.getAttribute('data-arg'));
        if (!activo && sec && sec.classList.contains('active')) activo = b;
      });
      const h = (global.location.hash || '').replace('#', '');
      botones.forEach(function (b) { if (h && b.getAttribute('data-arg') === h) activo = b; });
      if (activo) marcarPestana(activo);
    });
  });

  /* ---------- Buscador global ---------- */
  let buscadorGlobalTimeout;
  function buscarGlobal(q) {
    clearTimeout(buscadorGlobalTimeout);
    const dd = document.getElementById('buscadorGlobalDropdown');
    q = q.trim();
    if (q.length < 2) { dd.hidden = true; dd.innerHTML = ''; return; }

    buscadorGlobalTimeout = setTimeout(function () {
      fetch('buscar_global.php?q=' + encodeURIComponent(q))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          dd.innerHTML = '';
          const grupos = [
            { titulo: 'Tickets', items: data.tickets, campo: 'etiqueta', url: 'url' },
            { titulo: 'Usuarios', items: data.usuarios, campo: 'etiqueta', url: 'url' },
            { titulo: 'Componentes', items: data.componentes, campo: 'etiqueta', url: 'url' }
          ];
          let huboResultados = false;
          grupos.forEach(function (g) {
            if (!g.items || !g.items.length) return;
            huboResultados = true;
            const encabezado = document.createElement('div');
            encabezado.className = 'buscador-global-grupo-titulo';
            encabezado.textContent = g.titulo;
            dd.appendChild(encabezado);
            g.items.forEach(function (it) {
              const el = document.createElement(g.url ? 'a' : 'div');
              el.className = 'buscador-global-item';
              if (g.url && it[g.url]) el.href = it[g.url];
              el.textContent = it[g.campo];
              dd.appendChild(el);
            });
          });
          if (!huboResultados) {
            dd.innerHTML = '<p class="text-muted buscador-global-vacio">Sin resultados.</p>';
          }
          dd.hidden = false;
        });
    }, 250);
  }

  /* ---------- Notificaciones ---------- */
  function toggleNotifDropdown() {
    const dd = document.getElementById('notifDropdown');
    const open = dd.hidden;
    dd.hidden = !open;
    const b = document.querySelector('.notif-bell');
    if (b) b.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function tokenCsrf() {
    const w = document.querySelector('.notif-widget');
    return w ? (w.getAttribute('data-csrf') || '') : '';
  }

  function marcarTodasNotifLeidas() {
    const fd = new FormData();
    fd.append('csrf_token', tokenCsrf());
    fd.append('marcar_todas', '1');
    fetch('notificacion_marcar_leida.php', { method: 'POST', body: fd })
      .then(function () {
        document.querySelectorAll('.notif-item').forEach(function (el) {
          el.classList.add('leido');
          el.classList.remove('no-leido');
        });
        const badge = document.getElementById('notifBadge');
        if (badge) badge.remove();
      });
  }

  /* ---------- Eventos delegados (sin atributos onclick/oninput en el HTML) ----------
     data-shell="toggle-sidebar|close-sidebar|toggle-usermenu|notif-toggle|notif-todas|buscar-global"
     Cada aviso abre mis_notificaciones.php?ir=ID, que lo marca leído en el servidor. */
  document.addEventListener('click', function (e) {
    const el = e.target.closest ? e.target.closest('[data-shell]') : null;
    if (el) {
      switch (el.getAttribute('data-shell')) {
        case 'toggle-sidebar': toggleAppSidebar(); break;
        case 'close-sidebar': toggleAppSidebar(false); break;
        case 'toggle-usermenu': toggleAppUserMenu(); break;
        case 'notif-toggle': toggleNotifDropdown(); break;
        case 'notif-todas': marcarTodasNotifLeidas(); break;
      }
    }
  });
  // Enter en el buscador: abre el primer resultado.
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || !e.target.getAttribute || e.target.getAttribute('data-shell') !== 'buscar-global') return;
    e.preventDefault();
    const primero = document.querySelector('#buscadorGlobalDropdown a.buscador-global-item[href]');
    if (primero) window.location.href = primero.getAttribute('href');
  });
  document.addEventListener('input', function (e) {
    if (e.target.getAttribute && e.target.getAttribute('data-shell') === 'buscar-global') buscarGlobal(e.target.value);
  });

  /* ---------- Cierre de menús al hacer clic fuera (un solo listener) ---------- */
  document.addEventListener('click', function (e) {
    const menu = document.querySelector('.app-user-menu');
    const dd = document.getElementById('appUserDropdown');
    if (dd && !dd.hidden && menu && !menu.contains(e.target)) toggleAppUserMenu(false);

    if (!e.target.closest('.buscador-global-widget')) {
      const bg = document.getElementById('buscadorGlobalDropdown');
      if (bg) bg.hidden = true;
    }
    if (!e.target.closest('.notif-widget')) {
      const nd = document.getElementById('notifDropdown');
      if (nd) nd.hidden = true;
    }
  });

  /* ---------- Cierre con evidencia (.tk-cierre[data-tk-cierre], ver _tk_campos_cierre.php) ----------
     Si el ticket exige explicación + foto y se elige «Solucionado», avisa antes de enviar
     (el servidor lo vuelve a validar). Se usa en los paneles de técnico y beca. */
  document.addEventListener('submit', function (e) {
    const form = e.target;
    const bloque = form.querySelector ? form.querySelector('[data-tk-cierre][data-exige="1"]') : null;
    if (!bloque) return;
    const estado = form.querySelector('select[name="estado"]');
    if (!estado || estado.value !== 'Solucionado') return;
    const textos = Array.prototype.filter.call(form.querySelectorAll('textarea[name="solucion"]'), function (t) { return !t.disabled && t.offsetParent !== null; });
    const texto = textos.length ? textos[0].value.trim() : '';
    const minimo = parseInt(bloque.getAttribute('data-min'), 10) || 0;
    const archivos = bloque.querySelector('input[type="file"]');
    const fotos = (parseInt(bloque.getAttribute('data-fotos'), 10) || 0) + (archivos && archivos.files ? archivos.files.length : 0);
    const faltas = [];
    if (texto.length < minimo) faltas.push('explica qué hiciste (mínimo ' + minimo + ' caracteres)');
    if (fotos === 0) faltas.push('adjunta al menos una foto del trabajo');
    if (!faltas.length) return;
    e.preventDefault();
    const aviso = bloque.querySelector('.tk-cierre-aviso');
    if (aviso) { aviso.textContent = 'Para marcarlo como Solucionado: ' + faltas.join(' y ') + '.'; aviso.hidden = false; }
    (texto.length < minimo && textos.length ? textos[0] : archivos || bloque).focus();
  }, true);

  /* ---------- Bloques que dependen de un <select> ([data-mostrar-cuando="campo=VAL1,VAL2"]) ----------
     Se muestran solo cuando el select «campo» del mismo formulario tiene uno de esos valores; los campos
     de un bloque oculto se desactivan para que no viajen ni bloqueen el envío. */
  function aplicarMostrarCuando(form) {
    form.querySelectorAll('[data-mostrar-cuando]').forEach(function (b) {
      const partes = b.getAttribute('data-mostrar-cuando').split('=');
      const sel = form.querySelector('[name="' + partes[0] + '"]');
      if (!sel) return;
      const ver = (partes[1] || '').split(',').indexOf(sel.value) !== -1;
      b.hidden = !ver;
      b.querySelectorAll('input, select, textarea').forEach(function (c) { c.disabled = !ver; });
    });
  }
  document.addEventListener('change', function (e) {
    const f = e.target.form;
    if (f && f.querySelector('[data-mostrar-cuando]')) aplicarMostrarCuando(f);
  });
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form').forEach(function (f) { if (f.querySelector('[data-mostrar-cuando]')) aplicarMostrarCuando(f); });
  });
})(window);
