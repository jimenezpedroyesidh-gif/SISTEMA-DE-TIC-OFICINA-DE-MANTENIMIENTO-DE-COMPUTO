<?php
/**
 * Controlador de «Crear ticket (Profesor)» (public/create_ticket_profesor.php): rol profesor, y también el admin,
 * que es docente del Departamento: pide sus requerimientos/daños como cualquier profesor, pero solo para las salas
 * de su propia dependencia.
 *
 * Qué hace: autorización, CSRF, parámetros, llamada a TicketProfesorCreacionService::crearTicketProfesor (validación,
 * persistencia, avisos y auditoría), redirección tras crear y carga de la vista
 * app/Presentation/Views/tickets/crear_profesor.php. Sin SQL ni reglas de negocio.
 * Se carga en el ámbito global, por eso las variables de la vista conservan sus nombres.
 */
$raiz = dirname(__DIR__, 4);

// Profesor, o admin pidiendo algo como docente del Departamento.
requirePagina('create_ticket_profesor.php');

$profesor_id = (int)$_SESSION['user_id'];
$esAdminDocente = $_SESSION['role'] === Rol::ADMIN;
// Admin: solo las salas de su dependencia (por defecto, el Departamento de Sistemas e Informática).
$salasPermitidas = $esAdminDocente
    ? TicketRepository::salasDeDependencia($pdo, TicketRepository::dependenciaDeUsuario($pdo, $profesor_id))
    : null;
$error = "";
$success = "";

// Repuebla el formulario con lo que el profesor ya escribió si hay error, para no obligarlo a llenar todo de nuevo.
$old = $_POST;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $res = TicketProfesorCreacionService::crearTicketProfesor($pdo, $profesor_id, $_POST, $salasPermitidas);
    if ($res['ok']) {
        header('Location: ' . ($esAdminDocente ? 'tickets.php?creado=1#mis-solicitudes' : 'tickets.php?creado=1'));
        exit();
    }
    $error = $res['error'];
}

// Datos del formulario
// Su propio horario de clases: alimenta el select "Horario actual" del cambio de sala.
$miHorario = TicketRepository::horariosDeClaseDelProfesor($pdo, $profesor_id);
$diasSemanaCorto = [1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'];
// Salas reales (el ticket se liga a un id_sala de verdad, no a un texto libre).
$salasDisponibles = $salasPermitidas ?? TicketRepository::salasParaSelect($pdo);
$duracionesValidas = TicketProfesorCreacionService::DURACIONES_PRESTAMO;

$shell = ShellData::cargar($pdo);
require $raiz . '/app/Presentation/Views/tickets/crear_profesor.php';
