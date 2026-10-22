<?php
/**
 * Controlador de public/descargar_solucion.php: único punto de descarga de los archivos privados de las soluciones
 * técnicas (storage/soluciones está bloqueada por .htaccess). ?archivo=N baja un archivo de versión (siempre como
 * descarga y solo si su SHA-256 coincide con el registrado); ?evidencia=N abre una captura o foto. Autorización y
 * ruta: SolucionService::resolverDescarga. Ámbito global.
 */
requirePagina('descargar_solucion.php');

$tipo = isset($_GET['evidencia']) ? 'evidencia' : 'archivo';
$res = SolucionService::resolverDescarga($pdo, $tipo, validarId($_GET[$tipo] ?? null) ?? 0, (string)($_SESSION['role'] ?? ''), (int)$_SESSION['user_id']);
if (!$res['ok']) {
    http_response_code($res['status']);
    exit($res['mensaje']);
}

// Nombre ASCII de respaldo + el original en UTF-8 (RFC 6266): tildes y ñ llegan bien sin romper la cabecera.
$nombreAscii = (string)preg_replace('/[^\x20-\x7e]/', '_', $res['nombre']);
header('Content-Type: ' . $res['mime']);
header('Content-Length: ' . filesize($res['ruta']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Disposition: ' . ($res['adjunto'] ? 'attachment' : 'inline') . '; filename="' . $nombreAscii . '"; filename*=UTF-8\'\'' . rawurlencode($res['nombre']));
readfile($res['ruta']);
