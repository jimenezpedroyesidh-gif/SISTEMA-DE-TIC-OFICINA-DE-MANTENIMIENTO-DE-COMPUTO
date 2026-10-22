<?php
/**
 * Protección del reporte anónimo por QR contra spam y robots: pregunta aritmética sencilla (se puede
 * apagar), campo trampa invisible que solo llenan los robots, límite de reportes por IP y por hora
 * (configurable) y aceptación del aviso de privacidad (Ley 1581). Cada bloqueo queda en la bitácora.
 */
final class AntiSpamQr
{
    private const CLAVE_SESION = 'qr_reto';
    public const CAMPO_TRAMPA = 'sitio_web';

    /** Pregunta nueva (se guarda la respuesta en la sesión). Devuelve el texto: «¿Cuánto es 4 + 7?». */
    public static function nuevoReto(array &$sesion): string
    {
        $a = random_int(2, 9);
        $b = random_int(1, 9);
        $sesion[self::CLAVE_SESION] = ['r' => $a + $b, 't' => time()];
        return "¿Cuánto es $a + $b?";
    }

    /** ¿La respuesta coincide? El reto es de un solo uso y vence a los 30 minutos. */
    public static function retoCorrecto(array &$sesion, $respuesta): bool
    {
        $reto = $sesion[self::CLAVE_SESION] ?? null;
        unset($sesion[self::CLAVE_SESION]);
        if (!is_array($reto) || time() - (int)$reto['t'] > 1800) return false;
        $r = trim((string)$respuesta);
        return $r !== '' && ctype_digit($r) && (int)$r === (int)$reto['r'];
    }

    /** Reportes anónimos hechos desde esta IP en la última hora. */
    public static function reportesUltimaHora(PDO $pdo, ?string $ip): int
    {
        if ($ip === null || $ip === '') return 0;
        $st = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE origen = 'QR_ANONIMO' AND ip_origen = ? AND fecha_creacion >= NOW() - INTERVAL 1 HOUR");
        $st->execute([$ip]);
        return (int)$st->fetchColumn();
    }

    /**
     * Revisa un envío anónimo antes de crear el ticket. @return string|null mensaje para la persona, o null si pasa
     */
    public static function revisar(PDO $pdo, array $post, array &$sesion, ?string $ip, string $sala): ?string
    {
        if (trim((string)($post[self::CAMPO_TRAMPA] ?? '')) !== '') {
            BitacoraAccesos::registrar($pdo, 'QR_BLOQUEADO', null, null, "campo trampa lleno · sala $sala");
            return 'No se pudo enviar el reporte. Recarga la página e inténtalo de nuevo.';
        }
        $max = max(1, ConfigSistema::entero($pdo, 'seg_qr_max_por_hora'));
        if (self::reportesUltimaHora($pdo, $ip) >= $max) {
            BitacoraAccesos::registrar($pdo, 'QR_BLOQUEADO', null, null, "más de $max reportes en una hora · sala $sala");
            return 'Se recibieron muchos reportes desde esta red en la última hora. Si el daño es urgente, avisa en la oficina de mantenimiento.';
        }
        if (empty($post['acepto_privacidad'])) {
            return 'Para enviar el reporte acepta el aviso de privacidad (tratamiento de datos personales).';
        }
        if (ConfigSistema::activo($pdo, 'seg_qr_captcha') && !self::retoCorrecto($sesion, $post['reto'] ?? '')) {
            return 'La respuesta de la pregunta de verificación no es correcta. Inténtalo de nuevo.';
        }
        return null;
    }
}
