/* =========================================================
   Scripts del panel de tickets del técnico/solicitante. Requiere assets/app.js (UFPS.llenarSelectEquipos) y
   assets/tickets.js.
   ========================================================= */
// Pendientes y Firmados son dos listas visibles con su propia paginación (TicketBandeja): ya no hay bloques ocultos.

// ====== Resolución de tickets de "sala general" (sin equipo puntual) ======
// Carga el <select> de equipos de la sala del ticket (mismo endpoint que
// ya usa mantenimientos_programados.php) y controla qué bloque de campos
// se pide según el tipo de resolución elegido (cambio de pieza / traslado
// a oficina / solución en sitio), para no exigir campos que están ocultos.
function cargarEquiposDeSalaParaTicket(select){
  UFPS.llenarSelectEquipos(select, select.dataset.idSala,
    { cargando: 'Cargando equipos de la sala…', sinSala: '-- Sin sala asociada --', lista: '-- Selecciona el equipo --' });
}

function toggleTipoResolucionGeneral(select){
  const form = select.closest('form');
  const tipoElegido = select.value;
  form.querySelectorAll('.bloque-tipo-resolucion').forEach(bloque => {
    bloque.hidden = bloque.dataset.tipo !== tipoElegido;
  });
  // El campo único "Solución" aplica a "cambio_pieza" y "solucion", pero no
  // a "traslado_oficina" (ese caso solo cambia el estado del ticket, el
  // detalle se llena luego en el flujo de traslado ya existente).
  const campoSolucion = form.querySelector('.campo-solucion-general');
  if (campoSolucion) {
    const visible = tipoElegido === 'cambio_pieza' || tipoElegido === 'solucion';
    campoSolucion.hidden = !visible;
    const textarea = campoSolucion.querySelector('textarea[name="solucion"]');
    if (textarea) { textarea.required = (tipoElegido === 'solucion'); }
  }
}

// Un ticket "libre" (formulario clásico de solicitante, sin sala/equipo
// real vinculado) puede en la práctica describir un equipo real de un
// salón (ej. "SA401-PC01, torre no enciende"). Al elegir una sala aquí,
// se activa exactamente el mismo bloque de "sala_general" (equipo +
// tipo de resolución + componente) y se desactivan los campos de texto
// libre de Marca/Modelo/Inventario/Solución — usando `disabled` (no solo
// display:none) para que el navegador realmente deje de enviarlos y no
// colisionen con los del otro bloque, que comparten el mismo `name`.
function prepararSalaLibre(select){
  const form = select.closest('form');
  const idSala = select.value;
  const modoInput = form.querySelector('.input-modo-resolucion');
  const inputIdSala = form.querySelector('.input-id-sala-libre');
  const bloqueConSala = form.querySelector('.bloque-con-sala');
  const bloqueSinSala = form.querySelector('.bloque-libre-sin-sala');
  const selectEquipo = form.querySelector('.select-equipo-afectado');
  const selectTipo = form.querySelector('.select-tipo-resolucion');

  if (idSala) {
    modoInput.value = 'libre_con_sala';
    inputIdSala.value = idSala;
    bloqueConSala.hidden = false;
    bloqueSinSala.hidden = true;
    bloqueSinSala.querySelectorAll('input, textarea').forEach(el => { el.disabled = true; });
    selectEquipo.disabled = false;
    selectEquipo.required = true;
    selectEquipo.dataset.idSala = idSala;
    selectTipo.disabled = false;
    selectTipo.required = true;
    const campoSolucionGeneral = bloqueConSala.querySelector('.campo-solucion-general textarea[name="solucion"]');
    if (campoSolucionGeneral) { campoSolucionGeneral.disabled = false; }
    cargarEquiposDeSalaParaTicket(selectEquipo);
  } else {
    modoInput.value = 'libre';
    inputIdSala.value = '';
    bloqueConSala.hidden = true;
    bloqueSinSala.hidden = false;
    bloqueSinSala.querySelectorAll('input, textarea').forEach(el => { el.disabled = false; });
    selectEquipo.disabled = true;
    selectEquipo.required = false;
    selectTipo.disabled = true;
    selectTipo.required = false;
    selectTipo.value = '';
    // Vuelve a ocultar/desactivar los sub-bloques de tipo de resolución y
    // sus campos (componente, solución compartida) por si ya se habían
    // llegado a mostrar antes de quitar la sala.
    bloqueConSala.querySelectorAll('.bloque-tipo-resolucion').forEach(b => {
      b.hidden = true;
      b.querySelectorAll('input, textarea').forEach(el => { el.disabled = true; });
    });
    const campoSolucionGeneral = bloqueConSala.querySelector('.campo-solucion-general');
    if (campoSolucionGeneral) {
      campoSolucionGeneral.hidden = true;
      const ta = campoSolucionGeneral.querySelector('textarea[name="solucion"]');
      if (ta) { ta.disabled = true; ta.required = false; }
    }
  }
}

document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.select-equipo-afectado').forEach(sel => { if (!sel.disabled) cargarEquiposDeSalaParaTicket(sel); });
  document.querySelectorAll('.select-tipo-resolucion').forEach(sel => {
    sel.addEventListener('change', function(){ toggleTipoResolucionGeneral(this); });
  });

  // Al elegir "Trasladado a bodega/oficina" en un ticket con equipo puntual, se abre sola la "Propuesta de
  // cambio de pieza" para que el técnico vea de inmediato que ahora tiene que elegir el componente.
  document.querySelectorAll('.form-resolucion-ticket').forEach(form => {
    const estadoSel = form.querySelector('select[name="estado"]');
    const detallesTraslado = form.querySelector('[data-widget-traslado]');
    if (estadoSel && detallesTraslado) {
      estadoSel.addEventListener('change', function(){
        if (estadoSel.value === 'Listo pero sin validar') { detallesTraslado.open = true; }
      });
    }
  });

  // "required" en un <input type="hidden"> no lo valida ningún navegador
  // (la spec de HTML lo excluye explícitamente), así que marcarlo
  // required en el PHP no basta — sin esto, el técnico podría guardar un
  // traslado sin elegir ningún componente y el inventario nunca se movería.
  // Esta validación en JS sí bloquea el envío del formulario si falta.
  document.querySelectorAll('.form-resolucion-ticket').forEach(form => {
    form.addEventListener('submit', function(e){
      const modo = form.querySelector('input[name="modo_resolucion"]')?.value;

      if (modo === 'equipo_especifico') {
        const estadoSel = form.querySelector('select[name="estado"]');
        if (estadoSel && estadoSel.value === 'Listo pero sin validar') {
          const campo = form.querySelector('input[name="id_componente_saliente"]');
          if (campo && !campo.value) {
            e.preventDefault();
            const detalles = form.querySelector('details.mt-2');
            if (detalles) detalles.open = true;
            alert('Para trasladar el equipo a bodega/oficina de mantenimiento, primero indica qué componente se lleva en "Propuesta de cambio de pieza".');
          }
        }
      } else if (modo === 'sala_general' || modo === 'libre_con_sala') {
        const tipoSel = form.querySelector('select[name="tipo_resolucion_general"]');
        const tipo = tipoSel ? tipoSel.value : '';
        if (tipo === 'cambio_pieza') {
          const campo = form.querySelector('input[name="id_componente_saliente"]');
          if (campo && !campo.value) {
            e.preventDefault();
            alert('Selecciona el componente que se retira (saliente) antes de guardar el cambio de pieza.');
          }
        } else if (tipo === 'traslado_oficina') {
          const campo = form.querySelector('input[name="id_componente_saliente_traslado"]');
          if (campo && !campo.value) {
            e.preventDefault();
            alert('Selecciona qué componente se lleva a bodega/oficina de mantenimiento antes de guardar.');
          }
        }
      }
    });
  });
});

// Acciones declarativas

UFPS.accion('tickets.salaLibre', function (el) { prepararSalaLibre(el); });
