<?php
/**
 * Controlador de «Inventario GLPI» (public/glpi.php), solo admin:
 *  - inventario de GLPI (lectura por BD) agrupado por tipo y ubicación, comparado por serial con el sistema;
 *  - vínculo sala del sistema ↔ ubicación de GLPI (necesario para mover ubicaciones);
 *  - cola de envíos a GLPI por API (altas de componentes y cambios de ubicación aprobados) con «Reintentar».
 * Sin SQL: GlpiSyncService / GlpiColaRepository / GlpiDb. Ámbito global.
 */
requirePagina('glpi.php');
$glpi = Database::glpi();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');
    $idAdmin = (int)$_SESSION['user_id'];
    if ($accion === 'reintentar') {
        $r = GlpiSyncService::procesar($pdo, true, 100);
        $flash = ['ok' => $r['errores'] === 0, 'mensaje' => $r['mensaje']];
    } elseif ($accion === 'vincular_serial') {
        $flash = $glpi === null
            ? ['ok' => false, 'mensaje' => 'Primero conecta la base de datos de GLPI en Conexiones.']
            : ['ok' => true, 'mensaje' => 'Componentes vinculados por número de serie: ' . GlpiSyncService::vincularPorSerial($pdo, $glpi) . '.'];
    } elseif ($accion === 'guardar_salas') {
        $n = 0;
        // Dos listas emparejadas (glpi_salas[i] ↔ glpi_ubicaciones[i]): entradaNormalizar() solo deja pasar listas.
        $idsSala = (array)($_POST['glpi_salas'] ?? []);
        $ubics = (array)($_POST['glpi_ubicaciones'] ?? []);
        foreach ($idsSala as $i => $idSala) {
            if (!ctype_digit((string)$idSala)) continue;
            $loc = trim((string)($ubics[$i] ?? ''));
            GlpiColaRepository::vincularSala($pdo, (int)$idSala, ($loc !== '' && ctype_digit($loc)) ? (int)$loc : null);
            $n++;
        }
        AuditoriaService::registrar($pdo, $idAdmin, 'salas', 0, 'EDITAR', "Vínculo sala ↔ ubicación GLPI actualizado ($n salas)");
        $flash = ['ok' => true, 'mensaje' => 'Vínculos de salas guardados.'];
    } else {
        $flash = ['ok' => false, 'mensaje' => 'Acción no válida.'];
    }
    $_SESSION['flash_glpi'] = $flash;
    header('Location: glpi.php');
    exit();
}

$flash = $_SESSION['flash_glpi'] ?? null;
unset($_SESSION['flash_glpi']);

$filtroUbicacion = isset($_GET['ubicacion']) && ctype_digit((string)$_GET['ubicacion']) ? (int)$_GET['ubicacion'] : null;
$filtroTipo = isset($_GET['tipo']) && isset(GlpiDb::TIPOS[$_GET['tipo']]) ? (string)$_GET['tipo'] : null;

$inv = GlpiSyncService::inventario($pdo, $glpi, $filtroUbicacion, $filtroTipo);
$ubicacionesGlpi = $glpi ? GlpiDb::ubicaciones($glpi) : [];
$salas = GlpiColaRepository::salas($pdo);
$cola = GlpiColaRepository::ultimos($pdo, 50);
$conteoCola = GlpiColaRepository::conteo($pdo);
$apiConfigurada = ($c = ConexionesExternas::obtener($pdo, ConexionesExternas::GLPI_API)) !== null && !empty($c['url']) && !empty($c['app_token']) && !empty($c['user_token']);
$etiquetasTipo = array_map(fn($t) => $t[3], GlpiDb::TIPOS);

$shell = ShellData::cargar($pdo);
require dirname(__DIR__, 2) . '/Views/glpi/pagina.php';
