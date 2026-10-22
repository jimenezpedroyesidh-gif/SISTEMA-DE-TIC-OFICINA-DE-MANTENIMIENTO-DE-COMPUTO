<?php
/**
 * Copia este archivo a `config/smtp.php` (SIN el .example) y pon ahí
 * las credenciales reales — ese archivo ya está en .gitignore porque
 * contiene una contraseña real.
 *
 * Si usas Gmail: no funciona con la contraseña normal de la cuenta, hay
 * que generar una "contraseña de aplicación" en la configuración de
 * seguridad de Google (con verificación en 2 pasos activada).
 *
 * Si usas el correo institucional @ufps.edu.co: pide los datos del
 * servidor SMTP a sistemas/TI de la universidad (host, puerto,
 * usuario, si requiere TLS o SSL).
 */
return [
    'host'             => 'smtp.gmail.com',
    'puerto'           => 587,
    'seguridad'        => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS, // o ENCRYPTION_SMTPS para el puerto 465
    'usuario'          => 'tu_correo@gmail.com',
    'clave'            => 'contraseña_de_aplicacion_aqui',
    'remitente'        => 'tu_correo@gmail.com',
    'remitente_nombre' => 'Sistema de Mantenimiento UFPS',
];
