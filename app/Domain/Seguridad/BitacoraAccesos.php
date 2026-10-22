<?php
/**
 * Bitácora de accesos y eventos de seguridad (ISO/IEC 27001 A.8.15 «Registro» y A.8.16 «Seguimiento»):
 * inicios y cierres de sesión, intentos fallidos, bloqueos, segundo factor, cambios de rol y de clave,
 * cambios de configuración de seguridad y respaldos. Solo se agregan filas: cada una guarda el hash de
 * la anterior (cadena SHA-256), así cualquier edición o borrado intermedio se detecta con verificar().
 * Registrar nunca interrumpe la operación principal (si la tabla falta, se escribe en el log de PHP).
 */
final class BitacoraAccesos
{
    public const EVENTOS = [
        'LOGIN_OK'            => 'Inicio de sesión',
        'LOGIN_FALLIDO'       => 'Intento de inicio fallido',
        'CUENTA_BLOQUEADA'    => 'Cuenta bloqueada por intentos',
        'LOGOUT'              => 'Cierre de sesión',
        'MFA_OK'              => 'Segundo factor correcto',
        'MFA_FALLIDO'         => 'Segundo factor incorrecto',
        'MFA_ACTIVADO'        => 'Verificación en dos pasos activada',
        'MFA_DESACTIVADO'     => 'Verificación en dos pasos desactivada',
        'MFA_REINICIADO'      => 'Verificación en dos pasos reiniciada por el admin',
        'CAMBIO_ROL'          => 'Cambio de rol',
        'CUENTA_ESTADO'       => 'Cuenta habilitada o deshabilitada',
        'CAMBIO_CLAVE'        => 'Cambio de contraseña',
        'CLAVE_RESTABLECIDA'  => 'Contraseña restablecida',
        'CONFIG_SEGURIDAD'    => 'Cambio en la configuración de seguridad',
        'RESPALDO_CREADO'     => 'Respaldo generado',
        'RESPALDO_DESCARGADO' => 'Respaldo descargado',
        'RESPALDO_VERIFICADO' => 'Respaldo verificado',
        'RESPALDO_ELIMINADO'  => 'Respaldo eliminado',
        'BITACORA_DEPURADA'   => 'Bitácora depurada por retención',
        'QR_BLOQUEADO'        => 'Reporte por QR bloqueado (antispam)',
    ];
    /** Eventos que se resaltan en rojo en la pantalla (posibles ataques o cambios sensibles). */
    public const CRITICOS = ['LOGIN_FALLIDO', 'CUENTA_BLOQUEADA', 'MFA_FALLIDO', 'MFA_DESACTIVADO', 'MFA_REINICIADO', 'CAMBIO_ROL', 'QR_BLOQUEADO', 'BITACORA_DEPURADA'];
    public const POR_PAGINA = 50;
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** IP del cliente (la que ve el servidor; detrás de un proxy, la del proxy). */
    public static function ipCliente(): ?string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    private static function hashFila(string $anterior, string $fecha, string $evento, ?int $idUsuario, ?string $correo, ?string $ip, ?string $agente, ?string $detalle): string
    {
        return hash('sha256', implode("\x1F", [$anterior, $fecha, $evento, (string)$idUsuario, (string)$correo, (string)$ip, (string)$agente, (string)$detalle]));
    }

    /** Agrega un evento. $correo: el escrito en el login (aunque la cuenta no exista). */
    public static function registrar(PDO $pdo, string $evento, ?int $idUsuario = null, ?string $correo = null, string $detalle = ''): void
    {
        $evento = isset(self::EVENTOS[$evento]) ? $evento : 'LOGIN_OK';
        $fecha = date('Y-m-d H:i:s');
        $ip = PHP_SAPI === 'cli' ? 'consola' : self::ipCliente();
        $agente = PHP_SAPI === 'cli' ? 'tarea programada' : mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $correo = $correo !== null ? mb_substr($correo, 0, 150) : null;
        $detalle = mb_substr($detalle, 0, 255);
        $bloqueo = false;
        try {
            // Un candado con nombre serializa las escrituras: dos filas nunca encadenan con el mismo «anterior».
            $bloqueo = (int)$pdo->query("SELECT GET_LOCK('ufps_bitacora_accesos', 3)")->fetchColumn() === 1;
            $anterior = $pdo->query('SELECT hash FROM bitacora_accesos ORDER BY id_bitacora DESC LIMIT 1')->fetchColumn();
            $anterior = is_string($anterior) && $anterior !== '' ? $anterior : self::GENESIS;
            $hash = self::hashFila($anterior, $fecha, $evento, $idUsuario, $correo, $ip, $agente, $detalle);
            $pdo->prepare('INSERT INTO bitacora_accesos (fecha, evento, id_usuario, correo, ip, agente, detalle, hash_anterior, hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$fecha, $evento, $idUsuario, $correo, $ip, $agente, $detalle !== '' ? $detalle : null, $anterior, $hash]);
        } catch (Throwable $e) {
            RegistroErrores::registrar('Bitácora de accesos: no se pudo registrar ' . $evento, $e);
        } finally {
            if ($bloqueo) {
                try { $pdo->query("SELECT RELEASE_LOCK('ufps_bitacora_accesos')"); } catch (Throwable $e) { /* sin candado no hay nada que soltar */ }
            }
        }
    }

    /** Filtros normalizados desde la URL. */
    public static function filtros(array $get): array
    {
        $fecha = static function ($v): string {
            $v = (string)$v;
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            return ($d && $d->format('Y-m-d') === $v) ? $v : '';
        };
        return [
            'desde' => $fecha($get['desde'] ?? ''),
            'hasta' => $fecha($get['hasta'] ?? ''),
            'evento' => isset(self::EVENTOS[$get['evento'] ?? '']) ? (string)$get['evento'] : '',
            'q' => mb_substr(trim((string)($get['q'] ?? '')), 0, 100),
            'pagina' => max(1, (int)($get['pagina'] ?? 1)),
        ];
    }

    /** @return array{filas:list<array<string,mixed>>,total:int,paginas:int} */
    public static function listar(PDO $pdo, array $f, bool $todo = false): array
    {
        $where = ' WHERE 1 = 1'; $p = [];
        if ($f['desde'] !== '') { $where .= ' AND b.fecha >= ?'; $p[] = $f['desde'] . ' 00:00:00'; }
        if ($f['hasta'] !== '') { $where .= ' AND b.fecha <= ?'; $p[] = $f['hasta'] . ' 23:59:59'; }
        if ($f['evento'] !== '') { $where .= ' AND b.evento = ?'; $p[] = $f['evento']; }
        if ($f['q'] !== '') {
            $where .= ' AND (b.correo LIKE ? OR b.ip LIKE ? OR b.detalle LIKE ? OR u.nombre_completo LIKE ?)';
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            array_push($p, $like, $like, $like, $like);
        }
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM bitacora_accesos b LEFT JOIN users u ON u.id = b.id_usuario' . $where);
            $st->execute($p);
            $total = (int)$st->fetchColumn();
            $sql = 'SELECT b.*, u.nombre_completo, u.role FROM bitacora_accesos b LEFT JOIN users u ON u.id = b.id_usuario' . $where . ' ORDER BY b.id_bitacora DESC';
            if (!$todo) $sql .= ' LIMIT ' . self::POR_PAGINA . ' OFFSET ' . (($f['pagina'] - 1) * self::POR_PAGINA);
            $st = $pdo->prepare($sql);
            $st->execute($p);
            return ['filas' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'paginas' => max(1, (int)ceil($total / self::POR_PAGINA))];
        } catch (Throwable $e) {
            return ['filas' => [], 'total' => 0, 'paginas' => 1];
        }
    }

    /** Resumen de las últimas 24 horas (para el panel de seguridad). */
    public static function resumen24h(PDO $pdo): array
    {
        try {
            $r = $pdo->query("SELECT evento, COUNT(*) AS n FROM bitacora_accesos WHERE fecha >= NOW() - INTERVAL 1 DAY GROUP BY evento")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            $r = [];
        }
        return ['ingresos' => (int)($r['LOGIN_OK'] ?? 0), 'fallidos' => (int)($r['LOGIN_FALLIDO'] ?? 0) + (int)($r['MFA_FALLIDO'] ?? 0),
                'bloqueos' => (int)($r['CUENTA_BLOQUEADA'] ?? 0), 'qr' => (int)($r['QR_BLOQUEADO'] ?? 0)];
    }

    /**
     * Recalcula la cadena completa. La primera fila que queda (tras una depuración) es el inicio válido.
     * @return array{ok:bool,revisadas:int,falla:?int,mensaje:string}
     */
    public static function verificar(PDO $pdo): array
    {
        try {
            $st = $pdo->query('SELECT * FROM bitacora_accesos ORDER BY id_bitacora');
        } catch (Throwable $e) {
            return ['ok' => false, 'revisadas' => 0, 'falla' => null, 'mensaje' => 'Falta la tabla bitacora_accesos: vuelve a importar database/tickets_db.sql.'];
        }
        $n = 0; $previo = null;
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $n++;
            $calc = self::hashFila((string)$r['hash_anterior'], (string)$r['fecha'], (string)$r['evento'], $r['id_usuario'] !== null ? (int)$r['id_usuario'] : null,
                                   $r['correo'], $r['ip'], $r['agente'], $r['detalle'] ?? '');
            if (!hash_equals($calc, (string)$r['hash']) || ($previo !== null && !hash_equals($previo, (string)$r['hash_anterior']))) {
                return ['ok' => false, 'revisadas' => $n, 'falla' => (int)$r['id_bitacora'],
                        'mensaje' => 'La cadena se rompe en el registro #' . (int)$r['id_bitacora'] . ': fue modificado o se borraron registros anteriores a él fuera del sistema.'];
            }
            $previo = (string)$r['hash'];
        }
        return ['ok' => true, 'revisadas' => $n, 'falla' => null, 'mensaje' => $n === 0 ? 'La bitácora está vacía.' : "Íntegra: $n registros verificados, ninguno fue alterado."];
    }

    /** Borra lo más antiguo que la retención configurada (mínimo 90 días) y deja constancia. */
    public static function depurar(PDO $pdo, int $idAdmin): array
    {
        $dias = max(90, ConfigSistema::entero($pdo, 'seg_retencion_bitacora_dias'));
        try {
            $st = $pdo->prepare('DELETE FROM bitacora_accesos WHERE fecha < NOW() - INTERVAL ? DAY');
            $st->execute([$dias]);
            $n = $st->rowCount();
        } catch (Throwable $e) {
            return ['ok' => false, 'mensaje' => 'No se pudo depurar la bitácora.'];
        }
        self::registrar($pdo, 'BITACORA_DEPURADA', $idAdmin, null, "$n registros con más de $dias días");
        return ['ok' => true, 'mensaje' => $n === 0 ? "No hay registros con más de $dias días." : "Se depuraron $n registros con más de $dias días."];
    }

    /** CSV (Excel) de la bitácora filtrada. */
    public static function csv(PDO $pdo, array $f): string
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['#', 'Fecha', 'Evento', 'Usuario', 'Rol', 'Correo escrito', 'IP', 'Navegador', 'Detalle', 'Hash'], ';', '"', '\\');
        foreach (self::listar($pdo, $f, true)['filas'] as $r) {
            fputcsv($out, [$r['id_bitacora'], $r['fecha'], self::EVENTOS[$r['evento']] ?? $r['evento'], (string)$r['nombre_completo'], (string)$r['role'],
                           (string)$r['correo'], (string)$r['ip'], (string)$r['agente'], (string)$r['detalle'], $r['hash']], ';', '"', '\\');
        }
        rewind($out);
        $csv = (string)stream_get_contents($out);
        fclose($out);
        return $csv;
    }
}
