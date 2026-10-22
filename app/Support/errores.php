<?php
/**
 * Manejo de errores centralizado.
 *
 * En producción nunca se muestran al usuario SQL, rutas internas, trazas ni
 * credenciales: los errores PHP se registran en el log y una excepción no
 * capturada responde con una página/JSON genérico (HTTP 500).
 */
/** Respuesta 500 genérica (JSON si la petición ya declaró Content-Type JSON; HTML en otro caso). */
function erroresResponder500(): void
{
    if (!headers_sent()) {
        http_response_code(500);
    }
    $json = false;
    foreach (headers_list() as $cab) {
        if (stripos($cab, 'Content-Type:') === 0 && stripos($cab, 'json') !== false) {
            $json = true;
        }
    }
    if ($json) {
        echo json_encode(['error' => 'Error interno. Inténtalo de nuevo.']);
    } else {
        echo '<!DOCTYPE html><html lang="es"><meta charset="utf-8"><title>Error</title>'
           . '<p style="font-family:sans-serif;margin:2rem">Ocurrió un error interno. Inténtalo de nuevo o contacta al administrador.</p></html>';
    }
}

function erroresConfigurar(): void
{
    if (PHP_SAPI === 'cli' || getenv('APP_DEBUG') === '1') {
        return;
    }
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');

    // Excepciones sin capturar: log completo + respuesta genérica.
    set_exception_handler(static function (Throwable $e): void {
        RegistroErrores::registrar('excepción no capturada', $e);
        erroresResponder500();
    });

    // Errores fatales (memoria, tipos, parse en includes): PHP no llama al
    // handler de excepciones; se registran y se responde igual de genérico.
    register_shutdown_function(static function (): void {
        $u = error_get_last();
        if ($u === null || !in_array($u['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        error_log(sprintf('[error fatal] %s (%s:%d)', $u['message'], basename($u['file']), $u['line']));
        if (!headers_sent()) {
            erroresResponder500();
        }
    });
}
