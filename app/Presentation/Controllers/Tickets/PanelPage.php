<?php
/**
 * Controlador del panel único de tickets (public/tickets.php): elige el panel por el rol de la sesión.
 *   tecnico → TecnicoPanel · admin → AdminPanel · beca_trabajo → BecaPanel · profesor → ProfesorPanel
 *   solicitante / secretaria → SolicitantePanel
 * Cada panel conserva su propio requireRole() como segunda barrera. Ámbito global.
 */
define('PANEL_TICKETS', true); // el menú lateral marca «Tickets» como activo
require_once __DIR__ . '/PanelRouter.php';

requireAuth();

$panel = panelTicketsDeRol((string)($_SESSION['role'] ?? ''));
if ($panel === null) {
    // Rol desconocido: no se le concede acceso por defecto.
    header('Location: login.php');
    exit();
}

require __DIR__ . '/' . $panel . 'Panel.php';
