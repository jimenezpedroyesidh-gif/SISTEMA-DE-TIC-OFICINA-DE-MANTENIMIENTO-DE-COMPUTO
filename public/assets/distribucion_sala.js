/* =========================================================
   Editor de la distribución del salón (distribucion_sala.php).
   Maqueta el salón como es: filas (del frente al fondo) → mesas → puestos,
   pasillos y puesto docente (adelante / atrás). Cada puesto lleva el equipo
   de inventario, su número visible y el punto de red. Se envía como JSON en
   el campo «estructura»; el servidor lo valida (DistribucionSala::normalizar).
   Mismo aspecto que el plano de consulta (css/10-plano-sala.css).
   Sin atributos on* en el HTML; los datos del usuario siempre con textContent.
   ========================================================= */
(function () {
  'use strict';

  const MAX_FILAS = 20, MAX_MESAS = 10, MAX_PUESTOS = 8;
  const datosEl = document.getElementById('dist-datos');
  const form = document.getElementById('dist-form');
  if (!datosEl || !form) return;

  const datos = JSON.parse(datosEl.textContent || '{}');
  const E = datos.estructura || { docente: {}, filas: [] };
  const equipos = datos.equipos || [];
  const porId = {};
  equipos.forEach(function (e) { porId[e.id_equipo] = e; });
  let sel = null;      // {f, m, p} o {doc: true}
  let sucio = false;

  const $ = function (id) { return document.getElementById(id); };
  const plano = $('dist-plano');

  function puestoNuevo() { return { id_equipo: null, numero: '', red: '' }; }
  function normalizarInicial() {
    E.docente = E.docente || {};
    E.docente.ubicacion = E.docente.ubicacion || 'adelante';
    E.docente.lado = E.docente.lado || 'izq';
    if (E.docente.id_equipo === undefined) E.docente.id_equipo = null;
    E.docente.numero = E.docente.numero || '';
    E.docente.red = E.docente.red || '';
    E.filas = (E.filas || []).map(function (f) {
      return { mesas: (f.mesas || []).map(function (m) {
        return { pasillo: !!m.pasillo, puestos: (m.puestos || []).map(function (p) {
          return { id_equipo: p.id_equipo ? Number(p.id_equipo) : null, numero: p.numero || '', red: p.red || '' };
        }) };
      }) };
    });
  }
  normalizarInicial();

  function el(tag, clase, texto) {
    const e = document.createElement(tag);
    if (clase) e.className = clase;
    if (texto != null) e.textContent = texto;
    return e;
  }
  function boton(texto, accion, titulo, extra) {
    const b = el('button', 'dist-mini' + (extra ? ' ' + extra : ''), texto);
    b.type = 'button';
    b.setAttribute('data-dist', accion);
    if (titulo) { b.title = titulo; b.setAttribute('aria-label', titulo); }
    return b;
  }

  /* Código sin el prefijo de la sala («SA402-PC07» → «PC07»), igual que codigoEnSala() en ui.php */
  function codigoEnSala(c) {
    const m = String(c || '').match(/^[A-Za-z]{1,5}\d{1,4}[-_ ]+(\S.*)$/);
    return m ? m[1] : String(c || '');
  }

  // Código corto (sin prefijo de sala) salvo que choque con otro equipo de la sala
  const cortos = {};
  equipos.forEach(function (e) { const k = codigoEnSala(e.codigo).toLowerCase(); cortos[k] = (cortos[k] || 0) + 1; });
  equipos.forEach(function (e) { const c = codigoEnSala(e.codigo); e._cod = cortos[c.toLowerCase()] > 1 ? e.codigo : c; });

  /* ---------- Recorridos y consultas ---------- */
  function recorrido(orden) {
    let out = [], idx = E.filas.map(function (_, i) { return i; });
    if (orden === 'fondo') idx.reverse();
    idx.forEach(function (iF, n) {
      const fila = [];
      E.filas[iF].mesas.forEach(function (m, iM) { m.puestos.forEach(function (_, iP) { fila.push([iF, iM, iP]); }); });
      if (orden === 'zigzag' && n % 2 === 1) fila.reverse();
      out = out.concat(fila);
    });
    return out;
  }
  function puestoEn(s) { return s.doc ? E.docente : E.filas[s.f].mesas[s.m].puestos[s.p]; }
  function dondeEsta(id) {
    if (E.docente.ubicacion !== 'ninguno' && E.docente.id_equipo === id) return { doc: true };
    for (let f = 0; f < E.filas.length; f++) for (let m = 0; m < E.filas[f].mesas.length; m++) {
      const ps = E.filas[f].mesas[m].puestos;
      for (let p = 0; p < ps.length; p++) if (ps[p].id_equipo === id) return { f: f, m: m, p: p };
    }
    return null;
  }
  function nombreLugar(s) {
    if (!s) return '';
    if (s.doc) return 'puesto docente';
    const n = puestoEn(s).numero;
    return n ? 'puesto n.º ' + n : 'fila ' + (s.f + 1) + ', mesa ' + (s.m + 1);
  }
  function igual(a, b) { return a && b && !!a.doc === !!b.doc && (a.doc || (a.f === b.f && a.m === b.m && a.p === b.p)); }

  function numerar() {
    let orden = $('dist-orden').value, n = parseInt($('dist-inicio').value, 10);
    if (isNaN(n)) n = 1;
    const pref = ($('dist-prefijo').value || '').trim();
    recorrido(orden).forEach(function (x) { E.filas[x[0]].mesas[x[1]].puestos[x[2]].numero = (pref + n++).slice(0, 10); });
    if (E.docente.ubicacion !== 'ninguno' && !E.docente.numero) E.docente.numero = 'D';
  }
  function trasEstructura() { if ($('dist-auto').checked) numerar(); cambio(); }

  /* ---------- Pintar ---------- */
  function pintarPuesto(p, s, tipo) {
    const eq = p.id_equipo ? porId[p.id_equipo] : null;
    const b = el('button', 'sala-puesto dist-puesto sala-puesto-' + (tipo || 'pc') + (eq ? ' est-realizado' : ' sala-puesto-vacio'));
    b.type = 'button';
    b.setAttribute('data-dist-sel', s.doc ? 'doc' : s.f + '-' + s.m + '-' + s.p);
    b.setAttribute('aria-pressed', igual(sel, s) ? 'true' : 'false');
    b.setAttribute('aria-label', (s.doc ? 'Puesto docente' : 'Puesto ' + (p.numero || 'sin número')) + (eq ? ': ' + eq.codigo : ': vacío'));
    if (p.numero) b.appendChild(el('span', 'sala-puesto-num', p.numero));
    b.appendChild(el('span', 'sala-puesto-cod', eq ? eq._cod : (s.doc ? 'Docente' : 'Vacío')));
    if (eq) b.title = eq.codigo + (eq.descripcion ? ' — ' + eq.descripcion : '');
    if (p.red) b.appendChild(el('span', 'dist-red', p.red));
    return b;
  }

  function pintarDocente() {
    const cont = el('div', 'sala-frente-puestos dist-docente ' + (E.docente.lado === 'der' ? 'dist-docente-der' : 'dist-docente-izq'));
    cont.appendChild(pintarPuesto(E.docente, { doc: true }, 'docente'));
    return cont;
  }

  function pintar() {
    plano.textContent = '';
    const frente = el('div', 'sala-frente');
    frente.appendChild(el('div', 'sala-tablero', 'Frente del salón · Tablero'));
    if (E.docente.ubicacion === 'adelante') frente.appendChild(pintarDocente());
    plano.appendChild(frente);

    if (!E.filas.length) plano.appendChild(el('p', 'text-muted dist-vacio', 'Sin filas. Usa «+ Fila» o «Estructura rápida».'));

    E.filas.forEach(function (fila, iF) {
      const fl = el('div', 'dist-fila');
      const cab = el('div', 'dist-fila-cab');
      cab.appendChild(el('strong', 'dist-fila-n', 'Fila ' + (iF + 1)));
      const acc = el('div', 'dist-fila-acc');
      acc.appendChild(boton('+ mesa', 'agregar-mesa', 'Agregar mesa a la fila ' + (iF + 1)));
      acc.appendChild(boton('⧉', 'duplicar-fila', 'Duplicar la fila ' + (iF + 1)));
      acc.appendChild(boton('↑', 'subir-fila', 'Subir la fila ' + (iF + 1)));
      acc.appendChild(boton('↓', 'bajar-fila', 'Bajar la fila ' + (iF + 1)));
      acc.appendChild(boton('✕', 'quitar-fila', 'Quitar la fila ' + (iF + 1), 'dist-mini-peligro'));
      acc.querySelectorAll('[data-dist]').forEach(function (b) { b.setAttribute('data-f', iF); });
      cab.appendChild(acc);
      fl.appendChild(cab);

      const mesas = el('div', 'dist-mesas');
      fila.mesas.forEach(function (mesa, iM) {
        const bloque = el('div', 'dist-mesa-bloque' + (mesa.pasillo && iM < fila.mesas.length - 1 ? ' dist-con-pasillo' : ''));
        const m = el('div', 'sala-mesa');
        mesa.puestos.forEach(function (p, iP) { m.appendChild(pintarPuesto(p, { f: iF, m: iM, p: iP }, 'pc')); });
        bloque.appendChild(m);
        const ctrl = el('div', 'dist-mesa-ctrl');
        ctrl.appendChild(boton('−', 'menos-puesto', 'Quitar un puesto a la mesa ' + (iM + 1)));
        ctrl.appendChild(el('span', 'dist-mesa-n', mesa.puestos.length + ' p.'));
        ctrl.appendChild(boton('+', 'mas-puesto', 'Agregar un puesto a la mesa ' + (iM + 1)));
        if (iM < fila.mesas.length - 1) {
          const bp = boton('⇥', 'pasillo', 'Pasillo a la derecha de la mesa ' + (iM + 1), mesa.pasillo ? 'activo' : '');
          bp.setAttribute('aria-pressed', mesa.pasillo ? 'true' : 'false');
          ctrl.appendChild(bp);
        }
        ctrl.appendChild(boton('✕', 'quitar-mesa', 'Quitar la mesa ' + (iM + 1), 'dist-mini-peligro'));
        ctrl.querySelectorAll('[data-dist]').forEach(function (b) { b.setAttribute('data-f', iF); b.setAttribute('data-m', iM); });
        bloque.appendChild(ctrl);
        mesas.appendChild(bloque);
      });
      fl.appendChild(mesas);
      plano.appendChild(fl);
    });

    if (E.docente.ubicacion === 'atras') {
      const fondo = el('div', 'sala-fondo');
      fondo.appendChild(pintarDocente());
      fondo.appendChild(el('div', 'sala-fondo-rotulo', 'Fondo del salón'));
      plano.appendChild(fondo);
    }
    pintarResumen();
    pintarSinPuesto();
    pintarPanelPuesto();
  }

  function pintarResumen() {
    let total = recorrido('filas').length, ocupados = 0;
    recorrido('filas').forEach(function (x) { if (E.filas[x[0]].mesas[x[1]].puestos[x[2]].id_equipo) ocupados++; });
    $('dist-resumen').textContent = E.filas.length + ' fila(s) · ' + total + ' puesto(s) · ' + ocupados + ' con equipo';
  }

  function sinPuesto() {
    return equipos.filter(function (e) { return !dondeEsta(e.id_equipo); });
  }
  function pintarSinPuesto() {
    const cont = $('dist-sin'), lista = sinPuesto();
    cont.textContent = '';
    $('dist-sin-total').textContent = '(' + lista.length + ')';
    $('dist-sin-ayuda').hidden = !lista.length;
    if (!lista.length) { cont.appendChild(el('p', 'hint m-0', 'Todos los equipos de la sala tienen puesto.')); return; }
    lista.forEach(function (e) {
      const b = el('button', 'dist-chip', e.codigo);
      b.type = 'button';
      b.setAttribute('data-dist', 'poner');
      b.setAttribute('data-id', e.id_equipo);
      if (e.tipo !== 'pc') b.title = 'Tipo: ' + e.tipo + (e.descripcion ? ' — ' + e.descripcion : '');
      else if (e.descripcion) b.title = e.descripcion;
      cont.appendChild(b);
    });
  }

  function pintarPanelPuesto() {
    const panel = $('dist-puesto');
    if (!sel) { panel.hidden = true; return; }
    const p = puestoEn(sel);
    panel.hidden = false;
    $('dist-puesto-tit').textContent = sel.doc ? 'Puesto del docente' : 'Fila ' + (sel.f + 1) + ' · Mesa ' + (sel.m + 1) + ' · Puesto ' + (sel.p + 1);
    const s = $('dist-equipo');
    s.textContent = '';
    const o0 = el('option', '', '— Sin equipo —'); o0.value = ''; s.appendChild(o0);
    equipos.forEach(function (e) {
      const donde = dondeEsta(e.id_equipo);
      const o = el('option', '', e.codigo + (donde && !igual(donde, sel) ? ' · en ' + nombreLugar(donde) : '') + (e.tipo !== 'pc' ? ' (' + e.tipo + ')' : ''));
      o.value = e.id_equipo;
      s.appendChild(o);
    });
    s.value = p.id_equipo ? String(p.id_equipo) : '';
    if (document.activeElement !== $('dist-numero')) $('dist-numero').value = p.numero || '';
    if (document.activeElement !== $('dist-red')) $('dist-red').value = p.red || '';
  }

  function cambio() {
    sucio = true;
    $('dist-estado').textContent = 'Cambios sin guardar';
    $('dist-estado').classList.add('dist-sucio');
    pintar();
  }

  /* ---------- Acciones ---------- */
  function asignar(s, id) {
    const dest = puestoEn(s);
    if (id) {
      const origen = dondeEsta(id);
      if (origen && !igual(origen, s)) puestoEn(origen).id_equipo = dest.id_equipo; // intercambio
    }
    dest.id_equipo = id || null;
    cambio();
  }
  function plantilla() {
    const nf = Math.max(1, Math.min(MAX_FILAS, parseInt($('dist-p-filas').value, 10) || 1));
    const nm = Math.max(1, Math.min(MAX_MESAS, parseInt($('dist-p-mesas').value, 10) || 1));
    const np = Math.max(1, Math.min(MAX_PUESTOS, parseInt($('dist-p-puestos').value, 10) || 1));
    const pas = parseInt($('dist-p-pasillo').value, 10) || 0;
    if (recorrido('filas').length && !window.confirm('Se reemplazan las filas actuales por ' + nf + ' fila(s) de ' + nm + ' mesa(s) de ' + np + ' puesto(s). Los equipos se vuelven a ubicar en orden. ¿Continuar?')) return;
    const antes = [];
    recorrido('filas').forEach(function (x) { const id = E.filas[x[0]].mesas[x[1]].puestos[x[2]].id_equipo; if (id) antes.push(id); });
    E.filas = [];
    for (let f = 0; f < nf; f++) {
      const fila = { mesas: [] };
      for (let m = 1; m <= nm; m++) {
        const ps = []; for (let p = 0; p < np; p++) ps.push(puestoNuevo());
        fila.mesas.push({ pasillo: m === pas && m < nm, puestos: ps });
      }
      E.filas.push(fila);
    }
    // Los equipos que estaban ubicados vuelven a entrar en el mismo orden.
    const cola = antes.slice();
    recorrido('filas').forEach(function (x) { if (cola.length) E.filas[x[0]].mesas[x[1]].puestos[x[2]].id_equipo = cola.shift(); });
    sel = null;
    numerar();
    cambio();
  }
  function ubicar() {
    const lista = sinPuesto().filter(function (e) { return e.tipo === 'pc'; }).map(function (e) { return e.id_equipo; });
    if (!lista.length) { window.alert('No hay equipos de cómputo sin puesto.'); return; }
    recorrido($('dist-orden').value).forEach(function (x) {
      const p = E.filas[x[0]].mesas[x[1]].puestos[x[2]];
      if (!p.id_equipo && lista.length) p.id_equipo = lista.shift();
    });
    if (lista.length) window.alert('Faltan ' + lista.length + ' puesto(s): agrega mesas o puestos para ubicar el resto.');
    cambio();
  }

  plano.addEventListener('click', function (ev) {
    const t = ev.target.closest('[data-dist-sel]');
    if (!t) return;
    const v = t.getAttribute('data-dist-sel');
    if (v === 'doc') sel = { doc: true };
    else { const a = v.split('-').map(Number); sel = { f: a[0], m: a[1], p: a[2] }; }
    pintar();
    const panel = $('dist-puesto');
    if (window.matchMedia('(max-width: 1023px)').matches) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    $('dist-equipo').focus({ preventScroll: true });
  });

  form.addEventListener('click', function (ev) {
    const b = ev.target.closest('[data-dist]');
    if (!b) return;
    const acc = b.getAttribute('data-dist');
    const f = Number(b.getAttribute('data-f')), m = Number(b.getAttribute('data-m'));
    const fila = E.filas[f], mesa = fila ? fila.mesas[m] : null;
    switch (acc) {
      case 'agregar-fila': {
        if (E.filas.length >= MAX_FILAS) return window.alert('Máximo ' + MAX_FILAS + ' filas.');
        const ultima = E.filas[E.filas.length - 1];
        E.filas.push(ultima ? { mesas: ultima.mesas.map(function (x) { return { pasillo: x.pasillo, puestos: x.puestos.map(puestoNuevo) }; }) }
                            : { mesas: [{ pasillo: false, puestos: [puestoNuevo(), puestoNuevo(), puestoNuevo(), puestoNuevo()] }] });
        return trasEstructura();
      }
      case 'agregar-mesa':
        if (fila.mesas.length >= MAX_MESAS) return window.alert('Máximo ' + MAX_MESAS + ' mesas por fila.');
        fila.mesas.push({ pasillo: false, puestos: (fila.mesas[fila.mesas.length - 1] || { puestos: [1, 2, 3, 4] }).puestos.map(puestoNuevo) });
        return trasEstructura();
      case 'duplicar-fila':
        if (E.filas.length >= MAX_FILAS) return window.alert('Máximo ' + MAX_FILAS + ' filas.');
        E.filas.splice(f + 1, 0, { mesas: fila.mesas.map(function (x) { return { pasillo: x.pasillo, puestos: x.puestos.map(puestoNuevo) }; }) });
        sel = null; return trasEstructura();
      case 'subir-fila': case 'bajar-fila': {
        const d = acc === 'subir-fila' ? f - 1 : f + 1;
        if (d < 0 || d >= E.filas.length) return;
        E.filas.splice(d, 0, E.filas.splice(f, 1)[0]);
        sel = null; return trasEstructura();
      }
      case 'quitar-fila':
        if (fila.mesas.some(function (x) { return x.puestos.some(function (p) { return p.id_equipo; }); }) && !window.confirm('La fila ' + (f + 1) + ' tiene equipos ubicados; quedarán sin puesto. ¿Quitarla?')) return;
        E.filas.splice(f, 1); sel = null; return trasEstructura();
      case 'mas-puesto':
        if (mesa.puestos.length >= MAX_PUESTOS) return window.alert('Máximo ' + MAX_PUESTOS + ' puestos por mesa.');
        mesa.puestos.push(puestoNuevo()); return trasEstructura();
      case 'menos-puesto':
        if (mesa.puestos.length <= 1) return;
        if (mesa.puestos[mesa.puestos.length - 1].id_equipo && !window.confirm('El último puesto de esta mesa tiene un equipo; quedará sin puesto. ¿Continuar?')) return;
        mesa.puestos.pop(); sel = null; return trasEstructura();
      case 'pasillo':
        mesa.pasillo = !mesa.pasillo; return cambio();
      case 'quitar-mesa':
        if (mesa.puestos.some(function (p) { return p.id_equipo; }) && !window.confirm('Esta mesa tiene equipos ubicados; quedarán sin puesto. ¿Quitarla?')) return;
        fila.mesas.splice(m, 1);
        if (!fila.mesas.length) E.filas.splice(f, 1);
        sel = null; return trasEstructura();
      case 'plantilla': return plantilla();
      case 'numerar': numerar(); return cambio();
      case 'ubicar': return ubicar();
      case 'vaciar-todo':
        if (!window.confirm('¿Quitar todos los equipos de sus puestos? La estructura y los números se conservan.')) return;
        recorrido('filas').forEach(function (x) { E.filas[x[0]].mesas[x[1]].puestos[x[2]].id_equipo = null; });
        E.docente.id_equipo = null; return cambio();
      case 'poner':
        if (!sel) { window.alert('Primero toca en el plano el puesto donde va este equipo.'); return; }
        return asignar(sel, Number(b.getAttribute('data-id')));
      case 'vaciar-puesto': if (sel) asignar(sel, null); return;
      case 'cerrar-puesto': sel = null; return pintar();
    }
  });

  $('dist-equipo').addEventListener('change', function () { if (sel) asignar(sel, this.value ? Number(this.value) : null); });
  $('dist-numero').addEventListener('input', function () { if (!sel) return; puestoEn(sel).numero = this.value.trim().slice(0, 10); cambio(); });
  $('dist-red').addEventListener('input', function () { if (!sel) return; puestoEn(sel).red = this.value.trim().slice(0, 30); cambio(); });
  $('dist-doc-ubic').value = E.docente.ubicacion;
  $('dist-doc-lado').value = E.docente.lado;
  $('dist-doc-ubic').addEventListener('change', function () {
    E.docente.ubicacion = this.value;
    if (this.value === 'ninguno') { E.docente.id_equipo = null; if (sel && sel.doc) sel = null; }
    cambio();
  });
  $('dist-doc-lado').addEventListener('change', function () { E.docente.lado = this.value; cambio(); });

  // Si la estructura actual ya tiene la forma de una plantilla, la «Estructura rápida» arranca con esos valores.
  if (E.filas.length) {
    const f0 = E.filas[0].mesas;
    $('dist-p-filas').value = E.filas.length;
    $('dist-p-mesas').value = f0.length;
    $('dist-p-puestos').value = f0.length ? f0[0].puestos.length : 4;
    const ip = f0.findIndex(function (x) { return x.pasillo; });
    $('dist-p-pasillo').value = ip >= 0 ? ip + 1 : 0;
  }

  // Enter en un campo del panel no envía el formulario (se guarda solo con el botón).
  form.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && ev.target.matches('.dist-panel input')) ev.preventDefault();
  });

  form.addEventListener('submit', function (ev) {
    const sub = ev.submitter;
    if (sub && sub.name !== 'guardar_distribucion') return;
    $('dist-estructura').value = JSON.stringify(E);
    sucio = false;
  });
  window.addEventListener('beforeunload', function (ev) { if (sucio) { ev.preventDefault(); ev.returnValue = ''; } });

  pintar();
})();
