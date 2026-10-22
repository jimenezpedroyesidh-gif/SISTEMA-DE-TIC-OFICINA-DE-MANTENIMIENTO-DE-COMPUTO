<?php
/**
 * Inicio de sesión con código + correo + contraseña. Se busca por correo (único) para contar los fallos de
 * esa cuenta; la verificación sigue siendo estricta: código Y correo deben ser del MISMO usuario, y además la
 * clave. Tras 3 fallos la cuenta se bloquea hasta que la persona restablezca su clave (LoginThrottle). Abrir
 * la sesión (iniciarSesionDeUsuario) lo hace el controlador.
 */

class LoginService
{
    public static function mensajeBloqueada(): string
    {
        return "Cuenta bloqueada por " . LoginThrottle::MAX_INTENTOS . " intentos fallidos. Restablece tu contraseña con el enlace que te enviamos al correo: usa «¿Olvidaste tu contraseña?».";
    }

    /** @return array{ok:bool,usuario:?array,error:string} */
    public static function intentar(PDO $pdo, string $codigo, string $correo, string $password, ?string $ip): array
    {
        $user = UserRepository::porCorreo($pdo, $correo);
        $parCorrecto = $user && $codigo !== '' && mb_strtolower((string)($user['codigo'] ?? '')) === mb_strtolower($codigo);
        $estado = $user ? LoginThrottle::estado($pdo, (int)$user['id']) : ['bloqueado' => false];

        if ($user && $estado['bloqueado']) {
            BitacoraAccesos::registrar($pdo, 'LOGIN_FALLIDO', (int)$user['id'], $correo, 'cuenta bloqueada');
            return ['ok' => false, 'usuario' => null, 'error' => self::mensajeBloqueada()];
        }
        if ($parCorrecto && empty($user['estado_activo'])) {
            BitacoraAccesos::registrar($pdo, 'LOGIN_FALLIDO', (int)$user['id'], $correo, 'cuenta deshabilitada');
            return ['ok' => false, 'usuario' => null, 'error' => "Esta cuenta está deshabilitada."];
        }
        if ($parCorrecto && password_verify($password, $user['password'])) {
            LoginThrottle::registrarExito($pdo, (int)$user['id']);
            return ['ok' => true, 'usuario' => $user, 'error' => ''];
        }

        $error = "Código, correo o contraseña incorrectos";
        if ($user) {
            $r = LoginThrottle::registrarFallo($pdo, (int)$user['id'], $ip);
            BitacoraAccesos::registrar($pdo, 'LOGIN_FALLIDO', (int)$user['id'], $correo, $parCorrecto ? 'contraseña incorrecta' : 'código y correo no coinciden');
            if ($r['bloqueado']) BitacoraAccesos::registrar($pdo, 'CUENTA_BLOQUEADA', (int)$user['id'], $correo, LoginThrottle::MAX_INTENTOS . ' intentos fallidos');
            $error = $r['bloqueado']
                ? self::mensajeBloqueada()
                : $error . ". Te " . ($r['restantes'] === 1 ? "queda 1 intento" : "quedan {$r['restantes']} intentos") . ".";
        } else {
            BitacoraAccesos::registrar($pdo, 'LOGIN_FALLIDO', null, $correo, 'correo no registrado');
        }
        return ['ok' => false, 'usuario' => null, 'error' => $error];
    }
}
