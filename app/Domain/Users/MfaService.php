<?php
/**
 * Verificación en dos pasos (ISO/IEC 27001 A.8.5 «Autenticación segura») para las cuentas con más
 * privilegios: admin y técnico. Tras la contraseña (o Google) se pide el código de 6 dígitos de una app
 * autenticadora (TOTP) o uno de los 8 códigos de recuperación de un solo uso. El secreto se guarda
 * cifrado (AES-256-GCM, la misma clave de storage/ que las conexiones); los códigos de recuperación,
 * solo como hash. El admin decide si es obligatoria y puede reiniciarla a quien pierda el celular.
 */
final class MfaService
{
    public const ROLES = [Rol::ADMIN, Rol::TECNICO];
    public const N_RECUPERACION = 8;

    public static function aplica(string $rol): bool
    {
        return in_array($rol, self::ROLES, true);
    }

    /** ¿Ya existen las columnas mfa_* en users? */
    public static function disponible(PDO $pdo): bool
    {
        static $hay = null;
        if ($hay === null) {
            try {
                $pdo->query('SELECT mfa_activo FROM users LIMIT 1');
                $hay = true;
            } catch (Throwable $e) {
                $hay = false;
            }
        }
        return $hay;
    }

    /** @return array{activo:bool,activado_en:?string,restantes:int} */
    public static function estado(PDO $pdo, int $idUsuario): array
    {
        if (!self::disponible($pdo)) return ['activo' => false, 'activado_en' => null, 'restantes' => 0];
        $st = $pdo->prepare('SELECT mfa_activo, mfa_activado_en, mfa_recuperacion FROM users WHERE id = ?');
        $st->execute([$idUsuario]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $rec = json_decode((string)($r['mfa_recuperacion'] ?? ''), true);
        return ['activo' => (int)($r['mfa_activo'] ?? 0) === 1, 'activado_en' => $r['mfa_activado_en'] ?? null,
                'restantes' => is_array($rec) ? count((array)($rec['codigos'] ?? [])) : 0];
    }

    /** Códigos de recuperación legibles: XXXX-XXXX sin caracteres ambiguos. @return list<string> */
    private static function nuevosCodigos(): array
    {
        $alf = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = [];
        for ($i = 0; $i < self::N_RECUPERACION; $i++) {
            $c = '';
            for ($j = 0; $j < 8; $j++) $c .= $alf[random_int(0, strlen($alf) - 1)];
            $out[] = substr($c, 0, 4) . '-' . substr($c, 4);
        }
        return $out;
    }

    private static function normalizarCodigo(string $c): string
    {
        return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $c));
    }

    /**
     * Confirma la inscripción: el código debe corresponder al secreto mostrado en pantalla.
     * @return list<string>|null códigos de recuperación (se muestran UNA vez) o null si el código no es válido
     */
    public static function confirmarInscripcion(PDO $pdo, int $idUsuario, string $secreto, string $codigo): ?array
    {
        $contador = Totp::verificar($secreto, $codigo);
        if ($contador === null) return null;
        $codigos = self::nuevosCodigos();
        $hashes = array_map(static fn($c) => password_hash(self::normalizarCodigo($c), PASSWORD_DEFAULT), $codigos);
        $pdo->prepare('UPDATE users SET mfa_activo = 1, mfa_secreto = ?, mfa_recuperacion = ?, mfa_activado_en = NOW() WHERE id = ?')
            ->execute([ConexionesExternas::cifrar($secreto), json_encode(['codigos' => $hashes, 'ultimo' => $contador]), $idUsuario]);
        AuditoriaService::registrar($pdo, $idUsuario, 'users', $idUsuario, 'EDITAR', 'verificación en dos pasos activada');
        BitacoraAccesos::registrar($pdo, 'MFA_ACTIVADO', $idUsuario);
        return $codigos;
    }

    /**
     * Valida el segundo factor: código de la app (una sola vez cada uno) o código de recuperación (se gasta).
     * @return string|null 'app' | 'recuperacion' si es correcto; null si no
     */
    public static function verificar(PDO $pdo, int $idUsuario, string $codigo): ?string
    {
        if (!self::disponible($pdo)) return null;
        $st = $pdo->prepare('SELECT mfa_secreto, mfa_recuperacion FROM users WHERE id = ? AND mfa_activo = 1');
        $st->execute([$idUsuario]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $rec = json_decode((string)$r['mfa_recuperacion'], true);
        $rec = is_array($rec) ? $rec + ['codigos' => [], 'ultimo' => 0] : ['codigos' => [], 'ultimo' => 0];

        $soloDigitos = (string)preg_replace('/\D/', '', $codigo);
        if (strlen($soloDigitos) === Totp::DIGITOS && strlen(trim($codigo)) <= Totp::DIGITOS + 2) {
            $secreto = ConexionesExternas::descifrar((string)$r['mfa_secreto']);
            $contador = Totp::verificar($secreto, $soloDigitos);
            if ($contador === null || $contador <= (int)$rec['ultimo']) return null; // un código ya usado no vuelve a servir
            $rec['ultimo'] = $contador;
            $pdo->prepare('UPDATE users SET mfa_recuperacion = ? WHERE id = ?')->execute([json_encode($rec), $idUsuario]);
            return 'app';
        }
        $norm = self::normalizarCodigo($codigo);
        if (strlen($norm) !== 8) return null;
        foreach ((array)$rec['codigos'] as $i => $hash) {
            if (password_verify($norm, (string)$hash)) {
                unset($rec['codigos'][$i]);
                $rec['codigos'] = array_values($rec['codigos']);
                $pdo->prepare('UPDATE users SET mfa_recuperacion = ? WHERE id = ?')->execute([json_encode($rec), $idUsuario]);
                return 'recuperacion';
            }
        }
        return null;
    }

    /** La propia persona la desactiva (con un código válido). No se permite si el admin la hizo obligatoria. */
    public static function desactivar(PDO $pdo, int $idUsuario, string $codigo): array
    {
        if (ConfigSistema::activo($pdo, 'seg_mfa_obligatorio')) {
            return ['ok' => false, 'mensaje' => 'La verificación en dos pasos es obligatoria para tu rol: no se puede desactivar.'];
        }
        if (self::verificar($pdo, $idUsuario, $codigo) === null) {
            BitacoraAccesos::registrar($pdo, 'MFA_FALLIDO', $idUsuario, null, 'al intentar desactivarla');
            return ['ok' => false, 'mensaje' => 'El código no es válido.'];
        }
        self::limpiar($pdo, $idUsuario);
        AuditoriaService::registrar($pdo, $idUsuario, 'users', $idUsuario, 'EDITAR', 'verificación en dos pasos desactivada');
        BitacoraAccesos::registrar($pdo, 'MFA_DESACTIVADO', $idUsuario);
        return ['ok' => true, 'mensaje' => 'Verificación en dos pasos desactivada.'];
    }

    /** El admin la reinicia (la persona perdió el celular): la deberá configurar de nuevo al entrar. */
    public static function reiniciar(PDO $pdo, int $idUsuario, int $idAdmin): array
    {
        if (!self::disponible($pdo)) return ['ok' => false, 'mensaje' => 'Falta actualizar la base de datos (columnas mfa_* de users).'];
        self::limpiar($pdo, $idUsuario);
        AuditoriaService::registrar($pdo, $idAdmin, 'users', $idUsuario, 'EDITAR', 'verificación en dos pasos reiniciada por el admin');
        BitacoraAccesos::registrar($pdo, 'MFA_REINICIADO', $idUsuario, null, 'por el admin #' . $idAdmin);
        return ['ok' => true, 'mensaje' => 'Se reinició la verificación en dos pasos: la persona la configurará de nuevo en su próximo ingreso.'];
    }

    private static function limpiar(PDO $pdo, int $idUsuario): void
    {
        $pdo->prepare('UPDATE users SET mfa_activo = 0, mfa_secreto = NULL, mfa_recuperacion = NULL, mfa_activado_en = NULL WHERE id = ?')->execute([$idUsuario]);
    }

    /** Admin/técnicos activos sin verificación en dos pasos (panel de seguridad). */
    public static function cuentasSinMfa(PDO $pdo): array
    {
        if (!self::disponible($pdo)) return [];
        return $pdo->query("SELECT id, nombre_completo, role FROM users WHERE estado_activo = 1 AND role IN ('admin','tecnico') AND mfa_activo = 0 ORDER BY role, nombre_completo")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Decide qué falta tras validar la contraseña/Google: '' (nada), 'verificar' (pedir código) o
     * 'inscribir' (debe configurarla porque el admin la hizo obligatoria).
     */
    public static function pasoPendiente(PDO $pdo, array $usuario): string
    {
        if (!self::aplica((string)($usuario['role'] ?? '')) || !self::disponible($pdo)) return '';
        if (self::estado($pdo, (int)$usuario['id'])['activo']) return 'verificar';
        return ConfigSistema::activo($pdo, 'seg_mfa_obligatorio') ? 'inscribir' : '';
    }
}
