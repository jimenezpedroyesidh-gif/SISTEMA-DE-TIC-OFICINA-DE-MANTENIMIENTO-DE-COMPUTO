<?php
/**
 * Controlador de la ficha de un componente (public/ficha_componente.php).
 *
 * Qué hace: autorización (admin/técnico/beca), CSRF, parámetros, llamadas a ComponenteService y
 * carga de la vista (componente.php; componente_baja.php para una pieza DE_BAJA que ve alguien
 * que no es admin). No contiene SQL ni reglas de negocio. Se carga en el ámbito global.
 */
$raiz = dirname(__DIR__, 4);
require_once $raiz . '/app/Support/lib_inventario.php';

// Consulta de ficha de componente: solo inventario completo (sección 6)
requirePagina('ficha_componente.php');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header("Location: gestionar_inventario.php"); exit();
}

$idUsuario = (int)($_SESSION['user_id'] ?? 0);
$rolActual = $_SESSION['role'] ?? '';
$error = null;
$exito = null;
$esPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// Reinstalar ya requiere ser técnico/admin; el beca solo puede avisar (ver ComponenteService).
if ($esPost && isset($_POST['reinstalar_componente'])) {
    $res = ComponenteService::reinstalar($pdo, $idUsuario, $rolActual, $id, (int)($_POST['id_equipo_destino'] ?? 0), (string)($_POST['motivo_reinstalacion'] ?? ''));
    $error = $res['error'] ?: null;
    $exito = $res['exito'] ?: null;
}

// beca_trabajo no ejecuta el movimiento, pero puede avisar a los técnicos.
if ($esPost && isset($_POST['avisar_lista_reinstalar'])) {
    $exito = ComponenteService::avisarListaReinstalar($pdo, $id)['exito'];
}

// Tipos que son de Infraestructura (no van dentro de un equipo/puesto, sino directo a una sala).
$TIPOS_INFRAESTRUCTURA = ComponenteService::TIPOS_INFRAESTRUCTURA;

$componente = ComponenteRepository::ficha($pdo, $id);
if (!$componente) {
    header("Location: gestionar_inventario.php"); exit();
}

// Un componente DE_BAJA queda "como si no existiera" para todos menos admin: técnico/beca_trabajo
// solo ven el nombre y su última ubicación conocida, nunca sus datos completos ni acciones.
if ($componente['estado'] === 'DE_BAJA' && $rolActual !== Rol::ADMIN) {
    $ubicacionPrevia = $componente['equipo_codigo']
        ? ($componente['nombre_sala'] . ' — ' . $componente['equipo_codigo'])
        : ($componente['nombre_sala_directa'] ?? 'Bodega');
    $shell = ShellData::cargar($pdo);
    require $raiz . '/app/Presentation/Views/inventario/componente_baja.php';
    exit();
}

// Videobeam/parlante pueden ser periférico de un puesto o ítem directo de la sala: solo cuentan como
// infraestructura cuando no están instalados en un equipo.
$esInfraestructura = in_array($componente['tipo'], $TIPOS_INFRAESTRUCTURA, true)
    && (!in_array($componente['tipo'], ComponenteService::TIPOS_EQUIPO_TI, true) || empty($componente['id_equipo_actual']));

// Cambiar estado (solo Infraestructura, solo técnico): el admin y el beca solo consultan.
$errorEstadoInfra = '';
$exitoEstadoInfra = '';
if ($esInfraestructura && $rolActual === Rol::TECNICO && $esPost && isset($_POST['cambiar_estado_infra'])) {
    $res = ComponenteService::cambiarEstadoInfra($pdo, $idUsuario, $componente, (string)($_POST['nuevo_estado_infra'] ?? ''), (string)($_POST['observacion_estado_infra'] ?? ''));
    $errorEstadoInfra = $res['error'];
    $exitoEstadoInfra = $res['exito'];
    if ($res['exito'] !== '') {
        $componente['estado'] = (string)$_POST['nuevo_estado_infra'];
    }
}

$historial = ComponenteRepository::historial($pdo, $id);

$etiquetasEvento = [
    'REGISTRO' => '🆕 Registro',
    'ASIGNACION' => 'Asignación',
    'DESASIGNACION' => '⬅️ Desasignación',
    'CAMBIO_ESTADO' => 'Cambio de estado',
    'REVISION' => 'Revisión',
    'BAJA' => 'Baja',
];

// URL absoluta de esta misma ficha, para el QR de bodega/inventario.
$protocoloQr = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$hostQr = $_SERVER['HTTP_HOST'] ?? 'localhost';
$urlQrComponente = "{$protocoloQr}://{$hostQr}" . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/ficha_componente.php?id=' . (int)$id;

// Historial de auditoría (altas/ediciones de datos), distinto de la línea de tiempo de movimientos.
$historialAuditoriaComponente = AuditoriaService::historialObjeto($pdo, 'componentes', (int)$id);

// Salas del desplegable «Reinstalar» (solo se muestra a admin/técnico con la pieza suelta).
$salasParaReinstalar = ComponenteRepository::salasParaSelect($pdo);

$shell = ShellData::cargar($pdo);
require $raiz . '/app/Presentation/Views/inventario/componente.php';
