<?php
/**
 * Política de contraseñas (ISO/IEC 27001 A.5.17 y NIST SP 800-63B): mínimo 12 caracteres, máximo 128,
 * no puede ser una contraseña común ni una variación obvia de ella (Password123!, ufps2026…), ni contener
 * el correo, el código o el nombre de la persona, ni repetir un solo carácter. No se exigen mezclas
 * forzadas de símbolos: la longitud y la lista de bloqueo protegen más y se recuerdan mejor.
 */
final class PoliticaClave
{
    public const MIN = 12;
    public const MAX = 128;

    /** Contraseñas más usadas (y las típicas de una universidad colombiana). Se comparan sin mayúsculas. */
    private const COMUNES = [
        '123456', '123456789', '12345678', '1234567890', '1234567', '12345', '1234', '111111', '000000', '123123', '654321', '666666',
        '987654321', '112233', '121212', '123321', '1q2w3e4r', '1q2w3e', 'qwerty', 'qwerty123', 'qwertyuiop', 'asdfgh', 'asdfghjkl',
        'zxcvbnm', 'password', 'passw0rd', 'contrasena', 'contraseña', 'clave', 'micontrasena', 'admin', 'administrador', 'administrator',
        'root', 'toor', 'user', 'usuario', 'invitado', 'guest', 'welcome', 'bienvenido', 'letmein', 'iloveyou', 'teamo', 'tequiero',
        'monkey', 'dragon', 'master', 'shadow', 'sunshine', 'princess', 'football', 'futbol', 'baseball', 'superman', 'batman',
        'trustno1', 'abc123', 'abcd1234', 'abcdef', 'abcdefg', 'abcdefgh', 'login', 'starwars', 'pokemon', 'naruto', 'hello', 'hola',
        'holamundo', 'secret', 'secreto', 'changeme', 'cambiame', 'default', 'temporal', 'prueba', 'test', 'testing', 'demo',
        'colombia', 'cucuta', 'bogota', 'medellin', 'santander', 'nortedesantander', 'ufps', 'ufpscucuta', 'universidad',
        'estudiante', 'profesor', 'docente', 'tecnico', 'sistemas', 'soporte', 'mantenimiento', 'ingenieria', 'informatica',
        'computador', 'laboratorio', 'secretaria', 'becario', 'becatrabajo', 'tickets', 'helpdesk', 'mesadeayuda',
        'america', 'millonarios', 'nacional', 'cucutadeportivo', 'barcelona', 'realmadrid', 'jesus', 'diosesamor', 'amor',
        'familia', 'mama', 'papa', 'mariposa', 'estrella', 'angel', 'ganador', 'campeon', 'samsung', 'iphone', 'google',
        'facebook', 'instagram', 'whatsapp', 'microsoft', 'windows', 'linux', 'internet', 'computer', 'qazwsx', 'qweasd',
        'aaaaaa', 'zzzzzz', 'asd123', 'qwe123', 'pass', 'pass123', 'password1', 'password123',
    ];

    /** Normaliza para comparar: minúsculas, sin tildes, sin espacios, «leet» básico (0→o, 1→i, 3→e, 4→a, 5→s, @→a, $→s). */
    private static function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        return (string)preg_replace('/\s+/', '', $s);
    }

    private static function sinLeet(string $s): string
    {
        return strtr($s, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '!' => 'i']);
    }

    /** ¿Es común o una variación (prefijo/sufijo de números y símbolos sobre una palabra común)? */
    public static function esComun(string $clave): bool
    {
        static $lista = null;
        if ($lista === null) {
            $lista = [];
            foreach (self::COMUNES as $c) $lista[self::normalizar($c)] = true;
        }
        $n = self::normalizar($clave);
        if (isset($lista[$n]) || isset($lista[self::sinLeet($n)])) return true;
        // Quita números y símbolos al inicio y al final: «Ufps2026*», «*Password123» → ufps / password
        // (y después deshace el «leet» del centro: «P4ssw0rd!2024» → password).
        $nucleo = self::sinLeet((string)preg_replace('/^[^a-z]+|[^a-z]+$/', '', $n));
        if ($nucleo !== '' && isset($lista[$nucleo])) return true;
        // Una palabra común repetida: «ufpsufpsufps».
        foreach (array_keys($lista) as $c) {
            if (strlen((string)$c) >= 3 && str_replace((string)$c, '', $nucleo) === '' && $nucleo !== '') return true;
        }
        return false;
    }

    /**
     * Valida una contraseña nueva. $contexto: correo, codigo, nombre (para que no los contenga).
     * @return string|null mensaje de error, o null si cumple
     */
    public static function validar(string $clave, array $contexto = []): ?string
    {
        $largo = mb_strlen($clave);
        if ($largo < self::MIN) return 'La contraseña debe tener al menos ' . self::MIN . ' caracteres (puedes usar una frase, por ejemplo «cafe con pan a las 7»).';
        if ($largo > self::MAX) return 'La contraseña admite máximo ' . self::MAX . ' caracteres.';
        if (count(array_unique(mb_str_split($clave))) < 4) return 'La contraseña es demasiado repetitiva: usa al menos 4 caracteres distintos.';
        if (self::esComun($clave)) return 'Esa contraseña es muy común o fácil de adivinar. Elige otra (una frase de varias palabras funciona bien).';
        $n = self::normalizar($clave);
        $partes = [];
        if (!empty($contexto['correo'])) $partes[] = explode('@', (string)$contexto['correo'])[0];
        foreach (['codigo'] as $c) if (!empty($contexto[$c])) $partes[] = (string)$contexto[$c];
        if (!empty($contexto['nombre'])) foreach (preg_split('/\s+/', (string)$contexto['nombre']) ?: [] as $p) $partes[] = $p;
        foreach ($partes as $p) {
            $p = self::normalizar($p);
            if (mb_strlen($p) >= 4 && strpos($n, $p) !== false) return 'La contraseña no puede contener tu nombre, correo, código o cédula.';
        }
        return null;
    }

    /** Datos de la persona para validar(): correo, código, cédula y nombre. */
    public static function contextoDe(PDO $pdo, int $idUsuario): array
    {
        $st = $pdo->prepare('SELECT correo, codigo, nombre_completo AS nombre FROM users WHERE id = ?');
        $st->execute([$idUsuario]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
