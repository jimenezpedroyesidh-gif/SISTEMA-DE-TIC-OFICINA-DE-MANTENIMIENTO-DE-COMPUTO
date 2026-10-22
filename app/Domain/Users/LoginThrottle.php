<?php
/**
 * LoginThrottle: bloqueo de cuenta tras 3 intentos fallidos de login.
 *
 *  - Cada fallo (código/correo/clave incorrectos) suma 1 al contador del usuario.
 *  - Al 3.er fallo la cuenta queda bloqueada: no entra ni con la clave correcta.
 *  - Se desbloquea al restablecer la clave por correo (PasswordReset) o por un admin.
 *  - Un login correcto pone el contador en 0.
 */

final class LoginThrottle
{
    public const MAX_INTENTOS = 3;

    /** @return array{intentos:int,bloqueado:bool} */
    public static function estado(PDO $pdo, int $idUsuario): array
    {
        try {
            $stmt = $pdo->prepare("SELECT intentos_fallidos, bloqueado FROM usuario_bloqueo WHERE id_usuario = ?");
            $stmt->execute([$idUsuario]);
            $f = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            RegistroErrores::registrar('LoginThrottle::estado', $e);
            return ['intentos' => 0, 'bloqueado' => false];
        }
        return $f ? ['intentos' => (int)$f['intentos_fallidos'], 'bloqueado' => (bool)$f['bloqueado']] : ['intentos' => 0, 'bloqueado' => false];
    }

    /**
     * Registra un intento fallido. @return array{intentos:int,bloqueado:bool,restantes:int}
     */
    public static function registrarFallo(PDO $pdo, int $idUsuario, ?string $ip = null): array
    {
        try {
            $pdo->prepare(
                "INSERT INTO usuario_bloqueo (id_usuario, intentos_fallidos, ultimo_intento, ultima_ip)
                 VALUES (?, 1, NOW(), ?)
                 ON DUPLICATE KEY UPDATE
                    intentos_fallidos = intentos_fallidos + 1,
                    ultimo_intento = NOW(),
                    ultima_ip = VALUES(ultima_ip),
                    bloqueado_en = IF(bloqueado = 0 AND intentos_fallidos >= " . self::MAX_INTENTOS . ", NOW(), bloqueado_en),
                    bloqueado = IF(intentos_fallidos >= " . self::MAX_INTENTOS . ", 1, bloqueado)"
            )->execute([$idUsuario, $ip !== null ? substr($ip, 0, 45) : null]);
        } catch (PDOException $e) {
            RegistroErrores::registrar('LoginThrottle::registrarFallo', $e);
            return ['intentos' => 0, 'bloqueado' => false, 'restantes' => self::MAX_INTENTOS];
        }
        $e = self::estado($pdo, $idUsuario);
        if ($e['bloqueado']) {
            AuditoriaService::registrar($pdo, $idUsuario, 'users', $idUsuario, 'EDITAR', 'bloqueo de login: ' . self::MAX_INTENTOS . ' intentos fallidos' . ($ip ? " desde $ip" : ''));
        }
        return $e + ['restantes' => max(0, self::MAX_INTENTOS - $e['intentos'])];
    }

    /** Login correcto: el contador vuelve a 0. */
    public static function registrarExito(PDO $pdo, int $idUsuario): void
    {
        try {
            $pdo->prepare("UPDATE usuario_bloqueo SET intentos_fallidos = 0 WHERE id_usuario = ? AND bloqueado = 0")->execute([$idUsuario]);
        } catch (PDOException $e) {
            RegistroErrores::registrar('LoginThrottle::registrarExito', $e);
        }
    }

    /** Quita el bloqueo y reinicia el contador. @return bool true si había algo que limpiar. */
    public static function desbloquear(PDO $pdo, int $idUsuario, ?int $idQuienDesbloquea = null): bool
    {
        try {
            $stmt = $pdo->prepare("UPDATE usuario_bloqueo SET intentos_fallidos = 0, bloqueado = 0, bloqueado_en = NULL WHERE id_usuario = ? AND (bloqueado = 1 OR intentos_fallidos > 0)");
            $stmt->execute([$idUsuario]);
            $hubo = $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            RegistroErrores::registrar('LoginThrottle::desbloquear', $e);
            return false;
        }
        if ($hubo) {
            AuditoriaService::registrar($pdo, $idQuienDesbloquea ?? $idUsuario, 'users', $idUsuario, 'EDITAR', $idQuienDesbloquea ? 'desbloqueo de login por admin' : 'desbloqueo de login al restablecer la clave');
        }
        return $hubo;
    }
}
