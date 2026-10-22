<?php
/**
 * Controlador de «Soluciones técnicas» (public/soluciones_tecnicas.php).
 *  - Técnico y admin: panel de seguimiento (por defecto, ?sala=N muestra la cobertura por equipo), catálogo
 *    (?vista=catalogo) y ficha de una solución (?id=N&sec=resumen|versiones|procedimientos|despliegue|verificaciones|historial).
 *  - Beca: solo sus tareas (equipos asignados o tomados en un mantenimiento con una solución).
 * Sin SQL ni reglas: SolucionService / SolucionDespliegueService (que vuelven a revisar los permisos con el rol de la
 * base). Un POST correcto redirige (el mensaje viaja en la sesión) para que recargar no lo repita. Ámbito global.
 */
$raiz = dirname(__DIR__, 4);
requirePagina('soluciones_tecnicas.php');

$rol = (string)$_SESSION['role'];
$userId = (int)$_SESSION['user_id'];
$permisos = SolucionService::permisos($rol);
$idSolucion = validarId($_GET['id'] ?? null) ?? 0;
$secciones = [
    'resumen' => 'Resumen y alcance', 'versiones' => 'Versiones y archivos', 'procedimientos' => 'Procedimientos',
    'despliegue' => 'Despliegue por equipo', 'verificaciones' => 'Verificaciones', 'historial' => 'Historial',
];
$seccion = isset($secciones[$_GET['sec'] ?? '']) ? (string)$_GET['sec'] : 'resumen';
$vista = !$permisos['verTodo'] ? 'tareas' : ($idSolucion ? 'ficha' : (($_GET['vista'] ?? '') === 'catalogo' ? 'catalogo' : 'panel'));
$error = '';
$formulario = ''; // acción cuyo formulario vuelve abierto con el error y lo que se escribió

// Primero: los mantenimientos ya cerrados pasan su checklist a los despliegues y nacen los pendientes nuevos.
SolucionDespliegueService::sincronizar($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formulario = (string)($_POST['accion_st'] ?? '');
    $idPost = (int)($_POST['id_solucion'] ?? 0);
    $res = match ($formulario) {
        'crear'              => SolucionService::crear($pdo, $userId, $_POST),
        'editar'             => SolucionService::editar($pdo, $userId, $idPost, $_POST),
        'estado'             => SolucionService::cambiarEstado($pdo, $userId, $idPost, (string)($_POST['estado'] ?? ''), trim((string)($_POST['motivo'] ?? ''))),
        'version'            => SolucionService::crearVersion($pdo, $userId, $idPost, $_POST, $_FILES['archivos'] ?? null),
        'revisar_version'    => SolucionService::revisarVersion($pdo, $userId, (int)($_POST['id_version'] ?? 0), (string)($_POST['decision'] ?? ''), trim((string)($_POST['observacion'] ?? ''))),
        'retirar_version'    => SolucionService::retirarVersion($pdo, $userId, (int)($_POST['id_version'] ?? 0), trim((string)($_POST['motivo'] ?? ''))),
        'procedimiento'      => SolucionService::guardarProcedimiento($pdo, $userId, $idPost, $_POST, $_FILES['capturas'] ?? null),
        'baja_procedimiento' => SolucionService::retirarProcedimiento($pdo, $userId, $idPost, (int)($_POST['id_procedimiento'] ?? 0)),
        'objetivos'          => SolucionDespliegueService::agregarObjetivos($pdo, $userId, $idPost, $_POST),
        'quitar_objetivo'    => SolucionDespliegueService::quitarObjetivo($pdo, $userId, $idPost, (int)($_POST['id_objetivo'] ?? 0)),
        'despliegue'         => SolucionDespliegueService::aplicarAccion($pdo, $userId, $idPost, $_POST, $_FILES['evidencias'] ?? null),
        'programar'          => SolucionDespliegueService::programarMantenimiento($pdo, $userId, $idPost, $_POST),
        'evidencia_tarea'    => SolucionDespliegueService::subirEvidenciaBeca($pdo, $userId, $_POST, $_FILES['evidencias'] ?? null),
        default              => ['error' => 'Acción no válida.', 'exito' => '', 'id' => 0],
    };
    if ($res['error'] === '') {
        $_SESSION['flash_soluciones'] = $res['exito'];
        $volver = $formulario === 'crear' ? ['id' => $res['id']] : array_intersect_key($_GET, ['id' => 1, 'sec' => 1, 'vista' => 1, 'sala' => 1]);
        header('Location: soluciones_tecnicas.php' . ($volver ? '?' . http_build_query($volver) : ''));
        exit();
    }
    $error = $res['error'];
}
$exito = (string)($_SESSION['flash_soluciones'] ?? '');
unset($_SESSION['flash_soluciones']);

if ($vista === 'ficha') {
    $ficha = SolucionService::datosFicha($pdo, $idSolucion, $seccion);
    if ($ficha === null) {
        http_response_code(404);
        $error = 'Esa solución técnica no existe.';
        $vista = 'catalogo';
    } else {
        extract($ficha);
    }
}
if ($vista === 'catalogo') {
    extract(SolucionService::datosCatalogo($pdo, $_GET));
} elseif ($vista === 'panel') {
    extract(SolucionDespliegueService::datosPanel($pdo, validarId($_GET['sala'] ?? null) ?? 0));
} elseif ($vista === 'tareas') {
    extract(SolucionDespliegueService::datosTareas($pdo, $userId));
}
$estadosSolucion = SolucionService::ESTADOS;
$chipSolucion = SolucionService::CHIP_ESTADO;
$estadosDespliegue = SolucionDespliegueService::ESTADOS;
$chipDespliegue = SolucionDespliegueService::CHIP;
$fases = SolucionService::FASES;

$shell = ShellData::cargar($pdo);
$vistas = ['panel' => 'panel.php', 'catalogo' => 'catalogo.php', 'ficha' => 'ficha.php', 'tareas' => 'tareas.php'];
require $raiz . '/app/Presentation/Views/soluciones/' . $vistas[$vista];
