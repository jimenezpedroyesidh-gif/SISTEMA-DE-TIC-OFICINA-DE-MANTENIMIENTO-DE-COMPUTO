<?php
/**
 * No exige sesión: solo el código de seguimiento que se mostró al reportar. Deliberadamente NO se busca por
 * id del ticket (permitiría enumerar reportes ajenos) ni se expone el detalle interno. Ámbito global.
 */
$raiz = dirname(__DIR__, 4);

// Encuesta de satisfacción de quien reportó por QR sin cuenta (identificado por su código).
$mensajeEncuesta = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calificar'])) {
    csrfCheck();
    $mensajeEncuesta = EncuestaService::responderConCodigo($pdo, (string)($_POST['codigo'] ?? ''), $_POST['calificacion'] ?? null, (string)($_POST['comentario'] ?? ''));
}

$codigo = validarTexto($_GET['codigo'] ?? ($_POST['codigo'] ?? ''), 100) ?? '';
$res = ReporteQrService::consultar($pdo, $codigo, $_SESSION);
$codigo = $res['codigo'];
$ticket = $res['ticket'];
$error = $res['error'];
// Conversación con soporte: quien reportó por QR (sin cuenta) responde o reitera con su código de seguimiento.
// Solo para reportes vivos (no archivados): se vuelve a leer la fila real y se exige que el código coincida.
$ticketVivo = null;
$mensajeConversacion = null;
if ($ticket) {
    $fila = TicketRepository::crudo($pdo, TablaTicket::NORMAL, (int)$ticket['id']);
    if ($fila && ($fila['origen'] ?? '') === 'QR_ANONIMO' && hash_equals((string)$fila['codigo_seguimiento'], strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo)))) {
        $ticketVivo = $fila;
    }
}
if ($ticketVivo && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['conversacion_enviar'])) {
    csrfCheck();
    $mensajeConversacion = TicketConversacion::enviarSolicitante($pdo, TablaTicket::NORMAL, (int)$ticketVivo['id'], null, (string)($_POST['mensaje'] ?? ''), (string)($ticketVivo['nombre_contacto'] ?: 'Quien reportó (QR)'));
}
$mensajesConversacion = $ticketVivo ? TicketConversacion::deTicket($pdo, TablaTicket::NORMAL, (int)$ticketVivo['id']) : [];
$estadoConversacion = $ticketVivo ? TicketConversacion::estadoSolicitante($pdo, TablaTicket::NORMAL, $ticketVivo, $mensajesConversacion) : null;

$encuesta = $ticket ? EncuestaService::respuesta($pdo, TablaTicket::NORMAL, (int)$ticket['id']) : null;
$puedeCalificar = $ticket && $encuesta === null && in_array($ticket['estado'], EncuestaService::ESTADOS_CALIFICABLES, true);

require $raiz . '/app/Presentation/Views/publico/consultar_estado.php';
