<?php
/**
 * Controlador de public/ticket_profesor_pdf.php: sesión → TicketPdfService (acceso y datos) → TicketPdf
 * (dibujo). Ámbito global.
 */
$raiz = dirname(__DIR__, 4);

requireAuthOrDie();

$res = TicketPdfService::paraTicketProfesor($pdo, $_GET['id'] ?? null, (string)($_SESSION['role'] ?? ''), (int)$_SESSION['user_id']);
if ($res['error'] !== null) {
    if ($res['status'] !== 200) http_response_code($res['status']);
    die($res['error']);
}

TicketPdf::ticketProfesor($res['ticket'], $res['equipos'], $res['extra'])->Output('I', 'Ticket_Profesor_' . $res['ticket']['id'] . '.pdf');
