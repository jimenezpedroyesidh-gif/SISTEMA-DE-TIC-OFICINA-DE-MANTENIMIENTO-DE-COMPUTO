<?php
/**
 * Conexión PDO única a MySQL/MariaDB (tickets_db).
 *
 * Uso típico (ver bootstrap.php):
 *   $pdo = Database::conexion();
 */
require_once __DIR__ . '/../../Support/config.php';

/** Señal interna: abandona la transacción sin error y devuelve $valor. */
final class TransaccionRevertida extends Exception
{
    private $valor;
    public function __construct($valor = null)
    {
        parent::__construct('Transacción revertida');
        $this->valor = $valor;
    }
    public function valor()
    {
        return $this->valor;
    }
}

final class Database
{
    /**
     * Opciones de toda conexión de la aplicación:
     *  - ERRMODE_EXCEPTION: todo error SQL lanza PDOException (se captura y se registra en el log,
     *    nunca se muestra al usuario).
     *  - EMULATE_PREPARES = false: las consultas preparadas las prepara el SERVIDOR MySQL; la
     *    consulta y los datos viajan por separado, así que un valor nunca puede alterar el SQL.
     */
    private const OPCIONES_PDO = [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    private static ?PDO $instancia = null;
    private static ?PDO $asistencia = null;
    private static bool $asistenciaIntentada = false;
    private static ?PDO $glpi = null;
    private static bool $glpiIntentada = false;

    public static function conexion(): PDO
    {
        if (self::$instancia === null) {
            $config = configCargar('database');
            if ($config === null) {
                error_log('[Database] Falta config/database.php (copia config/database.example.php).');
                if (PHP_SAPI === 'cli') {
                    fwrite(STDERR, "Falta config/database.php\n");
                    exit(1);
                }
                http_response_code(503);
                exit('Servicio no disponible temporalmente. Intente de nuevo en unos minutos.');
            }

            try {
                $charset = $config['charset'] ?? 'utf8mb4';
                self::$instancia = new PDO(
                    "mysql:host={$config['host']};dbname={$config['dbname']};charset={$charset}",
                    $config['username'],
                    $config['password'],
                    self::OPCIONES_PDO
                );
            } catch (PDOException $e) {
                // El detalle (host, usuario, motivo) va SOLO al log del
                // servidor; nunca al navegador.
                RegistroErrores::registrar('Database: fallo de conexión', $e);
                if (PHP_SAPI === 'cli') {
                    fwrite(STDERR, "Error de conexión a la base de datos (ver log del servidor).\n");
                    exit(1);
                }
                http_response_code(503);
                exit('Servicio no disponible temporalmente. Intente de nuevo en unos minutos.');
            }
        }

        return self::$instancia;
    }

    /**
     * Ejecuta $fn dentro de una transacción (por defecto, la conexión principal;
     * se puede pasar otra $pdo, p. ej. la de las pruebas). Confirma si termina
     * bien y deshace (y relanza la excepción) si falla.
     *
     * Si ya hay una transacción abierta:
     *  - sin $savepoint, se une a ella (no anida): la confirmación/reversión
     *    la decide quien la abrió;
     *  - con $savepoint (nombre [a-z_]), usa SAVEPOINT para poder deshacer solo
     *    esta operación sin romper la transacción externa.
     *
     * Para abandonar a mitad de camino SIN error (equivalente al antiguo
     * "rollBack(); return X;"), $fn lanza TransaccionRevertida($valor):
     * se deshace lo de esta llamada y devuelve $valor.
     *
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public static function transaccion(callable $fn, ?PDO $pdo = null, ?string $savepoint = null)
    {
        $pdo = $pdo ?? self::conexion();
        $propia = !$pdo->inTransaction();
        $sp = null;
        if ($propia) {
            $pdo->beginTransaction();
        } elseif ($savepoint !== null) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $savepoint)) {
                throw new InvalidArgumentException('Nombre de savepoint inválido');
            }
            $sp = $savepoint;
            $pdo->exec("SAVEPOINT `$sp`");
        }
        $deshacer = static function () use ($pdo, $propia, $sp): void {
            if (!$pdo->inTransaction()) {
                return;
            }
            if ($propia) {
                $pdo->rollBack();
            } elseif ($sp !== null) {
                $pdo->exec("ROLLBACK TO SAVEPOINT `$sp`");
            }
        };
        try {
            $resultado = $fn($pdo);
            if ($propia && $pdo->inTransaction()) {
                $pdo->commit();
            } elseif ($sp !== null && $pdo->inTransaction()) {
                $pdo->exec("RELEASE SAVEPOINT `$sp`");
            }
            return $resultado;
        } catch (TransaccionRevertida $r) {
            $deshacer();
            return $r->valor();
        } catch (Throwable $e) {
            $deshacer();
            throw $e;
        }
    }

    /**
     * Conexión de SOLO LECTURA a PostgreSQL (asistencia facial), creada una sola vez por petición.
     * Datos: lo que el admin guardó en «Conexiones» (tabla conexiones_externas); si no hay nada guardado,
     * config/postgres.php. Si no hay datos o Postgres no responde, devuelve null: el sistema de tickets
     * (MySQL) sigue funcionando igual.
     */
    public static function asistencia(): ?PDO
    {
        if (self::$asistenciaIntentada) {
            return self::$asistencia;
        }
        self::$asistenciaIntentada = true;
        $c = self::datosConexion(ConexionesExternas::ASISTENCIA_PG, 'postgres');
        if ($c === null) {
            return null; // sin configurar
        }
        // Cortacircuito: si falló hace menos de un minuto, no se vuelve a esperar en cada página.
        if (self::caidaReciente('asistencia')) {
            return null;
        }
        try {
            self::$asistencia = self::abrirPostgres($c, 3);
        } catch (Throwable $e) {
            RegistroErrores::registrar('Database: no se pudo conectar a PostgreSQL (asistencia)', $e);
            self::marcarCaida('asistencia');
            self::$asistencia = null;
        }
        return self::$asistencia;
    }

    /**
     * Conexión de SOLO LECTURA a la base de GLPI (inventario). null si no está configurada o no responde.
     */
    public static function glpi(): ?PDO
    {
        if (self::$glpiIntentada) {
            return self::$glpi;
        }
        self::$glpiIntentada = true;
        $c = self::datosConexion(ConexionesExternas::GLPI_DB, null);
        if ($c === null || self::caidaReciente('glpi')) {
            return null;
        }
        try {
            self::$glpi = self::abrirMysql($c, 3);
        } catch (Throwable $e) {
            RegistroErrores::registrar('Database: no se pudo conectar a la base de GLPI', $e);
            self::marcarCaida('glpi');
            self::$glpi = null;
        }
        return self::$glpi;
    }

    /** Olvida las conexiones externas abiertas y sus caídas (tras guardar datos nuevos en «Conexiones»). */
    public static function reiniciarExternas(): void
    {
        self::$asistencia = null; self::$asistenciaIntentada = false;
        self::$glpi = null; self::$glpiIntentada = false;
        foreach (['asistencia', 'glpi'] as $n) @unlink(self::archivoCaida($n));
    }

    /** Segundos que se deja de intentar una conexión externa después de que falla. */
    private const ESPERA_TRAS_CAIDA = 60;

    private static function archivoCaida(string $nombre): string
    {
        return dirname(__DIR__, 3) . '/storage/caida_' . $nombre . '.txt';
    }

    private static function caidaReciente(string $nombre): bool
    {
        $f = self::archivoCaida($nombre);
        return is_file($f) && (time() - (int)@file_get_contents($f)) < self::ESPERA_TRAS_CAIDA;
    }

    private static function marcarCaida(string $nombre): void
    {
        @file_put_contents(self::archivoCaida($nombre), (string)time(), LOCK_EX);
    }

    /**
     * Datos normalizados [host, port, dbname, user, password] de una conexión externa: primero la tabla
     * conexiones_externas; si no hay, el archivo config/<archivo>.php (solo Postgres).
     */
    private static function datosConexion(string $clave, ?string $archivo): ?array
    {
        $c = null;
        if (self::$instancia !== null || configCargar('database') !== null) {
            try { $c = ConexionesExternas::obtener(self::conexion(), $clave); } catch (Throwable $e) { $c = null; }
        }
        if (($c === null || empty($c['host'])) && $archivo !== null) {
            $f = configCargar($archivo);
            if ($f !== null) {
                $c = ['host' => $f['host'] ?? '', 'port' => $f['port'] ?? '', 'dbname' => $f['dbname'] ?? '',
                      'user' => $f['username'] ?? ($f['user'] ?? ''), 'password' => $f['password'] ?? ''];
            }
        }
        return ($c !== null && !empty($c['host']) && !empty($c['dbname'])) ? $c : null;
    }

    /** Abre PostgreSQL con los datos dados (también lo usa «Probar conexión»). Lanza excepción si falla. */
    public static function abrirPostgres(array $c, int $timeout = 5): PDO
    {
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('El servidor no tiene disponible la extensión pdo_pgsql.');
        }
        $port = (int)($c['port'] ?? 0) ?: 5432;
        return new PDO(
            "pgsql:host={$c['host']};port={$port};dbname={$c['dbname']}",
            (string)($c['user'] ?? ''), (string)($c['password'] ?? ''),
            // ATTR_TIMEOUT es el que pdo_pgsql convierte en connect_timeout (si no, espera 30 s por defecto)
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => $timeout]
        );
    }

    /** Abre MySQL/MariaDB (GLPI) con los datos dados. Lanza excepción si falla. */
    public static function abrirMysql(array $c, int $timeout = 5): PDO
    {
        $port = (int)($c['port'] ?? 0) ?: 3306;
        return new PDO(
            "mysql:host={$c['host']};port={$port};dbname={$c['dbname']};charset=utf8mb4",
            (string)($c['user'] ?? ''), (string)($c['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => $timeout]
        );
    }

    /**
     * Conexión al servidor MySQL de la aplicación SIN elegir base (mismas credenciales de config/database.php).
     * La usa la restauración de prueba de los respaldos para crear y borrar una base temporal.
     */
    public static function abrirServidor(): PDO
    {
        $c = configCargar('database') ?? [];
        return new PDO('mysql:host=' . ($c['host'] ?? 'localhost') . ';charset=utf8mb4', (string)($c['username'] ?? ''), (string)($c['password'] ?? ''),
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
