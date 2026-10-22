<?php
/**
 * Controlador del alta/edición de usuarios (public/register.php): solo admin.
 *
 * Qué hace: autorización, CSRF, parámetros, llamada a UserService::guardar (validación, reglas,
 * persistencia y auditoría) y carga de la vista app/Presentation/Views/usuarios/formulario.php.
 * Sin SQL ni reglas de negocio. Se carga en el ámbito global.
 */
$raiz = dirname(__DIR__, 4);
// Solo el admin puede crear o editar perfiles. Nadie más llega aquí.
requirePagina('register.php');

$rolesDisponibles = UserService::ROLES;
$cargosDisponibles = UserService::CARGOS;

// Dependencia/departamento/plan de estudios: se elige de la lista única de `unidades_organizacionales`
// (la que administra el admin en Gestionar salas) para que no quede escrita de formas distintas.
$unidadesDisponibles = UserRepository::unidades($pdo);

$editId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$usuarioEditar = null;
if ($editId) {
    $usuarioEditar = UserRepository::porId($pdo, $editId);
    if (!$usuarioEditar) {
        $editId = null;
    }
}

$error = "";
$claveTemporalGenerada = null;
$usuarioCreadoNombre = null;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $idPost = isset($_POST['id']) ? (int)$_POST['id'] : null;
    $res = UserService::guardar($pdo, (int)$_SESSION['user_id'], $_POST, $idPost);
    $error = $res['error'];
    $claveTemporalGenerada = $res['clave'];
    $usuarioCreadoNombre = $res['nombre'];
    // Tras una edición correcta se recarga el usuario; en alta o con error el formulario queda como estaba.
    if ($idPost && $res['nombre'] !== null) {
        $editId = $idPost;
        $usuarioEditar = UserRepository::porId($pdo, $idPost);
    }
}

$shell = ShellData::cargar($pdo); // header + menú lateral, igual que Usuarios (misma ventana)
require $raiz . '/app/Presentation/Views/usuarios/formulario.php';
