/* Crear ticket (Beca Trabajo). Requiere assets/app.js. Datos de PHP: <script type="application/json"
   id="beca-config"> {origenSala} (solo cuando se llega desde el botón "Cambio de pieza" de un ticket de
   daño). */
(function () {
  'use strict';
  function mostrarTipo(tipo) {
    document.getElementById('form-cambio_pieza').hidden = tipo !== 'cambio_pieza';
    document.getElementById('form-cambio_posicion').hidden = tipo !== 'cambio_posicion';
  }
  UFPS.accion('beca.mostrarTipo', function (el) { mostrarTipo(el.getAttribute('data-arg')); });

  document.addEventListener('DOMContentLoaded', function () {
    // Al menos una de las dos piezas (saliente o entrante) debe quedar seleccionada: cualquiera
    // de las dos, sola, es una operación válida (sacar a bodega / devolver).
    const formPieza = document.querySelector('#form-cambio_pieza form');
    if (formPieza) {
      formPieza.addEventListener('submit', function (e) {
        const saliente = document.getElementById('id_componente_saliente');
        const entrante = document.getElementById('id_componente_entrante');
        if (!saliente.value && !entrante.value) {
          e.preventDefault();
          alert('Selecciona al menos un componente: el que sacas a bodega, el que devuelves reparado, o ambos si es un cambio directo.');
        }
      });
    }

    // Se llegó desde "Cambio de pieza" de un ticket de daño: abrir el formulario con la sala
    // preseleccionada y cargar sus equipos.
    const cfg = document.getElementById('beca-config');
    if (cfg) {
      const idSala = JSON.parse(cfg.textContent).origenSala;
      mostrarTipo('cambio_pieza');
      UFPS.llenarSelectEquipos(document.getElementById('id_equipo'), idSala,
        { cargando: 'Cargando...', sinSala: '-- Primero selecciona la sala --', lista: '-- Seleccione --' });
    }
  });
})();
