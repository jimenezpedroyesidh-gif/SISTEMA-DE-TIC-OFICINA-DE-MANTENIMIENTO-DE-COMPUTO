<?php
/**
 * Controlador de «Verificación en dos pasos» (public/verificacion.php). Tres momentos:
 *  - verificar: tras la contraseña/Google de un admin o técnico con el segundo factor activo, pide el código.
 *  - inscribir: el admin la hizo obligatoria y la cuenta aún no la tiene → se configura antes de entrar.
 *  - configurar: desde «Mi perfil», activarla o desactivarla voluntariamente.
 * Al activarla se muestran UNA vez 8 códigos de recuperación. Sin SQL: MfaService / Totp. Ámbito global.
 */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
requireAuth(); // exigirSegundoFactor() deja pasar esta página
$idUsuario = (int)$_SESSION['user_id'];
$rol = (string)($_SESSION['role'] ?? '');
$pendiente = !empty($_SESSION['mfa_pendiente']);
$forzada = !empty($_SESSION['mfa_inscribir']);
$modo = $pendiente ? 'verificar' : ($forzada ? 'inscribir' : 'configurar');
if ($modo === 'configurar' && !MfaService::aplica($rol)) {
    header('Location: perfil.php');
    exit();
}
const MFA_MAX_INTENTOS = 5;
$error = '';
$exito = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['verificar_codigo']) && $modo === 'verificar') {
        $tipo = MfaService::verificar($pdo, $idUsuario, (string)($_POST['codigo'] ?? ''));
        if ($tipo !== null) {
            unset($_SESSION['mfa_pendiente'], $_SESSION['mfa_intentos']);
            session_regenerate_id(true);
            BitacoraAccesos::registrar($pdo, 'MFA_OK', $idUsuario, null, $tipo === 'app' ? 'código de la app' : 'código de recuperación');
            $destino = completarInicioDeSesion($pdo, ($_SESSION['auth_proveedor'] ?? '') === 'google' ? 'Google + segundo factor' : 'contraseña + segundo factor');
            if ($tipo === 'recuperacion') $_SESSION['flash_perfil_mfa'] = 'Usaste un código de recuperación: te quedan ' . MfaService::estado($pdo, $idUsuario)['restantes'] . '.';
            header("Location: $destino");
            exit();
        }
        $_SESSION['mfa_intentos'] = (int)($_SESSION['mfa_intentos'] ?? 0) + 1;
        BitacoraAccesos::registrar($pdo, 'MFA_FALLIDO', $idUsuario, null, 'intento ' . $_SESSION['mfa_intentos'] . ' de ' . MFA_MAX_INTENTOS);
        if ($_SESSION['mfa_intentos'] >= MFA_MAX_INTENTOS) {
            sesionDestruir();
            session_start();
            $_SESSION['_google_error'] = 'Demasiados códigos incorrectos. Vuelve a iniciar sesión.';
            header('Location: login.php');
            exit();
        }
        $error = 'El código no es válido. Te quedan ' . (MFA_MAX_INTENTOS - $_SESSION['mfa_intentos']) . ' intento(s).';
    } elseif (isset($_POST['confirmar_inscripcion']) && in_array($modo, ['inscribir', 'configurar'], true)) {
        $codigos = MfaService::confirmarInscripcion($pdo, $idUsuario, (string)($_SESSION['mfa_secreto_temp'] ?? ''), (string)($_POST['codigo'] ?? ''));
        if ($codigos === null) {
            $error = 'El código no coincide. Revisa que la hora del celular sea automática y escribe el código que se ve ahora.';
        } else {
            unset($_SESSION['mfa_secreto_temp']);
            $_SESSION['mfa_codigos_mostrar'] = $codigos;
            header('Location: verificacion.php?codigos=1');
            exit();
        }
    } elseif (isset($_POST['continuar']) && !empty($_SESSION['mfa_codigos_vistos'])) {
        unset($_SESSION['mfa_codigos_vistos']);
        if ($forzada) {
            unset($_SESSION['mfa_inscribir']);
            session_regenerate_id(true);
            header('Location: ' . completarInicioDeSesion($pdo, 'contraseña + segundo factor (configurado al entrar)'));
            exit();
        }
        $_SESSION['flash_perfil_mfa'] = 'Verificación en dos pasos activada.';
        header('Location: perfil.php');
        exit();
    } elseif (isset($_POST['desactivar']) && $modo === 'configurar') {
        $r = MfaService::desactivar($pdo, $idUsuario, (string)($_POST['codigo'] ?? ''));
        if ($r['ok']) {
            $_SESSION['flash_perfil_mfa'] = $r['mensaje'];
            header('Location: perfil.php');
            exit();
        }
        $error = $r['mensaje'];
    }
}

// Códigos de recuperación recién generados: se muestran una sola vez.
$codigosRecuperacion = null;
if (isset($_GET['codigos']) && !empty($_SESSION['mfa_codigos_mostrar'])) {
    $codigosRecuperacion = $_SESSION['mfa_codigos_mostrar'];
    unset($_SESSION['mfa_codigos_mostrar']);
    $_SESSION['mfa_codigos_vistos'] = true;
}

$estadoMfa = MfaService::estado($pdo, $idUsuario);
$secreto = '';
$uriQr = '';
if ($codigosRecuperacion === null && ($modo === 'inscribir' || ($modo === 'configurar' && !$estadoMfa['activo']))) {
    if (empty($_SESSION['mfa_secreto_temp'])) $_SESSION['mfa_secreto_temp'] = Totp::generarSecreto();
    $secreto = (string)$_SESSION['mfa_secreto_temp'];
    $uriQr = Totp::uri($secreto, (string)($_SESSION['correo'] ?? ('usuario' . $idUsuario)));
}
$obligatoria = ConfigSistema::activo($pdo, 'seg_mfa_obligatorio');
$conShell = $modo === 'configurar';
if ($conShell) $shell = ShellData::cargar($pdo);

require dirname(__DIR__, 2) . '/Views/cuenta/verificacion.php';
