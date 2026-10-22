<?php
/* Panel de tickets (Admin). */
require_once dirname(__DIR__, 4) . '/app/Infrastructure/Database/PostgresConnection.php';
requireRole(Rol::ADMIN);


// ========================
// Tickets Solicitante — separados en Departamento de Sistemas / Externos. El admin veía todo mezclado.
// ========================
$todosTicketsSolicitante = AdminTicketsQueries::ticketsSolicitanteLigeros($pdo);
// Del Departamento: sala de Sistemas, creado directo por técnico/beca, o registrado por la secretaría del Departamento.
$esTicketDepto = fn($t) => TicketConsultas::esDependenciaSistemas($t['sala_dependencia'] ?? null) || in_array($t['origen'] ?? '', ['TECNICO_DIRECTO','BECA_DIRECTO'], true) || ($t['solicitante_rol'] ?? '') === Rol::SECRETARIA;
$tickets_solicitante_depto = array_values(array_filter($todosTicketsSolicitante, $esTicketDepto));
$tickets_solicitante_externo = array_values(array_filter($todosTicketsSolicitante, fn($t) => !$esTicketDepto($t)));

// ========================
// Tickets Profesor — mismo criterio, resolviendo la sala vía tp.id_sala/id_sala_actual directamente.
// ========================
$todosTicketsProfesor = AdminTicketsQueries::ticketsProfesorLigeros($pdo);
$esProfDeSistemas = fn($p) => TicketConsultas::esDependenciaSistemas($p['dependencia_sala'] ?? null) || TicketConsultas::esDependenciaSistemas($p['dependencia_sala_actual'] ?? null);
$tickets_profesor_depto = array_values(array_filter($todosTicketsProfesor, $esProfDeSistemas));
$tickets_profesor_externo = array_values(array_filter($todosTicketsProfesor, fn($p) => !$esProfDeSistemas($p)));
$diasSemanaCorto = [1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'];
$duracionesPrestamoLabel = TicketProfesorCreacionService::DURACIONES_PRESTAMO;

// Cada tabla muestra 5 por página (más recientes primero), con su propia paginación. Las listas ya vienen
// ordenadas por fecha_creacion DESC. Bandeja unificada por grupo: solicitantes/QR y profesores juntos, en
// Pendientes y Firmados (5 por página c/u).
$cargarSolicitantes = fn(array $ids) => AdminTicketsQueries::ticketsSolicitantePorIds($pdo, $ids);
$cargarProfesores = fn(array $ids) => AdminTicketsQueries::ticketsProfesorPorIds($pdo, $ids);
$bandejaAdminDepto = TicketBandeja::desdeListasLigeras($tickets_solicitante_depto, $tickets_profesor_depto, (int)($_GET['pagina_depto_pend'] ?? 1), (int)($_GET['pagina_depto_firm'] ?? 1), $cargarSolicitantes, $cargarProfesores);
$bandejaAdminExt = TicketBandeja::desdeListasLigeras($tickets_solicitante_externo, $tickets_profesor_externo, (int)($_GET['pagina_ext_pend'] ?? 1), (int)($_GET['pagina_ext_firm'] ?? 1), $cargarSolicitantes, $cargarProfesores);
// El admin también es docente del Departamento: sus propias solicitudes (requerimientos / daños) en «Mis solicitudes».
$flashConversacion = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['conversacion_enviar'])) {
    $flashConversacion = TicketInteraccionService::procesar($pdo, $_POST, $_FILES, (int)$_SESSION['user_id'], Rol::ADMIN);
}
$misSolicitudes = TicketConsultas::obtenerTicketsPorUsuario($pdo, (int)$_SESSION['user_id'], TablaTicket::PROFESOR, null, 1, 1000)['datos'];
$pagMisSolicitudes = paginarArreglo($misSolicitudes, (int)($_GET['pagina_mis'] ?? 1));

// Conversación y fotos de evidencia de las filas visibles (el admin consulta las ajenas y escribe en las suyas).
$interaccion = TicketInteraccionService::precargar($pdo, [
    TablaTicket::NORMAL => array_column(TicketBandeja::visibles([$bandejaAdminDepto, $bandejaAdminExt], 'normal'), 'id'),
    TablaTicket::PROFESOR => array_merge(array_column(TicketBandeja::visibles([$bandejaAdminDepto, $bandejaAdminExt], 'prof'), 'id'), array_column($pagMisSolicitudes['datos'], 'id')),
]);
$estadosConv = TicketInteraccionService::estadosSolicitante($pdo, TablaTicket::PROFESOR, $pagMisSolicitudes['datos'], $interaccion['conversacion']['tickets_profesor']);

// ========================
// Cambiar contraseña del admin
// ========================
if(isset($_POST['cambiar_pass'])){
    // Se exige la clave actual antes de aceptar la nueva (igual que cambiar_clave.php para el resto de roles)
    // — así una sesión de admin dejada abierta un momento no basta para tomar la cuenta completa. La
    // verificación y el cambio viven en UserAccount::cambiarPropiaClave().
    $resPass = UserAccount::cambiarPropiaClave($pdo, (int)$_SESSION['user_id'], (string)($_POST['clave_actual_admin'] ?? ''), (string)($_POST['nueva_pass'] ?? ''));
    $mensaje_pass = $resPass['ok'] ? "Contraseña cambiada correctamente." : $resPass['error'];
}

// ========================
// Aprobar / Rechazar ticket
// ========================
// El admin NO asigna nada en tickets de Daño/error — solo observa el
// proceso y consulta estadísticas. La tabla `tickets` es exactamente
// ese tipo (QR anónimo + solicitante), y aprobar/rechazar es una
// decisión técnica, no una simple observación: ese paso es exclusivo
// del técnico (ver tickets.php: aprobar_ticket_tecnico /
// rechazar_ticket_tecnico y aprobar_inventario_ticket ya existente).
// Aquí solo queda un mensaje informativo si alguien intenta forzar la
// acción vieja.
if(isset($_POST['aprobar_ticket']) || isset($_POST['rechazar_ticket'])){
    $mensaje_bloqueo_inventario = "Los tickets de Daño/error solo pueden aprobarse o rechazarse desde el panel del técnico responsable. El admin únicamente supervisa y consulta estadísticas para este tipo de ticket.";
}

// Para tickets_profesor SÍ puede haber acción de admin, pero solo cuando el
// ticket es de tipo 'requerimiento' (donde el documento permite que el admin
// "asigne según política operativa"). Si es tipo 'danio', es el mismo caso
// de arriba y queda exclusivamente en manos del técnico.
if(isset($_POST['aprobar_profesor'])){
    $id = (int)$_POST['ticket_id'];
    // Reglas en TicketAprobacionService::aprobarProfesorAdmin().
    $res = TicketAprobacionService::aprobarProfesorAdmin($pdo, $id, (int)$_SESSION['user_id']);
    if ($res['bloqueo'] === 'danio') {
        $mensaje_bloqueo_inventario = "Este ticket es de tipo Daño/error: solo el técnico responsable puede aprobarlo o rechazarlo, el admin solo supervisa.";
    } elseif ($res['bloqueo'] === 'prestamo') {
        // Regla dura #3: préstamo no se cierra sin devolución confirmada,
        // ni siquiera por el admin.
        $mensaje_bloqueo_inventario = "Este ticket es un préstamo — no se puede aprobar hasta que el técnico confirme la devolución.";
    } else {
        if ($res['ok']) {
            // Si es un CAMBIO_SALA, crea (o actualiza) la excepción "solo esta semana" en excepciones_horario
            // -- ver HorarioEventos::crearExcepcionPorCambioSalaAprobado(). No hace nada si
            // el ticket no es de ese subtipo.
            HorarioEventos::crearExcepcionPorCambioSalaAprobado($pdo, $id, (int)$_SESSION['user_id']);
            if ($res['id_profesor']) {
                NotificationService::crear($pdo, $res['id_profesor'], 'TICKET_ESTADO', "Tu ticket #$id pasó a Aprobado", TicketConsultas::enlaceTicketParaUsuario($pdo, $res['id_profesor'], "ticket_profesor_pdf.php?id=$id"));
            }
        }
        header("Location: tickets.php"); exit();
    }
}
if(isset($_POST['rechazar_profesor'])){
    $id = (int)$_POST['ticket_id'];
    $res = TicketAprobacionService::rechazarProfesorAdmin($pdo, $id, (int)$_SESSION['user_id']);
    if ($res['bloqueo'] === 'danio') {
        $mensaje_bloqueo_inventario = "Este ticket es de tipo Daño/error: solo el técnico responsable puede aprobarlo o rechazarlo, el admin solo supervisa.";
    } else {
        if ($res['ok'] && $res['id_profesor']) {
            NotificationService::crear($pdo, $res['id_profesor'], 'TICKET_ESTADO', "Tu ticket #$id fue Rechazado", TicketConsultas::enlaceTicketParaUsuario($pdo, $res['id_profesor'], "ticket_profesor_pdf.php?id=$id"));
        }
        header("Location: tickets.php"); exit();
    }
}

// ========================
// Estadísticas Generales
// ========================
$stats = TicketConsultas::contarTicketsPorEstado($pdo);
$stats_tecnicos = TicketConsultas::ticketsPorTecnico($pdo);

// ========================
// Tendencias: tickets por semana y tiempo de resolución (fecha_creacion/fecha_terminacion).
// ========================
$tendenciaSemanal = TicketConsultas::ticketsPorSemana($pdo, 8);
$tendenciaLabels = array_map(function ($fila) {
    // YEARWEEK(...,3) da algo como "202634" (ISO); lo mostramos como
    // "Sem 34" para no amontonar el eje X con el año todas las veces.
    return 'Sem ' . substr((string)$fila['semana'], 4);
}, $tendenciaSemanal);
$tendenciaValores = array_map(fn($fila) => (int)$fila['total'], $tendenciaSemanal);
$tiempoPromedioGeneral = TicketConsultas::tiempoPromedioResolucion($pdo);

// ========================
// Estadísticas de tiempo y de fallas
// ========================

// Tiempo promedio de resolución en horas (global), tickets normales y de
// profesor por separado — cada tabla tiene su propia columna de cierre
// (fecha_terminacion / fecha_firmado).
$tiempoTickets = AdminTicketsQueries::tiempoPromedioTickets($pdo);

$tiempoTicketsProfesor = AdminTicketsQueries::tiempoPromedioTicketsProfesor($pdo);

// Tiempo promedio por técnico, solo tickets normales (son los que tienen
// fecha_terminacion consistente en todo el ciclo de vida)
$tiempoPorTecnico = AdminTicketsQueries::tiempoPorTecnico($pdo);

// Salas que más reportan: combina tickets normales (id_sala si viene de QR,
// o el texto nombre_dependencia si es panel interno viejo) con los tickets
// de profesor tipo 'danio' (sala_danio/salon son texto libre, sin id_sala).
$rankingSalas = AdminTicketsQueries::rankingSalas($pdo);

// Modelos que más fallan — fuente 1: texto libre `marca_modelo` en tickets.
// Cubre todos los tickets normales, pero cada quien lo escribe distinto
// (mismo modelo puede aparecer varias veces con variaciones de escritura).
$rankingModelosTexto = AdminTicketsQueries::rankingModelosTexto($pdo);

// Modelos que más fallan — fuente 2: componentes reales retirados del
// inventario por daño (evento DESASIGNACION en historial_componente). Dato
// estructurado y confiable, pero solo cubre lo que pasó por el flujo de
// inventario con propuesta de cambio de pieza aprobada.
$rankingModelosComponentes = AdminTicketsQueries::rankingModelosComponentes($pdo);

// ========================
// Becas trabajo activos (Módulo B del plan): se cruza con la asistencia
// facial real (PostgreSQL), no con el simple "online" de sesión web.
// ========================
$becasUsuarios = AdminTicketsQueries::becasUsuarios($pdo);
$codigosBecas = array_filter(array_column($becasUsuarios, 'codigo'));
$estadoAsistencia = estadoAsistenciaPorCodigos($codigosBecas);
// Fin de semestre: aviso para que el admin decida si archiva los tickets terminados (nunca es automático).
$avisoArchivo = ArchivoSemestralService::avisoPendiente($pdo);
?>
<?php $shell = ShellData::cargar($pdo);?>
<?php $paginaTitulo = 'Panel de administración'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<script src="<?= h(asset('assets/vendor/chartjs-4.4.7/chart.umd.min.js')) ?>"></script>
<?php $tituloHeader = 'Panel de administración'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <div class="app-dashboard-welcome">
      <h1>Panel de administración</h1>
      <p>Supervisa tickets, becas, dependencias y tendencias. Cambia de vista con las pestañas.</p>
    </div>
    <?php if ($avisoArchivo): ?>
      <div class="nota-info mb-3">
        <strong>Fin de semestre <?= h($avisoArchivo['periodo']) ?>:</strong> hay <?= (int)$avisoArchivo['terminados'] ?> ticket(s) terminados que puedes archivar para aligerar los paneles
        (<?= (int)$avisoArchivo['abiertos'] ?> abiertos se quedan). <a href="archivo_semestral.php?periodo=<?= h(urlencode($avisoArchivo['periodo'])) ?>#cerrar">Revisar y decidir</a>
      </div>
    <?php endif; ?>

    <nav class="tabs mb-3" data-panel-hash aria-label="Secciones del panel">
      <button class="btn btn-ghost btn-sm" type="button" data-click="panel.showSection" data-arg="tickets-departamento">Departamento de Sistemas</button>
      <button class="btn btn-ghost btn-sm" type="button" data-click="panel.showSection" data-arg="mis-solicitudes">Mis solicitudes</button>
      <button class="btn btn-ghost btn-sm" type="button" data-click="panel.showSection" data-arg="tickets-externos">Otras dependencias</button>
      <button class="btn btn-ghost btn-sm" type="button" data-click="panel.showSection" data-arg="estadisticas">Estadísticas</button>
      <button class="btn btn-ghost btn-sm" type="button" data-click="panel.showSection" data-arg="tendencias">Tendencias</button>
      <button class="btn btn-ghost btn-sm" type="button" data-click="panel.showSection" data-arg="becas-activas">Becas activas</button>
      <button class="btn btn-ghost btn-sm" type="button" data-click="panel.showSection" data-arg="cambiar-pass">Cambiar contraseña</button>
    </nav>

  <?php
  // Cada pestaña vive en app/Presentation/Views/admin_tickets/ (se incluyen con el mismo ámbito de variables
  // que esta página).
  $vistasAdmin = dirname(__DIR__, 4) . '/app/Presentation/Views/admin_tickets';
  include $vistasAdmin . '/seccion_departamento.php';
  include $vistasAdmin . '/seccion_mis_solicitudes.php';
  include $vistasAdmin . '/seccion_externos.php';
  include $vistasAdmin . '/seccion_estadisticas.php';
  include $vistasAdmin . '/seccion_tendencias.php';
  include $vistasAdmin . '/seccion_becas.php';
  include $vistasAdmin . '/seccion_cambiar_pass.php';
  ?>
<?php include LAYOUT_DIR . '/app_page_main_end.php'; ?>

<?php include $vistasAdmin . '/scripts_graficos.php'; ?>
<script src="<?= h(asset('assets/paneles.js')) ?>"></script>
<script src="<?= h(asset('assets/graficos_admin.js')) ?>"></script>
</body>
</html>
