<?php
/** Controlador de public/buscar_global.php: sesión + q → BusquedaGlobalService → JSON. Ámbito global. */

header('Content-Type: application/json; charset=utf-8');
requireAuthJson(); // sin sesión, nada de datos

$q = validarTexto($_GET['q'] ?? '', 100) ?? '';
echo json_encode(BusquedaGlobalService::buscar($pdo, $_SESSION['role'] ?? '', (int)$_SESSION['user_id'], $q));
