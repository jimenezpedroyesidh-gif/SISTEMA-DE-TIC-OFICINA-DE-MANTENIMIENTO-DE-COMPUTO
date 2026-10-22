<?php
/**
 * Página pública: sin sesión se reporta de forma anónima; un profesor con sesión reporta a su nombre. Límite
 * simple de un reporte por minuto y por sesión+QR; el anónimo además pasa por AntiSpamQr (trampa, límite por
 * IP, privacidad y pregunta). Sin SQL ni reglas de negocio: llama a ReporteQrService y carga la vista. Ámbito
 * global.
 */
$raiz = dirname(__DIR__, 4);

$token = $_GET['token'] ?? '';
$error = '';
$exito = false;
$duplicado = false;
$estadoExistente = '';
$codigoSeguimiento = '';

if ($token === '') {
    die("QR inválido: falta el token.");
}
$sala = ReporteQrRepository::salaPorToken($pdo, (string)$token);
if (!$sala) {
    die("QR inválido o sala no encontrada.");
}

// Quien reporta por QR sin sesión SOLO describe el problema; el equipo puntual lo decide el beca/técnico al tomar el ticket.
// Con sesión de PROFESOR el reporte deja de ser anónimo (queda a su nombre y se le lleva a su panel).
$profesorLogueado = (!empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'profesor');
$nombreSesion = $profesorLogueado ? $_SESSION['nombre_completo'] : '';
// Plano de la sala para todos (con y sin sesión): se ve qué equipos están operativos y se pueden marcar
// los que fallan (opcional). Los nombres de quién trasladó/aprobó solo se muestran con sesión iniciada.
$equiposSala = ListadoAjaxRepository::equiposDeSala($pdo, (int)$sala['id_sala']);
$estadosSala = PlanoSalaService::estadoEquipos($pdo, array_column($equiposSala, 'id_equipo'), !empty($_SESSION['user_id']));
$idsEquipoPost = array_map('intval', (array)($_POST['ids_equipo_danio'] ?? []));
$frenteSala = PlanoSalaService::frentePorSalas($pdo, [(int)$sala['id_sala']], !empty($_SESSION['user_id']))[(int)$sala['id_sala']] ?? [];
$idsComponentePost = array_map('intval', (array)($_POST['ids_componente_danio'] ?? []));
$distribucionSala = DistribucionSalaRepository::deSala($pdo, (int)$sala['id_sala']); // filas/mesas/número de puesto si se maquetó

// Lo que ya escribió quien reporta (si el envío falla no tiene que volver a escribirlo).
$nombreContactoPost = (string)($_POST['nombre_contacto'] ?? ($_SESSION['nombre_completo'] ?? ''));
$correoContactoPost = (string)($_POST['correo_contacto'] ?? '');

$claveLimite = 'rl_' . md5($token . $_SERVER['REMOTE_ADDR']);
$ahora = time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Un POST hecho con la sesión de un profesor crea un ticket a su nombre → exige token CSRF. El reporte
    // anónimo no actúa en nombre de nadie, así que no lo necesita.
    if ($profesorLogueado) csrfCheck();

    // Reporte anónimo: campo trampa, límite por IP y por hora, aviso de privacidad y pregunta de verificación.
    $bloqueoSpam = $profesorLogueado ? null : AntiSpamQr::revisar($pdo, $_POST, $_SESSION, BitacoraAccesos::ipCliente(), (string)$sala['nombre_sala']);
    if (isset($_SESSION[$claveLimite]) && ($ahora - $_SESSION[$claveLimite]) < 60) {
        $error = "Ya enviaste un reporte hace poco. Espera un minuto antes de enviar otro.";
    } elseif ($bloqueoSpam !== null) {
        $error = $bloqueoSpam;
    } else {
        $res = ReporteQrService::reportar($pdo, $sala, (string)($_POST['descripcion'] ?? ''), $profesorLogueado ? (int)$_SESSION['user_id'] : null, $_SERVER['REMOTE_ADDR'] ?? null, $idsEquipoPost, $idsComponentePost,
            ['nombre' => (string)($_POST['nombre_contacto'] ?? ''), 'correo' => (string)($_POST['correo_contacto'] ?? '')]);
        switch ($res['resultado']) {
            case 'error':
                $error = $res['mensaje'];
                break;
            case 'duplicado':
                $duplicado = true;
                $estadoExistente = $res['estado'];
                break;
            case 'profesor':
                $_SESSION[$claveLimite] = $ahora;
                header("Location: tickets.php?new=1");
                exit();
            case 'anonimo':
                $_SESSION[$claveLimite] = $ahora;
                $exito = true;
                $codigoSeguimiento = $res['codigo'];
                break;
        }
    }
}

// Pregunta de verificación nueva en cada carga del formulario anónimo (se responde una sola vez).
$pedirReto = !$profesorLogueado && !$exito && !$duplicado && ConfigSistema::activo($pdo, 'seg_qr_captcha');
$preguntaReto = $pedirReto ? AntiSpamQr::nuevoReto($_SESSION) : '';

require $raiz . '/app/Presentation/Views/publico/reportar.php';
