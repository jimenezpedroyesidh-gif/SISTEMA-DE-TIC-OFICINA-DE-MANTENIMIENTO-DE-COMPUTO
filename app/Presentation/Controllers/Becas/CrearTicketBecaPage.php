<?php
/** Autorización, CSRF, parámetros, TicketBecaService y vista; sin SQL ni reglas de negocio. Ámbito global. */
$raiz = dirname(__DIR__, 4);

// tickets.php solo actúa sobre tickets existentes; aquí se crean los propios de beca/técnico:
// cambio de pieza, cambio de posición o llevar un equipo a bodega sin reporte previo.
requirePagina('create_ticket_beca.php');

$rolActual = $_SESSION['role'];
$becaId = (int)$_SESSION['user_id'];
$esTecnicoOAdmin = in_array($rolActual, [Rol::TECNICO, Rol::ADMIN], true);
$error = '';
$success = '';

// Si se llega desde un ticket de daño de profesor que este usuario ya tomó, el ticket de cambio de pieza
// que se cree queda enlazado a él (siempre revalidado contra la BD).
$ticketProfesorOrigenGet = TicketBecaService::resolverTicketProfesorOrigen($pdo, $_GET['id_ticket_profesor_origen'] ?? null, $becaId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = TicketBecaService::crear($pdo, $becaId, $rolActual, $_POST);
    $error = $res['error'];
    $success = $res['exito'];
}

$salas = TicketBecaRepository::salasParaSelect($pdo);
$todosLosEquipos = TicketBecaRepository::equiposParaSelect($pdo);

$shell = ShellData::cargar($pdo);
require $raiz . '/app/Presentation/Views/becas/crear_ticket.php';
