/* Mantenimientos programados — formulario (sala → tipo → alcance → equipos). Requiere assets/app.js. Endpoints:
   listar_equipos_ajax.php (id_sala), mantenimientos_programados.php?ajax=disponibilidad (id_sala, fecha,
   duracion, id_responsable, buscar). */
function alcanceMant(){
  const marcado = document.querySelector('input[name="alcance_equipo"]:checked');
  return marcado ? marcado.value : 'TODOS';
}

function esSoftwareMant(){ return document.getElementById('tipo').value === 'INSTALACION_SOFTWARE'; }

function contarEquiposMant(){
  const n = document.querySelectorAll('#lista-equipos input:checked').length;
  document.getElementById('contador-equipos').textContent = n ? n + ' elegido(s)' : 'Ninguno elegido';
}

// «Ciertos equipos»: casillas con los equipos de la sala (listar_equipos_ajax.php). En instalación de software
// solo se pueden elegir equipos de cómputo (el servidor igual lo revalida).
function cargarEquiposMant(idSala){
  const lista = document.getElementById('lista-equipos');
  const previos = (lista.getAttribute('data-seleccion') || '').split(',').filter(Boolean);
  if (!idSala){ lista.innerHTML = '<p class="hint">Primero elige la sala.</p>'; contarEquiposMant(); return; }
  lista.innerHTML = '<p class="hint">Cargando…</p>';
  UFPS.listarEquipos(idSala).then(function (equipos){
    if (!equipos.length){ lista.innerHTML = '<p class="hint">Esta sala no tiene equipos registrados.</p>'; contarEquiposMant(); return; }
    lista.innerHTML = equipos.map(function (eq){
      const id = String(eq.id_equipo);
      return '<label title="' + UFPS.esc(UFPS.textoEquipo(eq)) + '"><input type="checkbox" name="ids_equipo[]" value="' + UFPS.esc(id) + '"'
        + ' data-computo="' + (Number(eq.es_computo) ? '1' : '0') + '"' + (previos.indexOf(id) >= 0 ? ' checked' : '') + '> '
        + UFPS.esc(eq.codigo) + '</label>';
    }).join('');
    lista.setAttribute('data-seleccion', '');
    alternarAlcanceMant();
  }).catch(function (){ lista.innerHTML = '<p class="hint">No se pudieron cargar los equipos.</p>'; });
}

// Muestra la lista de equipos solo con «Ciertos equipos». Instalar software en un videobeam no tiene sentido:
// se bloquean esa opción y los equipos que no son de cómputo.
function alternarAlcanceMant(){
  const software = esSoftwareMant();
  const videobeam = document.querySelector('input[name="alcance_equipo"][value="VIDEOBEAM"]');
  videobeam.disabled = software;
  if (software && videobeam.checked){ document.querySelector('input[name="alcance_equipo"][value="COMPUTO"]').checked = true; }
  document.getElementById('hint-alcance-software').hidden = !software;
  document.getElementById('campo-equipos').hidden = alcanceMant() !== 'SELECCION';
  document.querySelectorAll('#lista-equipos input').forEach(function (c){
    c.disabled = software && c.getAttribute('data-computo') !== '1';
    if (c.disabled){ c.checked = false; }
  });
  contarEquiposMant();
}

// Disponibilidad con la duración y el coordinador: mantenimientos_programados.php?ajax=disponibilidad
// devuelve {choques:[{texto,hora_inicio,hora_fin}], sugerencia:'YYYY-MM-DDTHH:MM'|null}.
function urlDisponibilidad(buscar){
  const p = new URLSearchParams({
    ajax: 'disponibilidad',
    id_sala: document.getElementById('id_sala').value,
    fecha: document.getElementById('fecha_programada').value,
    duracion: document.getElementById('duracion_minutos').value,
    id_responsable: document.getElementById('id_responsable').value,
    buscar: buscar ? '1' : '0'
  });
  return 'mantenimientos_programados.php?' + p.toString();
}

function mostrarChoqueMant(choques){
  const aviso = document.getElementById('aviso-choque-mant');
  const campoConfirmar = document.getElementById('campo-confirmar-choque');
  const checkbox = document.getElementById('confirmar_choque');
  if (choques && choques.length){
    aviso.textContent = 'Esa sala tiene ' + choques[0].texto + '. ¿Deseas programar igual?';
    aviso.hidden = false;
    campoConfirmar.hidden = false;
  } else {
    aviso.hidden = true;
    campoConfirmar.hidden = true;
  }
  checkbox.checked = false;
}

function verificarChoqueMant(){
  if (!document.getElementById('id_sala').value || !document.getElementById('fecha_programada').value){ mostrarChoqueMant([]); return; }
  fetch(urlDisponibilidad(false))
    .then(r => r.json())
    .then(data => mostrarChoqueMant(data.choques))
    .catch(() => mostrarChoqueMant([]));
}

function buscarHuecoMant(){
  const hint = document.getElementById('hint-hueco');
  if (!document.getElementById('id_sala').value){ hint.textContent = 'Primero elige la sala.'; return; }
  hint.textContent = 'Buscando…';
  fetch(urlDisponibilidad(true))
    .then(r => r.json())
    .then(data => {
      if (data.sugerencia){
        document.getElementById('fecha_programada').value = data.sugerencia;
        hint.textContent = 'Primer espacio libre: ' + data.sugerencia.replace('T', ' a las ') + '.';
        mostrarChoqueMant([]);
      } else {
        hint.textContent = 'No hay un espacio libre en los próximos 90 días: elige la fecha a mano.';
      }
    })
    .catch(() => { hint.textContent = 'No se pudo consultar la disponibilidad.'; });
}

// Solo se ofrecen los reportes de la sala elegida (el servidor igual lo revalida).
function filtrarVinculosMant(idSala){
  const filas = document.querySelectorAll('[data-vinculo-sala]');
  let visibles = 0;
  filas.forEach(f => {
    const ver = idSala !== '' && f.getAttribute('data-vinculo-sala') === idSala;
    f.hidden = !ver;
    if (!ver){ f.querySelector('input').checked = false; } else { visibles++; }
  });
  const vacio = document.getElementById('vinculos-vacio');
  if (vacio){ vacio.hidden = visibles > 0; }
}

document.getElementById('form-programar-mant').addEventListener('submit', function(ev){
  if (alcanceMant() === 'SELECCION' && !document.querySelector('#lista-equipos input:checked')){
    ev.preventDefault();
    document.getElementById('contador-equipos').textContent = 'Elige al menos un equipo.';
    return;
  }
  const aviso = document.getElementById('aviso-choque-mant');
  const checkbox = document.getElementById('confirmar_choque');
  if (!aviso.hidden && !checkbox.checked){
    ev.preventDefault();
    alert('Marca la casilla de confirmación para programar a pesar del choque de horario, o cambia la fecha/sala.');
  }
});

// Eventos
document.getElementById('id_sala').addEventListener('change', function(){ cargarEquiposMant(this.value); filtrarVinculosMant(this.value); verificarChoqueMant(); });
document.getElementById('tipo').addEventListener('change', alternarAlcanceMant);
document.querySelectorAll('input[name="alcance_equipo"]').forEach(r => r.addEventListener('change', alternarAlcanceMant));
document.getElementById('lista-equipos').addEventListener('change', contarEquiposMant);
document.getElementById('btn-todos-equipos').addEventListener('click', function(){
  const casillas = Array.from(document.querySelectorAll('#lista-equipos input:not(:disabled)'));
  const marcar = casillas.some(c => !c.checked);
  casillas.forEach(c => { c.checked = marcar; });
  this.textContent = marcar ? 'Quitar todos' : 'Marcar todos';
  contarEquiposMant();
});
['fecha_programada', 'duracion_minutos', 'id_responsable'].forEach(id => document.getElementById(id).addEventListener('change', verificarChoqueMant));
document.getElementById('btn-buscar-hueco').addEventListener('click', buscarHuecoMant);
alternarAlcanceMant();
filtrarVinculosMant(document.getElementById('id_sala').value);
if (document.getElementById('id_sala').value) { cargarEquiposMant(document.getElementById('id_sala').value); verificarChoqueMant(); }
