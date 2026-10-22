<?php
/**
 * Controlador de «Mantenimientos programados» (public/mantenimientos_programados.php).
 *
 * Qué hace: autorización por rol, CSRF, leer parámetros de GET/POST/FILES, llamar a
 * MantenimientoService y cargar la vista app/Presentation/Views/mantenimientos/pagina.php.
 * Sin SQL ni reglas de negocio. Se carga en ámbito global desde la página (como los paneles
 * de tickets), por eso las variables de la vista conservan sus nombres.
 */
$raiz = dirname(__DIR__, 4);

// Programar/ver el listado completo: técnico/admin. El beca entra pero solo ve y ejecuta lo que le corresponde.
requirePagina('mantenimientos_programados.php');

$rol = $_SESSION['role'];
$userId = (int)$_SESSION['user_id'];
$permisos = MantenimientoService::permisos($rol);
$puedeProgramar = $permisos['puedeProgramar'];          // crear/elevar: solo técnico (el admin supervisa)
$puedeVerCompleto = $permisos['puedeVerCompleto'];      // listado sin filtrar a «lo mío»: admin + técnico
$puedeMarcarEquipos = $permisos['puedeMarcarEquipos'];  // plano de equipos: técnico y beca (el admin solo supervisa)
$esTecnico = $rol === Rol::TECNICO;                        // asignar / revertir equipos: solo técnico

// Disponibilidad para el formulario de programar (JSON): choques en toda la duración y primer espacio libre.
if (($_GET['ajax'] ?? '') === 'disponibilidad') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$puedeProgramar) {
        http_response_code(403);
        echo json_encode(['error' => 'Sin permiso']);
        exit();
    }
    echo json_encode(MantenimientoService::disponibilidad(
        $pdo, (int)($_GET['id_sala'] ?? 0), (string)($_GET['fecha'] ?? ''), (int)($_GET['duracion'] ?? 60),
        !empty($_GET['id_responsable']) ? (int)$_GET['id_responsable'] : null, ($_GET['buscar'] ?? '') === '1'
    ), JSON_UNESCAPED_UNICODE);
    exit();
}

$error = '';
$exito = '';
$aplicar = static function (array $res) use (&$error, &$exito): void {
    if ($res['error'] !== '') { $error = $res['error']; }
    if ($res['exito'] !== '') { $exito = $res['exito']; }
    if (!empty($res['http'])) { http_response_code($res['http']); }
};
$entero = static fn(string $campo): int => (int)($_POST[$campo] ?? 0);

// Prefill desde «Elevar a mantenimiento de sala» (solo técnico).
$prefillIdSala = 0;
$prefillIdTicketOrigen = 0;
$prefillIdTicketProfesorOrigen = 0;
$ticketYaElevadoA = null;
if ($puedeProgramar && (!empty($_GET['elevar_ticket']) || !empty($_GET['elevar_ticket_profesor']))) {
    $pf = MantenimientoService::prefill($pdo, $userId, (int)($_GET['elevar_ticket'] ?? 0), (int)($_GET['elevar_ticket_profesor'] ?? 0));
    $error = $pf['error'];
    $prefillIdSala = $pf['idSala'];
    $prefillIdTicketOrigen = $pf['idTicketOrigen'];
    $prefillIdTicketProfesorOrigen = $pf['idTicketProfesorOrigen'];
    $ticketYaElevadoA = $pf['yaElevadoA'];
}

// Prefill desde una acción correctiva del análisis de semestre (historial_mantenimiento.php).
$prefillAccion = $puedeProgramar && !empty($_GET['desde_accion']) ? MantenimientoHistorialService::prefillDesdeAccion($pdo, (int)$_GET['desde_accion']) : null;
if ($prefillAccion && $prefillAccion['id_sala']) {
    $prefillIdSala = $prefillAccion['id_sala'];
}
// Prefill desde una celda vacía de Horarios (sala + día + hora elegidos en la grilla).
$prefillDesdeHorario = $puedeProgramar && !empty($_GET['nuevo']);
$prefillFecha = '';
if ($prefillDesdeHorario) {
    $prefillIdSala = $prefillIdSala ?: (int)($_GET['sala'] ?? 0);
    $tsH = strtotime((string)($_GET['fecha'] ?? ''));
    $prefillFecha = $tsH ? date('Y-m-d\TH:i', $tsH) : '';
}

if ($puedeProgramar && isset($_POST['programar'])) {
    $aplicar(MantenimientoService::programar($pdo, $userId, $_POST));
}

if (isset($_POST['cambiar_estado_mantenimiento'])) {
    // $_FILES['evidencia'] (multi-archivo) → lista de archivos individuales; se omiten los campos sin archivo.
    $archivos = [];
    foreach (($_FILES['evidencia']['name'] ?? []) as $i => $nombre) {
        if (($_FILES['evidencia']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
        $archivos[] = [
            'name' => $nombre, 'type' => $_FILES['evidencia']['type'][$i], 'tmp_name' => $_FILES['evidencia']['tmp_name'][$i],
            'error' => $_FILES['evidencia']['error'][$i], 'size' => $_FILES['evidencia']['size'][$i],
        ];
    }
    $aplicar(MantenimientoService::cambiarEstado($pdo, $userId, $rol, $entero('id_programado'), (string)($_POST['nuevo_estado'] ?? ''), trim($_POST['observaciones_cierre'] ?? ''), $archivos, (string)($_POST['resultado'] ?? '')));
}

// Reprogramar (solo técnico): fecha nueva o, vacía, el primer espacio libre. Queda en el historial del mantenimiento.
if ($puedeProgramar && isset($_POST['reprogramar'])) {
    $aplicar(MantenimientoService::reprogramar($pdo, $userId, $entero('id_programado'), trim((string)($_POST['nueva_fecha'] ?? '')), trim((string)($_POST['motivo_reprogramacion'] ?? '')), ($_POST['confirmar_choque'] ?? '') === '1'));
}

if ($esTecnico && isset($_POST['asignar_equipo_tecnico'])) {
    $aplicar(MantenimientoService::asignarEquipo($pdo, $userId, $entero('id_mant_equipo'), $entero('id_programado'), $entero('id_beca_asignar')));
}
if ($puedeMarcarEquipos && isset($_POST['tomar_equipo'])) {
    $aplicar(MantenimientoService::tomarEquipo($pdo, $userId, $entero('id_mant_equipo'), $entero('id_programado')));
}
if ($puedeMarcarEquipos && isset($_POST['liberar_equipo'])) {
    $aplicar(MantenimientoService::liberarEquipo($pdo, $userId, $rol, $entero('id_mant_equipo')));
}
if ($puedeMarcarEquipos && isset($_POST['marcar_equipo']) && ($_POST['nuevo_estado_equipo'] ?? '') === 'TRASLADO') {
    // «Trasladar a la oficina de mantenimiento (bodega)»: propuesta que aprueba el técnico.
    $aplicar(MantenimientoService::proponerTraslado(
        $pdo, $userId, $rol, $entero('id_mant_equipo'), $entero('id_programado'),
        trim($_POST['observaciones_equipo'] ?? ''), !empty($_POST['id_componente_traslado']) ? (int)$_POST['id_componente_traslado'] : null
    ));
    unset($_POST['marcar_equipo']);
}
if ($esTecnico && isset($_POST['aprobar_traslado_equipo'])) {
    $aplicar(MantenimientoService::aprobarTraslado($pdo, $userId, $entero('id_mant_equipo'), $entero('id_programado')));
}
if ($esTecnico && isset($_POST['rechazar_traslado_equipo'])) {
    $aplicar(MantenimientoService::rechazarTraslado($pdo, $userId, $entero('id_mant_equipo'), $entero('id_programado'), trim($_POST['motivo_rechazo'] ?? '')));
}
if ($puedeMarcarEquipos && isset($_POST['marcar_equipo'])) {
    $aplicar(MantenimientoService::marcarEquipo(
        $pdo, $userId, $rol, $entero('id_mant_equipo'), $entero('id_programado'),
        (string)($_POST['nuevo_estado_equipo'] ?? ''), trim($_POST['observaciones_equipo'] ?? ''),
        !empty($_POST['id_componente_saliente']) ? (int)$_POST['id_componente_saliente'] : null,
        !empty($_POST['id_componente_entrante']) ? (int)$_POST['id_componente_entrante'] : null
    ));
}
if ($esTecnico && isset($_POST['revertir_equipo'])) {
    $aplicar(MantenimientoService::revertirEquipo($pdo, $userId, $entero('id_mant_equipo')));
}
if ($rol === Rol::TECNICO && isset($_POST['aprobar_pieza_equipo'])) {
    $aplicar(MantenimientoService::aprobarPieza($pdo, $userId, $entero('id_mant_equipo'), $entero('id_programado')));
}

// Acciones hechas desde el plano del panel de beca (tickets.php): se vuelve al panel con el mismo plano
// y equipo abiertos (el #mant-N-eq-M de la URL se conserva en la redirección) y el mensaje del resultado.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['volver'] ?? '') === 'panel') {
    $_SESSION['flash_checklist_panel'] = ['error' => $error, 'exito' => $exito];
    header('Location: tickets.php?vista=checklist');
    exit();
}

$filtroSala = (int)($_GET['sala'] ?? 0);
$filtroEstado = $_GET['estado'] ?? '';
$filtroCampana = (int)($_GET['campana'] ?? 0);
extract(MantenimientoService::datosPantalla($pdo, $userId, $puedeVerCompleto, $filtroSala, (string)$filtroEstado, (int)($_GET['pagina_historial'] ?? 1), $puedeProgramar, $filtroCampana));

$shell = ShellData::cargar($pdo);
require $raiz . '/app/Presentation/Views/mantenimientos/pagina.php';
