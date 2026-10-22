/* =========================================================
   Plano de sala (tipo «selección de sillas de cine»).
   Dos usos, mismo aspecto (estilos en css/10-plano-sala.css):
   1) Mantenimientos: ventana aparte con el plano; cada puesto es un
      equipo de la checklist. Al tocarlo se despliega su información y
      las acciones del rol (formularios POST ya pintados por PHP en
      app/Presentation/Views/mantenimientos/checklist.php).
   2) Reporte de daño del profesor (panel y QR de sala): selector de
      equipos afectados; cada puesto es una casilla ids_equipo_danio[].
   En el celular se ve un lado de la sala a la vez, con botones para
   pasar al lado izquierdo / derecho. No depende de app.js (también
   corre en la página pública del QR). Sin atributos on* en el HTML.
   ========================================================= */
(function (global) {
  'use strict';

  /* ---------- Reparto de equipos (misma regla que planoSala() en app/Support/ui.php) ---------- */
  function tipoPuesto(codigo, descripcion) {
    const t = ((codigo || '') + ' ' + (descripcion || '')).toLowerCase();
    if (/video\s*beam|proyector|\bvb\d*\b/.test(t)) return 'videobeam';
    if (/parlante|sonido|audio|bafle|altavoz/.test(t)) return 'parlante';
    if (/docente|profesor|maestro/.test(t)) return 'docente';
    return 'pc';
  }
  function repartir(equipos, dist) {
    const frente = [], puestos = [], cortos = {};
    // Código corto (sin prefijo de sala) salvo que choque con otro equipo de la lista
    equipos.forEach(function (eq) { const k = codigoEnSala(eq.codigo).toLowerCase(); cortos[k] = (cortos[k] || 0) + 1; });
    equipos.forEach(function (eq) { const c = codigoEnSala(eq.codigo); eq._cod = cortos[c.toLowerCase()] > 1 ? String(eq.codigo || '') : c; });
    equipos.forEach(function (eq) {
      // Videobeam/parlantes de la sala (componentes, no equipos de cómputo): siempre frente al tablero.
      if (eq.id_componente) { frente.push({ eq: eq, tipo: eq.tipo || 'videobeam', comp: true }); return; }
      const tipo = tipoPuesto(eq.codigo, eq.descripcion);
      (tipo === 'pc' ? puestos : frente).push({ eq: eq, tipo: tipo });
    });
    const orden = function (a, b) { return String(a.eq.codigo || '').localeCompare(String(b.eq.codigo || ''), 'es', { numeric: true, sensitivity: 'base' }); };
    puestos.sort(orden); frente.sort(orden);
    const mitad = Math.ceil(puestos.length / 2);
    const izq = puestos.slice(0, mitad);
    if (!dist || !dist.filas || !dist.filas.length) {
      return { frente: frente, izquierda: izq, derecha: puestos.slice(mitad), columnas: izq.length <= 4 ? 2 : 3, filas: null, fondo: [], sinUbicar: [] };
    }
    // Con distribución guardada (distribucion_sala.php): misma regla que planoSala() en ui.php.
    const porId = {}, usados = {};
    frente.concat(puestos).forEach(function (it) { if (!it.comp) porId[it.eq.id_equipo] = it; });
    function tomar(p, tipoVacio) {
      const id = p && p.id_equipo ? Number(p.id_equipo) : 0;
      if (id && porId[id] && !usados[id]) {
        usados[id] = true;
        const it = porId[id];
        return { eq: it.eq, tipo: (tipoVacio === 'docente' && it.tipo === 'pc') ? 'docente' : it.tipo, num: p.numero || '' };
      }
      return { vacio: true, tipo: tipoVacio || 'pc', num: (p && p.numero) || '' };
    }
    const doc = dist.docente || {}, puestoDoc = doc.ubicacion && doc.ubicacion !== 'ninguno' ? tomar(doc, 'docente') : null;
    const filas = [], izqR = [], derR = [];
    dist.filas.forEach(function (f) {
      let lados = { izq: [], der: [] }, lado = 'izq';
      (f.mesas || []).forEach(function (m) {
        const items = (m.puestos || []).map(function (pp) { return tomar(pp, 'pc'); });
        lados[lado].push({ puestos: items, pasillo: !!m.pasillo && lado === 'der' });
        items.forEach(function (it) { if (!it.vacio) (lado === 'izq' ? izqR : derR).push(it); });
        if (m.pasillo && lado === 'izq') lado = 'der';
      });
      filas.push(lados);
    });
    const frenteF = frente.filter(function (it) { return it.comp || !usados[it.eq.id_equipo]; });
    const sinUbicar = puestos.filter(function (it) { return !usados[it.eq.id_equipo]; });
    const fondo = [];
    if (puestoDoc) {
      if (doc.ubicacion === 'atras') fondo.push(puestoDoc);
      else if (doc.lado === 'der') frenteF.push(puestoDoc); else frenteF.unshift(puestoDoc);
    }
    return { frente: frenteF, izquierda: izqR, derecha: derR, columnas: 2, filas: filas, fondo: fondo, sinUbicar: sinUbicar };
  }

  /* Código sin el prefijo de la sala («SA402-PC07» → «PC07»), igual que codigoEnSala() en ui.php */
  function codigoEnSala(c) {
    const m = String(c || '').match(/^[A-Za-z]{1,5}\d{1,4}[-_ ]+(\S.*)$/);
    return m ? m[1] : String(c || '');
  }

  /* Íconos (mismos trazos que layout/iconos.php) */
  const ICONOS = {
    pc: '<rect x="2" y="5" width="13" height="10" rx="1.5"/><path d="M2 12.5h13M6.5 19h4M8.5 15v4"/><rect x="17" y="7" width="5" height="12" rx="1.2"/><path d="M18.5 10h2M18.5 12h2"/><circle cx="19.5" cy="16" r=".9"/>',
    docente: '<rect x="2" y="4" width="20" height="12" rx="1.5"/><path d="M2 13h20M9 20h6M12 16v4"/><circle cx="12" cy="8.5" r="1.6"/>',
    videobeam: '<rect x="2" y="8" width="20" height="9" rx="2"/><circle cx="15.5" cy="12.5" r="2.5"/><path d="M5.5 11.5h4M5.5 13.5h4M6 17v2M18 17v2"/>',
    parlante: '<rect x="5" y="2" width="14" height="20" rx="2"/><circle cx="12" cy="14.5" r="3.5"/><circle cx="12" cy="7" r="1.5"/>'
  };
  function svgIcono(tipo) {
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('class', 'ico sala-puesto-ico');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.75');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.innerHTML = ICONOS[tipo] || ICONOS.pc; // trazos fijos de este archivo, nunca datos del usuario
    return svg;
  }
  function el(tag, clase, texto) {
    const e = document.createElement(tag);
    if (clase) e.className = clase;
    if (texto != null) e.textContent = texto;
    return e;
  }

  /* ---------- Lado visible en el celular ---------- */
  function ponerLado(plano, lado) {
    if (!plano) return;
    plano.setAttribute('data-lado-activo', lado);
    plano.querySelectorAll('[data-sala-lado]').forEach(function (b) {
      const act = b.getAttribute('data-sala-lado') === lado;
      b.classList.toggle('active', act);
      b.setAttribute('aria-selected', act ? 'true' : 'false');
    });
    const pasar = plano.querySelector('[data-sala-pasar]');
    if (pasar) pasar.textContent = lado === 'izq' ? 'Pasar al lado derecho ▶' : '◀ Volver al lado izquierdo';
  }

  /* ---------- Selector de equipos afectados (profesor) ---------- */
  function resumenSelector(plano) {
    const marcados = Array.prototype.filter.call(plano.querySelectorAll('.sala-puesto-check'), function (c) { return c.checked; });
    const out = plano.querySelector('[data-sala-resumen]');
    if (!out) return;
    if (!marcados.length) { out.textContent = 'Ningún equipo seleccionado (opcional).'; out.classList.remove('con-seleccion'); return; }
    const nombres = marcados.map(function (c) { return c.getAttribute('data-codigo'); });
    out.textContent = marcados.length + (marcados.length === 1 ? ' equipo seleccionado: ' : ' equipos seleccionados: ') + nombres.join(', ');
    out.classList.add('con-seleccion');
  }

  /** Pinta el selector dentro de `cont` con la lista [{id_equipo, codigo, descripcion}] (de listar_equipos_ajax.php)
      y, si el salón se maquetó, su distribución (filas, mesas, número de puesto). */
  function selector(cont, equipos, nombreCampo, dist) {
    const p = repartir(equipos || [], dist || null);
    cont.innerHTML = '';
    const plano = el('div', 'sala-plano sala-plano-selector');
    plano.setAttribute('data-lado-activo', 'izq');
    plano.setAttribute('data-plano-selector', '');

    const ley = el('ul', 'sala-leyenda');
    [['est-pendiente', 'Disponible'], ['est-seleccionado', 'Con falla (seleccionado)'], ['no-operativo', 'No operativo']].forEach(function (x) {
      const li = el('li'); li.appendChild(el('span', 'sala-swatch ' + x[0])); li.appendChild(document.createTextNode(x[1])); ley.appendChild(li);
    });
    plano.appendChild(ley);

    function puesto(item) {
      const noOp = item.eq.estado && item.eq.estado.operativo === false;
      const lab = el('label', 'sala-puesto sala-puesto-' + item.tipo + (item.comp ? ' sala-puesto-infra' : '') + ' est-pendiente' + (noOp ? ' no-operativo' : ''));
      const cb = el('input', 'sala-puesto-check');
      cb.type = 'checkbox';
      cb.name = item.comp ? 'ids_componente_danio[]' : nombreCampo;
      cb.value = item.comp ? item.eq.id_componente : item.eq.id_equipo;
      cb.setAttribute('data-codigo', item.eq.codigo || '');
      cb.disabled = !!noOp; // ya se sabe que no funciona: se explica debajo del plano
      lab.title = (item.eq.codigo || '') + (noOp ? ' — no operativo: ' + item.eq.estado.motivo : (item.eq.descripcion ? ' — ' + item.eq.descripcion : ''));
      lab.appendChild(cb);
      lab.appendChild(svgIcono(item.tipo));
      if (noOp) { const x = el('span', 'sala-puesto-x'); x.setAttribute('aria-hidden', 'true'); lab.appendChild(x); }
      if (item.num) lab.appendChild(el('span', 'sala-puesto-num', item.num));
      lab.appendChild(el('span', 'sala-puesto-cod', item.eq._cod || item.eq.codigo || ''));
      return lab;
    }
    function vacio(item) {
      const v = el('span', 'sala-puesto sala-puesto-vacio');
      v.title = (item.tipo === 'docente' ? 'Puesto docente' : 'Puesto') + (item.num ? ' n.º ' + item.num : '') + ' sin equipo';
      if (item.num) v.appendChild(el('span', 'sala-puesto-num', item.num));
      v.appendChild(el('span', 'sala-puesto-cod', item.tipo === 'docente' ? 'Docente' : 'Vacío'));
      return v;
    }
    function item(it) { return it.vacio ? vacio(it) : puesto(it); }

    const frente = el('div', 'sala-frente');
    frente.appendChild(el('div', 'sala-tablero', 'Frente del salón · Tablero'));
    if (p.frente.length) {
      const fp = el('div', 'sala-frente-puestos');
      p.frente.forEach(function (it) { fp.appendChild(item(it)); });
      frente.appendChild(fp);
    }
    plano.appendChild(frente);

    const hayDer = p.filas ? p.filas.some(function (f) { return f.der.length; }) : p.derecha.length > 0;
    if (hayDer) {
      const nav = el('div', 'sala-lados-nav');
      nav.setAttribute('role', 'tablist');
      [['izq', '◀ Izquierdo (' + p.izquierda.length + ')'], ['der', 'Derecho (' + p.derecha.length + ') ▶']].forEach(function (x, i) {
        const b = el('button', 'sala-lado-btn' + (i === 0 ? ' active' : ''), x[1]);
        b.type = 'button'; b.setAttribute('data-sala-lado', x[0]); b.setAttribute('role', 'tab');
        b.setAttribute('aria-selected', i === 0 ? 'true' : 'false');
        nav.appendChild(b);
      });
      plano.appendChild(nav);
    }

    if (p.filas) {
      const grid = el('div', 'sala-filas' + (hayDer ? ' sala-filas-pasillo' : ''));
      p.filas.forEach(function (f, iF) {
        (hayDer ? ['izq', 'der'] : ['izq']).forEach(function (lado) {
          if (lado === 'der') {
            const pc = el('div', 'sala-fila-pasillo'); pc.setAttribute('aria-hidden', 'true');
            if (iF === 0) pc.appendChild(el('span', '', 'Pasillo'));
            grid.appendChild(pc);
          }
          const cel = el('div', 'sala-fila-lado sala-lado-' + lado);
          cel.setAttribute('data-fila', String(iF + 1));
          f[lado].forEach(function (m) {
            const mesa = el('div', 'sala-mesa' + (m.pasillo ? ' sala-mesa-pasillo' : ''));
            m.puestos.forEach(function (it) { mesa.appendChild(item(it)); });
            cel.appendChild(mesa);
          });
          grid.appendChild(cel);
        });
      });
      plano.appendChild(grid);
    } else {
      const lados = el('div', 'sala-lados');
      const li = el('div', 'sala-lado sala-lado-izq ' + (p.columnas === 3 ? 'sala-cols-3' : 'sala-cols-2'));
      li.setAttribute('data-lado', 'izq');
      p.izquierda.forEach(function (it) { li.appendChild(puesto(it)); });
      lados.appendChild(li);
      if (hayDer) {
        const pas = el('div', 'sala-pasillo'); pas.setAttribute('aria-hidden', 'true'); pas.appendChild(el('span', '', 'Pasillo'));
        lados.appendChild(pas);
        const ld = el('div', 'sala-lado sala-lado-der ' + (p.columnas === 3 ? 'sala-cols-3' : 'sala-cols-2'));
        ld.setAttribute('data-lado', 'der');
        p.derecha.forEach(function (it) { ld.appendChild(puesto(it)); });
        lados.appendChild(ld);
      }
      plano.appendChild(lados);
    }
    if (hayDer) {
      const bp = el('button', 'btn btn-ghost btn-sm sala-pasar', 'Pasar al lado derecho ▶');
      bp.type = 'button'; bp.setAttribute('data-sala-pasar', '');
      plano.appendChild(bp);
    }
    if (p.fondo.length) {
      const fo = el('div', 'sala-fondo'), fop = el('div', 'sala-frente-puestos');
      p.fondo.forEach(function (it) { fop.appendChild(item(it)); });
      fo.appendChild(fop); fo.appendChild(el('div', 'sala-fondo-rotulo', 'Fondo del salón'));
      plano.appendChild(fo);
    }
    if (p.sinUbicar.length) {
      const su = el('div', 'sala-sin-ubicar'), sup = el('div', 'sala-frente-puestos');
      su.appendChild(el('p', 'sala-sin-ubicar-tit', 'Equipos sin puesto asignado en el plano (' + p.sinUbicar.length + ')'));
      p.sinUbicar.forEach(function (it) { sup.appendChild(puesto(it)); });
      su.appendChild(sup); plano.appendChild(su);
    }
    plano.appendChild(el('p', 'sala-seleccion-resumen', 'Ningún equipo seleccionado (opcional).')).setAttribute('data-sala-resumen', '');
    const fuera = (equipos || []).filter(function (eq) { return eq.estado && eq.estado.operativo === false; });
    if (fuera.length) plano.appendChild(listaNoOperativos(fuera));
    cont.appendChild(plano);
  }

  /* Desplegable «Equipos no operativos»: qué les falta y quién los trasladó/aprobó
     (mismo contenido que partials/plano_estado_equipo.php; todo con textContent). */
  function listaNoOperativos(fuera) {
    const det = el('details', 'sala-fuera');
    det.appendChild(el('summary', '', 'Equipos no operativos (' + fuera.length + '): ver qué les falta y quién los trasladó'));
    fuera.forEach(function (eq) {
      const e = eq.estado, item = el('div', 'sala-fuera-item');
      item.appendChild(el('strong', '', eq.codigo || ''));
      const caja = el('div', 'sala-estado sala-estado-no');
      const linea = el('p', 'sala-estado-linea');
      linea.appendChild(el('strong', '', 'No operativo'));
      if (e.motivo) linea.appendChild(document.createTextNode(' — ' + e.motivo));
      caja.appendChild(linea);
      const piezas = (e.piezas || []).map(function (p) { return { t: p, fuera: false }; })
        .concat((e.faltan || []).map(function (p) { return { t: p, fuera: true }; }));
      if (piezas.length) {
        const ul = el('ul', 'sala-piezas');
        piezas.forEach(function (x) {
          let p = x.t, txt = p.tipo.charAt(0).toUpperCase() + p.tipo.slice(1) + (p.codigo ? ' · ' + p.codigo : '');
          if (x.fuera) txt += ' — fuera de la sala'; else if (p.estado !== 'ACTIVO') txt += ' (' + p.estado.toLowerCase().replace('_', ' ') + ')';
          ul.appendChild(el('li', x.fuera ? 'pieza-fuera' : (p.estado !== 'ACTIVO' ? 'pieza-alerta' : ''), txt));
        });
        caja.appendChild(ul);
      }
      if ((e.traslados || []).length) {
        caja.appendChild(el('p', 'sala-estado-sub', 'Últimos traslados'));
        const ol = el('ol', 'sala-traslados');
        e.traslados.forEach(function (t) {
          const li = el('li');
          li.appendChild(el('strong', '', t.que));
          li.appendChild(document.createTextNode(': ' + t.desde + ' → ' + t.hacia));
          li.appendChild(el('span', 'hint d-block', t.motivo + (t.ticket ? ' · ticket ' + t.ticket : '') + ' · ' + t.fecha));
          li.appendChild(el('span', 'hint d-block', (t.solicito ? 'Solicitó: ' + t.solicito + '. ' : '') + (t.ejecuto ? 'Trasladó: ' + t.ejecuto + '. ' : '') + 'Aprobó: ' + (t.aprobo || 'pendiente') + '.'));
          ol.appendChild(li);
        });
        caja.appendChild(ol);
      }
      item.appendChild(caja);
      det.appendChild(item);
    });
    return det;
  }

  /* ---------- Ventana del plano (mantenimientos) ---------- */
  let modalAbierto = null;

  function abrir(id, idPuesto) {
    const m = document.getElementById(id);
    if (!m) return;
    if (modalAbierto && modalAbierto !== m) cerrar();
    m.hidden = false;
    modalAbierto = m;
    document.body.classList.add('modal-abierto');
    if (idPuesto) seleccionar(m, idPuesto);
    const foco = m.querySelector('[data-sala-cerrar]');
    if (foco) foco.focus({ preventScroll: true });
    if (global.history && global.history.replaceState) global.history.replaceState(null, '', '#' + id + (idPuesto ? '-eq-' + idPuesto : ''));
  }
  function cerrar() {
    if (!modalAbierto) return;
    const id = modalAbierto.id;
    cerrarDetalle(modalAbierto);
    modalAbierto.hidden = true;
    modalAbierto = null;
    document.body.classList.remove('modal-abierto');
    if (global.history && global.history.replaceState) global.history.replaceState(null, '', global.location.pathname + global.location.search);
    const boton = document.querySelector('[data-sala-abrir="' + id + '"]');
    if (boton) boton.focus({ preventScroll: true });
  }
  function seleccionar(m, idPuesto) {
    const btn = m.querySelector('.sala-puesto[data-puesto="' + idPuesto + '"]');
    if (!btn) return;
    m.querySelectorAll('.sala-puesto[aria-pressed="true"]').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
    btn.setAttribute('aria-pressed', 'true');
    // Si el puesto está en el otro lado, el celular cambia de lado solo
    const lado = btn.closest('[data-lado]');
    if (lado) ponerLado(m.querySelector('.sala-plano'), lado.getAttribute('data-lado'));
    const panel = m.querySelector('.sala-detalle-panel');
    panel.querySelectorAll('.sala-detalle').forEach(function (d) { d.hidden = d.getAttribute('data-detalle') !== String(idPuesto); });
    panel.classList.add('con-detalle');
    m.classList.add('detalle-abierto');
    // Plano de consulta (ficha por salón): en el celular solo una hoja de detalle abierta a la vez.
    document.querySelectorAll('[data-plano-interactivo].detalle-abierto').forEach(function (otro) { if (otro !== m) cerrarDetalle(otro); });
    if (m.classList.contains('sala-modal') && global.history && global.history.replaceState) global.history.replaceState(null, '', '#' + m.id + '-eq-' + idPuesto);
  }
  function cerrarDetalle(m) {
    m.querySelectorAll('.sala-puesto[aria-pressed="true"]').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
    const panel = m.querySelector('.sala-detalle-panel');
    if (panel) {
      panel.classList.remove('con-detalle');
      panel.querySelectorAll('.sala-detalle').forEach(function (d) { d.hidden = true; });
    }
    m.classList.remove('detalle-abierto');
  }

  /* ---------- Eventos delegados ---------- */
  document.addEventListener('click', function (e) {
    const t = e.target;
    if (!t.closest) return;
    let b;
    if ((b = t.closest('[data-sala-abrir]'))) { abrir(b.getAttribute('data-sala-abrir')); return; }
    if (t.closest('[data-sala-cerrar]')) { cerrar(); return; }
    if (t.classList && t.classList.contains('sala-modal')) { cerrar(); return; } // clic en el fondo oscuro
    if (t.closest('[data-sala-detalle-cerrar]')) { cerrarDetalle(t.closest('.sala-modal, [data-plano-interactivo]')); return; }
    if ((b = t.closest('[data-abrir-detalle]'))) {
      // Opción de un menú «Acciones» que despliega una sección de la misma página (p. ej. el formulario de infraestructura)
      const dest = document.getElementById(b.getAttribute('data-abrir-detalle'));
      const menu = b.closest('details[data-menu]');
      if (menu) menu.open = false;
      if (dest) { dest.open = true; dest.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
      return;
    }
    if ((b = t.closest('[data-sala-lado]'))) { ponerLado(b.closest('.sala-plano'), b.getAttribute('data-sala-lado')); return; }
    if ((b = t.closest('[data-sala-pasar]'))) {
      const pl = b.closest('.sala-plano');
      ponerLado(pl, pl.getAttribute('data-lado-activo') === 'izq' ? 'der' : 'izq');
      pl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      return;
    }
    if ((b = t.closest('.sala-puesto[data-puesto]'))) {
      const m = b.closest('.sala-modal, [data-plano-interactivo]');
      if (b.getAttribute('aria-pressed') === 'true') cerrarDetalle(m); else seleccionar(m, b.getAttribute('data-puesto'));
    }
  });

  document.addEventListener('change', function (e) {
    const c = e.target;
    if (!c.classList || !c.classList.contains('sala-puesto-check')) return;
    const lab = c.closest('.sala-puesto');
    if (lab) { lab.classList.toggle('est-seleccionado', c.checked); lab.classList.toggle('est-pendiente', !c.checked); }
    resumenSelector(c.closest('.sala-plano'));
  });

  // Al enviar una acción del plano, la página vuelve con el mismo plano y equipo abiertos.
  document.addEventListener('submit', function (e) {
    const f = e.target;
    if (!f.hasAttribute || !f.hasAttribute('data-sala-form')) return;
    const m = f.closest('.sala-modal');
    const d = f.closest('[data-detalle]');
    // data-sala-accion: el plano está fuera de Mantenimientos (panel de beca) y las acciones van a otra página.
    const base = m && m.getAttribute('data-sala-accion') ? m.getAttribute('data-sala-accion') : global.location.pathname + global.location.search;
    if (m) f.action = base + '#' + m.id + (d ? '-eq-' + d.getAttribute('data-detalle') : '');
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modalAbierto) {
      document.querySelectorAll('[data-plano-interactivo].detalle-abierto').forEach(cerrarDetalle);
      return;
    }
    if (e.key !== 'Escape' || !modalAbierto) return;
    if (modalAbierto.classList.contains('detalle-abierto')) cerrarDetalle(modalAbierto); else cerrar();
  });

  document.addEventListener('DOMContentLoaded', function () {
    // Selectores pintados por PHP (QR de sala): marcar los que ya vienen elegidos
    document.querySelectorAll('[data-plano-selector]').forEach(function (pl) {
      pl.querySelectorAll('.sala-puesto-check').forEach(function (c) {
        const lab = c.closest('.sala-puesto');
        if (lab) { lab.classList.toggle('est-seleccionado', c.checked); lab.classList.toggle('est-pendiente', !c.checked); }
      });
      resumenSelector(pl);
    });
    // #mant-12 o #mant-12-eq-34: reabrir el plano (y el equipo) tras guardar una acción
    const h = (global.location.hash || '').match(/^#(mant-\d+)(?:-eq-(\d+))?$/);
    if (h && document.getElementById(h[1])) abrir(h[1], h[2] || null);
  });

  global.PlanoSala = { selector: selector };
})(window);
