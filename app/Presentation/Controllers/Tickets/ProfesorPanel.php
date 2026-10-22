<?php
/* Panel de tickets (Profesor). */

requireRole(Rol::PROFESOR);

// Encuesta de satisfacción de un ticket resuelto (una sola vez por ticket).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calificar_servicio'])) {
    $_SESSION['flash_encuesta'] = EncuestaService::responderDesdePanel($pdo, (int)$_SESSION['user_id'], TablaTicket::PROFESOR, (int)($_POST['id_ticket'] ?? 0), $_POST['calificacion'] ?? null, (string)($_POST['comentario'] ?? ''));
    header('Location: tickets.php?pagina_solucionados=1#tickets-solucionados');
    exit();
}
// Conversación con soporte (responder o reiterar respetando el tiempo de respuesta acordado).
$flashConversacion = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['conversacion_enviar'])) {
    $flashConversacion = TicketInteraccionService::procesar($pdo, $_POST, $_FILES, (int)$_SESSION['user_id'], Rol::PROFESOR);
}
$flashEncuesta = $_SESSION['flash_encuesta'] ?? null;
unset($_SESSION['flash_encuesta']);

// obtener datos del usuario actual
$usuario = BecaPanelRepository::datosUsuario($pdo, (int)$_SESSION['user_id']);

// Tickets del profesor vía obtenerTicketsPorUsuario() (Módulo 2).
$resultTickets = TicketConsultas::obtenerTicketsPorUsuario($pdo, (int)$_SESSION['user_id'], TablaTicket::PROFESOR, null, 1, 1000);
$tickets_all = $resultTickets['datos'];

// separar tickets por estado
$estadosCerrados = [TicketWorkflow::SOLUCIONADO,TicketWorkflow::APROBADO,TicketWorkflow::CERRADO];
$tickets_activos = array_filter($tickets_all, fn($t) => !in_array($t['estado'], $estadosCerrados, true));
$tickets_solucionados = array_filter($tickets_all, fn($t) => in_array($t['estado'], $estadosCerrados, true));

// 5 por página, más recientes primero (cada lista con su paginación).
$resActivos = paginarArreglo($tickets_activos, (int)($_GET['pagina_activos'] ?? 1));
$resSolucionados = paginarArreglo($tickets_solucionados, (int)($_GET['pagina_solucionados'] ?? 1));
$tickets_activos = $resActivos['datos'];
$tickets_solucionados = $resSolucionados['datos'];
$verSolucionados = isset($_GET['pagina_solucionados']);
$respuestasEncuesta = EncuestaService::respuestasDe($pdo, TablaTicket::PROFESOR, array_column($tickets_solucionados, 'id'));
$interaccion = TicketInteraccionService::precargar($pdo, [TablaTicket::PROFESOR => array_merge(array_column($tickets_activos, 'id'), array_column($tickets_solucionados, 'id'))]);
$estadosConv = TicketInteraccionService::estadosSolicitante($pdo, TablaTicket::PROFESOR, array_merge($tickets_activos, $tickets_solucionados), $interaccion['conversacion']['tickets_profesor']);
// Si el profesor viene a conversar sobre un ticket ya solucionado, se abre esa lista.
if ($flashConversacion && in_array((int)($_POST['ticket_id'] ?? 0), array_map('intval', array_column($tickets_solucionados, 'id')), true)) $verSolucionados = true;

?>

<?php $shell = ShellData::cargar($pdo);?>
<?php $paginaTitulo = 'Mis tickets'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<script src="<?= h(asset('assets/paneles.js')) ?>"></script>
<?php $tituloHeader = 'Mis tickets'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <div class="app-dashboard-welcome">
      <h1>Mis tickets</h1>
      <p><?= htmlspecialchars($usuario['cargo']) ?> · <?= htmlspecialchars($usuario['correo']) ?></p>
    </div>

    <?php if ($flashConversacion && $flashConversacion['mensaje'] !== ''): ?><div class="mb-3 <?= $flashConversacion['ok'] ? 'exito' : 'error' ?>"><?= h($flashConversacion['mensaje']) ?></div><?php endif; ?>
    <?php if ($flashEncuesta): ?><div class="mb-3 <?= $flashEncuesta['ok'] ? 'exito' : 'error' ?>"><?= h($flashEncuesta['mensaje']) ?></div><?php endif; ?>
    <?php if (isset($_GET['creado']) && $_GET['creado'] === '1'): ?>
    <div class="exito mb-3">Ticket creado correctamente y enviado al listado.</div>
    <?php endif; ?>

    <div class="actions mb-3">
      <button class="btn btn-ghost" data-click="panel.toggleSolucionados">Ver/Ocultar Solucionados</button>
    </div>

<?php /* Tickets activos */ ?>
<?php $filasTabla = $tickets_activos; $conSolucion = false; include dirname(__DIR__, 2) . '/Views/tickets/_tabla_profesor.php'; ?>
<?= renderControlesPaginacion($resActivos['pagina'], $resActivos['total_paginas'], [], 'pagina_activos') ?>

<?php /* Tickets solucionados */ ?>
<section id="tickets-solucionados" class="mt-4"<?= $verSolucionados ? '' : ' hidden' ?>>
  <h3 class="mb-2">Tickets solucionados</h3>
  <?php $filasTabla = $tickets_solucionados; $conSolucion = true; include dirname(__DIR__, 2) . '/Views/tickets/_tabla_profesor.php'; ?>
  <?= renderControlesPaginacion($resSolucionados['pagina'], $resSolucionados['total_paginas'], [], 'pagina_solucionados', 'tickets-solucionados') ?>
</section>
<?php $ticketsEncuesta = $tickets_solucionados; include PARCIALES_DIR . '/encuesta_ventanas.php'; ?>
<?php include LAYOUT_DIR . '/app_page_end.php'; ?>
