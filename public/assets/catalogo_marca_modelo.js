/*
 * Cascada marca → modelo del catálogo (ficha_equipo.php).
 *
 * Contrato con el HTML/PHP (no cambia):
 *   <select name="id_fabricante" data-catalogo="marca"> con la opción "__nueva__"
 *   <input name="marca_nueva">  (oculto hasta elegir "__nueva__")
 *   <select name="id_modelo" data-catalogo="modelo"> con la opción "__nuevo__"
 *   <input name="modelo_nuevo"> (oculto hasta elegir "__nuevo__")
 * Los modelos salen de listar_modelos_ajax.php?id_fabricante=N → [{id_modelo, nombre}].
 */
(function () {
  'use strict';

  function contenedor(el) {
    return el.closest('form') || document;
  }

  function opcion(valor, texto) {
    const o = document.createElement('option');
    o.value = valor;
    o.textContent = texto;
    return o;
  }

  function mostrar(input, visible) {
    if (!input) { return; }
    input.hidden = !visible;
    if (!visible) { input.value = ''; }
    input.required = visible;
  }

  function llenarModelos(selModelo, modelos) {
    selModelo.innerHTML = '';
    selModelo.appendChild(opcion('', '-- Seleccione --'));
    modelos.forEach(function (m) { selModelo.appendChild(opcion(String(m.id_modelo), m.nombre)); });
    selModelo.appendChild(opcion('__nuevo__', 'Modelo nuevo…'));
  }

  window.alternarModeloNuevo = function (selModelo) {
    const f = contenedor(selModelo);
    mostrar(f.querySelector('input[name="modelo_nuevo"]'), selModelo.value === '__nuevo__');
  };

  window.cargarModelosCatalogo = function (selMarca) {
    const f = contenedor(selMarca);
    const selModelo = f.querySelector('select[name="id_modelo"]');
    const inputMarca = f.querySelector('input[name="marca_nueva"]');
    const inputModelo = f.querySelector('input[name="modelo_nuevo"]');
    if (!selModelo) { return; }

    const v = selMarca.value;
    mostrar(inputMarca, v === '__nueva__');
    mostrar(inputModelo, false);

    if (v === '') {
      selModelo.innerHTML = '';
      selModelo.appendChild(opcion('', '-- Primero selecciona la marca --'));
      return;
    }
    if (v === '__nueva__') {
      // Marca nueva: no hay modelos previos, solo se puede dar de alta uno nuevo.
      selModelo.innerHTML = '';
      selModelo.appendChild(opcion('__nuevo__', 'Modelo nuevo…'));
      selModelo.value = '__nuevo__';
      mostrar(inputModelo, true);
      return;
    }

    selModelo.innerHTML = '';
    selModelo.appendChild(opcion('', 'Cargando…'));
    fetch('listar_modelos_ajax.php?id_fabricante=' + encodeURIComponent(v), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : []; })
      .then(function (modelos) { llenarModelos(selModelo, Array.isArray(modelos) ? modelos : []); })
      .catch(function () { llenarModelos(selModelo, []); });
  };

  // Eventos: data-catalogo="marca|modelo"
  document.addEventListener('change', function (ev) {
    const t = ev.target;
    const tipo = t.getAttribute ? t.getAttribute('data-catalogo') : null;
    if (tipo === 'marca') window.cargarModelosCatalogo(t);
    else if (tipo === 'modelo') window.alternarModeloNuevo(t);
  });
})();
