<?php
/**
 * Credenciales de conexión a MySQL/MariaDB. Solo datos — ninguna
 * lógica de conexión vive aquí (eso es responsabilidad de
 * app/Infrastructure/Database/Database.php).
 *
 * Copia este archivo como 'database.php' en la misma carpeta y ajusta
 * los valores a tu entorno local (XAMPP) o de producción. 'database.php'
 * NUNCA se sube al repositorio (ver .gitignore).
 */
return [
    'host'     => 'localhost',
    'dbname'   => 'tickets_db',
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4', // utf8mb4 = UTF-8 completo (tildes, ñ y emojis); igual al de las tablas
];
