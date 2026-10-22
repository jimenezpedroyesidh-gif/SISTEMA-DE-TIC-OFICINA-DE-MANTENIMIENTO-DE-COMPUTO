<?php
/**
 * Carga de configuración centralizada.
 *
 * Regla: el CÓDIGO nunca lleva credenciales. Los valores viven en config/*.php
 * (fuera de git, ver .gitignore) y se leen solo con esta función.
 *
 *   config/database.php   MySQL/MariaDB de tickets
 *   config/postgres.php   PostgreSQL de asistencia facial (solo lectura)
 *   config/smtp.php       correo saliente
 *
 * Cada archivo tiene su `*.example.php` como plantilla.
 */

/**
 * Devuelve el arreglo de config/<nombre>.php, o null si el archivo no existe.
 * Los resultados se guardan en memoria durante la petición.
 */
function configCargar(string $nombre): ?array
{
    static $cache = [];
    if (!preg_match('/^[a-z_]+$/', $nombre)) {
        return null; // nunca construir rutas con texto arbitrario
    }
    if (!array_key_exists($nombre, $cache)) {
        $ruta = __DIR__ . '/../../config/' . $nombre . '.php';
        $cfg = is_file($ruta) ? require $ruta : null;
        $cache[$nombre] = is_array($cfg) ? $cfg : null;
        if ($nombre === 'database' && $cache[$nombre] !== null && getenv('APP_DB_NAME')) {
            $cache[$nombre]['dbname'] = (string)getenv('APP_DB_NAME');
        }
    }
    return $cache[$nombre];
}

/**
 * Dirección pública del sistema (sin barra final) para armar enlaces en
 * correos. Usa config/app.php → 'url_base' (plantilla: config/app.example.php); si no existe, la deduce de la
 * petición solo cuando el Host tiene un formato válido (evita que un Host
 * falsificado meta un dominio ajeno en el enlace).
 */
function urlBase(): string
{
    $cfg = configCargar('app');
    if ($cfg !== null && !empty($cfg['url_base'])) {
        return rtrim((string)$cfg['url_base'], '/');
    }
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.-]+(:[0-9]{1,5})?$/', $host)) {
        $host = 'localhost';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

/** Datos de la institución que usa el sistema si config/app.php no trae la clave 'institucion'. */
const INSTITUCION_DEFECTO = [
    'sigla'          => 'UFPS',
    'nombre'         => 'Universidad Francisco de Paula Santander',
    'ciudad'         => 'Cúcuta',
    'sistema'        => 'Sistema de Soporte',
    'oficina'        => 'Oficina de Mantenimiento de Cómputo',
    'ubicacion'      => 'Departamento de Sistemas, Aula Sur SC403',
    'contacto'       => 'ext:342',
    'dominio_correo' => 'ufps.edu.co',
    'dominios_extra' => ['gmail.com'],
];

/**
 * Un dato de la institución (sigla, nombre, ciudad, sistema, oficina, ubicacion, contacto, dominio_correo).
 * Para usar el sistema en otra empresa basta con cambiar 'institucion' en config/app.php (y los colores de
 * public/assets/tokens.css).
 */
function institucion(string $clave): string
{
    $cfg = (array)(configCargar('app')['institucion'] ?? []);
    $valor = $cfg[$clave] ?? INSTITUCION_DEFECTO[$clave] ?? '';
    return is_string($valor) ? $valor : '';
}

/** Dominios de correo aceptados al crear usuarios: el institucional primero y luego los extra. */
function correoDominiosPermitidos(): array
{
    $extra = configCargar('app')['institucion']['dominios_extra'] ?? INSTITUCION_DEFECTO['dominios_extra'];
    return array_values(array_unique(array_merge([institucion('dominio_correo')], array_map('strval', (array)$extra))));
}

/** Patrón HTML (atributo pattern) para un correo del dominio institucional, sin distinguir mayúsculas. */
function correoPatronHtmlInstitucional(): string
{
    $dominio = preg_replace_callback('/[a-z]/i', fn($m) => '[' . strtolower($m[0]) . strtoupper($m[0]) . ']', preg_quote(institucion('dominio_correo')));
    return '^[^@\s]+@' . $dominio . '$';
}

/** «@a.com o @b.com» para mensajes de error y ayudas de formulario. */
function correoDominiosTexto(): string
{
    $d = array_map(fn($x) => '@' . $x, correoDominiosPermitidos());
    return count($d) > 1 ? implode(', ', array_slice($d, 0, -1)) . ' o ' . end($d) : (string)($d[0] ?? '');
}
