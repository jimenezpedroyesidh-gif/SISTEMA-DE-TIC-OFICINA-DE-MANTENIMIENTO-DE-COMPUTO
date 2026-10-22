<?php
/* Panel de tickets (Solicitante). */

// Solicitantes y secretaria: la secretaria ve los que ella creó y, si el admin le activó el permiso, todos
// los tickets del Departamento de Sistemas.
requireRole([Rol::SOLICITANTE, Rol::SECRETARIA]);
$esSecretaria = $_SESSION['role'] === Rol::SECRETARIA;
$veTicketsSistemas = $esSecretaria && UserPermissions::tiene($pdo, (int)$_SESSION['user_id'], UserPermissions::VER_TICKETS_SISTEMAS);

// Encuesta de satisfacción de un ticket resuelto (una sola vez por ticket).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calificar_servicio'])) {
    $_SESSION['flash_encuesta'] = EncuestaService::responderDesdePanel($pdo, (int)$_SESSION['user_id'], TablaTicket::NORMAL, (int)($_POST['id_ticket'] ?? 0), $_POST['calificacion'] ?? null, (string)($_POST['comentario'] ?? ''));
    header('Location: tickets.php?pagina_solucionados=1');
    exit();
}
// Conversación con soporte (responder o reiterar respetando el tiempo de respuesta acordado).
$flashConversacion = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['conversacion_enviar'])) {
    $flashConversacion = TicketInteraccionService::procesar($pdo, $_POST, $_FILES, (int)$_SESSION['user_id'], (string)$_SESSION['role']);
}
$flashEncuesta = $_SESSION['flash_encuesta'] ?? null;
unset($_SESSION['flash_encuesta']);

// Obtener info del usuario logueado
$usuario = BecaPanelRepository::datosUsuario($pdo, (int)$_SESSION['user_id']);

// Tickets en espera, en proceso, o rechazados (un rechazo necesita más
// trabajo del técnico) — vía obtenerTicketsPorUsuario() (Módulo 2),
// paginados 20 por página (Módulo 4). Parámetro propio (pagina_activos)
// para no chocar con la paginación de la tabla de solucionados.
$paginaActivos = max(1, (int)($_GET['pagina_activos'] ?? 1));
$resultActivos = TicketConsultas::obtenerTicketsPorUsuario($pdo, (int)$_SESSION['user_id'], TablaTicket::NORMAL, [TicketWorkflow::EN_ESPERA, TicketWorkflow::EN_PROCESO, TicketWorkflow::RECHAZADO], $paginaActivos, ticketsPorPagina());
$tickets_activos = $resultActivos['datos'];

// Tickets solucionados/aprobados/cerrados
$paginaSolucionados = max(1, (int)($_GET['pagina_solucionados'] ?? 1));
$resultSolucionados = TicketConsultas::obtenerTicketsPorUsuario($pdo, (int)$_SESSION['user_id'], TablaTicket::NORMAL, [TicketWorkflow::SOLUCIONADO, TicketWorkflow::APROBADO, TicketWorkflow::CERRADO], $paginaSolucionados, ticketsPorPagina());
$tickets_solucionados = $resultSolucionados['datos'];
$respuestasEncuesta = EncuestaService::respuestasDe($pdo, TablaTicket::NORMAL, array_column($tickets_solucionados, 'id'));

$interaccion = TicketInteraccionService::precargar($pdo, [TablaTicket::NORMAL => array_merge(array_column($tickets_activos, 'id'), array_column($tickets_solucionados, 'id'))]);
$estadosConv = TicketInteraccionService::estadosSolicitante($pdo, TablaTicket::NORMAL, array_merge($tickets_activos, $tickets_solucionados), $interaccion['conversacion']['tickets']);

$paginaSistemas = max(1, (int)($_GET['pagina_sistemas'] ?? 1));
$resultSistemas = $veTicketsSistemas ? TicketConsultas::obtenerTicketsSistemas($pdo, null, $paginaSistemas, ticketsPorPagina()) : null;
// Pestaña a abrir: la de la paginación que se está usando.
$seccionInicial = isset($_GET['pagina_sistemas']) ? 'sistemas' : (isset($_GET['pagina_solucionados']) ? 'solucionados' : 'activos');
if ($flashConversacion && in_array((int)($_POST['ticket_id'] ?? 0), array_map('intval', array_column($tickets_solucionados, 'id')), true)) $seccionInicial = 'solucionados';
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
    <div class="actions mb-3">
      <button class="btn btn-ghost" data-click="panel.mostrarSeccion" data-arg="activos">Activos</button>
      <button class="btn btn-ghost" data-click="panel.mostrarSeccion" data-arg="solucionados">Solucionados</button>
      <?php if($veTicketsSistemas): ?><button class="btn btn-ghost" data-click="panel.mostrarSeccion" data-arg="sistemas">Tickets de Sistemas</button><?php endif; ?>
    </div>

  <?php /* Tickets activos */ ?>
  <div data-seccion-inicial="<?= htmlspecialchars($seccionInicial, ENT_QUOTES, 'UTF-8') ?>" hidden></div>
  <div id="activos" class="tab-content active">
    <?php $filasTabla = $tickets_activos; $conSolucion = false; include dirname(__DIR__, 2) . '/Views/tickets/_tabla_solicitante.php'; ?>
    <?= renderControlesPaginacion($resultActivos['pagina'], $resultActivos['total_paginas'], [], 'pagina_activos') ?>
  </div>

  <?php /* Tickets solucionados */ ?>
  <div id="solucionados" class="tab-content">
    <?php $filasTabla = $tickets_solucionados; $conSolucion = true; include dirname(__DIR__, 2) . '/Views/tickets/_tabla_solicitante.php'; ?>
    <?= renderControlesPaginacion($resultSolucionados['pagina'], $resultSolucionados['total_paginas'], [], 'pagina_solucionados') ?>
    <?php $ticketsEncuesta = $tickets_solucionados; include PARCIALES_DIR . '/encuesta_ventanas.php'; ?>
  </div>

  <?php if($veTicketsSistemas): ?>
  <?php /* Tickets del Departamento de Sistemas (solo lectura; permiso que activa el admin) */ ?>
  <div id="sistemas" class="tab-content">
    <table class="data tk-lista">
      <thead>
        <tr><th>Ticket</th><th>Solicitud</th><th>Personas</th><th>Estado</th></tr>
      </thead>
      <tbody>
        <?php foreach($resultSistemas['datos'] as $t): ?>
        <tr>
          <td data-label="Ticket" class="tk-col-id"><span class="tk-num">#<?= (int)$t['id'] ?></span> <span class="tk-fecha"><?= h(date('d/m/Y H:i', strtotime((string)$t['fecha_creacion']))) ?></span></td>
          <td data-label="Solicitud" class="tk-col-sol">
            <div class="tk-titulo"><?= h(trim(($t['nombre_sala'] ?? $t['ubicacion'] ?? '-') . (!empty($t['equipo']) ? ' · ' . $t['equipo'] : ''))) ?></div>
            <p class="tk-desc"><?= h((string)$t['descripcion']) ?></p>
          </td>
          <td data-label="Personas"><div class="tk-personas">
            <span><span class="tk-persona-rol">Solicita</span> <?= h((string)($t['solicitante_nombre'] ?? '-')) ?></span>
            <span><span class="tk-persona-rol">Atiende</span> <?= h((string)($t['tecnico_nombre'] ?? '-')) ?></span>
          </div></td>
          <td data-label="Estado"><span class="chip <?= claseChipHistorialTicket((string)$t['estado']) ?>"><?= h($t['estado']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        <?php if(!$resultSistemas['datos']): ?><tr><td colspan="4">No hay tickets del Departamento de Sistemas.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?= renderControlesPaginacion($resultSistemas['pagina'], $resultSistemas['total_paginas'], [], 'pagina_sistemas') ?>
  </div>
  <?php endif; ?>
<?php include LAYOUT_DIR . '/app_page_end.php'; ?>
