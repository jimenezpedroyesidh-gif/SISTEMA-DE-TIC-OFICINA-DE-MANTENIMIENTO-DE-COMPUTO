<?php
/* Panel de tickets (Tecnico). */
require_once dirname(__DIR__, 4) . '/app/Infrastructure/Database/PostgresConnection.php';

requireRole(Rol::TECNICO); // app/Support/auth.php

$tecnico_id = $_SESSION['user_id'];

// Solo Departamento de Sistemas e Informática: los tickets de otras dependencias se ven y se atienden
// únicamente con el permiso que activa el admin en «Usuarios» (apagado por defecto).
$veExternos = TicketConsultas::puedeVerExternos($pdo, (int)$tecnico_id, Rol::TECNICO);
if (!$veExternos && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $idGuardia = (int)($_POST['ticket_id'] ?? ($_POST['id'] ?? 0));
    if ($idGuardia > 0) {
        $tablaGuardia = TablaTicket::valida($_POST['tabla_ticket'] ?? '')
            ? $_POST['tabla_ticket']
            : (preg_grep('/profesor/', array_keys($_POST)) ? TablaTicket::PROFESOR : TablaTicket::NORMAL);
        if (!TicketConsultas::esTicketDeSistemas($pdo, $tablaGuardia, $idGuardia)) {
            $error = 'Ese ticket es de otra dependencia. Por ahora solo se atienden los del Departamento de Sistemas e Informática (el admin puede activarte el permiso).';
            $_POST = []; // ninguna acción de abajo se ejecuta
        }
    }
}

// Comentario interno — aplica a ambos tipos de ticket,
// por eso vive fuera de las secciones "TICKETS NORMALES"/"TICKETS
// PROFESOR" de abajo.
if(isset($_POST['agregar_comentario'])){
    // agregarComentarioTicket valida la tabla y el texto.
    $tablaTicket = TablaTicket::desdeEntrada($_POST['tabla_ticket'] ?? null);
    if ($tablaTicket) {
        TicketSeguimiento::agregarComentarioTicket($pdo, $tablaTicket, (int)$_POST['ticket_id'], (int)$tecnico_id, (string)($_POST['comentario'] ?? ''));
    }
}

// Conversación con quien reportó y fotos de evidencia (mismo manejo en todos los paneles).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['conversacion_enviar']) || isset($_POST['evidencia_subir']) || isset($_POST['evidencia_quitar']))) {
    $resInter = TicketInteraccionService::procesar($pdo, $_POST, $_FILES, (int)$tecnico_id, Rol::TECNICO);
    if ($resInter['mensaje'] !== '') {
        if ($resInter['ok']) { $exito = $resInter['mensaje']; } else { $error = $resInter['mensaje']; }
    }
}

// Un ticket que llegó por QR nunca trae equipo(s) ya elegidos: quien lo tomó decide, al revisar la sala,
// cuáles están afectados.
if(isset($_POST['agregar_equipos_ticket'])){
    TicketSeguimientoService::agregarEquiposAfectados(
        $pdo, (string)($_POST['tabla_ticket'] ?? ''), (int)($_POST['ticket_id'] ?? 0),
        (int)$tecnico_id, $_POST['ids_equipo'] ?? [], false
    );
}

// El técnico responsable asigna una beca para que EJECUTE el ticket (o se la quita). La beca lo resuelve
// (Solucionado) y el técnico lo aprueba/rechaza como siempre. Aplica a ambas tablas.
if(isset($_POST['asignar_beca']) || isset($_POST['quitar_beca'])){
    requirePermission(TicketPermissions::ASSIGN);
    $tablaAsig = TablaTicket::desdeEntrada($_POST['tabla_ticket'] ?? null);
    $idAsig = (int)($_POST['ticket_id'] ?? 0);
    if ($tablaAsig && $idAsig) {
        if (isset($_POST['asignar_beca'])) {
            $idBecaNueva = (int)($_POST['beca_id'] ?? 0);
            $res = TicketAsignacionService::asignarBeca($pdo, $tablaAsig, $idAsig, (int)$tecnico_id, $idBecaNueva);
            if ($res['ok']) {
                if ($res['cambio']) {
                    NotificationService::crear($pdo, $idBecaNueva, 'TICKET_ESTADO', "El técnico te asignó el ticket #$idAsig", 'tickets.php');
                    if (!empty($res['id_beca_anterior'])) {
                        NotificationService::crear($pdo, (int)$res['id_beca_anterior'], 'TICKET_ESTADO', "El técnico reasignó el ticket #$idAsig a otra beca", 'tickets.php');
                    }
                }
                $exito = $res['mensaje'];
            } else {
                $error = $res['mensaje'];
            }
        } else {
            $res = TicketAsignacionService::quitarBeca($pdo, $tablaAsig, $idAsig, (int)$tecnico_id);
            if ($res['ok']) {
                NotificationService::crear($pdo, (int)$res['id_beca'], 'TICKET_ESTADO', "El técnico te quitó la asignación del ticket #$idAsig", 'tickets.php');
                $exito = 'Beca quitada del ticket.';
            } else {
                $error = 'No se pudo quitar la beca (¿el ticket ya está cerrado o no es tuyo?).';
            }
        }
    }
}

/* ================== ACCIONES: TICKETS NORMALES ================== */

// Tomar ticket normal
if(isset($_POST['tomar_ticket'])){
    $ticket_id = (int)$_POST['ticket_id'];
    // Paso 3: la lógica vive en TicketService (incluye el historial de
    // asignación y la auditoría de la transición).
    TicketAsignacionService::tomar($pdo, TablaTicket::NORMAL, $ticket_id, $tecnico_id);
}

// Liberar ticket normal (devolver a espera)
if(isset($_POST['liberar_ticket'])){
    $ticket_id = (int)$_POST['ticket_id'];
    TicketAsignacionService::liberar($pdo, TablaTicket::NORMAL, $ticket_id, $tecnico_id);
}

// Descartar ticket normal: 'Descartado' permite cerrar un reporte
// inválido, duplicado o que ya no aplica sin forzarlo como 'Cerrado'
// (que implica que sí se resolvió algo). Cualquier técnico puede
// descartarlo, lo haya tomado o no; el motivo queda en `solucion`
// para dejar registro de por qué se descartó. Al quedar 'Descartado' es
// tratado como cerrado por ticketDanioActivoExistente(), así que libera
// la sala/equipo para un reporte nuevo si de verdad hace falta.
if(isset($_POST['descartar_ticket'])){
    // La regla vive en TicketSeguimientoService::descartar() (misma regla de siempre).
    TicketSeguimientoService::descartar($pdo, TablaTicket::NORMAL, (int)$_POST['ticket_id'], (int)$tecnico_id, (string)($_POST['motivo_descarte'] ?? ''));
}

// Actualizar ticket normal
if(isset($_POST['update_ticket'])){
    // La lógica (ligar sala/equipo, propuesta de pieza, traslado, estados permitidos) vive en
    // TicketEstadoService::actualizarTicketTecnico(). Las fotos de evidencia del mismo formulario se guardan
    // primero: «Solucionado» las exige en reportes QR.
    $avisoFotos = TicketInteraccionService::subirFotosDelFormulario($pdo, TablaTicket::NORMAL, (int)$_POST['id'], (int)$tecnico_id, $_FILES);
    $res = TicketEstadoService::actualizarTicketTecnico($pdo, (int)$_POST['id'], (int)$tecnico_id, $_POST);
    if (!$res['ok']) {
        $error = TicketInteraccionService::motivoNoGuardado($pdo, TablaTicket::NORMAL, (int)$_POST['id'], (string)($_POST['estado'] ?? ''), $_POST['solucion'] ?? null);
    } elseif ($avisoFotos !== '') {
        $error = $avisoFotos;
    }
    if ($res['ok'] && $res['estado'] === TicketWorkflow::SOLUCIONADO && $res['id_dueno']) {
        // Notificación al solicitante dueño del ticket (mensaje corto, sin
        // el texto libre de "solucion", para evitar XSS al mostrarlo).
        $idT = (int)$_POST['id'];
        NotificationService::crear($pdo, $res['id_dueno'], 'TICKET_ESTADO', "Tu ticket #$idT pasó a Solucionado", TicketConsultas::enlaceTicketParaUsuario($pdo, $res['id_dueno'], "ticket_pdf.php?id=$idT"));
    }
}

// Control de propietario para las acciones que MUEVEN inventario (aprobar/rechazar cambio de pieza, traslado
// a bodega, reubicación). Un técnico no puede decidir sobre el ticket de OTRO técnico; si el ticket lo tomó
// una beca o un admin, cualquier técnico puede (ver TicketAprobacionService::tecnicoPuedeDecidir).
foreach (['aprobar_inventario_ticket','rechazar_inventario_ticket','aprobar_traslado_ticket','marcar_reubicado_ticket','aprobar_reubicacion_final'] as $accionInv) {
    if (isset($_POST[$accionInv])) {
        if (!TicketAprobacionService::tecnicoPuedeDecidir($pdo, (int)($_POST['ticket_id'] ?? 0), (int)$tecnico_id)) {
            unset($_POST[$accionInv]);
            $error = 'Este ticket lo tiene asignado otro técnico: solo él puede aprobarlo, rechazarlo o validar su traslado.';
        }
    }
}

// ================== Traslado a bodega/oficina de mantenimiento (spec 2.2-c) ==================
// Paso 1: el técnico aprueba que el equipo efectivamente salió y quedó bien registrado en bodega (no cierra
// el ticket todavía). La lógica (incluido el movimiento real de inventario del componente que se lleva) vive
// en TicketTrasladoService::aprobarTraslado(), de forma atómica.
if(isset($_POST['aprobar_traslado_ticket'])){
    $res = TicketTrasladoService::aprobarTraslado($pdo, (int)$_POST['ticket_id'], (int)$tecnico_id);
    if (!$res['ok'] && $res['motivo']) {
        $error = $res['motivo'];
    }
}
// Paso 2 (después de reparado): un beca_trabajo o el propio técnico marca
// que ya lo reubicó físicamente en su sitio.
if(isset($_POST['marcar_reubicado_ticket'])){
    TicketTrasladoService::marcarReubicado($pdo, (int)$_POST['ticket_id'], (int)$tecnico_id);
}
// Paso 3: aprobación final del técnico — solo aquí se cierra de verdad.
if(isset($_POST['aprobar_reubicacion_final'])){
    $id = (int)$_POST['ticket_id'];
    $res = TicketTrasladoService::aprobarReubicacionFinal($pdo, $id, (int)$tecnico_id);
    if ($res['ok']) {
        if ($res['id_dueno']) {
            NotificationService::crear($pdo, $res['id_dueno'], 'TICKET_ESTADO', "Tu ticket #$id fue cerrado — el equipo ya está de vuelta en su sitio", TicketConsultas::enlaceTicketParaUsuario($pdo, $res['id_dueno'], "ticket_pdf.php?id=$id"));
        }
    } else {
        $error = "Falta que se apruebe el traslado y/o que quede marcado como reubicado antes de cerrar.";
    }
}

// Aprobar/rechazar ticket "simple" (sin propuesta de pieza) que quedó en
// Solucionado. El admin no asigna ni decide en tickets de Daño/error,
// solo supervisa — el que aprueba o rechaza siempre debe ser el técnico
// responsable del ticket.
if(isset($_POST['aprobar_ticket_tecnico'])){
    $id = (int)$_POST['ticket_id'];

    // Ticket de "cambio de posición" (create_ticket_beca.php): aprobarlo MUEVE el
    // equipo entre salas (TicketTrasladoService::aprobarCambioPosicion, atómico). Si no
    // es de ese tipo, sigue el caso simple (aprobarTicketNormal).
    $pos = TicketTrasladoService::aprobarCambioPosicion($pdo, $id, (int)$tecnico_id);
    if ($pos['aplica']) {
        if (!$pos['ok']) {
            $error = "No se pudo ejecutar el traslado: el equipo ya no está en la sala de origen esperada (puede que otro ticket ya lo haya movido). Revisa y descarta o corrige este ticket.";
        } elseif ($pos['id_dueno']) {
            NotificationService::crear($pdo, $pos['id_dueno'], 'TICKET_ESTADO', "Tu ticket #$id pasó a Aprobado — el equipo ya quedó reubicado", TicketConsultas::enlaceTicketParaUsuario($pdo, $pos['id_dueno'], "ticket_pdf.php?id=$id"));
        }
    } else {
        // El caso "simple" (sin pieza ni cambio de posición) lo
        // resuelve TicketAprobacionService::aprobarTicketNormal().
        $res = TicketAprobacionService::aprobarTicketNormal($pdo, $id, (int)$tecnico_id);
        if ($res['ok']) {
            if ($res['id_dueno']) {
                NotificationService::crear($pdo, $res['id_dueno'], 'TICKET_ESTADO', "Tu ticket #$id pasó a Aprobado", TicketConsultas::enlaceTicketParaUsuario($pdo, $res['id_dueno'], "ticket_pdf.php?id=$id"));
            }
            // Avisa también a la beca ejecutora (beca_id).
            if ($res['id_beca'] && $res['id_beca'] !== $res['id_dueno']) {
                NotificationService::crear($pdo, $res['id_beca'], 'TICKET_ESTADO', "El técnico aprobó tu intervención en el ticket #$id", 'tickets.php');
            }
        }
    }
}
if(isset($_POST['rechazar_ticket_tecnico'])){
    $id = (int)$_POST['ticket_id'];
    $res = TicketAprobacionService::rechazarTicketNormal($pdo, $id, (int)$tecnico_id);
    if ($res['ok'] && $res['id_beca']) {
        // La beca ejecutora se entera de que debe corregir.
        NotificationService::crear($pdo, $res['id_beca'], 'TICKET_ESTADO', "El técnico rechazó tu intervención en el ticket #$id — revísala de nuevo", 'tickets.php');
    }
}

// Aprobar cambio de inventario (sección 1.3 del plan): SOLO tecnico puede
// ejecutar el cambio real cuando un ticket trae propuesta de componente.
// admin puede aprobar otros tipos de ticket, pero no este paso concreto.
if(isset($_POST['aprobar_inventario_ticket'])){
    $id = (int)$_POST['ticket_id'];
    $res = TicketTrasladoService::aprobarCambioPieza($pdo, $id, (int)$tecnico_id);
    if ($res['ok']) {
        $ticket = $res['ticket'];
        // Si este cambio de pieza está enlazado a un ticket de daño de
        // tickets_profesor (ver create_ticket_beca.php), el profesor que
        // reportó el daño se entera de que su reporte ya tuvo movimiento.
        if (($ticket['tabla_ticket_relacionado'] ?? null) === TablaTicket::PROFESOR && !empty($ticket['id_ticket_relacionado'])) {
            $idProfesorAvisar = TicketPanelRepository::idProfesorDeTicket($pdo, (int)$ticket['id_ticket_relacionado']);
            if ($idProfesorAvisar) {
                if (!empty($ticket['id_componente_entrante'])) {
                    $mensajeProf = "Ya se instaló la pieza de reemplazo en el equipo de tu reporte #{$ticket['id_ticket_relacionado']}.";
                } else {
                    $mensajeProf = "Se retiró una pieza del equipo de tu reporte #{$ticket['id_ticket_relacionado']} para revisión/reparación.";
                }
                NotificationService::crear($pdo, (int)$idProfesorAvisar, 'TICKET_ESTADO', $mensajeProf, "tickets.php");
            }
        }
    } elseif (!empty($res['motivo'])) {
        $error = $res['motivo'];
    }
}

// Rechazar cambio de inventario propuesto (vuelve el ticket a En proceso
// para que el técnico busque otra pieza o corrija la propuesta)
if(isset($_POST['rechazar_inventario_ticket'])){
    TicketTrasladoService::rechazarCambioPieza($pdo, (int)$_POST['ticket_id'], (int)$tecnico_id);
}

/* ================== ACCIONES: TICKETS PROFESOR ================== */

// Tomar ticket profesor
if(isset($_POST['tomar_profesor'])){
    $ticket_id = (int)$_POST['ticket_id'];
    TicketAsignacionService::tomar($pdo, TablaTicket::PROFESOR, $ticket_id, $tecnico_id);
}

// Liberar ticket profesor
if(isset($_POST['liberar_profesor'])){
    $ticket_id = (int)$_POST['ticket_id'];
    TicketAsignacionService::liberar($pdo, TablaTicket::PROFESOR, $ticket_id, $tecnico_id);
}

// Descartar ticket profesor, mismo caso que en tickets normales. Se
// limita al tipo 'danio' (reportes de sala duplicados/inválidos) porque
// descripcion_problema es la columna de texto libre que ese subtipo sí
// tiene (requerimiento usa otras columnas propias por subtipo).
if(isset($_POST['descartar_profesor'])){
    TicketSeguimientoService::descartar($pdo, TablaTicket::PROFESOR, (int)$_POST['ticket_id'], (int)$tecnico_id, (string)($_POST['motivo_descarte'] ?? ''));
}

// Actualizar ticket profesor (solo estado/firmado)
if(isset($_POST['update_profesor'])){
    $idUp = (int)$_POST['id'];
    $estadoUp = (string)($_POST['estado'] ?? '');
    $solucionUp = isset($_POST['solucion']) ? (string)$_POST['solucion'] : null;
    // Primero las fotos del formulario (Solucionado exige explicación + foto en daño / instalación de software).
    $avisoFotos = TicketInteraccionService::subirFotosDelFormulario($pdo, TablaTicket::PROFESOR, $idUp, (int)$tecnico_id, $_FILES);
    if (!TicketEstadoService::actualizarEstadoProfesorTecnico($pdo, $idUp, (int)$tecnico_id, $estadoUp, $solucionUp)) {
        $error = TicketInteraccionService::motivoNoGuardado($pdo, TablaTicket::PROFESOR, $idUp, $estadoUp, $solucionUp);
    } else {
        if ($avisoFotos !== '') $error = $avisoFotos;
        $exito = 'Ticket #' . $idUp . ' actualizado.';
        if ($estadoUp === TicketWorkflow::SOLUCIONADO) {
            $tUp = TicketRepository::crudo($pdo, TablaTicket::PROFESOR, $idUp);
            if ($tUp && !empty($tUp['profesor_id'])) {
                NotificationService::crear($pdo, (int)$tUp['profesor_id'], 'TICKET_ESTADO', "Tu ticket #$idUp fue solucionado — revisa la explicación y las fotos", 'tickets.php#tk-' . $idUp);
            }
        }
    }
}

// Aprobar/rechazar ticket de profesor en Solucionado. Cuando un
// beca_trabajo toma y ejecuta un ticket de Daño/error de profesor, el
// campo tecnico_id queda con el ID del beca (es el mismo campo que usa
// la ejecución, ver tickets.php). El técnico, como
// responsable técnico del sistema, debe poder revisar y
// aprobar/rechazar CUALQUIER ticket de profesor en Solucionado, haya
// sido ejecutado por él mismo o por un beca.
if(isset($_POST['aprobar_profesor_tecnico'])){
    $id = (int)$_POST['ticket_id'];
    $res = TicketAprobacionService::aprobarTicketProfesor($pdo, $id, (int)$tecnico_id);
    if ($res['motivo']) {
        $error = $res['motivo']; // préstamo sin devolución confirmada
    } elseif ($res['ok']) {
        // Si es un CAMBIO_SALA, crea (o actualiza) la excepción "solo esta semana" (HorarioEventos). No
        // hace nada si el ticket no es de ese subtipo.
        HorarioEventos::crearExcepcionPorCambioSalaAprobado($pdo, $id, $tecnico_id);
        $urlPdf = "ticket_profesor_pdf.php?id=$id";
        if ($res['id_profesor']) {
            NotificationService::crear($pdo, $res['id_profesor'], 'TICKET_ESTADO', "Tu ticket #$id pasó a Aprobado", TicketConsultas::enlaceTicketParaUsuario($pdo, $res['id_profesor'], $urlPdf));
        }
        // Notifica también al ejecutor (beca_trabajo o técnico que lo trabajó)
        if ($res['id_tecnico'] && $res['id_tecnico'] !== (int)$tecnico_id) {
            NotificationService::crear($pdo, $res['id_tecnico'], 'TICKET_ESTADO', "El técnico aprobó tu intervención en el ticket #$id", TicketConsultas::enlaceTicketParaUsuario($pdo, $res['id_tecnico'], $urlPdf));
        }
        // La beca asignada por el técnico (beca_id) también se entera.
        if ($res['id_beca'] && $res['id_beca'] !== $res['id_tecnico']) {
            NotificationService::crear($pdo, $res['id_beca'], 'TICKET_ESTADO', "El técnico aprobó tu intervención en el ticket #$id", TicketConsultas::enlaceTicketParaUsuario($pdo, $res['id_beca'], $urlPdf));
        }
    }
}
// Confirmar devolución de un préstamo: puede hacerse en cualquier
// momento antes de aprobar — típicamente el docente avisa que ya
// devolvió el objeto, y el técnico lo confirma aquí. Exige lo mismo que
// update_profesor: que el ticket ya esté asignado a quien confirma y
// que no esté ya cerrado (firmado).
if(isset($_POST['confirmar_devolucion_profesor'])){
    $id = (int)$_POST['ticket_id'];
    $res = TicketAprobacionService::confirmarDevolucion($pdo, $id, (int)$tecnico_id);
    if ($res['ok'] && $res['id_profesor']) {
        NotificationService::crear($pdo, $res['id_profesor'], 'TICKET_ESTADO', "Se confirmó la devolución de tu préstamo (ticket #$id)", TicketConsultas::enlaceTicketParaUsuario($pdo, $res['id_profesor'], "ticket_profesor_pdf.php?id=$id"));
    }
}
if(isset($_POST['rechazar_profesor_tecnico'])){
    $id = (int)$_POST['ticket_id'];
    $res = TicketAprobacionService::rechazarTicketProfesor($pdo, $id, (int)$tecnico_id);
    if ($res['ok'] && $res['id_ejecutor'] && $res['id_ejecutor'] !== (int)$tecnico_id) {
        NotificationService::crear($pdo, $res['id_ejecutor'], 'TICKET_ESTADO', "El técnico rechazó tu intervención en el ticket #$id — revísala de nuevo", TicketConsultas::enlaceTicketParaUsuario($pdo, $res['id_ejecutor'], "ticket_profesor_pdf.php?id=$id"));
    }
}

/* ================== CONSULTAS ================== */

// Tickets normales — separados en dos grupos (a pedido del panel técnico,
// para no ver "ese montón" mezclado):
//   - "Sistemas": creados directamente por técnico o beca_trabajo
//     (BECA_DIRECTO/TECNICO_DIRECTO — son personal del Departamento de
//     Sistemas), o reportados por QR sin login cuando la sala reportada
//     pertenece al Departamento de Sistemas (columna `salas.dependencia`).
//   - "Solicitantes": el resto — el formulario clásico de solicitante
//     (PANEL_INTERNO), y los QR sin login de salas que NO son de Sistemas.
// Cada grupo se pagina aparte (5 en 5) y separado en Activos/Firmados
// (cada uno con su propia paginación), en vez de una sola tabla de 20.

// Para tickets "libres" (formulario clásico de solicitante, sin
// id_sala/id_equipo) que en realidad SÍ son sobre un salón con
// inventario real (el caso más común: alguien reporta "SA401-PC01, torre
// no enciende" en el formulario de texto libre), se necesita el listado
// completo de salas para poder ofrecer "¿es sobre esta sala?" y así
// poder capturar el componente igual que en los demás modos.
$todasLasSalas = TicketPanelRepository::salasTodas($pdo);


// Becas activas para el selector "Asignar a una beca".
$becasActivas = TicketPanelRepository::becasParaAsignar($pdo);

// Bandeja UNIFICADA por grupo (paso «una sola tabla»): tickets normales + de profesor juntos, partidos en
// pendientes y firmados, 5 por página cada uno con botones numéricos (TicketBandeja). Otras dependencias
// solo se consultan con el permiso que activa el admin.
$bandejas = ['sistemas' => TicketBandeja::cargar($pdo, true, (int)($_GET['pagina_sistemas_pend'] ?? 1), (int)($_GET['pagina_sistemas_firm'] ?? 1))];
if ($veExternos) {
    $bandejas['externos'] = TicketBandeja::cargar($pdo, false, (int)($_GET['pagina_externos_pend'] ?? 1), (int)($_GET['pagina_externos_firm'] ?? 1));
}

// ---- Tarea 2: equipos afectados de tickets de QR ya tomados ----
// Igual que en tickets.php: en bloque (no fila por fila), trae los equipos ya registrados en ticket_activos
// para los tickets de QR que este técnico tomó, y los equipos disponibles de las salas de los que todavía no
// tienen nada — para pintar el checklist de "agregar equipos" en _fila_ticket_normal.php /
// _fila_ticket_profesor.php.
$todosTicketsNormalesQr = TicketBandeja::visibles($bandejas, 'normal');
$todosTicketsProfQr = TicketBandeja::visibles($bandejas, 'prof');

// Conversación con quien reportó y fotos de evidencia de las filas visibles, en bloque.
$interaccion = TicketInteraccionService::precargar($pdo, [
    TablaTicket::NORMAL => array_column($todosTicketsNormalesQr, 'id'),
    TablaTicket::PROFESOR => array_column($todosTicketsProfQr, 'id'),
]);

// Comentarios internos de todas las filas visibles, en bloque.
$comentariosPorTicket = [
    TablaTicket::NORMAL => TicketSeguimiento::comentariosDeTickets($pdo, TablaTicket::NORMAL, array_column($todosTicketsNormalesQr, 'id')),
    TablaTicket::PROFESOR => TicketSeguimiento::comentariosDeTickets($pdo, TablaTicket::PROFESOR, array_column($todosTicketsProfQr, 'id')),
];

$idsTicketQrTk = [];
foreach ($todosTicketsNormalesQr as $t) {
    if (($t['origen'] ?? '') === 'QR_ANONIMO' && (int)$t['tecnico_id'] === $tecnico_id) {
        $idsTicketQrTk[] = (int)$t['id'];
    }
}
$idsTicketProfQrTk = [];
foreach ($todosTicketsProfQr as $p) {
    if (($p['origen'] ?? '') === 'QR_PROFESOR' && (int)$p['tecnico_id'] === $tecnico_id) {
        $idsTicketProfQrTk[] = (int)$p['id'];
    }
}
$equiposYaQrTk   = TicketActivos::equiposYaAsignadosATickets($pdo, TablaTicket::NORMAL, $idsTicketQrTk);
$equiposYaProfTk = TicketActivos::equiposYaAsignadosATickets($pdo, TablaTicket::PROFESOR, $idsTicketProfQrTk);

$salasNecesariasTk = [];
foreach ($todosTicketsNormalesQr as $t) {
    if (($t['origen'] ?? '') === 'QR_ANONIMO' && (int)$t['tecnico_id'] === $tecnico_id
        && empty($equiposYaQrTk[(int)$t['id']]) && !empty($t['id_sala'])) {
        $salasNecesariasTk[(int)$t['id_sala']] = true;
    }
}
foreach ($todosTicketsProfQr as $p) {
    if (($p['origen'] ?? '') === 'QR_PROFESOR' && (int)$p['tecnico_id'] === $tecnico_id
        && empty($equiposYaProfTk[(int)$p['id']]) && !empty($p['id_sala'])) {
        $salasNecesariasTk[(int)$p['id_sala']] = true;
    }
}
$equiposPorSalaTk = TicketActivos::equiposPorSalas($pdo, array_keys($salasNecesariasTk));


$diasSemanaCorto = [1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'];
$duracionesPrestamoLabel = TicketProfesorCreacionService::DURACIONES_PRESTAMO;

/* ================== BECAS TRABAJO ================== */
$becas_activos = TicketPanelRepository::becasConCodigo($pdo);
$codigosBecas = array_filter(array_column($becas_activos, 'codigo'));
$estadoAsistenciaBecas = estadoAsistenciaPorCodigos($codigosBecas);


?>
<?php $shell = ShellData::cargar($pdo);?>
<?php $paginaTitulo = 'Panel técnico'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php $vistasTecnico = dirname(__DIR__, 4) . '/app/Presentation/Views/list_tickets'; include $vistasTecnico . '/scripts_head.php'; ?>
<?php $tituloHeader = 'Panel técnico'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <div class="app-dashboard-welcome">
      <h1>Panel técnico</h1>
      <p>Tickets por atender, asignación a becas y seguimiento. Usa las pestañas para cambiar de bandeja.</p>
    </div>
    <?php if(!empty($error)): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
    <?php if(!empty($exito)): ?><div class="exito mb-3"><?= h($exito) ?></div><?php endif; ?>

<?php
// Una sola bandeja por grupo (seccion_grupo.php): tickets normales y de profesor juntos.
$gruposTickets = [
    ['id' => 'sistemas', 'titulo' => 'Departamento de Sistemas e Informática',
     'ayuda' => 'salas del departamento (QR con o sin sesión), profesores, secretaría y lo creado por técnicos y becas'],
];
if ($veExternos) {
    $gruposTickets[] = ['id' => 'externos', 'titulo' => 'Otras dependencias', 'ayuda' => 'solicitantes, QR y profesores de salas fuera del departamento'];
}
foreach ($gruposTickets as $grupo) {
    $bandeja = $bandejas[$grupo['id']];
    include $vistasTecnico . '/seccion_grupo.php';
}
if (!$veExternos) {
    echo '<p class="nota-info">Trabajas solo con los tickets del Departamento de Sistemas e Informática. Los de otras dependencias se activan con un permiso que da el administrador.</p>';
}
include $vistasTecnico . '/seccion_becas.php';
?>

<?php include LAYOUT_DIR . '/app_page_end.php'; ?>
