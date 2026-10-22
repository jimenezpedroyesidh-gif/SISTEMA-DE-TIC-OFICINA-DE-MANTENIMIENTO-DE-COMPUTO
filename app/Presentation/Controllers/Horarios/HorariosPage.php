<?php
/**
 * Controlador de la pantalla de horarios (public/horarios.php).
 *
 * Qué hace: autenticación/autorización, CSRF, extraer los parámetros de la
 * petición, llamar a HorarioService (reglas + persistencia) y HorarioCalendario
 * (datos de la grilla) y cargar la vista app/Presentation/Views/horarios/pagina.php.
 * No contiene SQL ni reglas de negocio.
 *
 * Se carga desde public/horarios.php en el ámbito global (igual que los paneles
 * de tickets), por eso las variables de la vista conservan sus nombres.
 */
$raiz = dirname(__DIR__, 4);

// Ver: admin, técnico, beca, profesor, secretaria. Escribir: admin/técnico siempre y la
// secretaria SOLO si un admin le otorgó users.permiso_horarios_extra (ver HorarioService::permisos).
requirePagina('horarios.php');

$rol = $_SESSION['role'];
$userId = (int)$_SESSION['user_id'];

$permisos = HorarioService::permisos($pdo, $rol, $userId);
$tienePermisoSecretaria = $permisos['tienePermisoSecretaria'];
$puedeVerTodo = $permisos['puedeVerTodo'];       // ve todas las salas/periodos
$puedeModificar = $permisos['puedeModificar'];   // escribir
$alcance = $permisos['alcance'];                  // secretaria: salas habilitadas + becas de la secretaría (null = todo)

$diasSemana = HorarioService::DIAS;
$tiposHorario = HorarioService::TIPOS;

// HorarioPeriodo::actual(), HorarioPeriodo::siguiente(), HorarioPeriodo::anterior() viven en inventario.php (compartidas con mantenimientos_programados.php).
$periodoActual = HorarioPeriodo::actual();
$periodosDisponibles = [HorarioPeriodo::anterior($periodoActual), $periodoActual, HorarioPeriodo::siguiente($periodoActual)];

// ================== AJAX: verificar choque (lo consume public/assets/mantenimientos.js) ==================
// Solo quien puede modificar horarios (admin/técnico, o secretaria con permiso).
if (isset($_GET['ajax']) && $_GET['ajax'] === 'verificar_choque') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$puedeModificar) {
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado']);
        exit();
    }
    echo json_encode(['choques' => HorarioService::choques($pdo, (int)($_GET['id_sala'] ?? 0), (string)($_GET['fecha'] ?? ''))]);
    exit();
}

$error = '';
$exito = '';
/** Aplica el resultado de un caso de uso al mensaje que se muestra (null = sin mensaje). */
$mostrarResultado = function (?array $res) use (&$error, &$exito): void {
    if ($res === null) return;
    if ($res['ok']) { $exito = $res['mensaje']; } else { $error = $res['mensaje']; }
};

if ($puedeModificar && isset($_POST['crear_horario'])) {
    $mostrarResultado(HorarioService::crear($pdo, $userId, $_POST, $periodoActual, $alcance));
}
if ($puedeModificar && isset($_POST['editar_horario'])) {
    $mostrarResultado(HorarioService::editar($pdo, $userId, (int)($_POST['id_horario'] ?? 0), $_POST, $periodoActual, $alcance));
}
if ($puedeModificar && isset($_POST['borrar_horario'])) {
    $mostrarResultado(HorarioService::borrar($pdo, $userId, (int)($_POST['id_horario'] ?? 0), $alcance));
}
if ($puedeModificar && isset($_POST['guardar_excepcion'])) {
    $mostrarResultado(HorarioService::guardarExcepcion($pdo, $userId, $_POST, $alcance));
}
if ($puedeModificar && isset($_POST['borrar_excepcion'])) {
    $mostrarResultado(HorarioService::borrarExcepcion($pdo, $userId, (int)($_POST['id_excepcion'] ?? 0), $alcance));
}
if ($puedeModificar && isset($_POST['vaciar_periodo'])) {
    $mostrarResultado(HorarioService::vaciarPeriodo($pdo, $userId, (string)($_POST['periodo_borrar'] ?? ''), (string)($_POST['confirmacion'] ?? ''), $alcance));
}
if ($rol === Rol::ADMIN && isset($_POST['cambiar_permiso_secretaria'])) {
    $res = HorarioService::cambiarPermisoSecretaria($pdo, $rol, $userId, (int)($_POST['id_usuario'] ?? 0), (int)($_POST['otorgar'] ?? 0));
    $mostrarResultado($res);
}

if ($rol === Rol::ADMIN && isset($_POST['guardar_salas_secretaria'])) {
    $mostrarResultado(HorarioService::guardarSalasSecretaria($pdo, $rol, $userId, (int)($_POST['id_usuario'] ?? 0), (array)($_POST['salas'] ?? [])));
}

// ================== Datos de la pantalla ==================
$vista = HorarioCalendario::armar($pdo, [
    'rol' => $rol, 'userId' => $userId, 'puedeVerTodo' => $puedeVerTodo, 'puedeModificar' => $puedeModificar,
    'periodoActual' => $periodoActual,
    'areaBeca' => $_SESSION['area_beca'] ?? null, 'alcance' => $alcance, 'nombreUsuario' => (string)($_SESSION['nombre_completo'] ?? ''),
], $_GET);
extract($vista);

$shell = ShellData::cargar($pdo);
require $raiz . '/app/Presentation/Views/horarios/pagina.php';
