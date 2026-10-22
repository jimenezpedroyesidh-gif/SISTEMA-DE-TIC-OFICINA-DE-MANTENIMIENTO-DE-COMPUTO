<?php
/**
 * Ajustes generales del sistema (copiar a `config/app.php`; fuera de git).
 *
 * url_base: dirección pública del sistema, SIN barra final y hasta la carpeta
 * donde está public/ (p. ej. 'https://tickets.ufps.edu.co'). Se usa en los
 * enlaces de los correos (recuperar contraseña). Si falta, se deduce de la
 * petición, lo cual es menos seguro; en producción defínelo.
 *
 * institucion: nombre, sigla y dominio de correo que se muestran en títulos, cabecera,
 * portada, PDF, correos y validación de correos. Cualquier clave que falte toma el valor
 * de INSTITUCION_DEFECTO (app/Support/config.php). Los colores se cambian aparte, en
 * public/assets/tokens.css (--color-principal).
 */
return [
    'url_base' => 'http://localhost/tickets/public',

    'institucion' => [
        'sigla'          => 'UFPS',
        'nombre'         => 'Universidad Francisco de Paula Santander',
        'ciudad'         => 'Cúcuta',
        'sistema'        => 'Sistema de Soporte',
        'oficina'        => 'Oficina de Mantenimiento de Cómputo',
        'ubicacion'      => 'Departamento de Sistemas, Aula Sur SC403',
        'contacto'       => 'ext:342',
        'dominio_correo' => 'ufps.edu.co',          // único dominio aceptado en el reporte por QR sin cuenta
        'dominios_extra' => ['gmail.com'],          // aceptados además al crear usuarios
    ],
];
