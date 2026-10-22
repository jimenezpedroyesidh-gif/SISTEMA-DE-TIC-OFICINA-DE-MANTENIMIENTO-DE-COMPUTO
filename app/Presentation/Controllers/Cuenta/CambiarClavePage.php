<?php
/**
 * Dos usos: cambio OBLIGATORIO (clave temporal: layout mínimo; los guards de auth.php bloquean cualquier
 * otra página mientras tanto) y VOLUNTARIO (desde el perfil, layout completo). Siempre se exige la clave actual. Sin SQL:
 * UserAccount::cambiarClaveDesdeSesion. Ámbito global.
 */
$raiz = dirname(__DIR__, 4);
requireAuth();

$error = "";
$obligatorio = !empty($_SESSION['password_temporal']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = UserAccount::cambiarClaveDesdeSesion(
        $pdo, (int)$_SESSION['user_id'], (string)($_POST['clave_actual'] ?? ''), (string)($_POST['nueva_clave'] ?? ''), (string)($_POST['confirmar_clave'] ?? '')
    );
    if ($res['ok']) {
        $_SESSION['password_temporal'] = false;
        // Misma página de inicio por rol que el login.
        header("Location: " . (rutaInicioPorRol((string)$_SESSION['role']) ?? 'tickets.php'));
        exit();
    }
    $error = $res['error'];
}

$shell = ShellData::cargar($pdo);
require $raiz . '/app/Presentation/Views/cuenta/cambiar_clave.php';
