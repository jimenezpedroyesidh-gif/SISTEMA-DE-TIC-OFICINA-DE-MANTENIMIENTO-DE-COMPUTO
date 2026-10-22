<?php
/**
 * UserAccount
 * ====================
 * Operaciones sobre la cuenta propia del usuario. Por ahora: cambio de contraseña con verificación de la
 * actual.
 */

final class UserAccount
{
    public const MIN_LONGITUD_CLAVE = PoliticaClave::MIN;

    /** @return array{ok:bool,error:?string} */
    public static function cambiarPropiaClave(PDO $pdo, int $idUsuario, string $claveActual, string $claveNueva): array
    {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id=?");
        $stmt->execute([$idUsuario]);
        $hash = $stmt->fetchColumn();

        if ($claveActual === '' || !password_verify($claveActual, (string)$hash)) {
            return ['ok' => false, 'error' => 'La contraseña actual no es correcta.'];
        }
        if (($errClave = PoliticaClave::validar($claveNueva, PoliticaClave::contextoDe($pdo, $idUsuario))) !== null) {
            return ['ok' => false, 'error' => $errClave];
        }
        $stmt = $pdo->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->execute([password_hash($claveNueva, PASSWORD_DEFAULT), $idUsuario]);
        AuditoriaService::registrar($pdo, $idUsuario, 'users', $idUsuario, 'EDITAR', 'cambio de contraseña propia');
        BitacoraAccesos::registrar($pdo, 'CAMBIO_CLAVE', $idUsuario, null, 'cambio de contraseña propia');
        return ['ok' => true, 'error' => null];
    }

    /**
     * Se exige SIEMPRE la clave actual (una sesión abierta un momento no basta para tomar la cuenta). Orden
     * de las validaciones: actual → política (PoliticaClave) → confirmación. Al guardar quita la marca de
     * clave temporal y deja auditoría.
     *
     * @return array{ok:bool,error:?string}
     */
    public static function cambiarClaveDesdeSesion(PDO $pdo, int $idUsuario, string $claveActual, string $nueva, string $confirmar): array
    {
        if ($claveActual === '' || !password_verify($claveActual, UserRepository::claveHash($pdo, $idUsuario))) {
            return ['ok' => false, 'error' => 'La contraseña actual no es correcta.'];
        }
        if (($errClave = PoliticaClave::validar($nueva, PoliticaClave::contextoDe($pdo, $idUsuario))) !== null) {
            return ['ok' => false, 'error' => $errClave];
        }
        if ($nueva !== $confirmar) {
            return ['ok' => false, 'error' => 'Las contraseñas no coinciden.'];
        }
        UserRepository::guardarClavePropia($pdo, $idUsuario, password_hash($nueva, PASSWORD_DEFAULT));
        AuditoriaService::registrar($pdo, $idUsuario, 'users', $idUsuario, 'EDITAR', 'cambio de contraseña propia');
        BitacoraAccesos::registrar($pdo, 'CAMBIO_CLAVE', $idUsuario, null, 'cambio de contraseña propia');
        return ['ok' => true, 'error' => null];
    }
}
