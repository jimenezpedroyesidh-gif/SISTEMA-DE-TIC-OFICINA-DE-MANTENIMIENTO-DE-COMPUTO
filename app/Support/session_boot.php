<?php
/**
 * Inicio de sesión endurecido y cabeceras de seguridad, en un solo lugar (primera línea de cada página).
 *  - Cookie HttpOnly, SameSite=Lax y Secure con HTTPS.
 *  - use_strict_mode + use_only_cookies.
 *  - Expiración por inactividad (SESION_INACTIVIDAD_SEGUNDOS): la sesión vencida se vacía y el guard
 *    redirige al login.
 */

// Esqueleto común de página (head, header, sidebar, cierre): vive fuera de public/ para que no se abra por URL.
if (!defined('LAYOUT_DIR')) {
    define('LAYOUT_DIR', dirname(__DIR__) . '/Presentation/Views/layout');
}

// Minutos sin actividad tras los cuales la sesión se cierra.
// Ajustable en un solo lugar (aplica a todos los roles).
if (!defined('SESION_INACTIVIDAD_SEGUNDOS')) {
    define('SESION_INACTIVIDAD_SEGUNDOS', 60 * 60); // 60 minutos
}

if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    session_set_cookie_params([
        'lifetime' => 0,          // cookie de sesión (se borra al cerrar el navegador)
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// ---- Expiración por inactividad (solo si hay un usuario logueado) ----
if (isset($_SESSION['user_id'])) {
    $ahora = time();
    $ultima = $_SESSION['_ultima_actividad'] ?? $ahora;

    if (($ahora - (int)$ultima) > SESION_INACTIVIDAD_SEGUNDOS) {
        // Sesión vencida: se vacía y se cambia el ID. La página que
        // incluyó este archivo no verá 'user_id' y redirigirá al login.
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['_sesion_expirada'] = true; // login.php lo muestra una vez
    } else {
        $_SESSION['_ultima_actividad'] = $ahora;
    }
}

/**
 * Llamar justo después de un login correcto. Cambia el ID de sesión para impedir la fijación de sesión
 * (session fixation).
 */
function sesionRegenerarTrasLogin(): void
{
    session_regenerate_id(true);
    $_SESSION['_ultima_actividad'] = time();
}

/**
 * Cierre de sesión completo: vacía datos, borra la cookie y destruye
 * la sesión en el servidor.
 */
function sesionDestruir(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/errores.php';
require_once __DIR__ . '/validacion.php';
erroresConfigurar();
entradaNormalizar();

// Cabeceras de seguridad básicas para todas las páginas (sin efecto funcional):
// evita clickjacking (iframe de otro sitio) y el "sniffing" de tipo MIME.
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');

    // CSP: sin scripts en línea; APP_CSP=report-only solo avisa, APP_CSP=off la desactiva.
    $modoCsp = getenv('APP_CSP') ?: 'enforce';
    if ($modoCsp !== 'off') {
        $csp = "default-src 'self'; "
             . "script-src 'self'; "
             . "style-src 'self' 'unsafe-inline'; "
             . "img-src 'self' data: blob:; "
             . "font-src 'self' data:; "
             . "media-src 'self' data: blob:; "
             . "connect-src 'self'; "
             . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";
        header(($modoCsp === 'report-only' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy') . ': ' . $csp);
    }
}
