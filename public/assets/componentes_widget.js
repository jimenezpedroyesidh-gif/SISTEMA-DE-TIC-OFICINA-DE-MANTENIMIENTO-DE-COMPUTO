/* =========================================================
   Buscador de componentes por código/serial + lector por cámara. Requiere assets/app.js (UFPS.esc,
   UFPS.fetchJson).
   Endpoint: buscar_componente_ajax.php  (GET q= | id= , disponibles=0|1, sala=)
   El HTML del widget llama a: buscarComponenteWidget, abrirLectorComponente,
   cerrarLectorComponente.
   ========================================================= */
/** Botón de resultado. Los datos vienen de la BD (o de un QR leído): siempre escapados. */
function crearItemComponente(prefix, campo, c, contenedor){
  const e = UFPS.esc;
  const item = document.createElement('button');
  item.type = 'button';
  item.className = 'comp-search-item';
  item.innerHTML = '<strong>' + e(c.codigo_inventario) + '</strong> — ' + e(c.tipo) +
    (c.marca ? ' (' + e(c.marca) + ' ' + e(c.modelo) + ')' : '') +
    '<br><span class="text-muted">' + e(c.ubicacion) + '</span>';
  item.onclick = function(){
    seleccionarComponenteWidget(prefix, campo, c);
    contenedor.innerHTML = '';
  };
  return item;
}

function buscarComponenteWidget(input, prefix, campo, soloDisponibles){
  clearTimeout(window['bcw_timeout_' + prefix + campo]);
  const q = input.value.trim();
  const contenedor = document.getElementById(prefix + 'resultados_' + campo);
  if (q.length < 2) { contenedor.innerHTML = ''; return; }

  window['bcw_timeout_' + prefix + campo] = setTimeout(function(){
    ejecutarBusquedaComponente(prefix, campo, q, soloDisponibles, function(data){
      contenedor.innerHTML = '';
      if (!data.length) {
        contenedor.innerHTML = '<p class="text-muted">Sin resultados.</p>';
        return;
      }
      data.forEach(c => contenedor.appendChild(crearItemComponente(prefix, campo, c, contenedor)));
    });
  }, 250);
}

function seleccionarComponenteWidget(prefix, campo, c){
  document.getElementById(prefix + 'id_componente_' + campo).value = c.id;
  document.getElementById(prefix + 'seleccionado_' + campo).textContent = 'Seleccionado: ' + c.codigo_inventario;
  document.getElementById(prefix + 'buscar_' + campo).value = c.codigo_inventario;
}

/** Encuentra la sala de contexto (fija o desde un <select> vivo) para el campo dado. */
function salaContextoComponente(prefix, campo){
  const input = document.getElementById(prefix + 'buscar_' + campo);
  const campoDiv = input ? input.closest('[data-sala-fija], [data-sala-selector]') : null;
  if (!campoDiv) return '';
  const selectorId = campoDiv.getAttribute('data-sala-selector');
  if (selectorId) {
    const sel = document.getElementById(selectorId);
    if (sel && sel.value) return sel.value;
  }
  return campoDiv.getAttribute('data-sala-fija') || '';
}

function ejecutarBusquedaComponente(prefix, campo, q, soloDisponibles, callback){
  const sala = salaContextoComponente(prefix, campo);
  let url = 'buscar_componente_ajax.php?q=' + encodeURIComponent(q) + '&disponibles=' + (soloDisponibles ? '1' : '0');
  if (sala) url += '&sala=' + encodeURIComponent(sala);
  UFPS.fetchJson(url).then(callback);
}

/** Igual que ejecutarBusquedaComponente(), pero para el QR nuevo de
 *  ficha_componente.php, que guarda el id del componente (no el código
 *  en texto) — evita tener que escribir/reescribir el código a mano. */
function ejecutarBusquedaComponentePorId(prefix, campo, idComponente, soloDisponibles, callback){
  const sala = salaContextoComponente(prefix, campo);
  let url = 'buscar_componente_ajax.php?id=' + encodeURIComponent(idComponente) + '&disponibles=' + (soloDisponibles ? '1' : '0');
  if (sala) url += '&sala=' + encodeURIComponent(sala);
  UFPS.fetchJson(url).then(callback);
}

/* ---------- Lector de código por cámara (reutiliza html5-qrcode) ---------- */
let compQrPrefixActivo = null, compQrCampoActivo = null, compQrSoloDisponiblesActivo = false, compQrLector = null;

function cargarLibreriaQr(){
  if (typeof Html5Qrcode !== 'undefined') return Promise.resolve();
  if (window.__html5QrcodeCargando) return window.__html5QrcodeCargando;
  window.__html5QrcodeCargando = new Promise(function(resolve, reject){
    const script = document.createElement('script');
    script.src = 'assets/vendor/html5-qrcode-2.3.8/html5-qrcode.min.js';
    script.onload = resolve;
    script.onerror = reject;
    document.head.appendChild(script);
  });
  return window.__html5QrcodeCargando;
}

function abrirLectorComponente(prefix, campo, soloDisponibles){
  compQrPrefixActivo = prefix;
  compQrCampoActivo = campo;
  compQrSoloDisponiblesActivo = soloDisponibles;
  document.getElementById('compQrModal').hidden = false;
  document.getElementById('compQrEstado').textContent = 'Cargando lector…';

  cargarLibreriaQr().then(function(){
    compQrLector = new Html5Qrcode('compQrReader');
    Html5Qrcode.getCameras().then(function(camaras){
      if (!camaras || !camaras.length) {
        document.getElementById('compQrEstado').textContent = 'No se encontró ninguna cámara. Escribe el código manualmente.';
        return;
      }
      const camaraElegida = camaras.find(c => /back|rear|trasera/i.test(c.label)) || camaras[0];
      compQrLector.start(
        camaraElegida.id,
        { fps: 10, qrbox: { width: 250, height: 250 } },
        function(textoDecodificado){ onCodigoLeidoComponente(textoDecodificado); },
        function(){ /* frame sin código detectado, normal mientras enfoca */ }
      ).then(function(){
        document.getElementById('compQrEstado').textContent = 'Apunta la cámara al código pegado en el componente.';
      }).catch(function(){
        document.getElementById('compQrEstado').textContent = 'No se pudo activar la cámara (permiso denegado o no disponible).';
      });
    }).catch(function(){
      document.getElementById('compQrEstado').textContent = 'No se pudo acceder a la cámara.';
    });
  }).catch(function(){
    document.getElementById('compQrEstado').textContent = 'No se pudo cargar el lector. Escribe el código manualmente.';
  });
}

function onCodigoLeidoComponente(textoDecodificado){
  const prefix = compQrPrefixActivo, campo = compQrCampoActivo, soloDisponibles = compQrSoloDisponiblesActivo;
  cerrarLectorComponente();

  const texto = textoDecodificado.trim();
  const contenedor = document.getElementById(prefix + 'resultados_' + campo);

  function mostrarResultados(codigoParaMostrar, data, coincidenciaExacta){
    if (coincidenciaExacta) {
      seleccionarComponenteWidget(prefix, campo, coincidenciaExacta);
      contenedor.innerHTML = '';
      return;
    }
    contenedor.innerHTML = '';
    if (!data.length) {
      contenedor.innerHTML = '<p class="text-muted">Código leído: ' + UFPS.esc(codigoParaMostrar) + '. Sin resultados en esta sala/bodega.</p>';
      return;
    }
    data.forEach(c => contenedor.appendChild(crearItemComponente(prefix, campo, c, contenedor)));
  }

  // QR nuevo de ficha_componente.php: guarda un enlace, no el código en
  // texto plano. Se busca directo por id — sin necesidad de escribir nada.
  let idDesdeUrl = null;
  try {
    const url = new URL(texto);
    if (/\/ficha_componente\.php$/.test(url.pathname)) {
      idDesdeUrl = url.searchParams.get('id');
    }
  } catch (e) { /* no era una URL, sigue como código de texto normal */ }

  if (idDesdeUrl) {
    document.getElementById(prefix + 'buscar_' + campo).value = 'Leído por QR…';
    ejecutarBusquedaComponentePorId(prefix, campo, idDesdeUrl, soloDisponibles, function(data){
      mostrarResultados('QR #' + idDesdeUrl, data, data[0] || null);
    });
    return;
  }

  // Código/serial pegado en el componente (adhesivo con solo texto),
  // como siempre.
  const codigo = texto;
  document.getElementById(prefix + 'buscar_' + campo).value = codigo;
  ejecutarBusquedaComponente(prefix, campo, codigo, soloDisponibles, function(data){
    const exacto = data.find(c => c.codigo_inventario === codigo);
    mostrarResultados(codigo, data, exacto);
  });
}

function cerrarLectorComponente(){
  document.getElementById('compQrModal').hidden = true;
  if (compQrLector) {
    compQrLector.stop().catch(() => {});
    compQrLector = null;
  }
}

/* ---------- Eventos delegados (sustituyen onclick/oninput del HTML) ---------- */
document.addEventListener('input', function(ev){
  const el = ev.target.closest ? ev.target.closest('[data-comp-buscar]') : null;
  if (el) buscarComponenteWidget(el, el.dataset.compPrefix || '', el.dataset.compCampo, el.dataset.compSoloDisp === '1');
});
document.addEventListener('click', function(ev){
  const leer = ev.target.closest ? ev.target.closest('[data-comp-leer]') : null;
  if (leer) { abrirLectorComponente(leer.dataset.compPrefix || '', leer.dataset.compCampo, leer.dataset.compSoloDisp === '1'); return; }
  if (ev.target.closest && ev.target.closest('[data-comp-cerrar]')) cerrarLectorComponente();
});
