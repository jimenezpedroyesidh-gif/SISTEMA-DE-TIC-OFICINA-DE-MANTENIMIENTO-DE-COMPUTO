<?php
/**
 * Registro único de errores del servidor.
 *
 * Todos los Services/Repositories que capturan una excepción la registran
 * aquí con el mismo formato, en vez de cada uno armar su propio error_log().
 * Nunca escribe nada en la respuesta: el detalle (SQL, rutas, trazas) va solo
 * al log del servidor y el usuario ve un mensaje genérico.
 */
final class RegistroErrores
{
    /** Formato: [contexto] Clase: mensaje (archivo:línea). */
    public static function registrar(string $contexto, Throwable $e): void
    {
        error_log(sprintf('[%s] %s: %s (%s:%d)', $contexto, get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine()));
    }
}
