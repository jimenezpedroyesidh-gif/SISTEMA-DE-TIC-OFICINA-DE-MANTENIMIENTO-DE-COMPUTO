<?php
require_once __DIR__ . '/config.php'; // institucion(): dominio de correo
/**
 * Validación de entrada centralizada.
 *
 * Capas:
 *  2. Funciones validar*(): conversión estricta de un valor ya recibido a un
 *     tipo concreto (id, entero acotado, enumeración, fecha, hora, texto). Todas
 *     devuelven null (o el valor por defecto) si el valor no es válido, así el
 *     llamador decide el mensaje; nunca lanzan excepciones.
 */

/** Campos que SÍ pueden llegar como lista (checkboxes de equipos). */
const ENTRADA_CAMPOS_LISTA = ['ids_equipo', 'ids_equipo_danio', 'ids_componente_danio', 'glpi_salas', 'glpi_ubicaciones', 'salas', 'vinculos', 'despliegues']; // glpi_*: Inventario GLPI (listas emparejadas); salas: salas habilitadas a una secretaria en Horarios, salas de una campaña de mantenimiento y salas donde se aplica una solución técnica; vinculos: reportes que atiende un mantenimiento; despliegues: equipos marcados de una solución técnica

function entradaNormalizar(): void
{
    foreach (['_GET', '_POST', '_COOKIE'] as $nombre) {
        foreach ($GLOBALS[$nombre] as $clave => $valor) {
            if (!is_array($valor)) {
                continue;
            }
            if (in_array($clave, ENTRADA_CAMPOS_LISTA, true)) {
                // lista de escalares: se descartan anidados
                $GLOBALS[$nombre][$clave] = array_values(array_filter($valor, 'is_scalar'));
            } else {
                $GLOBALS[$nombre][$clave] = '';
            }
        }
    }
}

/** Id entero positivo (>0) o null. Rechaza '1abc', '1.5', '-3', arreglos y desbordes. */
function validarId($valor): ?int
{
    if (is_int($valor)) {
        return $valor > 0 ? $valor : null;
    }
    if (!is_string($valor) || !preg_match('/^[1-9][0-9]{0,17}$/', trim($valor))) {
        return null;
    }
    return (int)trim($valor);
}

/** Entero dentro de [$min,$max]; si no es entero o está fuera de rango devuelve $defecto. */
function validarEntero($valor, int $min, int $max, int $defecto): int
{
    if (is_string($valor)) {
        $valor = trim($valor);
    }
    if (is_int($valor) || (is_string($valor) && preg_match('/^-?[0-9]{1,15}$/', $valor))) {
        $n = (int)$valor;
        if ($n >= $min && $n <= $max) {
            return $n;
        }
    }
    return $defecto;
}

/** El valor debe ser exactamente uno de $permitidos (comparación estricta de cadena) o null. */
function validarEnum($valor, array $permitidos): ?string
{
    return (is_string($valor) && in_array($valor, $permitidos, true)) ? $valor : null;
}

/** Fecha real con formato Y-m-d (rechaza 2026-13-45 o 2026-02-30) o null. */
function validarFecha($valor, string $formato = 'Y-m-d'): ?string
{
    if (!is_string($valor)) {
        return null;
    }
    $d = DateTime::createFromFormat('!' . $formato, $valor);
    $err = DateTime::getLastErrors();
    if ($d === false || ($err && ($err['warning_count'] || $err['error_count'])) || $d->format($formato) !== $valor) {
        return null;
    }
    return $valor;
}

/** Hora HH:MM o HH:MM:SS (00:00–23:59) o null. */
function validarHora($valor): ?string
{
    return (is_string($valor) && preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $valor)) ? $valor : null;
}

/**
 * Texto recortado (trim) de longitud entre $min y $max caracteres, sin bytes
 * nulos ni UTF-8 inválido; null si no cumple.
 */
function validarTexto($valor, int $max, int $min = 0): ?string
{
    if (!is_string($valor)) {
        return null;
    }
    $t = trim($valor);
    if (strpos($t, "\0") !== false || !mb_check_encoding($t, 'UTF-8')) {
        return null;
    }
    $n = mb_strlen($t, 'UTF-8');
    return ($n >= $min && $n <= $max) ? $t : null;
}

/* Correo institucional */

/** Solo correo del dominio institucional (formulario QR sin sesión: así se puede contactar al profesor). */
function correoInstitucionalValido(string $correo): bool {
    return correoDeDominios($correo, [institucion('dominio_correo')]);
}

/** Correo de un dominio aceptado al crear o editar un usuario (institucional o extra, ver config/app.php). */
function correoValido(string $correo): bool {
    return correoDeDominios($correo, correoDominiosPermitidos());
}

function correoDeDominios(string $correo, array $dominios): bool {
    $partes = explode('@', trim($correo));
    return count($partes) === 2 && $partes[0] !== '' && !preg_match('/\s/', $partes[0])
        && in_array(strtolower($partes[1]), array_map('strtolower', $dominios), true);
}
