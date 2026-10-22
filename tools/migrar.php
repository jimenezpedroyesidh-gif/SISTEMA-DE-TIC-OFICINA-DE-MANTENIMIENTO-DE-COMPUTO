<?php
/**
 * Migraciones versionadas: aplica en orden los archivos pendientes de database/migraciones/ (NNN_nombre.sql) y
 * los anota en la tabla `migraciones`, así cada cambio de esquema se aplica una sola vez y la aplicación no tiene
 * que preguntar en cada petición si una tabla o columna existe.
 *
 *   php tools/migrar.php            aplica las pendientes
 *   php tools/migrar.php --estado   solo muestra cuáles están aplicadas y cuáles faltan
 *
 * Reglas de cada archivo: una sentencia por `;` al final de línea, sin DELIMITER, y que se pueda repetir sin daño
 * (MODIFY, ADD COLUMN IF NOT EXISTS, CREATE TABLE IF NOT EXISTS…): en una instalación limpia se corren todas
 * después de importar database/tickets_db.sql. El cambio también se refleja en tickets_db.sql.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por consola.'); }
require __DIR__ . '/../bootstrap.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS `migraciones` (
  `version` varchar(10) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `aplicada_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$aplicadas = $pdo->query('SELECT version FROM migraciones')->fetchAll(PDO::FETCH_COLUMN);
$archivos = glob(dirname(__DIR__) . '/database/migraciones/[0-9][0-9][0-9]_*.sql') ?: [];
sort($archivos);
$soloEstado = in_array('--estado', $argv, true);
$pendientes = 0;

foreach ($archivos as $ruta) {
    [$version, $nombre] = explode('_', basename($ruta, '.sql'), 2);
    if (in_array($version, $aplicadas, true)) {
        echo "  aplicada   $version $nombre\n";
        continue;
    }
    $pendientes++;
    if ($soloEstado) {
        echo "  PENDIENTE  $version $nombre\n";
        continue;
    }
    $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($ruta));
    try {
        foreach (preg_split('/;\s*$/m', $sql) as $sentencia) {
            if (trim($sentencia) !== '') $pdo->exec($sentencia);
        }
        $pdo->prepare('INSERT INTO migraciones (version, nombre) VALUES (?, ?)')->execute([$version, $nombre]);
        echo "  OK         $version $nombre\n";
    } catch (PDOException $e) {
        fwrite(STDERR, "  ERROR      $version $nombre: " . $e->getMessage() . "\n");
        exit(1);
    }
}
echo $soloEstado ? "$pendientes pendiente(s).\n" : ($pendientes ? "Listo.\n" : "Nada pendiente.\n");
exit($soloEstado && $pendientes ? 1 : 0);
