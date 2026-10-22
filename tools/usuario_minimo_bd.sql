-- =====================================================================
-- Usuario de MySQL/MariaDB con permisos MÍNIMOS para la aplicación
-- (ISO/IEC 27001 A.8.2 «Derechos de acceso privilegiados»).
--
-- La aplicación solo necesita leer y escribir datos de tickets_db: no debe
-- poder borrar tablas, crear bases ni tocar otras bases del servidor.
--
-- 1. Cambia la contraseña de ejemplo por una larga (20+ caracteres).
-- 2. Ejecuta este archivo como root (phpMyAdmin → pestaña SQL, o
--    mysql -u root -p < tools/usuario_minimo_bd.sql).
-- 3. Pon el usuario y la contraseña en config/database.php.
-- 4. Para importar/actualizar database/tickets_db.sql sigue usando root.
--
-- Nota: con este usuario «Verificar respaldo» comprueba el archivo pero no
-- puede crear la base temporal de prueba (es lo esperado).
-- =====================================================================

CREATE USER IF NOT EXISTS 'tickets_app'@'localhost' IDENTIFIED BY 'CAMBIA-ESTA-CLAVE-por-una-muy-larga-2026';
GRANT SELECT, INSERT, UPDATE, DELETE ON `tickets_db`.* TO 'tickets_app'@'localhost';
-- La bitácora de accesos solo admite agregar filas y depurar las antiguas (nunca editarlas):
-- si quieres reforzarlo, quita UPDATE sobre esa tabla concreta otorgando los permisos tabla por tabla.
FLUSH PRIVILEGES;
