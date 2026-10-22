<?php
/** Genera (o reutiliza) el token CSRF de la sesión actual. */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Campo oculto listo para pegar dentro de un <form>. */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Verifica el token recibido por POST; corta la ejecución si no coincide. */
function csrfCheck(): void {
    csrfVerificar(false);
}

/**
 * Verificación única del token. Acepta el campo `csrf_token` del formulario o la cabecera `X-CSRF-Token`
 * (AJAX). Responde 403 y corta si no coincide; en modo $json responde {"error": ...} en vez de texto plano.
 */
function csrfVerificar(bool $json): void {
    $recibido = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($recibido) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $recibido)) {
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            die(json_encode(['error' => 'Token de seguridad inválido. Recarga la página e intenta de nuevo.']));
        }
        die("Token de seguridad inválido. Recarga la página e intenta de nuevo.");
    }
}

/**
 * La llaman los guards de auth.php: toda petición POST autenticada debe traer un token válido. Las páginas
 * públicas (login, QR, recuperar clave) llaman csrfCheck() ellas mismas. GET/HEAD no se tocan.
 */
function csrfExigirEnPost(bool $json = false): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        csrfVerificar($json);
    }
}

