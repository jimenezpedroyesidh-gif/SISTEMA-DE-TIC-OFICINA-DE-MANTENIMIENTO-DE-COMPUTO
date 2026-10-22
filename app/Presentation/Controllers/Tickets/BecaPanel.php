<?php
/* Panel de tickets (Beca). */

requireRole(Rol::BECA); // app/Support/auth.php

$user_id = $_SESSION['user_id'];
$error = null;
$exito = null;

// Conversación con quien reportó y fotos de evidencia (mismo manejo en todos los paneles).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['conversacion_enviar']) || isset($_POST['evidencia_subir']) || isset($_POST['evidencia_quitar']))) {
    $resInter = TicketInteraccionService::procesar($pdo, $_POST, $_FILES, (int)$user_id, Rol::BECA);
    if ($resInter['mensaje'] !== '') {
        if ($resInter['ok']) { $exito = $resInter['mensaje']; } else { $error = $resInter['mensaje']; }
    }
}

// ================== ACCIONES: TICKETS PROFESORES ==================

// Tomar ticket profesor
if(isset($_POST['tomar_profesor'])){
    $ticket_id = (int)$_POST['ticket_id'];
    // La beca solo toma errores de sala; los requerimientos de los profesores son del técnico.
    if (!TicketConsultas::becaPuedeVerTicket($pdo, (int)$user_id, TablaTicket::PROFESOR, $ticket_id) || !BecaPanelRepository::esDanioProfesor($pdo, $ticket_id)) {
        http_response_code(403);
        exit('No tienes acceso a este ticket.');
    }
    TicketAsignacionService::tomar($pdo, TablaTicket::PROFESOR, $ticket_id, (int)$user_id);
}

// Liberar ticket profesor
if(isset($_POST['liberar_profesor'])){
    $ticket_id = (int)$_POST['ticket_id'];
    TicketAsignacionService::liberar($pdo, TablaTicket::PROFESOR, $ticket_id, (int)$user_id);
}

// Sugerir mantenimiento general (spec 2.2-d / 2.1): el beca YA NO crea el
// mantenimiento directamente — solo lo remite/sugiere sobre un ticket que
// él mismo tomó, y es el técnico quien revisa y decide elevarlo (botón
// "Elevar a mantenimiento de sala" en tickets.php).
if(isset($_POST['sugerir_mantenimiento_profesor'])){
    $ticket_id = (int)$_POST['ticket_id'];
    if (TicketSeguimientoService::sugerirMantenimientoProfesor($pdo, $ticket_id, (int)$user_id)) {
        $tecnicos = BecaPanelRepository::idsTecnicos($pdo);
        foreach ($tecnicos as $idTec) {
            NotificationService::crear($pdo, (int)$idTec, 'TICKET_ESTADO', "Un beca sugiere elevar el ticket #$ticket_id a mantenimiento general de sala", "tickets.php");
        }
    }
}

// Actualizar ticket profesor
if(isset($_POST['update_profesor'])){
    $id = (int)$_POST['id'];
    $estado = (string)($_POST['estado'] ?? '');
    // Un beca_trabajo ejecuta pero NUNCA aprueba ni cierra: solo puede dejar En espera / En proceso /
    // Solucionado (TicketEstadoService::ESTADOS_ACTUALIZABLES_BECA). El técnico aprueba/rechaza desde tickets.php.
    $solucionUp = isset($_POST['solucion']) ? (string)$_POST['solucion'] : null;
    // Primero las fotos del formulario: Solucionado exige explicación + foto en los daños de sala.
    $avisoFotos = TicketInteraccionService::subirFotosDelFormulario($pdo, TablaTicket::PROFESOR, $id, (int)$user_id, $_FILES);
    $okUp = TicketEstadoService::actualizarEstadoEjecutor($pdo, TablaTicket::PROFESOR, $id, (int)$user_id, $estado, $solucionUp);
    if (!$okUp) {
        $error = TicketInteraccionService::motivoNoGuardado($pdo, TablaTicket::PROFESOR, $id, $estado, $solucionUp);
    } else {
        $exito = 'Reporte #' . $id . ' actualizado.';
        if ($avisoFotos !== '') $error = $avisoFotos;
    }
    if ($okUp && $estado === TicketWorkflow::SOLUCIONADO) {
        $tecnicos = BecaPanelRepository::idsTecnicos($pdo);
        foreach ($tecnicos as $idTec) {
            NotificationService::crear($pdo, (int)$idTec, 'TICKET_ESTADO', "Un beca_trabajo marcó el ticket #$id como Solucionado — pendiente de tu aprobación", "tickets.php");
        }
    }
}

// ================== ACCIONES: TICKETS QR ANÓNIMOS (daño de sala/equipo) ==================
// Los reportes de daño anónimos por QR (tabla `tickets`, origen
// QR_ANONIMO) también aparecen en esta pantalla, además de los que el
// propio beca crea (origen=BECA_DIRECTO): el beca es quien debe ir
// físicamente al salón, así que necesita verlos y poder tomarlos aquí,
// igual que ya puede con los de tickets_profesor.

// Tomar ticket QR anónimo
if(isset($_POST['tomar_qr'])){
    $ticket_id = (int)$_POST['ticket_id'];
    if (!TicketConsultas::becaPuedeVerTicket($pdo, (int)$user_id, TablaTicket::NORMAL, $ticket_id)) {
        http_response_code(403);
        exit('No tienes acceso a este ticket.');
    }
    // Esta pantalla solo maneja reportes QR anónimos (origen QR_ANONIMO);
    // se conserva ese filtro que tenía el UPDATE original.
    if (BecaPanelRepository::esReporteQrAnonimo($pdo, $ticket_id)) {
        TicketAsignacionService::tomar($pdo, TablaTicket::NORMAL, $ticket_id, (int)$user_id);
    }
}

// Liberar ticket QR anónimo
if(isset($_POST['liberar_qr'])){
    $ticket_id = (int)$_POST['ticket_id'];
    if (BecaPanelRepository::esReporteQrAnonimo($pdo, $ticket_id)) {
        TicketAsignacionService::liberar($pdo, TablaTicket::NORMAL, $ticket_id, (int)$user_id);
    }
}

// Actualizar ticket QR anónimo (mismo criterio que update_profesor: el
// beca deja constancia de que terminó, pero NUNCA aprueba/cierra él
// mismo — eso sigue siendo del técnico en tickets.php).
if(isset($_POST['update_qr'])){
    $id = (int)$_POST['id'];
    $estado = (string)($_POST['estado'] ?? '');
    $solucionUp = trim((string)($_POST['solucion'] ?? ''));
    $avisoFotos = TicketInteraccionService::subirFotosDelFormulario($pdo, TablaTicket::NORMAL, $id, (int)$user_id, $_FILES);
    $okUp = TicketEstadoService::actualizarEstadoEjecutor($pdo, TablaTicket::NORMAL, $id, (int)$user_id, $estado, $solucionUp, 'QR_ANONIMO');
    if (!$okUp) {
        $error = TicketInteraccionService::motivoNoGuardado($pdo, TablaTicket::NORMAL, $id, $estado, $solucionUp);
    } else {
        $exito = 'Reporte QR #' . $id . ' actualizado.';
        if ($avisoFotos !== '') $error = $avisoFotos;
    }
    if ($okUp && $estado === TicketWorkflow::SOLUCIONADO) {
        $tecnicos = BecaPanelRepository::idsTecnicos($pdo);
        foreach ($tecnicos as $idTec) {
            NotificationService::crear($pdo, (int)$idTec, 'TICKET_ESTADO', "Un beca_trabajo marcó el reporte QR #$id como Solucionado — pendiente de tu aprobación", "tickets.php");
        }
    }
}

// Tarea 2 (prompt de ajustes QR): un reporte que llegó por QR (anónimo o
// de profesor) NUNCA trae equipo(s) ya elegidos — el que lo tomó es
// quien, al revisar la sala en persona, decide cuál(es) equipo(s) están
// realmente afectados. Un único handler sirve para las dos tablas
// (tickets / tickets_profesor), igual que agregarActivosATicket() ya
// hace en el resto del sistema.
if(isset($_POST['agregar_equipos_ticket'])){
    // TicketSeguimientoService::agregarEquiposAfectados (la beca debe ser la ejecutora).
    TicketSeguimientoService::agregarEquiposAfectados(
        $pdo, (string)($_POST['tabla_ticket'] ?? ''), (int)($_POST['ticket_id'] ?? 0),
        (int)$user_id, $_POST['ids_equipo'] ?? [], true
    );
}

// ================== CONSULTAS ==================

// «Mis tickets» de la beca = una sola tabla de reportes de daño de sala: errores de sala de los
// profesores (con sesión o por QR) y reportes por QR sin sesión, más recientes primero (20 por página).
// Bodega, tickets creados y checklist de equipos van en su propia vista (?vista=…, submenú lateral).
$vista = in_array($_GET['vista'] ?? '', ['bodega', 'creados', 'checklist'], true) ? $_GET['vista'] : 'reportes';
$resultReportes = paginarArreglo(BecaPanelRepository::reportesDanio($pdo, (int)$user_id), max(1, (int)($_GET['pagina'] ?? 1)), ticketsPorPagina());
$reportesDanio = $resultReportes['datos'];
$tickets_prof = array_values(array_filter($reportesDanio, fn($r) => $r['_tipo'] === 'prof'));
$tickets_qr = array_values(array_filter($reportesDanio, fn($r) => $r['_tipo'] === 'qr'));

// Tickets creados por este becario (origen BECA_DIRECTO: mantenimiento de
// sala y cambio de pieza puntual — sección 4 del plan de inventario),
// también paginados.
$paginaMios = max(1, (int)($_GET['pagina_mios'] ?? 1));
$resultMios = BecaPanelRepository::ticketsCreadosPorBeca($pdo, (int)$user_id, $paginaMios, ticketsPorPagina());
$tickets_mios = $resultMios['datos'];

// ---- Tarea 2: equipos afectados de tickets de QR ya tomados ----
// Trae en bloque (no fila por fila) los equipos ya registrados en ticket_activos para los tickets de QR que
// este becario tomó, y los equipos disponibles de las salas de los que todavía no tienen nada (para pintar el
// checklist de "agregar equipos").
$idsTicketProfQr = [];
foreach ($tickets_prof as $p) {
    if (($p['origen'] ?? '') === 'QR_PROFESOR' && (int)$p['tecnico_id'] === $user_id) {
        $idsTicketProfQr[] = (int)$p['id'];
    }
}
$idsTicketQr = [];
foreach ($tickets_qr as $q) {
    if ((int)$q['tecnico_id'] === $user_id) {
        $idsTicketQr[] = (int)$q['id']; // $tickets_qr ya viene filtrado a origen=QR_ANONIMO
    }
}
$equiposYaProf = TicketActivos::equiposYaAsignadosATickets($pdo, TablaTicket::PROFESOR, $idsTicketProfQr);
$equiposYaQr   = TicketActivos::equiposYaAsignadosATickets($pdo, TablaTicket::NORMAL, $idsTicketQr);

$salasNecesarias = [];
foreach ($tickets_prof as $p) {
    if (($p['origen'] ?? '') === 'QR_PROFESOR' && (int)$p['tecnico_id'] === $user_id
        && empty($equiposYaProf[(int)$p['id']]) && !empty($p['id_sala'])) {
        $salasNecesarias[(int)$p['id_sala']] = true;
    }
}
foreach ($tickets_qr as $q) {
    if ((int)$q['tecnico_id'] === $user_id && empty($equiposYaQr[(int)$q['id']]) && !empty($q['id_sala'])) {
        $salasNecesarias[(int)$q['id_sala']] = true;
    }
}
$equiposPorSala = TicketActivos::equiposPorSalas($pdo, array_keys($salasNecesarias));

// Conversación y fotos de evidencia de los reportes visibles, en bloque.
$interaccion = TicketInteraccionService::precargar($pdo, [
    TablaTicket::NORMAL => array_column($tickets_qr, 'id'),
    TablaTicket::PROFESOR => array_column($tickets_prof, 'id'),
]);

// Checklist compartida de equipos por sala (módulo de Horarios, sección 9):
// mantenimientos de sala activos, con la lista de equipos que cualquier
// becario o el técnico puede ir marcando.
$mantenimientosActivos = BecaPanelRepository::mantenimientosActivos($pdo, (int)$user_id);
$checklistPorProgramado = BecaPanelRepository::checklistPorMantenimiento($pdo, $mantenimientosActivos);
$chipEquipo = ['PENDIENTE' => 'espera', 'TOMADO' => 'proceso', 'REALIZADO' => 'solucionado', 'INCOMPLETO' => 'incompleto', 'EXCLUIDO_TRASLADO' => 'espera', 'TRASLADO_PENDIENTE' => 'proceso'];
// Mismo plano de sala que en Mantenimientos (mantenimientos/checklist.php): estado real de cada equipo,
// videobeam/parlantes frente al tablero y la distribución maquetada del salón (filas, mesas, números).
$idsEquipoChecklist = [];
foreach ($checklistPorProgramado as $filasCk) { foreach ($filasCk as $fCk) { $idsEquipoChecklist[] = (int)$fCk['id_equipo']; } }
$idsSalaChecklist = array_map(fn($ma) => (int)$ma['id_sala'], $mantenimientosActivos);
$estadoEquipos = PlanoSalaService::estadoEquipos($pdo, $idsEquipoChecklist, true);
$frentePorSala = PlanoSalaService::frentePorSalas($pdo, $idsSalaChecklist, true);
$distribucionPorSala = DistribucionSalaRepository::porSalas($pdo, $idsSalaChecklist);
$flashChecklist = $_SESSION['flash_checklist_panel'] ?? null; // resultado de tomar/marcar/liberar hecho desde este panel
unset($_SESSION['flash_checklist_panel']);

// "Bodega" no es una sala física a la que se traslada el equipo completo — es el estado normal de una PIEZA
// suelta (componentes.id_equipo_actual IS NULL: "NULL = en bodega / sin asignar"). Lo único de "inventario"
// que el beca puede ver es esto: piezas sueltas, no el catálogo completo. Se excluyen las dadas de baja (ya
// no vuelven a instalarse) y se muestra de qué equipo/sala salieron, buscando el último evento de
// DESASIGNACION en su historial. Por páginas: con inventarios grandes la lista completa tumbaba el panel.
// Solo se consulta cuando se abre la vista «Piezas en bodega» (las demás vistas no la muestran).
$bodegaPaginado = $vista === 'bodega'
    ? BecaPanelRepository::componentesEnBodega($pdo, (int)($_GET['pagina_bodega'] ?? 1))
    : ['datos' => [], 'pagina' => 1, 'hay_siguiente' => false];
$componentesEnBodega = $bodegaPaginado['datos'];

?>
<?php
$titulosVista = [
    'reportes'  => ['Reportes de daño', 'Errores de sala reportados por los profesores (con o sin sesión) y por el QR de cada sala. Toma uno para atenderlo.'],
    'bodega'    => ['Piezas en bodega', 'Componentes retirados de las salas que esperan revisión o reparación.'],
    'creados'   => ['Mis tickets creados', 'Mantenimientos de sala y cambios de pieza que registraste.'],
    'checklist' => ['Checklist de equipos', 'Mantenimientos de sala activos: abre el plano, toma un equipo y márcalo.'],
];
[$tituloVista, $descVista] = $titulosVista[$vista];
?>
<?php $shell = ShellData::cargar($pdo);?>
<?php $paginaTitulo = $tituloVista; include LAYOUT_DIR . '/app_page_head.php'; ?>
<script src="<?= h(asset('assets/tickets.js')) ?>"></script>
<script src="<?= h(asset('assets/plano_sala.js')) ?>" defer></script>
<?php $tituloHeader = 'Mis tickets'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <div class="app-dashboard-welcome">
      <h1><?= h($tituloVista) ?></h1>
      <p><?= h($descVista) ?></p>
    </div>
    <?php if (!empty($error)): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
    <?php if (!empty($exito)): ?><div class="exito mb-3"><?= h($exito) ?></div><?php endif; ?>

<?php $vistasBeca = dirname(__DIR__, 4) . '/app/Presentation/Views/my_tickets_becatrabajo';
switch ($vista) {
    case 'bodega':    include $vistasBeca . '/seccion_bodega.php'; break;
    case 'creados':   include $vistasBeca . '/seccion_mis_tickets.php'; break;
    case 'checklist': include $vistasBeca . '/seccion_checklist.php'; break;
    default:          include $vistasBeca . '/seccion_reportes_danio.php';
} ?>
<?php include LAYOUT_DIR . '/app_page_end.php'; ?>
