<?php
/**
 * Controlador de «Inventario de Componentes» (public/gestionar_inventario.php).
 *
 * Qué hace: autorización (admin/técnico), CSRF, extraer parámetros, llamar a ComponenteService
 * (reglas + persistencia vía ComponenteRepository) y cargar la vista
 * app/Presentation/Views/inventario/gestionar.php. No contiene SQL ni reglas de negocio.
 * Se carga desde la página en el ámbito global, por eso las variables de la vista conservan sus nombres.
 */
$raiz = dirname(__DIR__, 4);

// Control de acceso: inventario completo solo admin/tecnico (el beca_trabajo
// ya NO ve el inventario general — solo los equipos que están físicamente
// en la oficina de mantenimiento, desde su propio panel).
requirePagina('gestionar_inventario.php');

$rol = $_SESSION['role'];
$userId = (int)$_SESSION['user_id'];
$puedeEditar = in_array($rol, [Rol::ADMIN, Rol::TECNICO], true); // registro/edición: rol técnico (admin también puede)

$error = '';
$exito = '';
$aplicar = function (array $res) use (&$error, &$exito): void {
    $error = $res['error'];
    $exito = $res['exito'];
};

if ($puedeEditar && isset($_POST['crear_componente'])) {
    $aplicar(ComponenteService::crear($pdo, $userId, $_POST));
}
if ($puedeEditar && isset($_POST['editar_componente'])) {
    $aplicar(ComponenteService::editar($pdo, $userId, (int)($_POST['id_componente'] ?? 0), $_POST));
}

// ================== Búsqueda ==================
$q = validarTexto($_GET['q'] ?? '', 100) ?? '';
$resultados = $q !== '' ? buscarComponentes($pdo, $q, false, 30) : [];

// Equipos (puestos de trabajo) para el desplegable de asignación inicial
$equipos = ComponenteRepository::equiposParaSelect($pdo);

$shell = ShellData::cargar($pdo);
require $raiz . '/app/Presentation/Views/inventario/gestionar.php';
