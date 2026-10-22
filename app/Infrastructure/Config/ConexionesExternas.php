<?php
/**
 * Datos de conexión a sistemas externos que el admin configura en «Conexiones» (public/conexiones.php):
 *
 *   asistencia_pg  PostgreSQL de la asistencia facial (en la nube): host, port, dbname, user, password
 *   glpi_db        Base MySQL/MariaDB de GLPI, solo LECTURA del inventario: host, port, dbname, user, password
 *   glpi_api       API REST de GLPI (apirest.php), para ESCRIBIR: url, app_token, user_token, ubicacion_bodega
 *
 * Se guardan en la tabla `conexiones_externas` como JSON. Los campos secretos (password, app_token, user_token)
 * van cifrados con AES-256-GCM; la clave vive en storage/clave_conexiones.key (fuera de public/ y de git) y se
 * crea sola la primera vez.
 */
final class ConexionesExternas
{
    public const ASISTENCIA_PG = 'asistencia_pg';
    public const GLPI_DB = 'glpi_db';
    public const GLPI_API = 'glpi_api';

    /** clave => [campos permitidos] (lista blanca) */
    public const CAMPOS = [
        self::ASISTENCIA_PG => ['host', 'port', 'dbname', 'user', 'password'],
        self::GLPI_DB       => ['host', 'port', 'dbname', 'user', 'password'],
        self::GLPI_API      => ['url', 'app_token', 'user_token', 'ubicacion_bodega'],
    ];
    public const SECRETOS = ['password', 'app_token', 'user_token'];

    private const PREFIJO = 'enc:v1:';

    /** Ruta del archivo con la clave de cifrado (sobrescribible en pruebas con CONEXIONES_CLAVE_ARCHIVO). */
    public static function rutaClave(): string
    {
        $env = getenv('CONEXIONES_CLAVE_ARCHIVO');
        return $env ? (string)$env : dirname(__DIR__, 3) . '/storage/clave_conexiones.key';
    }

    /** Clave de cifrado. Solo se crea al CIFRAR; al descifrar sin archivo devuelve null (no hay nada que leer). */
    private static function clave(bool $crear = true): ?string
    {
        static $clave = null;
        if ($clave !== null) return $clave;
        $ruta = self::rutaClave();
        if (is_file($ruta)) {
            $bin = base64_decode(trim((string)file_get_contents($ruta)), true);
            if ($bin !== false && strlen($bin) === 32) return $clave = $bin;
        }
        if (!$crear) return null;
        $bin = random_bytes(32);
        if (@file_put_contents($ruta, base64_encode($bin), LOCK_EX) === false) {
            throw new RuntimeException('No se pudo crear ' . $ruta . ' (revisa permisos de storage/).');
        }
        @chmod($ruta, 0600);
        return $clave = $bin;
    }

    public static function cifrar(string $texto): string
    {
        if ($texto === '') return '';
        $iv = random_bytes(12);
        $tag = '';
        $c = openssl_encrypt($texto, 'aes-256-gcm', (string)self::clave(), OPENSSL_RAW_DATA, $iv, $tag);
        return self::PREFIJO . base64_encode($iv . $tag . $c);
    }

    public static function descifrar(string $valor): string
    {
        if (strpos($valor, self::PREFIJO) !== 0) return $valor;
        $bin = base64_decode(substr($valor, strlen(self::PREFIJO)), true);
        if ($bin === false || strlen($bin) < 29) return '';
        $k = self::clave(false);
        if ($k === null) return ''; // sin la clave original no se puede descifrar: el admin vuelve a escribir la contraseña
        $p = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
        return $p === false ? '' : $p;
    }

    /** Datos descifrados de una conexión, o null si no está configurada. */
    public static function obtener(PDO $pdo, string $clave): ?array
    {
        $fila = self::fila($pdo, $clave);
        if ($fila === null) return null;
        $d = json_decode((string)$fila['datos'], true);
        if (!is_array($d)) return null;
        foreach (self::SECRETOS as $s) {
            if (isset($d[$s])) $d[$s] = self::descifrar((string)$d[$s]);
        }
        return $d;
    }

    /**
     * Estado para la vista, SIN secretos: campos visibles + qué secretos están guardados + última prueba.
     * @return array{datos:array,secretosGuardados:array,prueba_ok:?int,prueba_en:?string,prueba_mensaje:?string,actualizado_en:?string}|null
     */
    public static function resumen(PDO $pdo, string $clave): ?array
    {
        $fila = self::fila($pdo, $clave);
        if ($fila === null) return null;
        $d = json_decode((string)$fila['datos'], true) ?: [];
        $secretos = [];
        foreach (self::SECRETOS as $s) {
            if (array_key_exists($s, $d)) { $secretos[$s] = ($d[$s] !== ''); unset($d[$s]); }
        }
        return ['datos' => $d, 'secretosGuardados' => $secretos, 'prueba_ok' => $fila['prueba_ok'] === null ? null : (int)$fila['prueba_ok'],
                'prueba_en' => $fila['prueba_en'], 'prueba_mensaje' => $fila['prueba_mensaje'], 'actualizado_en' => $fila['actualizado_en']];
    }

    /**
     * Guarda los campos permitidos. Un secreto vacío CONSERVA el anterior (el formulario nunca lo muestra);
     * para borrarlo se marca «borrar_<campo>».
     */
    public static function guardar(PDO $pdo, string $clave, array $entrada, int $idUsuario): void
    {
        if (!isset(self::CAMPOS[$clave])) throw new InvalidArgumentException('Conexión no válida.');
        $anterior = self::fila($pdo, $clave);
        $previo = $anterior ? (json_decode((string)$anterior['datos'], true) ?: []) : [];
        $datos = [];
        foreach (self::CAMPOS[$clave] as $campo) {
            $v = trim((string)($entrada[$campo] ?? ''));
            if (in_array($campo, self::SECRETOS, true)) {
                if (!empty($entrada['borrar_' . $campo])) { $datos[$campo] = ''; continue; }
                $datos[$campo] = $v !== '' ? self::cifrar($v) : (string)($previo[$campo] ?? '');
            } else {
                $datos[$campo] = $v;
            }
        }
        $pdo->prepare("INSERT INTO conexiones_externas (clave, datos, actualizado_en, actualizado_por) VALUES (?, ?, NOW(), ?)
                       ON DUPLICATE KEY UPDATE datos = VALUES(datos), actualizado_en = NOW(), actualizado_por = VALUES(actualizado_por),
                                               prueba_ok = NULL, prueba_en = NULL, prueba_mensaje = NULL")
            ->execute([$clave, json_encode($datos, JSON_UNESCAPED_UNICODE), $idUsuario]);
    }

    public static function registrarPrueba(PDO $pdo, string $clave, bool $ok, string $mensaje): void
    {
        $pdo->prepare("UPDATE conexiones_externas SET prueba_ok = ?, prueba_en = NOW(), prueba_mensaje = ? WHERE clave = ?")
            ->execute([$ok ? 1 : 0, mb_substr($mensaje, 0, 255), $clave]);
    }

    private static function fila(PDO $pdo, string $clave): ?array
    {
        $st = $pdo->prepare("SELECT * FROM conexiones_externas WHERE clave = ?");
        $st->execute([$clave]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
