/* Escáner de QR (escanear_qr.php). Requiere html5-qrcode (CDN). Redirige a reportar.php?token= /
   ficha_equipo.php?id= / ficha_componente.php?id= */
const statusEl   = document.getElementById('qr-status');
const fallbackEl = document.getElementById('fallback');

function extraerTokenYRedirigir(textoDecodificado){
  try{
    const url = new URL(textoDecodificado);

    const token = url.searchParams.get('token');
    const esReportar = /\/reportar\.php$/.test(url.pathname);
    if(esReportar && token){
      window.location.href = 'reportar.php?token=' + encodeURIComponent(token);
      return true;
    }

    // QR de equipo o componente (bodega/inventario) — lleva directo a la
    // ficha; si el rol no tiene acceso, esa misma página redirige a
    // login.php, así que no hace falta validar el rol aquí.
    const idInventario = url.searchParams.get('id');
    const esFichaEquipo = /\/ficha_equipo\.php$/.test(url.pathname);
    const esFichaComponente = /\/ficha_componente\.php$/.test(url.pathname);
    if((esFichaEquipo || esFichaComponente) && idInventario){
      const destino = (esFichaEquipo ? 'ficha_equipo.php' : 'ficha_componente.php') + '?id=' + encodeURIComponent(idInventario);
      window.location.href = destino;
      return true;
    }
  } catch(e){
    // No era una URL válida — no hacemos nada, seguimos escaneando
  }
  return false;
}

function mostrarFallback(mensaje){
  statusEl.textContent = mensaje;
  fallbackEl.hidden = false;
}

function irAToken(ev){
  ev.preventDefault();
  const valor = document.getElementById('token_manual').value.trim();
  if(!valor) return false;

  // Si pegaron un enlace completo (equipo/componente o incluso el de
  // sala), reutiliza la misma lógica del escaneo por cámara.
  if(/^https?:\/\//i.test(valor) && extraerTokenYRedirigir(valor)){
    return false;
  }

  // Si no, se asume que es un token de sala pegado directo (sin URL).
  window.location.href = 'reportar.php?token=' + encodeURIComponent(valor);
  return false;
}

if(typeof Html5Qrcode === 'undefined'){
  mostrarFallback('No se pudo cargar el lector de QR. Puedes escribir el token manualmente:');
} else {
  const lector = new Html5Qrcode('qr-reader');
  let yaRedirigido = false;

  Html5Qrcode.getCameras().then(camaras => {
    if(!camaras || camaras.length === 0){
      mostrarFallback('No se encontró ninguna cámara en este dispositivo. Escribe el token manualmente:');
      return;
    }

    // Prioriza la cámara trasera en celulares
    const camaraElegida = camaras.find(c => /back|rear|trasera/i.test(c.label)) || camaras[0];

    lector.start(
      camaraElegida.id,
      { fps: 10, qrbox: { width: 250, height: 250 } },
      (textoDecodificado) => {
        if(yaRedirigido) return;
        if(extraerTokenYRedirigir(textoDecodificado)){
          yaRedirigido = true;
          lector.stop().catch(() => {});
        }
      },
      () => { /* frame sin QR detectado — se ignora, es normal mientras se enfoca */ }
    ).then(() => {
      statusEl.textContent = 'Apunta la cámara al código QR de la sala.';
    }).catch(() => {
      mostrarFallback('No se pudo activar la cámara (permiso denegado o no disponible). Escribe el token manualmente:');
    });
  }).catch(() => {
    mostrarFallback('No se pudo acceder a la cámara. Escribe el token manualmente:');
  });
}

document.getElementById('form-token').addEventListener('submit', irAToken);
