/* Campañas de mantenimiento — formulario «Nueva campaña».
   Las salas se ejecutan en el orden en que se marcan: cada casilla muestra su número y, al enviar,
   las salas viajan en ese orden (salas[]). Sin JS se envían en el orden de la lista. */
(function(){
  const form = document.getElementById('form-campana');
  if (!form) return;
  const cajas = Array.from(form.querySelectorAll('#c-salas input[type=checkbox]'));
  let orden = cajas.filter(c => c.checked);

  function pintar(){
    cajas.forEach(c => {
      const chip = c.parentElement.querySelector('[data-orden]');
      const i = orden.indexOf(c);
      chip.hidden = i < 0;
      chip.textContent = i < 0 ? '' : String(i + 1);
    });
  }
  cajas.forEach(c => c.addEventListener('change', function(){
    orden = orden.filter(x => x !== c);
    if (c.checked) orden.push(c);
    pintar();
  }));
  document.getElementById('c-todas').addEventListener('click', function(){
    cajas.forEach(c => { if (!c.checked){ c.checked = true; orden.push(c); } });
    pintar();
  });
  document.getElementById('c-ninguna').addEventListener('click', function(){
    cajas.forEach(c => { c.checked = false; });
    orden = [];
    pintar();
  });
  form.addEventListener('submit', function(){
    cajas.forEach(c => c.removeAttribute('name'));
    orden.forEach(c => {
      const h = document.createElement('input');
      h.type = 'hidden'; h.name = 'salas[]'; h.value = c.value;
      form.appendChild(h);
    });
  });
  pintar();
})();
