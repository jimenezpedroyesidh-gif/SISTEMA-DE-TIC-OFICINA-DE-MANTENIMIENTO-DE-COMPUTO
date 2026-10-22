<?php
/** Sin SQL: PerfilService y obtenerUsuarioActualShell (ui_shell.php). Ámbito global. */
$raiz = dirname(__DIR__, 4);
requireAuth();

$errorFoto = null;
$exitoFoto = null;
$idUsuario = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subir_foto'])) {
    $res = PerfilService::subirFoto($pdo, $idUsuario, $_FILES['foto_perfil'] ?? null);
    $errorFoto = $res['error'];
    $exitoFoto = $res['exito'];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quitar_foto'])) {
    $res = PerfilService::quitarFoto($pdo, $idUsuario);
    $errorFoto = $res['error'];
    $exitoFoto = $res['exito'];
}

$usuario = obtenerUsuarioActualShell($pdo, $idUsuario);
if (!$usuario) {
    header("Location: login.php"); exit();
}

// Qué mostrar según el rol, con columnas que ya existen; no se muestran campos vacíos.
$filasPerfil = [
    'Nombre completo' => $usuario['nombre_completo'],
    'Correo'          => $usuario['correo'],
    'Rol'             => etiquetaRol($usuario['role']),
    'Cargo'           => $usuario['cargo'],
    'Dependencia'     => $usuario['unidad_nombre'] ?? $usuario['dependencia'],
    'Código'          => $usuario['codigo'],
];

// Verificación en dos pasos (solo admin y técnico): estado y aviso tras activarla/desactivarla.
$mfaAplica = MfaService::aplica((string)$usuario['role']) && MfaService::disponible($pdo);
$mfaEstado = $mfaAplica ? MfaService::estado($pdo, $idUsuario) : null;
$avisoMfa = (string)($_SESSION['flash_perfil_mfa'] ?? '');
unset($_SESSION['flash_perfil_mfa']);

$tituloHeader = 'Mi perfil';

$shell = ShellData::cargar($pdo);
require $raiz . '/app/Presentation/Views/cuenta/perfil.php';
