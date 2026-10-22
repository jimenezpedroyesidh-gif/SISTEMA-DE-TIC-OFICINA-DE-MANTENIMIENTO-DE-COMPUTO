<?php
/**
 * Autenticación / autorización centralizadas.
 *
 * Reemplazan el bloque repetido en cada página:
 *
 *     if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'tecnico') {
 *         header("Location: login.php"); exit();
 *     }
 *
 * por:   requireRole('tecnico');
 *
 * Comportamiento conservado: si no se cumple, redirige a login.php (igual que hoy).
 *
 * Requiere que la sesión ya esté iniciada (app/Support/session_boot.php).
 */

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/rutas.php'; // rutaInicioPorRol()

/** Hay un usuario logueado; si no, manda a login.php. */
function requireAuth(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }
    exigirCuentaActiva();
    exigirSegundoFactor();       // admin/técnico: código de la app antes de usar cualquier página
    exigirClaveDefinitiva();
    exigirPaginaDeRolRestringido(); // vigilante y pantalla de TV: solo sus páginas
    csrfExigirEnPost();
}

/**
 * Cuenta desactivada (o eliminada) = sesión cortada en la siguiente petición, sin esperar a los 60 min de
 * inactividad. Una consulta por petición; de paso refresca rol/nombre/cargo de la sesión desde la BD.
 *
 * @param bool $json true = endpoint AJAX: responde 403 JSON en vez de redirigir
 */
function exigirCuentaActiva(bool $json = false): void
{
    static $verificada = false;
    if ($verificada) {
        return;
    }
    $pdoCuenta = Database::conexion();
    $stmt = $pdoCuenta->prepare('SELECT estado_activo, role, nombre_completo, cargo, area_beca FROM users WHERE id = ?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u !== false && (int)$u['estado_activo'] === 1) {
        // Un cambio de rol/nombre/cargo hecho por el admin se aplica en la siguiente petición.
        $_SESSION['role'] = $u['role'];
        $_SESSION['nombre_completo'] = $u['nombre_completo'];
        $_SESSION['cargo'] = $u['cargo'];
        // Área de la beca (la asigna el admin): también se aplica en la siguiente petición.
        $_SESSION['area_beca'] = $u['role'] === Rol::BECA ? ((string)($u['area_beca'] ?? '') ?: AreaBeca::MANTENIMIENTO) : null;
        $verificada = true;
        exigirPaginaDeArea($json);
        return;
    }
    sesionDestruir();
    session_start(); // sesión nueva y vacía, solo para dejar el aviso en login.php
    $_SESSION['_cuenta_deshabilitada'] = true;
    if ($json) {
        http_response_code(403);
        echo json_encode(['error' => 'Cuenta deshabilitada']);
        exit();
    }
    header('Location: login.php');
    exit();
}

/** ¿La sesión es de una beca asignada a la secretaría? (no atiende tickets). */
/**
 * Roles de solo consulta con una lista cerrada de páginas: cualquier otra los devuelve a su inicio
 * (o 403 en AJAX). Así una página nueva que solo pida requireAuth() no les queda abierta por descuido.
 */
const PAGINAS_ROL_RESTRINGIDO = [
    Rol::VIGILANTE     => ['horarios.php', 'perfil.php', 'cambiar_clave.php', 'logout.php', 'verificacion.php',
                        'mis_notificaciones.php', 'notificacion_marcar_leida.php'],
    Rol::VISUALIZACION => ['pantalla.php', 'cambiar_clave.php', 'logout.php', 'verificacion.php'],
];

function exigirPaginaDeRolRestringido(bool $json = false): void
{
    $rol = (string)($_SESSION['role'] ?? '');
    if (!isset(PAGINAS_ROL_RESTRINGIDO[$rol]) || PHP_SAPI === 'cli') {
        return;
    }
    if (in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), PAGINAS_ROL_RESTRINGIDO[$rol], true)) {
        return;
    }
    if ($json) {
        http_response_code(403);
        echo json_encode(['error' => 'Tu rol no tiene acceso a esta información']);
        exit();
    }
    header('Location: ' . (rutaInicioPorRol($rol) ?? 'login.php'));
    exit();
}

function esBecaDeSecretaria(): bool
{
    return ($_SESSION['role'] ?? '') === Rol::BECA && ($_SESSION['area_beca'] ?? '') === AreaBeca::SECRETARIA;
}

/**
 * Una beca de secretaría solo usa su tablero de actividades, su turno y sus horas: cualquier otra
 * página (tickets, inventario, mantenimientos…) la lleva a actividades.php; un endpoint AJAX responde 403.
 */
function exigirPaginaDeArea(bool $json = false): void
{
    if (!esBecaDeSecretaria() || in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), AreaBeca::PAGINAS_SECRETARIA, true)) {
        return;
    }
    if ($json) {
        http_response_code(403);
        echo json_encode(['error' => 'Tu área (secretaría) no tiene acceso a esta información']);
        exit();
    }
    header('Location: actividades.php');
    exit();
}

/**
 * Abre la sesión de un usuario ya verificado (por clave o por Google): ID
 * nuevo, datos en $_SESSION y marca de conexión. Devuelve la página destino
 * (cambiar_clave.php si la clave es temporal, o la de inicio de su rol) o
 * null si el rol no existe — en ese caso quien llama debe cerrar la sesión.
 *
 * @param string $proveedor 'clave' | 'google' (con Google no se obliga a cambiar la clave)
 */
function iniciarSesionDeUsuario(PDO $pdo, array $user, string $proveedor = 'clave'): ?string
{
    sesionRegenerarTrasLogin();
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['nombre_completo'] = $user['nombre_completo'];
    $_SESSION['cargo'] = $user['cargo'];
    $_SESSION['correo'] = $user['correo'];
    $_SESSION['password_temporal'] = $proveedor === 'clave' ? (bool)$user['password_temporal'] : false;
    $_SESSION['auth_proveedor'] = $proveedor;
    if (rutaInicioPorRol((string)$user['role']) === null) {
        return null;
    }

    // Verificación en dos pasos (admin/técnico): la sesión queda «a medias» hasta escribir el código.
    $paso = MfaService::pasoPendiente($pdo, $user);
    if ($paso !== '') {
        $_SESSION[$paso === 'verificar' ? 'mfa_pendiente' : 'mfa_inscribir'] = true;
        return 'verificacion.php';
    }
    return completarInicioDeSesion($pdo, $proveedor === 'google' ? 'Google' : 'contraseña');
}

/**
 * Último paso del ingreso (tras la contraseña/Google y, si aplica, el segundo factor): marca la conexión,
 * deja el ingreso en la bitácora y devuelve la página destino.
 */
function completarInicioDeSesion(PDO $pdo, string $detalle): string
{
    $id = (int)$_SESSION['user_id'];
    $pdo->prepare("UPDATE users SET online=1, ultimo_login=NOW() WHERE id=?")->execute([$id]);
    BitacoraAccesos::registrar($pdo, 'LOGIN_OK', $id, (string)($_SESSION['correo'] ?? ''), 'con ' . $detalle);
    if (!empty($_SESSION['password_temporal'])) {
        return 'cambiar_clave.php';
    }
    return rutaInicioPorRol((string)($_SESSION['role'] ?? '')) ?? 'login.php';
}

/**
 * Con el segundo factor pendiente (o la inscripción obligatoria sin hacer), la única página utilizable
 * es verificacion.php (y logout.php). Se aplica dentro de TODOS los guards.
 *
 * @param bool $json true = endpoint AJAX: responde 403 JSON en vez de redirigir
 */
function exigirSegundoFactor(bool $json = false): void
{
    if (empty($_SESSION['mfa_pendiente']) && empty($_SESSION['mfa_inscribir'])) {
        return;
    }
    if (in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['verificacion.php', 'logout.php'], true)) {
        return;
    }
    if ($json) {
        http_response_code(403);
        echo json_encode(['error' => 'Falta la verificación en dos pasos']);
        exit();
    }
    header('Location: verificacion.php');
    exit();
}

/**
 * Regla de clave temporal. Con una clave temporal pendiente, la única página utilizable es cambiar_clave.php
 * (y logout.php). Se aplica dentro de TODOS los guards.
 *
 * @param bool $json true = endpoint AJAX: responde 403 JSON en vez de redirigir
 */
function exigirClaveDefinitiva(bool $json = false): void
{
    static $permitidas = ['cambiar_clave.php', 'logout.php'];
    if (empty($_SESSION['password_temporal'])) {
        return;
    }
    // Primero el segundo factor (exigirSegundoFactor); la clave temporal se cambia después.
    if (!empty($_SESSION['mfa_pendiente']) || !empty($_SESSION['mfa_inscribir'])) {
        return;
    }
    if (in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $permitidas, true)) {
        return;
    }
    if ($json) {
        http_response_code(403);
        echo json_encode(['error' => 'Debe cambiar su clave temporal']);
        exit();
    }
    header('Location: cambiar_clave.php');
    exit();
}

/**
 * Hay sesión y el rol es uno de los permitidos (string o lista).
 * Un rol no permitido se trata como hoy: redirección a login.php.
 */
function requireRole($roles): void
{
    requireAuth();
    $roles = (array)$roles;
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        header('Location: login.php');
        exit();
    }
}

/**
 * Hay sesión y el rol tiene el permiso (ver TicketPermissions). Para
 * acciones POST/AJAX responde 403 en vez de redirigir.
 */
function requirePermission(string $permiso): void
{
    requireAuth();
    if (!TicketPermissions::tienePermiso((string)($_SESSION['role'] ?? ''), $permiso)) {
        http_response_code(403);
        exit('No tienes permiso para esta acción.');
    }
}

/** Rol de la sesión actual ('' si no hay). */
function rolActual(): string
{
    return (string)($_SESSION['role'] ?? '');
}

/**
 * Variante para endpoints JSON/AJAX: sin sesión (o con rol no permitido)
 * responde 403 con un cuerpo JSON en vez de redirigir (un fetch() no debe
 * recibir el HTML de login.php). Conserva el cuerpo que cada endpoint daba.
 *
 * @param string[]|null $roles  null = basta con tener sesión
 * @param mixed         $cuerpo contenido JSON de la respuesta 403
 */
function requireAuthJson(?array $roles = null, $cuerpo = ['error' => 'No autorizado']): void
{
    if (empty($_SESSION['user_id']) || ($roles !== null && !in_array($_SESSION['role'] ?? '', $roles, true))) {
        http_response_code(403);
        echo json_encode($cuerpo);
        exit();
    }
    exigirCuentaActiva(true);
    exigirSegundoFactor(true);
    exigirClaveDefinitiva(true);
    exigirPaginaDeRolRestringido(true);
    csrfExigirEnPost(true);
}

/**
 * Variante para descargas/PDF: sin sesión (o con rol no permitido) corta con
 * un mensaje de texto, como hacían ticket_pdf.php y compañía.
 *
 * @param string[]|null $roles null = basta con tener sesión
 */
function requireAuthOrDie(?array $roles = null, string $mensaje = 'Acceso denegado'): void
{
    if (empty($_SESSION['user_id']) || ($roles !== null && !in_array($_SESSION['role'] ?? '', $roles, true))) {
        die($mensaje);
    }
    exigirCuentaActiva();
    exigirSegundoFactor();
    exigirClaveDefinitiva();
    csrfExigirEnPost();
}
