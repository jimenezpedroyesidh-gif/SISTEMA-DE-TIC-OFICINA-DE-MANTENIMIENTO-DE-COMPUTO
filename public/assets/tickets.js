// Funciones JS compartidas de las listas de tickets.
function toggleFirmados(sectionId){
  const section = document.getElementById(sectionId);
  const rows = section.querySelectorAll('tr[data-firmado]');
  rows.forEach(r => { r.hidden = !r.hidden; });
}

UFPS.accion('tickets.toggleFirmados', function (el) { toggleFirmados(el.getAttribute('data-arg')); });
