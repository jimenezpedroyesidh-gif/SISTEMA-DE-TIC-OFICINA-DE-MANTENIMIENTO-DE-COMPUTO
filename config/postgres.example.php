<?php
/**
 * Conexión de SOLO LECTURA a la base PostgreSQL del sistema de asistencia facial
 * (de ahí salen las horas de las becas trabajo). OPCIONAL.
 *
 * Copia este archivo como `config/postgres.php` (está en .gitignore) y pon los datos reales.
 * Si `postgres.php` no existe o Postgres no responde, el sistema de tickets sigue funcionando:
 * el módulo «Seguimiento de horas» muestra «Sin registros» y los paneles «SIN DATO».
 *
 * Requiere la extensión pdo_pgsql activa en PHP (en XAMPP: quitar el ; de
 * `extension=pdo_pgsql` en php.ini y reiniciar Apache).
 */
return [
    'host'     => '127.0.0.1',
    'port'     => '5432',
    'dbname'   => 'asistencia',
    'username' => 'usuario_solo_lectura',
    'password' => 'CAMBIAR',
];
