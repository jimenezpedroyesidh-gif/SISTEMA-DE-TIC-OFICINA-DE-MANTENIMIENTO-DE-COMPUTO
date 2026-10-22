<?php
/**
 * Controlador de la portada pública (public/index.php). Con sesión iniciada manda a la página de inicio del rol
 * (o a cambiar la clave temporal); sin sesión muestra la portada. No usa la base de datos. Ámbito global.
 */

require_once dirname(__DIR__, 3) . '/Support/rutas.php'; // rutaInicioPorRol()

if (isset($_SESSION['user_id'])) {
    if (!empty($_SESSION['password_temporal'])) { header("Location: cambiar_clave.php"); exit(); }
    $destino = rutaInicioPorRol((string)($_SESSION['role'] ?? ''));
    if ($destino !== null) header("Location: $destino");
    exit();
}

require_once dirname(__DIR__, 3) . '/Support/ui.php'; // h()
require dirname(__DIR__, 2) . '/Views/publico/inicio.php';
