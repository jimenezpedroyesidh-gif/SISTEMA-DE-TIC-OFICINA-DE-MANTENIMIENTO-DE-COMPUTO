<?php
/**
 * Inicio de sesión con Google (OPCIONAL, desactivado por defecto).
 *
 * Para activarlo en tu servidor:
 *  1. Copia este archivo a `config/google.php` (sin .example; está en .gitignore).
 *  2. En https://console.cloud.google.com → APIs y servicios → Credenciales →
 *     «ID de cliente de OAuth» (aplicación web) crea las credenciales y registra
 *     como «URI de redirección autorizado» el valor de `redirect_uri`.
 *  3. Pega `client_id` y `client_secret`, y pon `enabled` => true.
 *
 * Seguridad: solo entran usuarios que YA existen en el sistema (el correo de
 * Google debe estar verificado y coincidir con users.correo). No se crean
 * cuentas automáticamente.
 */
return [
    'enabled'       => false,
    'client_id'     => 'xxxxxxxx.apps.googleusercontent.com',
    'client_secret' => 'CAMBIAR',
    'redirect_uri'  => 'https://tu-servidor.ejemplo/google_callback.php',
    // Opcional: dominios de Google Workspace permitidos (claim "hd"). Vacío = cualquier cuenta cuyo correo exista en users.
    'dominios_permitidos' => [],   // p. ej. ['ufps.edu.co']
];
