<?php
/**
 * Códigos de un solo uso basados en tiempo (TOTP, RFC 6238) — los de Google Authenticator, Microsoft
 * Authenticator, Authy, FreeOTP… Seis dígitos cada 30 segundos con HMAC-SHA1. Sin dependencias externas.
 */
final class Totp
{
    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    public const PERIODO = 30;
    public const DIGITOS = 6;

    public static function generarSecreto(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function base32(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 5) as $grupo) $out .= self::ALFABETO[bindec(str_pad($grupo, 5, '0'))];
        return $out;
    }

    public static function base32Decodificar(string $texto): string
    {
        $texto = strtoupper((string)preg_replace('/[^A-Za-z2-7]/', '', $texto));
        $bits = '';
        foreach (str_split($texto) as $c) {
            $v = strpos(self::ALFABETO, $c);
            if ($v === false) return '';
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $out .= chr((int)bindec($byte));
        }
        return $out;
    }

    /** Código de un intervalo de 30 s ($contador = floor(tiempo / 30)). */
    public static function codigo(string $secreto, int $contador): string
    {
        $clave = self::base32Decodificar($secreto);
        $hmac = hash_hmac('sha1', pack('N*', 0) . pack('N*', $contador), $clave, true);
        $offset = ord($hmac[19]) & 0x0F;
        $num = ((ord($hmac[$offset]) & 0x7F) << 24) | ((ord($hmac[$offset + 1]) & 0xFF) << 16) | ((ord($hmac[$offset + 2]) & 0xFF) << 8) | (ord($hmac[$offset + 3]) & 0xFF);
        return str_pad((string)($num % (10 ** self::DIGITOS)), self::DIGITOS, '0', STR_PAD_LEFT);
    }

    /**
     * ¿El código es válido ahora? Acepta ±$ventana intervalos por desfase del reloj del celular.
     * Devuelve el contador que coincidió (para impedir reusar el mismo código) o null.
     */
    public static function verificar(string $secreto, string $codigo, ?int $ahora = null, int $ventana = 1): ?int
    {
        $codigo = (string)preg_replace('/\D/', '', $codigo);
        if (strlen($codigo) !== self::DIGITOS || $secreto === '') return null;
        $t = intdiv($ahora ?? time(), self::PERIODO);
        for ($i = -$ventana; $i <= $ventana; $i++) {
            if (hash_equals(self::codigo($secreto, $t + $i), $codigo)) return $t + $i;
        }
        return null;
    }

    /** Nombre con el que aparece la cuenta en la app autenticadora (p. ej. «Soporte UFPS»). */
    public static function emisor(): string
    {
        return 'Soporte ' . institucion('sigla');
    }

    /** Enlace otpauth:// que leen las apps al escanear el QR. */
    public static function uri(string $secreto, string $cuenta, ?string $emisor = null): string
    {
        $emisor ??= self::emisor();
        return 'otpauth://totp/' . rawurlencode($emisor) . ':' . rawurlencode($cuenta)
            . '?secret=' . $secreto . '&issuer=' . rawurlencode($emisor) . '&digits=' . self::DIGITOS . '&period=' . self::PERIODO;
    }
}
