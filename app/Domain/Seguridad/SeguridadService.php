<?php
/**
 * Configuración y diagnóstico de seguridad de la información (ISO/IEC 27001, controles del Anexo A), todo
 * administrado por el admin: HTTPS obligatorio y HSTS, verificación en dos pasos obligatoria, retenciones
 * (bitácora e IP de reportes anónimos), antispam del QR, aviso de privacidad (Ley 1581 de 2012) y una
 * lista de verificación del estado real del servidor (usuario de base de datos, respaldos, clave de cifrado…).
 */
final class SeguridadService
{
    /** ¿La petición actual llegó por HTTPS? (también detrás de un proxy que avise con X-Forwarded-Proto). */
    public static function esHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /**
     * Transporte seguro (A.8.24): si el admin activó «Forzar HTTPS», una petición por http:// se redirige a
     * https:// (solo GET/HEAD: un POST por http se rechaza para no reenviar datos en claro). Con HSTS el
     * navegador recordará usar siempre HTTPS durante un año. Lo llama bootstrap.php en cada página web.
     */
    public static function aplicarTransporte(PDO $pdo): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) return;
        $https = self::esHttps();
        if (!$https && ConfigSistema::activo($pdo, 'seg_forzar_https')) {
            $host = (string)($_SERVER['HTTP_HOST'] ?? '');
            if (!preg_match('/^[A-Za-z0-9.-]+(:[0-9]{1,5})?$/', $host)) $host = 'localhost';
            $host = (string)preg_replace('/:80$/', '', $host);
            if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
                header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
            } else {
                http_response_code(403);
                echo 'Por seguridad este sistema solo acepta conexiones HTTPS.';
            }
            exit();
        }
        if ($https && ConfigSistema::activo($pdo, 'seg_hsts')) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /** Elimina la IP de los reportes anónimos más antiguos que la retención (minimización, Ley 1581). */
    public static function aplicarRetencionIp(PDO $pdo): int
    {
        $dias = max(30, ConfigSistema::entero($pdo, 'seg_retencion_ip_dias'));
        $st = $pdo->prepare('UPDATE tickets SET ip_origen = NULL WHERE ip_origen IS NOT NULL AND fecha_creacion < NOW() - INTERVAL ? DAY');
        $st->execute([$dias]);
        return $st->rowCount();
    }

    /**
     * Guarda la configuración de seguridad. HTTPS y HSTS solo se pueden activar entrando por HTTPS (si no,
     * el admin se dejaría por fuera del sistema). @return array{ok:bool,mensaje:string}
     */
    public static function guardarConfiguracion(PDO $pdo, array $e, int $idAdmin, bool $esHttps): array
    {
        $forzar = !empty($e['seg_forzar_https']) ? '1' : '0';
        $hsts = !empty($e['seg_hsts']) ? '1' : '0';
        if (($forzar === '1' || $hsts === '1') && !$esHttps) {
            return ['ok' => false, 'mensaje' => 'Para activar «Forzar HTTPS» o HSTS entra al sistema por https://. Primero instala el certificado en el servidor (ver la guía de abajo).'];
        }
        if ($hsts === '1' && $forzar !== '1') {
            return ['ok' => false, 'mensaje' => 'HSTS requiere que también esté activo «Forzar HTTPS».'];
        }
        $num = static fn(string $k, int $min, int $max): ?int => (isset($e[$k]) && ctype_digit((string)$e[$k]) && (int)$e[$k] >= $min && (int)$e[$k] <= $max) ? (int)$e[$k] : null;
        $retB = $num('seg_retencion_bitacora_dias', 90, 3650);
        $retI = $num('seg_retencion_ip_dias', 30, 1825);
        $qr = $num('seg_qr_max_por_hora', 1, 100);
        $resp = $num('seg_respaldos_conservar', 1, 100);
        if ($retB === null) return ['ok' => false, 'mensaje' => 'La retención de la bitácora debe estar entre 90 y 3650 días.'];
        if ($retI === null) return ['ok' => false, 'mensaje' => 'La retención de las IP debe estar entre 30 y 1825 días.'];
        if ($qr === null) return ['ok' => false, 'mensaje' => 'El límite de reportes por QR debe estar entre 1 y 100 por hora.'];
        if ($resp === null) return ['ok' => false, 'mensaje' => 'Los respaldos a conservar deben estar entre 1 y 100.'];
        $responsable = trim((string)($e['priv_responsable'] ?? ''));
        $correo = trim((string)($e['priv_correo'] ?? ''));
        if ($responsable === '' || mb_strlen($responsable) > 200) return ['ok' => false, 'mensaje' => 'Escribe el responsable del tratamiento de datos (máximo 200 caracteres).'];
        if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'mensaje' => 'El correo de contacto para datos personales no es válido.'];

        $nuevos = [
            'seg_forzar_https' => $forzar, 'seg_hsts' => $hsts, 'seg_mfa_obligatorio' => !empty($e['seg_mfa_obligatorio']) ? '1' : '0',
            'seg_qr_captcha' => !empty($e['seg_qr_captcha']) ? '1' : '0', 'seg_retencion_bitacora_dias' => (string)$retB,
            'seg_retencion_ip_dias' => (string)$retI, 'seg_qr_max_por_hora' => (string)$qr, 'seg_respaldos_conservar' => (string)$resp,
            'priv_responsable' => $responsable, 'priv_correo' => $correo,
        ];
        $antes = ConfigSistema::todos($pdo);
        $cambios = [];
        foreach ($nuevos as $k => $v) {
            if ((string)($antes[$k] ?? '') === $v) continue;
            if (!ConfigSistema::guardar($pdo, $k, $v, $idAdmin)) {
                return ['ok' => false, 'mensaje' => 'Falta la tabla config_sistema: vuelve a importar database/tickets_db.sql.'];
            }
            $cambios[] = $k . '=' . (mb_strlen($v) > 30 ? mb_substr($v, 0, 30) . '…' : $v);
        }
        if (!$cambios) return ['ok' => true, 'mensaje' => 'No había cambios que guardar.'];
        AuditoriaService::registrar($pdo, $idAdmin, 'config_sistema', null, 'EDITAR', 'seguridad: ' . implode(', ', $cambios));
        BitacoraAccesos::registrar($pdo, 'CONFIG_SEGURIDAD', $idAdmin, null, implode(', ', $cambios));
        return ['ok' => true, 'mensaje' => 'Configuración de seguridad guardada.'];
    }

    /**
     * Lista de verificación del estado de seguridad. Cada fila: control, estado (ok|alerta|riesgo),
     * detalle y qué hacer. @return list<array{control:string,estado:string,detalle:string,accion:string,iso:string}>
     */
    public static function diagnostico(PDO $pdo): array
    {
        $out = [];
        $add = static function (string $control, string $estado, string $detalle, string $accion, string $iso) use (&$out): void {
            $out[] = compact('control', 'estado', 'detalle', 'accion', 'iso');
        };
        $cfg = ConfigSistema::todos($pdo);

        // Transporte cifrado
        $https = self::esHttps();
        if ($https && $cfg['seg_forzar_https'] === '1') $add('Conexión cifrada (HTTPS)', 'ok', 'HTTPS obligatorio' . ($cfg['seg_hsts'] === '1' ? ' y HSTS activos.' : ' activo (HSTS apagado).'), $cfg['seg_hsts'] === '1' ? '' : 'Activa HSTS cuando el certificado esté estable.', 'A.8.24');
        elseif ($https) $add('Conexión cifrada (HTTPS)', 'alerta', 'Esta conexión usa HTTPS, pero no es obligatorio.', 'Activa «Forzar HTTPS» para que nadie entre por http://.', 'A.8.24');
        else $add('Conexión cifrada (HTTPS)', 'riesgo', 'El sistema se está usando por http://: las contraseñas viajan sin cifrar por la red.', 'Instala un certificado (Let\'s Encrypt o el institucional) y activa «Forzar HTTPS».', 'A.8.24');

        // Usuario de la base de datos
        $usuarioBd = ''; $grants = [];
        try {
            $usuarioBd = (string)$pdo->query('SELECT CURRENT_USER()')->fetchColumn();
            $grants = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $grants = [];
        }
        $todo = (bool)preg_grep('/ALL PRIVILEGES ON \*\.\*|GRANT .*SUPER|WITH GRANT OPTION/i', $grants);
        $cfgBd = configCargar('database') ?? [];
        if (stripos($usuarioBd, 'root@') === 0 || $todo) {
            $add('Usuario de la base de datos', 'riesgo', "La aplicación entra como «{$usuarioBd}» con todos los permisos del servidor.", 'Crea el usuario de permisos mínimos con tools/usuario_minimo_bd.sql y ponlo en config/database.php.', 'A.8.2 · A.8.18');
        } else {
            $add('Usuario de la base de datos', 'ok', "La aplicación usa «{$usuarioBd}» sin privilegios globales.", '', 'A.8.2');
        }
        if (($cfgBd['password'] ?? '') === '') {
            $add('Contraseña de la base de datos', 'riesgo', 'config/database.php no tiene contraseña.', 'Asigna una contraseña larga al usuario de MySQL y escríbela en config/database.php.', 'A.5.17');
        }

        // Segundo factor
        $sinMfa = MfaService::cuentasSinMfa($pdo);
        if (!MfaService::disponible($pdo)) $add('Verificación en dos pasos', 'riesgo', 'Faltan las columnas mfa_* en la tabla users.', 'Vuelve a importar database/tickets_db.sql (no borra nada).', 'A.8.5');
        elseif ($cfg['seg_mfa_obligatorio'] === '1') $add('Verificación en dos pasos', $sinMfa ? 'alerta' : 'ok', 'Obligatoria para admin y técnico.' . ($sinMfa ? ' ' . count($sinMfa) . ' cuenta(s) la configurarán en su próximo ingreso.' : ''), '', 'A.8.5');
        else $add('Verificación en dos pasos', 'alerta', count($sinMfa) . ' cuenta(s) de admin o técnico sin segundo factor.', 'Actívala como obligatoria en esta página.', 'A.8.5');

        // Respaldos
        $ultimo = RespaldoService::ultimo($pdo);
        if ($ultimo === null) $add('Respaldos', 'riesgo', 'No hay respaldos generados desde el sistema.', 'Genera uno en «Respaldos» y programa la tarea diaria (ver la guía).', 'A.8.13');
        else {
            $dias = (int)floor((time() - strtotime((string)$ultimo['fecha'])) / 86400);
            $verif = $ultimo['verificacion_ok'] === null ? 'sin verificar' : ((int)$ultimo['verificacion_ok'] === 1 ? 'verificado' : 'verificación fallida');
            $add('Respaldos', $dias > 7 || (int)($ultimo['verificacion_ok'] ?? 0) !== 1 ? 'alerta' : 'ok', "Último respaldo hace $dias día(s) ($verif).",
                 $dias > 7 ? 'Genera un respaldo nuevo y prueba restaurarlo.' : ((int)($ultimo['verificacion_ok'] ?? 0) !== 1 ? 'Usa «Verificar» para probar que se puede restaurar.' : ''), 'A.8.13');
        }

        // Integridad de la bitácora
        $v = BitacoraAccesos::verificar($pdo);
        $add('Bitácora de accesos', $v['ok'] ? 'ok' : 'riesgo', $v['mensaje'], $v['ok'] ? '' : 'Revisa quién tiene acceso directo a la base de datos.', 'A.8.15');

        // Clave de cifrado
        $clave = ConexionesExternas::rutaClave();
        if (!is_file($clave)) $add('Clave de cifrado', 'alerta', 'Todavía no se ha creado storage/clave_conexiones.key (se crea al guardar el primer secreto).', 'Inclúyela en los respaldos fuera del servidor: sin ella no se descifran los secretos.', 'A.8.24');
        else {
            $perm = fileperms($clave) & 0077;
            $add('Clave de cifrado', $perm === 0 || DIRECTORY_SEPARATOR === '\\' ? 'ok' : 'alerta', 'storage/clave_conexiones.key existe' . ($perm === 0 ? ' y solo la lee el servidor.' : '.'),
                 $perm === 0 || DIRECTORY_SEPARATOR === '\\' ? 'Guárdala aparte (bóveda institucional).' : 'En Linux: chmod 600 storage/clave_conexiones.key', 'A.8.24');
        }

        // Errores visibles y versión de PHP
        $add('Errores de PHP en pantalla', ini_get('display_errors') && ini_get('display_errors') !== 'Off' && ini_get('display_errors') !== '0' ? 'alerta' : 'ok',
             'display_errors = ' . (ini_get('display_errors') ?: 'Off') . '.', 'En producción deja display_errors=Off en php.ini (los errores van al log).', 'A.8.9');
        $add('Versión de PHP', version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'riesgo', 'PHP ' . PHP_VERSION . '.', version_compare(PHP_VERSION, '8.1.0', '>=') ? '' : 'Actualiza PHP: esta versión ya no recibe parches de seguridad.', 'A.8.8');

        // Cuentas
        try {
            $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND estado_activo = 1")->fetchColumn();
            $inactivas = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE estado_activo = 1 AND (ultimo_login IS NULL OR ultimo_login < NOW() - INTERVAL 120 DAY)")->fetchColumn();
        } catch (Throwable $e) {
            $admins = 0; $inactivas = 0;
        }
        $add('Cuentas de administrador', $admins <= 3 ? 'ok' : 'alerta', "$admins cuenta(s) admin activas.", $admins <= 3 ? '' : 'Deja solo las necesarias (mínimo privilegio).', 'A.8.2');
        $add('Cuentas sin uso', $inactivas === 0 ? 'ok' : 'alerta', "$inactivas cuenta(s) activas sin ingresar en 120 días.", $inactivas === 0 ? '' : 'Revisa en «Usuarios» si siguen vigentes y deshabilita las que no.', 'A.5.18');

        // Privacidad
        $add('Aviso de privacidad (Ley 1581)', $cfg['priv_correo'] !== '' ? 'ok' : 'alerta', 'Publicado en aviso_privacidad.php y enlazado en el reporte por QR y el inicio de sesión.',
             $cfg['priv_correo'] !== '' ? '' : 'Escribe el correo de contacto para que los titulares ejerzan sus derechos.', 'A.5.34');
        return $out;
    }
}
