/* Crear ticket (Profesor). Requiere assets/app.js (UFPS.listarEquipos). */
// Nivel 1: tipo de ticket (requerimiento / daño)
function toggleTipoTicket(){
  const tipo = document.getElementById("tipo_ticket").value;
  document.getElementById("requerimiento_fields").hidden = tipo !== "requerimiento";
  document.getElementById("danio_fields").hidden = tipo !== "danio";
  setRequeridos("requerimiento_fields", tipo === "requerimiento");
  setRequeridos("danio_fields", tipo === "danio");
  if (tipo === "requerimiento") {
    toggleSubtipo();
  }
}

// Nivel 2: subtipo de requerimiento -> muestra solo el bloque de
// campos correspondiente (Cambio de Sala / Instalación de Software /
// Préstamo).
function toggleSubtipo(){
  const subtipo = document.getElementById("subtipo_requerimiento").value;
  const bloques = {
    CAMBIO_SALA: "bloque_cambio_sala",
    INSTALACION_SOFTWARE: "bloque_software",
    PRESTAMO_SALA: "bloque_prestamo",
    PRESTAMO_EQUIPO: "bloque_prestamo",
  };
  ["bloque_cambio_sala", "bloque_software", "bloque_prestamo"].forEach(id => {
    const visible = bloques[subtipo] === id;
    document.getElementById(id).hidden = !visible;
    setRequeridos(id, visible);
  });
}

// Marca/desmarca "required" en todos los input/select/textarea de un
// contenedor, para que el navegador no exija campos que están ocultos.
function setRequeridos(containerId, activo){
  const el = document.getElementById(containerId);
  if (!el) return;
  el.querySelectorAll("[data-required]").forEach(campo => {
    campo.required = activo;
  });
}

// Equipos afectados (opcional) en el reporte de daño: al elegir la sala trae sus equipos
// (listar_equipos_ajax.php) y los pinta como plano de sala tipo «sillas de cine»
// (PlanoSala.selector, assets/plano_sala.js). Cada puesto es una casilla ids_equipo_danio[].
function cargarChecklistEquiposDanio(idSala){
  const cont = document.getElementById("checklist_equipos_danio");
  function aviso(texto){
    cont.innerHTML = '';
    const p = document.createElement('p');
    p.className = 'text-muted m-0';
    p.textContent = texto;
    cont.appendChild(p);
  }
  if (!idSala) { aviso('Selecciona primero la sala.'); return; }
  aviso('Cargando plano de la sala...');
  UFPS.fetchJson('listar_equipos_ajax.php?plano=1&distribucion=1&id_sala=' + encodeURIComponent(idSala)).then(data => {
    // {equipos, distribucion}: la distribución (filas, mesas, número de puesto) es null si el salón no se maquetó.
    const equipos = Array.isArray(data) ? data : (data.equipos || []);
    if (!equipos.length) { aviso('Esta sala no tiene equipos registrados en el inventario.'); return; }
    PlanoSala.selector(cont, equipos, 'ids_equipo_danio[]', Array.isArray(data) ? null : data.distribucion);
  }).catch(() => aviso('No se pudo cargar el plano de la sala.'));
}

UFPS.accion('profesor.tipoTicket', function () { toggleTipoTicket(); });
UFPS.accion('profesor.subtipo', function () { toggleSubtipo(); });
UFPS.accion('profesor.salaDanio', function (el) { cargarChecklistEquiposDanio(el.value); });

document.addEventListener("DOMContentLoaded", function(){
  toggleTipoTicket();
  // Si el formulario volvió con error y ya traía la sala, se vuelve a pintar su plano.
  const sala = document.getElementById("id_sala_danio");
  if (sala && sala.value) cargarChecklistEquiposDanio(sala.value);
});
