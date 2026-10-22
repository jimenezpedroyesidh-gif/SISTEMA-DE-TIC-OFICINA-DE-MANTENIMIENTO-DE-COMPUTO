<?php
/**
 * Envío de correo centralizado (PHPMailer + config/smtp.php).
 *
 * Un fallo de envío nunca lanza excepción hacia quien llama: se registra en
 * el log y devuelve false (el correo es secundario respecto a la operación).
 */
require_once __DIR__ . '/../../Support/config.php';

final class Mailer
{
    public static function enviar(string $destino, string $nombreDestino, string $asunto, string $cuerpo): bool
    {
        $config = configCargar('smtp');
        if ($config === null) {
            error_log('Mailer: falta config/smtp.php (ver config/smtp.example.php) — correo no enviado.');
            return false;
        }

        require_once __DIR__ . '/../../../vendor/PHPMailer/Exception.php';
        require_once __DIR__ . '/../../../vendor/PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/../../../vendor/PHPMailer/SMTP.php';

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['usuario'];
            $mail->Password = $config['clave'];
            $mail->SMTPSecure = $config['seguridad'] ?? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $config['puerto'] ?? 587;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom($config['remitente'], $config['remitente_nombre'] ?? institucion('sistema') . ' ' . institucion('sigla'));
            $mail->addAddress($destino, $nombreDestino);

            $mail->Subject = $asunto;
            $mail->Body = $cuerpo;
            $mail->isHTML(false);

            $mail->send();
            return true;
        } catch (\Throwable $e) {
            RegistroErrores::registrar("Mailer: fallo al enviar a {$destino}", $e);
            return false;
        }
    }
}
