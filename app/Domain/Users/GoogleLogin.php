<?php
/**
 * GoogleLogin: relaciona un perfil de Google con un usuario YA existente.
 *
 *  - Si el `sub` de Google ya está vinculado → ese usuario.
 *  - Si no, el correo de Google (verificado) debe coincidir con users.correo
 *    y se crea el vínculo (primer ingreso con Google).
 *  - Nunca crea usuarios. Un usuario desactivado o bloqueado por intentos
 *    fallidos no entra por esta vía.
 */

final class GoogleLogin
{
    /** @return array{ok:bool,usuario:?array,error:?string} */
    public static function resolver(PDO $pdo, array $perfil): array
    {
        $no = static fn(string $m): array => ['ok' => false, 'usuario' => null, 'error' => $m];

        if (empty($perfil['email_verified'])) {
            return $no('Tu correo de Google no está verificado.');
        }

        $stmt = $pdo->prepare("SELECT u.* FROM usuario_google g JOIN users u ON u.id = g.id_usuario WHERE g.google_sub = ?");
        $stmt->execute([$perfil['sub']]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        $vincular = false;

        if (!$u) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE correo = ?");
            $stmt->execute([$perfil['email']]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                return $no('Esa cuenta de Google no está registrada en el sistema. Pide al administrador que te cree el usuario.');
            }
            // Si el usuario ya tiene OTRA cuenta de Google vinculada, no se le añade otra.
            $stmt = $pdo->prepare("SELECT 1 FROM usuario_google WHERE id_usuario = ?");
            $stmt->execute([$u['id']]);
            if ($stmt->fetchColumn()) {
                return $no('Esta cuenta ya está vinculada a otra cuenta de Google.');
            }
            $vincular = true;
        }

        if (empty($u['estado_activo'])) {
            return $no('Esta cuenta está deshabilitada.');
        }
        if (LoginThrottle::estado($pdo, (int)$u['id'])['bloqueado']) {
            return $no('Cuenta bloqueada por intentos fallidos. Restablece tu contraseña desde «¿Olvidaste tu contraseña?».');
        }

        if ($vincular) {
            $pdo->prepare("INSERT INTO usuario_google (id_usuario, google_sub, correo_google) VALUES (?, ?, ?)")
                ->execute([$u['id'], $perfil['sub'], $perfil['email']]);
            AuditoriaService::registrar($pdo, (int)$u['id'], 'users', (int)$u['id'], 'EDITAR', 'cuenta de Google vinculada');
        }
        return ['ok' => true, 'usuario' => $u, 'error' => null];
    }
}
