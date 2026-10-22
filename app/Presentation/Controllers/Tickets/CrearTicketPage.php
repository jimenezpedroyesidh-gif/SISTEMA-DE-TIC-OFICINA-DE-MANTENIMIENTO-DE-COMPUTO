<?php
/**
 * Autorización, CSRF, parámetros, TicketCreacionService::crearTicketPanel y vista; sin SQL ni reglas de
 * negocio. Ámbito global.
 */
$raiz = dirname(__DIR__, 4);
requirePagina('create_ticket.php');

$rolUsuario = $_SESSION['role'];
$error = null;

// Secretaria: equipos de cómputo que llegan a la oficina de mantenimiento (no salas).
$oficinaMantenimiento = $rolUsuario === Rol::SECRETARIA ? (TicketRepository::nombreOficinaMantenimiento($pdo) ?? 'Oficina de mantenimiento') : '';
$viejo = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : []; // lo escrito vuelve al formulario si hay error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // La creación (validaciones, lista blanca de categorías, regla de salas de Sistemas para secretaria)
    // vive en TicketCreacionService::crearTicketPanel().
    $res = TicketCreacionService::crearTicketPanel($pdo, (int)$_SESSION['user_id'], $rolUsuario, $_POST);
    if ($res['ok']) {
        header("Location: tickets.php?new=1");
        exit();
    }
    $error = $res['error'];
}

$shell = ShellData::cargar($pdo);
require $raiz . '/app/Presentation/Views/tickets/crear.php';
