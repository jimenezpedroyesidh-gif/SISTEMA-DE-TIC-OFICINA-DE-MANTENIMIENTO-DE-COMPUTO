<?php
/**
 * Arranque de toda página de public/: zona horaria, autoload, funciones compartidas y conexión PDO.
 * No inicia sesión ni valida acceso: eso lo hacen session_boot.php y los guards de auth.php
 * (requireAuth/requireRole) desde el controlador.
 */

// Hora de Colombia para todo date()/strtotime(), sin depender de la configuración del servidor.
date_default_timezone_set('America/Bogota');

// Carpeta de vistas parciales (filas de tablas, widgets): fuera de public/ para que no se abran por URL.
define('PARCIALES_DIR', __DIR__ . '/app/Presentation/Views/partials');

require_once __DIR__ . '/app/Support/autoload.php';   // clases de app/
require_once __DIR__ . '/app/Support/csrf.php';
require_once __DIR__ . '/app/Support/validacion.php'; // validar*() / entradaNormalizar()
require_once __DIR__ . '/app/Support/ui.php';   // h(), chips de estado
require_once __DIR__ . '/app/Support/ui_shell.php'; // avatar, etiqueta de rol, foto de perfil (header/sidebar/perfil)
require_once __DIR__ . '/app/Support/auth.php';  // requireAuth/requireRole/requirePermission
require_once __DIR__ . '/app/Support/rutas.php';  // mapa de páginas: roles, inicio de cada rol y menú
require_once __DIR__ . '/app/Support/paginacion.php';
require_once __DIR__ . '/app/Support/config.php';      // configCargar()/urlBase()
require_once __DIR__ . '/app/Support/inventario.php';   // búsqueda de componentes

$pdo = Database::conexion();

// HTTPS obligatorio y HSTS si el admin los activó en «Seguridad» (no aplica en consola).
SeguridadService::aplicarTransporte($pdo);
