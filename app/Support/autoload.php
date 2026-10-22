<?php
/**
 * Autocarga de clases. Reemplaza los `require_once` de archivos que solo definen clases.
 *
 * El proyecto no usa namespaces (nombres de clase únicos), así que el cargador es un mapa nombre → archivo
 * generado por `php tools/generar_classmap.php` en app/classmap.php (una prueba falla si el mapa está desactualizado).
 * Si una clase no está en el mapa se reescanea app/ una vez (útil al agregar una clase antes de regenerar el mapa).
 *
 * Los archivos de FUNCIONES sueltas (auth.php, csrf.php, ui.php, validacion.php, paginacion.php, marca.php, inventario.php, …)
 * no se pueden autocargar: se siguen incluyendo explícitamente (bootstrap.php o quien las use).
 */
if (!function_exists('autoloadClases')) {
    /** Escanea app/ y devuelve [clase => ruta relativa a la raíz del proyecto]. */
    function autoloadEscanear(string $raiz): array
    {
        $mapa = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/app', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || substr((string)$f, -4) !== '.php') continue;
            $ruta = substr((string)$f, strlen($raiz) + 1);
            if ($ruta === 'app/classmap.php') continue;
            if (preg_match_all('/^(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+([A-Za-z_]\w*)/m', (string)file_get_contents((string)$f), $m)) {
                foreach ($m[1] as $clase) $mapa[$clase] = $ruta;
            }
        }
        ksort($mapa);
        return $mapa;
    }

    function autoloadClases(string $clase): void
    {
        static $mapa = null, $reescaneado = false;
        $raiz = dirname(__DIR__, 2);
        if ($mapa === null) {
            $mapa = is_file($raiz . '/app/classmap.php') ? (array)require $raiz . '/app/classmap.php' : [];
        }
        if (!isset($mapa[$clase]) && !$reescaneado) {
            $reescaneado = true;
            $mapa = autoloadEscanear($raiz);
        }
        if (isset($mapa[$clase])) require_once $raiz . '/' . $mapa[$clase];
    }

    spl_autoload_register('autoloadClases');
}
