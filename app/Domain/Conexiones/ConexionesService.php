<?php
/**
 * Casos de uso de la vista «Conexiones» (solo admin): guardar y probar las conexiones externas.
 * Los datos se guardan cifrados con ConexionesExternas; aquí solo se valida y se prueba.
 */
final class ConexionesService
{
    /** Valores iniciales que se muestran en el formulario de la asistencia facial mientras no haya nada guardado. */
    public const PG_PREDETERMINADO = ['host' => '3.93.129.8', 'port' => '5432', 'dbname' => 'bd_sistemas_becario', 'user' => 'dptosistemas'];

    public const TITULOS = [
        ConexionesExternas::ASISTENCIA_PG => 'Asistencia facial (PostgreSQL en la nube)',
        ConexionesExternas::GLPI_DB       => 'GLPI — base de datos (lectura del inventario)',
        ConexionesExternas::GLPI_API      => 'GLPI — API REST (crear componentes y mover ubicaciones)',
    ];

    /** @return array{ok:bool,mensaje:string} */
    public static function guardar(PDO $pdo, string $clave, array $post, int $idAdmin): array
    {
        if (!isset(ConexionesExternas::CAMPOS[$clave])) return ['ok' => false, 'mensaje' => 'Conexión no válida.'];
        $err = self::validar($clave, $post);
        if ($err !== null) return ['ok' => false, 'mensaje' => $err];
        try {
            ConexionesExternas::guardar($pdo, $clave, $post, $idAdmin);
        } catch (Throwable $e) {
            RegistroErrores::registrar('Conexiones: guardar ' . $clave, $e);
            return ['ok' => false, 'mensaje' => 'No se pudo guardar: ' . $e->getMessage()];
        }
        Database::reiniciarExternas();
        AuditoriaService::registrar($pdo, $idAdmin, 'conexiones_externas', 0, 'EDITAR', 'Conexión actualizada: ' . $clave);
        return ['ok' => true, 'mensaje' => 'Datos guardados: ' . self::TITULOS[$clave] . '. Usa «Probar conexión» para comprobarlos.'];
    }

    private static function validar(string $clave, array $p): ?string
    {
        if ($clave === ConexionesExternas::GLPI_API) {
            $url = trim((string)($p['url'] ?? ''));
            if ($url !== '' && !preg_match('~^https?://[^\s/$.?#][^\s]*$~i', $url)) return 'La URL debe empezar por http:// o https:// (ej. http://10.0.0.5/glpi/apirest.php).';
            $b = trim((string)($p['ubicacion_bodega'] ?? ''));
            if ($b !== '' && !ctype_digit($b)) return 'La ubicación de bodega debe ser el id numérico de la ubicación en GLPI.';
            return null;
        }
        $host = trim((string)($p['host'] ?? ''));
        if ($host !== '' && !preg_match('/^[A-Za-z0-9.\-]+$/', $host)) return 'El host debe ser una IP o un nombre de servidor (sin espacios ni http://).';
        $port = trim((string)($p['port'] ?? ''));
        if ($port !== '' && (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535)) return 'El puerto debe ser un número entre 1 y 65535.';
        $db = trim((string)($p['dbname'] ?? ''));
        if ($db !== '' && !preg_match('/^[A-Za-z0-9_\-]+$/', $db)) return 'El nombre de la base solo admite letras, números, guion y guion bajo.';
        return null;
    }

    /** Prueba la conexión guardada y deja el resultado registrado. @return array{ok:bool,mensaje:string} */
    public static function probar(PDO $pdo, string $clave): array
    {
        $c = ConexionesExternas::obtener($pdo, $clave);
        if ($c === null) return ['ok' => false, 'mensaje' => 'Primero guarda los datos de la conexión.'];
        try {
            switch ($clave) {
                case ConexionesExternas::ASISTENCIA_PG:
                    $pg = Database::abrirPostgres($c, 5);
                    try {
                        $n = (int)$pg->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
                        $msg = "Conectado. La base de asistencia tiene $n usuarios registrados.";
                    } catch (PDOException $e) {
                        $pg->query("SELECT 1");
                        $msg = 'Conectado, pero no se encontró la tabla «usuarios» de la asistencia facial.';
                    }
                    break;
                case ConexionesExternas::GLPI_DB:
                    $g = Database::abrirMysql($c, 5);
                    $total = count(GlpiDb::activos($g));
                    $msg = "Conectado. GLPI tiene $total activos (computadores, monitores, periféricos, impresoras y red).";
                    break;
                case ConexionesExternas::GLPI_API:
                    if (empty($c['url']) || empty($c['app_token']) || empty($c['user_token'])) {
                        throw new RuntimeException('Faltan la URL, el App-Token o el token de usuario.');
                    }
                    $api = new GlpiApi((string)$c['url'], (string)$c['app_token'], (string)$c['user_token']);
                    $quien = $api->iniciar();
                    $api->cerrar();
                    $msg = "Conectado a la API de GLPI como «{$quien}».";
                    break;
                default:
                    return ['ok' => false, 'mensaje' => 'Conexión no válida.'];
            }
            ConexionesExternas::registrarPrueba($pdo, $clave, true, $msg);
            return ['ok' => true, 'mensaje' => $msg];
        } catch (Throwable $e) {
            $msg = self::mensajeError($e);
            ConexionesExternas::registrarPrueba($pdo, $clave, false, $msg);
            return ['ok' => false, 'mensaje' => $msg];
        }
    }

    /** Mensaje corto y sin datos sensibles para el admin. */
    private static function mensajeError(Throwable $e): string
    {
        $m = $e->getMessage();
        if ($e instanceof PDOException) {
            if (stripos($m, 'password authentication failed') !== false || stripos($m, 'Access denied') !== false) return 'Usuario o contraseña incorrectos.';
            if (stripos($m, 'timeout') !== false || stripos($m, 'timed out') !== false) return 'El servidor no respondió a tiempo (revisa IP, puerto y firewall).';
            if (stripos($m, 'could not connect') !== false || stripos($m, 'Connection refused') !== false || stripos($m, "Can't connect") !== false || stripos($m, 'No route') !== false) return 'No se pudo conectar al servidor (revisa IP, puerto y que acepte conexiones remotas).';
            if (stripos($m, 'does not exist') !== false || stripos($m, 'Unknown database') !== false) return 'La base de datos indicada no existe.';
            return 'Error de base de datos: ' . mb_substr($m, 0, 150);
        }
        return mb_substr($m, 0, 200);
    }

    /** Estado de las 3 conexiones para la vista (sin secretos). */
    public static function estado(PDO $pdo): array
    {
        $out = [];
        foreach (array_keys(ConexionesExternas::CAMPOS) as $clave) {
            $out[$clave] = ConexionesExternas::resumen($pdo, $clave);
        }
        return $out;
    }
}
