<?php
/**
 * Controlador del listado de usuarios (public/usuarios.php): solo admin.
 *
 * Qué hace: autorización, CSRF, parámetros, llamadas a UserService (habilitar/deshabilitar),
 * LoginThrottle (desbloquear), UserPermissions (permisos puntuales por usuario) y UserRepository (lectura),
 * y carga de la vista app/Presentation/Views/usuarios/pagina.php. Sin SQL ni reglas de negocio.
 * Se carga en el ámbito global, por eso las variables de la vista conservan sus nombres.
 */
$raiz = dirname(__DIR__, 4);
requirePagina('usuarios.php');

$idAdmin = (int)$_SESSION['user_id'];
$esPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// Activar/desactivar cuenta directamente desde el listado
if ($esPost && isset($_POST['toggle_id'])) {
    $res = UserService::alternarEstado($pdo, $idAdmin, (int)$_POST['toggle_id']);
    if ($res['error'] !== '') {
        $_SESSION['mensaje_permiso'] = $res['error'];
    }
    header("Location: usuarios.php");
    exit();
}

// Desbloquear una cuenta bloqueada por 3 intentos fallidos de login.
if ($esPost && isset($_POST['desbloquear_id'])) {
    LoginThrottle::desbloquear($pdo, (int)$_POST['desbloquear_id'], $idAdmin);
    header("Location: usuarios.php");
    exit();
}

// Reiniciar la verificación en dos pasos de alguien que perdió el celular (la configurará de nuevo al entrar).
if ($esPost && isset($_POST['reiniciar_mfa_id'])) {
    $res = MfaService::reiniciar($pdo, (int)$_POST['reiniciar_mfa_id'], $idAdmin);
    $_SESSION['mensaje_permiso'] = $res['mensaje'];
    header("Location: usuarios.php");
    exit();
}

// Permisos puntuales (secretaria: horarios/tickets de Sistemas; cualquier rol menos admin: ver horas de becas). Un clic activa o desactiva.
if ($esPost && isset($_POST['alternar_permiso'])) {
    $res = UserPermissions::alternar($pdo, $idAdmin, (int)($_POST['id_usuario'] ?? 0), (string)($_POST['permiso'] ?? ''));
    $_SESSION['mensaje_permiso'] = ($res['ok'] ? '' : '') . $res['mensaje'];
    // Vuelve con el menú de permisos de esa persona abierto (y desplazado hasta su fila).
    $idPerm = (int)($_POST['id_usuario'] ?? 0);
    header("Location: usuarios.php" . ($idPerm > 0 ? "?abierto=$idPerm#u-$idPerm" : ''));
    exit();
}

$permisosAbierto = (int)($_GET['abierto'] ?? 0); // menú de permisos que queda desplegado

// Mensaje de la acción anterior (se muestra una sola vez)
$mensajePermiso = null;
if (!empty($_SESSION['mensaje_permiso'])) {
    $mensajePermiso = $_SESSION['mensaje_permiso'];
    unset($_SESSION['mensaje_permiso']);
}

$bloqueados = UserRepository::idsBloqueados($pdo);
$usuarios = UserRepository::listar($pdo);
$permisosUsuarios = UserPermissions::de($pdo, array_column($usuarios, 'id'));
// Quién tiene activa la verificación en dos pasos (admin y técnico).
$conMfa = [];
foreach ($usuarios as $u) {
    if (MfaService::aplica((string)$u['role']) && MfaService::estado($pdo, (int)$u['id'])['activo']) $conMfa[(int)$u['id']] = true;
}

$shell = ShellData::cargar($pdo);
require $raiz . '/app/Presentation/Views/usuarios/pagina.php';
