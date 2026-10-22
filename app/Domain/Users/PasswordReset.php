<?php
/**
 * PasswordReset: recuperación de clave por correo.
 *
 *  1. solicitar(): genera un token aleatorio de un solo uso (vigencia 60 min),
 *     guarda SOLO su hash SHA-256 y entrega el token a $enviar (que arma el
 *     enlace y manda el correo). Nunca revela si el correo existe.
 *  2. validar(): ¿el token existe, no ha vencido y no se usó? → id de usuario.
 *  3. restablecer(): cambia la clave, quita password_temporal, invalida el
 *     token y desbloquea la cuenta (LoginThrottle).
 *
 * Límite anti-abuso: máximo 3 solicitudes por usuario por hora.
 */
require_once __DIR__ . '/../../Support/validacion.php';  // correoValido(), validarTexto()
require_once __DIR__ . '/../../Support/paginacion.php';

final class PasswordReset
{
    public const VIGENCIA_MINUTOS = 60;
    public const MAX_SOLICITUDES_HORA = 3;
    public const MIN_LONGITUD_CLAVE = PoliticaClave::MIN;

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @param callable(array,string):void $enviar recibe [usuario, token]
     * @return bool true si se generó un enlace (solo para pruebas/logs; la página no lo muestra)
     */
    public static function solicitar(PDO $pdo, string $correo, ?string $ip, callable $enviar): bool
    {
        $correo = trim($correo);
        if ($correo === '' || !correoValido($correo)) {
            return false;
        }
        $stmt = $pdo->prepare("SELECT id, correo, nombre_completo FROM users WHERE correo = ? AND estado_activo = 1");
        $stmt->execute([$correo]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            return false;
        }
        $idUsuario = (int)$u['id'];

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM recuperacion_clave WHERE id_usuario = ? AND creado_en > (NOW() - INTERVAL 1 HOUR)");
        $stmt->execute([$idUsuario]);
        if ((int)$stmt->fetchColumn() >= self::MAX_SOLICITUDES_HORA) {
            return false;
        }

        // Solo el enlace más reciente sirve.
        $pdo->prepare("UPDATE recuperacion_clave SET usado_en = NOW() WHERE id_usuario = ? AND usado_en IS NULL")->execute([$idUsuario]);

        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO recuperacion_clave (id_usuario, token_hash, expira_en, ip) VALUES (?, ?, NOW() + INTERVAL " . self::VIGENCIA_MINUTOS . " MINUTE, ?)")
            ->execute([$idUsuario, self::hashToken($token), $ip !== null ? substr($ip, 0, 45) : null]);

        $enviar($u, $token);
        return true;
    }

    /** @return int|null id del usuario dueño de un token vigente */
    public static function validar(PDO $pdo, string $token): ?int
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $stmt = $pdo->prepare(
            "SELECT r.id_usuario FROM recuperacion_clave r JOIN users u ON u.id = r.id_usuario
             WHERE r.token_hash = ? AND r.usado_en IS NULL AND r.expira_en > NOW() AND u.estado_activo = 1"
        );
        $stmt->execute([self::hashToken($token)]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /** @return array{ok:bool,error:?string} */
    public static function restablecer(PDO $pdo, string $token, string $nueva, string $confirmar): array
    {
        if (mb_strlen($nueva) < self::MIN_LONGITUD_CLAVE) {
            return ['ok' => false, 'error' => 'La nueva contraseña debe tener al menos ' . self::MIN_LONGITUD_CLAVE . ' caracteres.'];
        }
        if ($nueva !== $confirmar) {
            return ['ok' => false, 'error' => 'Las contraseñas no coinciden.'];
        }
        $idUsuario = self::validar($pdo, $token);
        if ($idUsuario === null) {
            return ['ok' => false, 'error' => 'El enlace no es válido o ya venció. Solicita uno nuevo.'];
        }
        if (($errClave = PoliticaClave::validar($nueva, PoliticaClave::contextoDe($pdo, $idUsuario))) !== null) {
            return ['ok' => false, 'error' => $errClave];
        }

        try {
            Database::transaccion(function (PDO $pdo) use ($token, $nueva, $idUsuario) {
                // Marca el token como usado primero: si dos peticiones compiten, solo una gana.
                $stmt = $pdo->prepare("UPDATE recuperacion_clave SET usado_en = NOW() WHERE token_hash = ? AND usado_en IS NULL AND expira_en > NOW()");
                $stmt->execute([self::hashToken($token)]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('token ya usado');
                }
                $pdo->prepare("UPDATE users SET password = ?, password_temporal = 0 WHERE id = ?")
                    ->execute([password_hash($nueva, PASSWORD_DEFAULT), $idUsuario]);
                $pdo->prepare("UPDATE recuperacion_clave SET usado_en = NOW() WHERE id_usuario = ? AND usado_en IS NULL")->execute([$idUsuario]);
            }, $pdo);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'El enlace no es válido o ya venció. Solicita uno nuevo.'];
        }
        LoginThrottle::desbloquear($pdo, $idUsuario);
        AuditoriaService::registrar($pdo, $idUsuario, 'users', $idUsuario, 'EDITAR', 'clave restablecida por correo');
        BitacoraAccesos::registrar($pdo, 'CLAVE_RESTABLECIDA', $idUsuario, null, 'por enlace enviado al correo');
        return ['ok' => true, 'error' => null];
    }
}
