<?php
/**
 * Controlador de las fichas de equipo (public/ficha_equipo.php): detalle de un puesto (?id=) y
 * listado/búsqueda agrupado por dependencia y sala.
 *
 * Qué hace: autorización por modo, CSRF, parámetros, llamadas a ComponenteService (altas) y
 * FichaEquipoService (lectura), y carga de la vista (equipo.php o equipos_lista.php).
 * No contiene SQL ni reglas de negocio. Se carga en el ámbito global.
 */
$raiz = dirname(__DIR__, 4);
require_once $raiz . '/app/Support/lib_inventario.php';

$id = (int)($_GET['id'] ?? 0);

// Solo admin/tecnico pueden agregar componentes Equipos TI a un puesto
// (beca_trabajo puede ver la ficha, pero no registrar inventario nuevo).
$rol = $_SESSION['role'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$puedeEditarInventario = in_array($rol, [Rol::ADMIN, Rol::TECNICO], true);

// Infraestructura (aire, iluminación, sillas...) es distinto: solo el técnico la «mueve».
$puedeAgregarInfraestructura = ($rol === Rol::TECNICO);

// El beca_trabajo puede ver la ficha de UN equipo puntual (p. ej. al leer su QR en bodega),
// pero no navegar/buscar todo el inventario: el modo lista es solo admin/técnico.
requireRole($id ? [...PAGINAS_POR_ROL['ficha_equipo.php'], Rol::BECA] : PAGINAS_POR_ROL['ficha_equipo.php']); // la ficha de un equipo puntual también la abre la beca

// Catálogo de marcas para los formularios (el modelo se carga por AJAX en cascada: listar_modelos_ajax.php).
$fabricantes = ($puedeEditarInventario || $puedeAgregarInfraestructura) ? listarFabricantes($pdo) : [];

if ($id) {
    // ============= Vista de detalle de un equipo =============
    $equipo = FichaEquipoRepository::equipo($pdo, $id);
    if (!$equipo) { header("Location: ficha_equipo.php"); exit(); }

    // URL absoluta de esta misma ficha, para el QR de bodega/inventario.
    $protocoloQr = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $hostQr = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $urlQrEquipo = "{$protocoloQr}://{$hostQr}" . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/ficha_equipo.php?id=' . (int)$id;

    $historialAuditoriaEquipo = AuditoriaService::historialObjeto($pdo, 'equipos', (int)$id);

    // Agregar componente de Equipos TI a ESTE puesto
    $errorPuesto = '';
    $exitoPuesto = '';
    if ($puedeEditarInventario && isset($_POST['agregar_componente_puesto'])) {
        $res = ComponenteService::crearEnPuesto($pdo, $userId, $equipo, $_POST);
        $errorPuesto = $res['error'];
        $exitoPuesto = $res['exito'];
    }

    extract(FichaEquipoService::detalle($pdo, $equipo));
    $shell = ShellData::cargar($pdo);
    require $raiz . '/app/Presentation/Views/inventario/equipo.php';
    exit();
}

// ============= Listado / búsqueda de equipos =============
$q = validarTexto($_GET['q'] ?? '', 100) ?? '';
// «Fichas por equipo» (?vista=equipos): todos los equipos en una tabla, uno por fila, sin agrupar por sala.
$vistaEquipos = ($_GET['vista'] ?? '') === 'equipos';
$verTodas = $vistaEquipos || (isset($_GET['todas']) && $_GET['todas'] === '1');

// Agregar infraestructura directo a una SALA (vive en el listado agrupado por sala).
$errorSala = '';
$exitoSala = '';
$exitoSalaId = 0;
if ($puedeAgregarInfraestructura && isset($_POST['agregar_componente_sala'])) {
    $res = ComponenteService::crearEnSala($pdo, $userId, $_POST);
    $errorSala = $res['error'];
    $exitoSala = $res['exito'];
    $exitoSalaId = (int)($_POST['id_sala'] ?? 0);   // abre el acordeón de esa sala (también tras un error)
}

extract(FichaEquipoService::listado($pdo, $userId, $q, $verTodas));

// ¿La sala del aviso del alta está en el listado? Si no, la vista muestra el aviso arriba.
$salaDelAvisoVisible = false;
foreach ($equiposAgrupados as $salasDelGrupo) {
    if (isset($salasDelGrupo[$exitoSalaId])) { $salaDelAvisoVisible = true; break; }
}

// Base para armar la URL de cada QR de equipo (impresión en bloque de una sala).
$protocoloQr = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$hostQr = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrlQr = "{$protocoloQr}://{$hostQr}" . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$SIN_DEPENDENCIA = FichaEquipoService::SIN_DEPENDENCIA;

$shell = ShellData::cargar($pdo);
require $raiz . '/app/Presentation/Views/inventario/equipos_lista.php';
