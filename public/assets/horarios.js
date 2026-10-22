/* Horarios de salas (grilla semanal, popovers de edición/excepción, resaltado "ahora"). Requiere
   assets/app.js. Datos de PHP: <script type="application/json" id="horarios-config"> {periodo, userId}.
   Endpoint: ninguno (los formularios envían por POST a horarios.php). */
const CFG = JSON.parse(document.getElementById('horarios-config').textContent);
function toggleCamposHorario(){
  const tipo = document.getElementById('tipo').value;
  const hint = document.getElementById('sala-opcional-hint');
  hint.hidden = (tipo === 'CLASE');
}

// ================== Pestañas de sala (grilla semanal) ==================
function mostrarSalaHorario(idSala, btn){
  document.querySelectorAll('.horario-grid-wrap').forEach(function(panel){
    panel.hidden = parseInt(panel.getAttribute('data-sala-panel'), 10) !== idSala;
  });
  document.querySelectorAll('[data-sala-nota]').forEach(function(n){
    n.hidden = parseInt(n.getAttribute('data-sala-nota'), 10) !== idSala;
  });
  document.querySelectorAll('.sala-tab').forEach(function(t){ t.classList.remove('active'); });
  if (btn) btn.classList.add('active');
}

// ================== Edición inline (admin/técnico) desde el mismo recuadro ==================
function abrirEdicionBloque(el){
  const pop = document.getElementById('popover-editar-horario');
  if (!pop) return;
  document.getElementById('pe-id').value = el.dataset.idHorario || '';
  document.getElementById('pe-tipo').value = el.dataset.tipo || 'CLASE';
  document.getElementById('pe-periodo').value = el.dataset.periodo || CFG.periodo;
  document.getElementById('pe-sala').value = el.dataset.idSala || '';
  document.getElementById('pe-usuario').value = el.dataset.idUsuario || '';
  document.getElementById('pe-dia').value = el.dataset.dia || '1';
  document.getElementById('pe-inicio').value = el.dataset.inicio || '';
  document.getElementById('pe-fin').value = el.dataset.fin || '';
  document.getElementById('pe-detalle').value = el.dataset.detalle || '';

  pop.hidden = false;
  const panel = pop.querySelector('.hg-popover-panel');
  if (window.matchMedia('(max-width: 639px)').matches) return; // en móvil el panel queda fijo por CSS

  const r = el.getBoundingClientRect();
  panel.style.position = 'absolute';
  let top = window.scrollY + r.bottom + 6;
  let left = window.scrollX + r.left;
  const maxLeft = window.scrollX + document.documentElement.clientWidth - panel.offsetWidth - 12;
  const maxTop = window.scrollY + document.documentElement.clientHeight - panel.offsetHeight - 12;
  left = Math.max(12, Math.min(left, maxLeft));
  top = Math.max(window.scrollY + 12, Math.min(top, maxTop));
  panel.style.top = top + 'px';
  panel.style.left = left + 'px';
}

function cerrarPopover(){
  const pop = document.getElementById('popover-editar-horario');
  if (pop) pop.hidden = true;
}

// ================== Crear franja tocando una celda vacía ==================
function abrirCreacionCelda(el){
  const pop = document.getElementById('popover-crear-horario');
  if (!pop) return;
  document.getElementById('pc-tipo').value = 'CLASE';
  document.getElementById('pc-periodo').value = CFG.periodo;
  const sala = el.dataset.sala || '';
  document.getElementById('pc-sala').value = (sala && sala !== '0') ? sala : '';
  document.getElementById('pc-usuario').value = '';
  document.getElementById('pc-dia').value = el.dataset.dia || '1';
  document.getElementById('pc-inicio').value = el.dataset.inicio || '';
  document.getElementById('pc-fin').value = el.dataset.fin || '';
  document.getElementById('pc-detalle').value = '';
  // Técnico: el mismo clic sirve para programar un mantenimiento en esa sala, día y hora.
  const linkMant = document.getElementById('pc-link-mant');
  if (linkMant) {
    const q = new URLSearchParams({ nuevo: '1', fecha: (el.dataset.fecha || '') + 'T' + (el.dataset.inicio || '07:00') });
    if (sala && sala !== '0') q.set('sala', sala);
    linkMant.href = 'mantenimientos_programados.php?' + q.toString();
  }

  pop.hidden = false;
  const panel = pop.querySelector('.hg-popover-panel');
  if (window.matchMedia('(max-width: 639px)').matches) return; // en móvil el panel queda fijo por CSS

  const r = el.getBoundingClientRect();
  panel.style.position = 'absolute';
  let top = window.scrollY + r.bottom + 6;
  let left = window.scrollX + r.left;
  const maxLeft = window.scrollX + document.documentElement.clientWidth - panel.offsetWidth - 12;
  const maxTop = window.scrollY + document.documentElement.clientHeight - panel.offsetHeight - 12;
  left = Math.max(12, Math.min(left, maxLeft));
  top = Math.max(window.scrollY + 12, Math.min(top, maxTop));
  panel.style.top = top + 'px';
  panel.style.left = left + 'px';
}
function cerrarPopoverCrear(){
  const pop = document.getElementById('popover-crear-horario');
  if (pop) pop.hidden = true;
}

document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { cerrarPopover(); cerrarPopoverCrear(); cerrarPopoverExcepcion(); } });

// ================== Excepción puntual "solo esta semana" ==================
function toggleCamposExcepcion(){
  const tipo = document.getElementById('pex-tipo').value;
  document.getElementById('pex-campo-sala').hidden = (tipo !== 'CAMBIO_SALA');
  document.getElementById('pex-campo-hora').hidden = (tipo !== 'CAMBIO_HORA');
}
function abrirExcepcionBloque(el){
  const pop = document.getElementById('popover-excepcion-horario');
  if (!pop) return;
  document.getElementById('pex-id-horario').value = el.dataset.idHorario || '';
  document.getElementById('pex-fecha').value = el.dataset.fecha || '';
  document.getElementById('pex-fecha-texto').textContent = 'Fecha: ' + (el.dataset.fecha || '');
  document.getElementById('pex-tipo').value = el.dataset.excepcionTipo || 'CANCELADO';
  document.getElementById('pex-sala').value = el.dataset.excepcionSala || '';
  document.getElementById('pex-inicio').value = el.dataset.excepcionInicio || '';
  document.getElementById('pex-fin').value = el.dataset.excepcionFin || '';
  document.getElementById('pex-motivo').value = el.dataset.excepcionMotivo || '';
  const btnQuitar = document.getElementById('pex-btn-quitar');
  if (el.dataset.idExcepcion) {
    btnQuitar.hidden = false;
    btnQuitar.closest('form').querySelector('[name="id_excepcion"]')?.remove();
    const inputExc = document.createElement('input');
    inputExc.type = 'hidden'; inputExc.name = 'id_excepcion'; inputExc.value = el.dataset.idExcepcion;
    btnQuitar.closest('form').appendChild(inputExc);
  } else {
    btnQuitar.hidden = true;
  }
  toggleCamposExcepcion();
  pop.hidden = false;
}
function cerrarPopoverExcepcion(){
  const pop = document.getElementById('popover-excepcion-horario');
  if (pop) pop.hidden = true;
}

// ================== Resaltado en tiempo real: turno actual / mantenimiento en curso ==================
const HORARIOS_USER_ID = CFG.userId;
function actualizarResaltadoAhora(){
  const ahora = new Date();
  const diaJs = ahora.getDay(); // 0=domingo
  const diaISO = diaJs === 0 ? 7 : diaJs;
  const hhmm = String(ahora.getHours()).padStart(2, '0') + ':' + String(ahora.getMinutes()).padStart(2, '0');
  // Bloques sueltos, cada línea de un grupo y los bloques de «mi semana» (grilla y lista del celular).
  // Solo en la semana actual: en otra semana nada está «en curso».
  document.querySelectorAll('.hg-bloque:not(.hg-grupo), .hg-linea, .hg-abrir, .hg-agenda-item').forEach(function(b){
    const dia = parseInt(b.dataset.dia, 10);
    const enCurso = CFG.semanaActual !== false && dia === diaISO && hhmm >= b.dataset.inicio && hhmm < b.dataset.fin;
    b.classList.toggle('hg-ahora', enCurso);
    const esMio = b.dataset.usuario && parseInt(b.dataset.usuario, 10) === HORARIOS_USER_ID;
    b.classList.toggle('hg-mi-turno-ahora', enCurso && esMio);
  });
}
actualizarResaltadoAhora();
setInterval(actualizarResaltadoAhora, 30000);

// ================== Acciones declarativas ==================
UFPS.accion('horarios.sala', function (el) { mostrarSalaHorario(parseInt(el.dataset.sala, 10), el); });
UFPS.accion('horarios.crear', abrirCreacionCelda);
UFPS.accion('horarios.editar', abrirEdicionBloque);
UFPS.accion('horarios.excepcion', abrirExcepcionBloque);
UFPS.accion('horarios.cerrar', cerrarPopover);
UFPS.accion('horarios.cerrarCrear', cerrarPopoverCrear);
UFPS.accion('horarios.cerrarExcepcion', cerrarPopoverExcepcion);
UFPS.accion('horarios.toggleCampos', toggleCamposHorario);
UFPS.accion('horarios.toggleCamposExcepcion', toggleCamposExcepcion);
if (document.getElementById('tipo')) toggleCamposHorario();
