<?php
/**
 * Cliente OAuth 2.0 / OpenID Connect de Google (flujo "authorization code" + PKCE).
 * Solo prepara la infraestructura: permanece INACTIVO mientras no exista
 * config/google.php con 'enabled' => true (ver config/google.example.php).
 *
 * El intercambio de código y la lectura del perfil se hacen servidor a servidor
 * por TLS con el client_secret; el perfil viene del endpoint userinfo de Google
 * con el access_token recién emitido, por lo que no hace falta validar firmas
 * de JWT localmente. Las llamadas HTTP pasan por un callable inyectable para
 * poder probar sin red.
 */
require_once __DIR__ . '/../../Support/config.php';

final class GoogleOAuth
{
    public const URL_AUTORIZACION = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const URL_TOKEN = 'https://oauth2.googleapis.com/token';
    public const URL_USERINFO = 'https://openidconnect.googleapis.com/v1/userinfo';

    /** @var callable|null reemplazo de HTTP para pruebas: fn(string $metodo, string $url, array $datos, array $cabeceras): array{estado:int,cuerpo:string} */
    public static $http = null;

    public static function config(): ?array
    {
        $c = configCargar('google');
        if ($c === null || empty($c['enabled']) || empty($c['client_id']) || empty($c['client_secret']) || empty($c['redirect_uri'])) {
            return null;
        }
        return $c;
    }

    public static function habilitado(): bool
    {
        return self::config() !== null;
    }

    /** Valores de un solo uso que se guardan en la sesión antes de redirigir a Google. */
    public static function nuevaSolicitud(): array
    {
        $verificador = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        return ['state' => bin2hex(random_bytes(16)), 'verificador' => $verificador];
    }

    public static function urlAutorizacion(array $cfg, string $state, string $verificador): string
    {
        $desafio = rtrim(strtr(base64_encode(hash('sha256', $verificador, true)), '+/', '-_'), '=');
        $params = [
            'client_id' => $cfg['client_id'],
            'redirect_uri' => $cfg['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => $desafio,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ];
        if (!empty($cfg['dominios_permitidos']) && count($cfg['dominios_permitidos']) === 1) {
            $params['hd'] = reset($cfg['dominios_permitidos']); // solo sugerencia; se valida en perfilDesdeCodigo()
        }
        return self::URL_AUTORIZACION . '?' . http_build_query($params);
    }

    /**
     * Cambia el código por el perfil del usuario. @return array{sub:string,email:string,email_verified:bool,hd:?string,name:?string}|null
     */
    public static function perfilDesdeCodigo(array $cfg, string $codigo, string $verificador): ?array
    {
        $r = self::peticion('POST', self::URL_TOKEN, [
            'code' => $codigo,
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri' => $cfg['redirect_uri'],
            'grant_type' => 'authorization_code',
            'code_verifier' => $verificador,
        ]);
        $tok = $r['estado'] === 200 ? json_decode($r['cuerpo'], true) : null;
        if (!is_array($tok) || empty($tok['access_token'])) {
            error_log('GoogleOAuth: intercambio de código rechazado (HTTP ' . $r['estado'] . ')');
            return null;
        }
        $r = self::peticion('GET', self::URL_USERINFO, [], ['Authorization: Bearer ' . $tok['access_token']]);
        $p = $r['estado'] === 200 ? json_decode($r['cuerpo'], true) : null;
        if (!is_array($p) || empty($p['sub']) || empty($p['email'])) {
            error_log('GoogleOAuth: userinfo inválido (HTTP ' . $r['estado'] . ')');
            return null;
        }
        $perfil = [
            'sub' => (string)$p['sub'],
            'email' => strtolower((string)$p['email']),
            'email_verified' => ($p['email_verified'] ?? false) === true || ($p['email_verified'] ?? '') === 'true',
            'hd' => isset($p['hd']) ? strtolower((string)$p['hd']) : null,
            'name' => isset($p['name']) ? (string)$p['name'] : null,
        ];
        if (!empty($cfg['dominios_permitidos'])) {
            $permitidos = array_map('strtolower', $cfg['dominios_permitidos']);
            if ($perfil['hd'] === null || !in_array($perfil['hd'], $permitidos, true)) {
                return null;
            }
        }
        return $perfil;
    }

    /** @return array{estado:int,cuerpo:string} */
    private static function peticion(string $metodo, string $url, array $datos = [], array $cabeceras = []): array
    {
        if (self::$http !== null) {
            return (self::$http)($metodo, $url, $datos, $cabeceras);
        }
        if (!function_exists('curl_init')) {
            error_log('GoogleOAuth: la extensión curl de PHP no está disponible');
            return ['estado' => 0, 'cuerpo' => ''];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $cabeceras,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($metodo === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($datos));
        }
        $cuerpo = curl_exec($ch);
        $estado = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['estado' => $estado, 'cuerpo' => is_string($cuerpo) ? $cuerpo : ''];
    }
}
