<?php
/**
 * Solicitud de recuperación de contraseña por correo. Requiere urlBase() (config) y el Mailer
 * (Infrastructure/Mail). La respuesta a la persona es siempre la misma exista o no el correo (no se enumeran
 * cuentas).
 */

class RecuperacionClaveService
{
    /** @return array{error:string,enviado:bool} 'error' no vacío solo si el correo no tiene formato válido. */
    public static function solicitar(PDO $pdo, string $correo, ?string $ip): array
    {
        if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Escribe un correo válido.', 'enviado' => false];
        }
        PasswordReset::solicitar($pdo, $correo, $ip, static function (array $u, string $token): void {
            $enlace = urlBase() . '/restablecer_clave.php?token=' . $token;
            $cuerpo = "Hola " . ($u['nombre_completo'] ?? '') . ",\n\n"
                . "Recibimos una solicitud para restablecer tu contraseña del " . institucion('sistema') . ' ' . institucion('sigla') . ".\n"
                . "Abre este enlace (vale " . PasswordReset::VIGENCIA_MINUTOS . " minutos y solo se puede usar una vez):\n\n$enlace\n\n"
                . "Si no fuiste tú, ignora este mensaje: tu contraseña no cambia.";
            Mailer::enviar($u['correo'], $u['nombre_completo'] ?? '', 'Sistema de Mantenimiento — restablecer contraseña', $cuerpo);
        });
        return ['error' => '', 'enviado' => true];
    }
}
