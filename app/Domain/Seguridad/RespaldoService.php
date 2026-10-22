<?php
/**
 * Respaldos cifrados (ISO/IEC 27001 A.8.13 «Copias de seguridad»): la base de datos completa (estructura,
 * datos y disparadores) y los archivos subidos (adjuntos, fotos y archivos de soluciones técnicas) en un .zip, cifrado con una frase que
 * escribe el admin y que el sistema NO guarda (Argon2id + XChaCha20-Poly1305 de libsodium). Sin la frase
 * el archivo no sirve a nadie: así se puede copiar a un disco externo o a la nube institucional.
 * «Verificar» lo descifra y prueba restaurarlo en una base temporal que luego se borra.
 */
final class RespaldoService
{
    private const MAGIA = "UFPSBK1\n";
    private const BLOQUE = 1048576; // tamaño de cada trozo cifrado (1 MiB)
    private const FIN = "\n-- @@FIN@@\n";
    public const EXTENSION = '.ufpsbk';

    public static function carpeta(): string
    {
        $dir = getenv('RESPALDOS_CARPETA') ?: dirname(__DIR__, 3) . '/storage/respaldos';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        return $dir;
    }

    public static function disponible(): ?string
    {
        if (!function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push') || !function_exists('sodium_crypto_pwhash')) {
            return 'El servidor no tiene disponible la extensión sodium (cifrado de respaldos). Pide a quien administra el servidor que la active.';
        }
        if (!class_exists('ZipArchive')) return 'El servidor no tiene disponible la extensión zip. Pide a quien administra el servidor que la active.';
        return null;
    }

    /** @return list<array<string,mixed>> */
    public static function listar(PDO $pdo): array
    {
        try {
            return $pdo->query('SELECT r.*, u.nombre_completo FROM respaldos r LEFT JOIN users u ON u.id = r.id_usuario ORDER BY r.fecha DESC, r.id_respaldo DESC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function ultimo(PDO $pdo): ?array
    {
        return self::listar($pdo)[0] ?? null;
    }

    private static function porId(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM respaldos WHERE id_respaldo = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Ruta segura del archivo de un respaldo (solo el nombre registrado, dentro de la carpeta). */
    public static function ruta(array $r): string
    {
        return self::carpeta() . '/' . basename((string)$r['archivo']);
    }

    /**
     * Genera un respaldo. @return array{ok:bool,mensaje:string,id?:int}
     */
    public static function generar(PDO $pdo, string $frase, ?int $idUsuario): array
    {
        if (($falta = self::disponible()) !== null) return ['ok' => false, 'mensaje' => $falta];
        if (mb_strlen($frase) < 12) return ['ok' => false, 'mensaje' => 'La frase de cifrado debe tener al menos 12 caracteres. Guárdala en un lugar seguro: sin ella no se puede restaurar.'];
        @set_time_limit(0);
        $tmpSql = (string)tempnam(sys_get_temp_dir(), 'ufpsql');
        $tmpZip = (string)tempnam(sys_get_temp_dir(), 'ufpzip');
        try {
            $conteo = self::volcarBase($pdo, $tmpSql);
            $zip = new ZipArchive();
            if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('no se pudo crear el zip temporal');
            $zip->addFile($tmpSql, 'base_datos.sql');
            $raiz = dirname(__DIR__, 3);
            $archivos = 0;
            foreach (['storage/adjuntos', 'storage/soluciones', 'public/assets/uploads'] as $rel) {
                if (!is_dir("$raiz/$rel")) continue;
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$raiz/$rel", FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if (!$f->isFile()) continue;
                    $zip->addFile($f->getPathname(), 'archivos/' . $rel . '/' . substr($f->getPathname(), strlen("$raiz/$rel") + 1));
                    $archivos++;
                }
            }
            $manifiesto = ['sistema' => institucion('sistema') . ' ' . institucion('sigla'), 'version' => 1, 'generado' => date('c'), 'base' => (string)$pdo->query('SELECT DATABASE()')->fetchColumn(),
                           'tablas' => count($conteo), 'filas' => array_sum($conteo), 'por_tabla' => $conteo, 'archivos' => $archivos];
            $zip->addFromString('manifiesto.json', (string)json_encode($manifiesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $zip->addFromString('LEEME.txt', "Respaldo del " . institucion('sistema') . ' ' . institucion('sigla') . ".\n1. Crea una base vacía e importa base_datos.sql (mysql -u usuario -p base < base_datos.sql).\n2. Copia la carpeta archivos/ sobre el proyecto (storage/adjuntos, storage/soluciones y public/assets/uploads).\n3. Copia también storage/clave_conexiones.key desde tu bóveda (no viaja en el respaldo).\n");
            $zip->close();

            $nombre = 'respaldo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . self::EXTENSION;
            $destino = self::carpeta() . '/' . $nombre;
            self::cifrar($tmpZip, $destino, $frase);
            @chmod($destino, 0600);
            $pdo->prepare('INSERT INTO respaldos (archivo, tamano, sha256, tablas, filas, adjuntos, id_usuario) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$nombre, (int)filesize($destino), (string)hash_file('sha256', $destino), count($conteo), array_sum($conteo), $archivos, $idUsuario]);
            $id = (int)$pdo->lastInsertId();
            BitacoraAccesos::registrar($pdo, 'RESPALDO_CREADO', $idUsuario, null, "$nombre · " . count($conteo) . " tablas · $archivos archivos");
            $podados = self::podar($pdo);
            return ['ok' => true, 'id' => $id, 'mensaje' => 'Respaldo cifrado generado (' . count($conteo) . ' tablas, ' . array_sum($conteo) . " filas, $archivos archivos)."
                . ($podados ? " Se borraron $podados respaldo(s) antiguo(s) según la retención." : '')];
        } catch (Throwable $e) {
            RegistroErrores::registrar('Respaldo: no se pudo generar', $e);
            return ['ok' => false, 'mensaje' => 'No se pudo generar el respaldo: ' . $e->getMessage()];
        } finally {
            @unlink($tmpSql);
            @unlink($tmpZip);
        }
    }

    /** Vuelca estructura, datos y disparadores. @return array<string,int> tabla => filas */
    private static function volcarBase(PDO $pdo, string $destino): array
    {
        $f = fopen($destino, 'wb');
        if ($f === false) throw new RuntimeException('no se pudo escribir el volcado temporal');
        fwrite($f, "-- Respaldo del " . institucion('sistema') . ' ' . institucion('sigla') . " · " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;" . self::FIN . "SET FOREIGN_KEY_CHECKS = 0;" . self::FIN);
        $conteo = [];
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $t) {
            $tabla = (string)$t[0];
            $q = '`' . str_replace('`', '``', $tabla) . '`';
            $crear = (string)$pdo->query("SHOW CREATE TABLE $q")->fetch(PDO::FETCH_NUM)[1];
            fwrite($f, "DROP TABLE IF EXISTS $q;" . self::FIN . $crear . ';' . self::FIN);
            // Las columnas GENERADAS (p.
            $colsReales = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA NOT LIKE '%GENERATED%'
                                         ORDER BY ORDINAL_POSITION");
            $colsReales->execute([$tabla]);
            $listaCols = implode(', ', array_map(static fn($c) => '`' . str_replace('`', '``', (string)$c) . '`', $colsReales->fetchAll(PDO::FETCH_COLUMN)));
            $st = $pdo->query('SELECT ' . ($listaCols !== '' ? $listaCols : '*') . " FROM $q");
            $n = 0; $lote = []; $cols = null;
            while ($fila = $st->fetch(PDO::FETCH_ASSOC)) {
                if ($cols === null) $cols = '(' . implode(', ', array_map(static fn($c) => '`' . str_replace('`', '``', (string)$c) . '`', array_keys($fila))) . ')';
                $lote[] = '(' . implode(', ', array_map(static fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $fila)) . ')';
                $n++;
                if (count($lote) === 100) { fwrite($f, "INSERT INTO $q $cols VALUES " . implode(', ', $lote) . ';' . self::FIN); $lote = []; }
            }
            if ($lote) fwrite($f, "INSERT INTO $q $cols VALUES " . implode(', ', $lote) . ';' . self::FIN);
            $conteo[$tabla] = $n;
        }
        try {
            $disparadores = $pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $disparadores = []; // sin permiso TRIGGER: se recrean al importar database/tickets_db.sql
        }
        foreach ($disparadores as $tr) {
            $nombre = '`' . str_replace('`', '``', (string)$tr['Trigger']) . '`';
            try {
                $def = (string)($pdo->query("SHOW CREATE TRIGGER $nombre")->fetch(PDO::FETCH_ASSOC)['SQL Original Statement'] ?? '');
            } catch (Throwable $e) {
                $def = '';
            }
            $def = (string)preg_replace('/\sDEFINER\s*=\s*`[^`]*`@`[^`]*`/i', '', $def); // que se pueda crear con cualquier usuario
            if ($def !== '') fwrite($f, "DROP TRIGGER IF EXISTS $nombre;" . self::FIN . $def . ';' . self::FIN);
        }
        fwrite($f, 'SET FOREIGN_KEY_CHECKS = 1;' . self::FIN);
        fclose($f);
        return $conteo;
    }

    private static function clave(string $frase, string $sal): string
    {
        return sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $frase, $sal,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }

    /** Cifra un archivo por trozos (sirve para respaldos grandes sin cargarlos completos en memoria). */
    public static function cifrar(string $origen, string $destino, string $frase): void
    {
        $in = fopen($origen, 'rb');
        $out = fopen($destino, 'wb');
        if ($in === false || $out === false) throw new RuntimeException('no se pudo abrir el archivo para cifrar');
        $sal = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $k = self::clave($frase, $sal);
        [$estado, $cabecera] = sodium_crypto_secretstream_xchacha20poly1305_init_push($k);
        fwrite($out, self::MAGIA . $sal . $cabecera);
        $trozo = (string)fread($in, self::BLOQUE);
        do {
            $siguiente = feof($in) ? '' : (string)fread($in, self::BLOQUE);
            $ultimo = $siguiente === '' && feof($in);
            $c = sodium_crypto_secretstream_xchacha20poly1305_push($estado, $trozo, '', $ultimo ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            fwrite($out, pack('N', strlen($c)) . $c);
            $trozo = $siguiente;
        } while (!$ultimo);
        fclose($in);
        fclose($out);
        sodium_memzero($k);
    }

    /** Descifra. Devuelve false si la frase no es la correcta o el archivo fue alterado/está incompleto. */
    public static function descifrar(string $origen, string $destino, string $frase): bool
    {
        $in = @fopen($origen, 'rb');
        if ($in === false) return false;
        $magia = fread($in, strlen(self::MAGIA));
        $sal = fread($in, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $cab = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        if ($magia !== self::MAGIA || strlen((string)$sal) !== SODIUM_CRYPTO_PWHASH_SALTBYTES || strlen((string)$cab) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            fclose($in);
            return false;
        }
        $k = self::clave($frase, (string)$sal);
        $estado = sodium_crypto_secretstream_xchacha20poly1305_init_pull((string)$cab, $k);
        sodium_memzero($k);
        $out = fopen($destino, 'wb');
        $final = false;
        while (!$final && !feof($in)) {
            $lenBin = fread($in, 4);
            if ($lenBin === false || strlen($lenBin) !== 4) break;
            $len = (int)unpack('N', $lenBin)[1];
            if ($len <= 0 || $len > self::BLOQUE + 64) break;
            $c = (string)fread($in, $len);
            $r = sodium_crypto_secretstream_xchacha20poly1305_pull($estado, $c);
            if ($r === false) break;
            fwrite($out, $r[0]);
            $final = $r[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }
        fclose($in);
        fclose($out);
        if (!$final) @unlink($destino);
        return $final;
    }

    /**
     * Verifica un respaldo: integridad del archivo (SHA-256), que la frase lo descifre, que el contenido esté
     * completo y, si el usuario de la base puede crear una base temporal, una restauración real de prueba.
     */
    public static function verificar(PDO $pdo, int $id, string $frase, ?int $idUsuario): array
    {
        if (($falta = self::disponible()) !== null) return ['ok' => false, 'mensaje' => $falta];
        $r = self::porId($pdo, $id);
        if ($r === null) return ['ok' => false, 'mensaje' => 'Ese respaldo no existe.'];
        $ruta = self::ruta($r);
        if (!is_file($ruta)) return self::resultado($pdo, $id, false, 'El archivo ya no está en el servidor.', $idUsuario);
        if (!hash_equals((string)$r['sha256'], (string)hash_file('sha256', $ruta))) return self::resultado($pdo, $id, false, 'El archivo fue modificado: su huella SHA-256 no coincide.', $idUsuario);
        @set_time_limit(0);
        $tmpZip = (string)tempnam(sys_get_temp_dir(), 'ufpver');
        try {
            if (!self::descifrar($ruta, $tmpZip, $frase)) return ['ok' => false, 'mensaje' => 'La frase no es correcta (o el archivo está dañado). No se registró como fallido.'];
            $zip = new ZipArchive();
            if ($zip->open($tmpZip) !== true) return self::resultado($pdo, $id, false, 'El contenido descifrado no es un zip válido.', $idUsuario);
            $man = json_decode((string)$zip->getFromName('manifiesto.json'), true);
            $sql = $zip->getFromName('base_datos.sql');
            $zip->close();
            if (!is_array($man) || !is_string($sql)) return self::resultado($pdo, $id, false, 'Faltan base_datos.sql o manifiesto.json dentro del respaldo.', $idUsuario);
            $prueba = self::restaurarDePrueba($pdo, $sql, (array)($man['por_tabla'] ?? []));
            return self::resultado($pdo, $id, $prueba['ok'], $prueba['mensaje'], $idUsuario);
        } finally {
            @unlink($tmpZip);
        }
    }

    /** Ejecuta el volcado en una base temporal (se borra al final) y compara las filas de cada tabla. */
    private static function restaurarDePrueba(PDO $pdo, string $sql, array $esperado): array
    {
        $base = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $temporal = substr(preg_replace('/[^A-Za-z0-9_]/', '', $base), 0, 40) . '_prueba_restauracion';
        try {
            $otra = Database::abrirServidor();
            $otra->exec("CREATE DATABASE IF NOT EXISTS `$temporal` CHARACTER SET utf8mb4");
            $otra->exec("USE `$temporal`");
        } catch (Throwable $e) {
            return ['ok' => true, 'mensaje' => 'Archivo íntegro y descifrable (' . count($esperado) . ' tablas). No se probó la restauración: el usuario de la base no puede crear una base temporal (normal con permisos mínimos; pruébala en otro servidor).'];
        }
        try {
            foreach (explode(self::FIN, $sql) as $sentencia) {
                $sentencia = trim($sentencia);
                if ($sentencia === '' || strpos($sentencia, '--') === 0 && strpos($sentencia, "\n") === false) continue;
                $otra->exec($sentencia);
            }
            $malas = [];
            foreach ($esperado as $tabla => $n) {
                $real = (int)$otra->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', (string)$tabla) . '`')->fetchColumn();
                if ($real !== (int)$n) $malas[] = "$tabla ($real de $n)";
            }
            return $malas
                ? ['ok' => false, 'mensaje' => 'La restauración de prueba no coincide en: ' . implode(', ', array_slice($malas, 0, 5))]
                : ['ok' => true, 'mensaje' => 'Restauración de prueba correcta: ' . count($esperado) . ' tablas y ' . array_sum($esperado) . ' filas idénticas (base temporal ya borrada).'];
        } catch (Throwable $e) {
            return ['ok' => false, 'mensaje' => 'La restauración de prueba falló: ' . mb_substr($e->getMessage(), 0, 150)];
        } finally {
            try { $otra->exec("DROP DATABASE IF EXISTS `$temporal`"); } catch (Throwable $e) { /* se intenta borrar siempre */ }
        }
    }

    private static function resultado(PDO $pdo, int $id, bool $ok, string $mensaje, ?int $idUsuario): array
    {
        $pdo->prepare('UPDATE respaldos SET verificado_en = NOW(), verificacion_ok = ?, verificacion_detalle = ? WHERE id_respaldo = ?')
            ->execute([$ok ? 1 : 0, mb_substr($mensaje, 0, 255), $id]);
        BitacoraAccesos::registrar($pdo, 'RESPALDO_VERIFICADO', $idUsuario, null, "#$id " . ($ok ? 'correcto' : 'con fallas'));
        return ['ok' => $ok, 'mensaje' => $mensaje];
    }

    /** Envía el archivo cifrado al navegador (lo llama el controlador; termina la petición). */
    public static function descargar(PDO $pdo, int $id, int $idUsuario): ?string
    {
        $r = self::porId($pdo, $id);
        if ($r === null || !is_file(self::ruta($r))) return 'Ese respaldo ya no está disponible.';
        BitacoraAccesos::registrar($pdo, 'RESPALDO_DESCARGADO', $idUsuario, null, (string)$r['archivo']);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename((string)$r['archivo']) . '"');
        header('Content-Length: ' . (int)filesize(self::ruta($r)));
        header('X-Content-Type-Options: nosniff');
        readfile(self::ruta($r));
        exit();
    }

    public static function eliminar(PDO $pdo, int $id, int $idUsuario): array
    {
        $r = self::porId($pdo, $id);
        if ($r === null) return ['ok' => false, 'mensaje' => 'Ese respaldo no existe.'];
        @unlink(self::ruta($r));
        $pdo->prepare('DELETE FROM respaldos WHERE id_respaldo = ?')->execute([$id]);
        BitacoraAccesos::registrar($pdo, 'RESPALDO_ELIMINADO', $idUsuario, null, (string)$r['archivo']);
        return ['ok' => true, 'mensaje' => 'Respaldo eliminado.'];
    }

    /** Conserva solo los N más recientes (configurable). Devuelve cuántos borró. */
    private static function podar(PDO $pdo): int
    {
        $conservar = max(1, ConfigSistema::entero($pdo, 'seg_respaldos_conservar'));
        $n = 0;
        foreach (array_slice(self::listar($pdo), $conservar) as $r) {
            @unlink(self::ruta($r));
            $pdo->prepare('DELETE FROM respaldos WHERE id_respaldo = ?')->execute([(int)$r['id_respaldo']]);
            $n++;
        }
        return $n;
    }
}
