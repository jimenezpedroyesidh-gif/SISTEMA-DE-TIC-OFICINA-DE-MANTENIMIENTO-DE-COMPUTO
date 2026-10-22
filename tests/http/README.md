# Pruebas HTTP (README_REFACTORIZACION §28 y §29)

Cliente `curl` real contra el sistema en marcha: sesiones, CSRF, redirecciones y respuestas reales.
Complementa a `tests/run_tests.php` (unitarias sobre la base, con ROLLBACK).

## Requisitos
- PHP con `curl` y `pdo_mysql`; MySQL/MariaDB.
- Una base **aparte** cuyo nombre termine en `_test`, con el esquema de `database/tickets_db.sql`
  (ver instrucciones en la cabecera de `tests/run_tests.php`). No necesita datos: la suite crea los suyos.

## Ejecutar
```
bash tests/http/run.sh
```
o a mano:
```
APP_DB_NAME=tickets_db_test PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:8097 -t public
TEST_BASE_URL=http://127.0.0.1:8097 php tests/http/run_http_tests.php
```
Variables: `TEST_DB_HOST`, `TEST_DB_NAME`, `TEST_DB_USER`, `TEST_DB_PASS`, `TEST_BASE_URL`, `TEST_PORT`.

`APP_DB_NAME` hace que **el servidor** use la base de pruebas sin tocar `config/database.php`
(solo la lee `configCargar('database')` en `app/Support/config.php`; sin la variable no cambia nada).
La suite se niega a correr si la base no termina en `_test`.

## Qué cubre
| README | Prueba |
|---|---|
| §28 crear / consultar / listar / buscar / filtrar / paginar | ticket de solicitante, secretaria y profesor; listas; `pagina_activos` (válida, fuera de rango, negativa); `buscar_global` |
| §28 modificar / eliminar | técnico: tomar, comentar, cambiar estado, liberar, descartar; horarios: crear, editar, borrar |
| §28 AJAX | `buscar_global`, `listar_equipos_ajax`, `listar_modelos_ajax`, `buscar_componente_ajax`, `notificacion_marcar_leida` |
| §28 PDF | `ticket_pdf`, `ticket_profesor_pdf` (`%PDF`, cabecera) |
| §28 archivos | descarga de adjuntos, foto de perfil válida |
| §28 notificaciones | aparece, se marca leída |
| §29 no autenticado / sin permisos / con permisos | matriz de ~20 páginas × (anónimo + 6 roles) + usuario inactivo |
| §29 propietario / no propietario / IDs | PDF, adjuntos, notificaciones, comentarios, cambio de estado de tickets ajenos |
| §29 CSRF | 8 acciones sensibles sin token, con token falso y con token de otra sesión |
| §29 XSS | payloads guardados (ticket, notificación) y reflejados (8 parámetros), listas, búsqueda, PDF, cabeceras CSP |
| §29 SQL Injection | 7 payloads × 10 endpoints × 3 perfiles: sin 5xx ni mensajes de BD; login con SQLi |
| §29 acceso directo | `config/`, `app/`, `storage/`, `.git`, `.env`, SQL, `vendor/` por URL |
| §29 archivos no permitidos / Path Traversal | `.php`, `.html`, `.svg`, doble extensión, contenido PHP con MIME de imagen, tamaño; `../` en `id` y en `nombre_almacenado` |
| Soluciones técnicas (`modulo_soluciones_tecnicas.php`) | catálogo, versión con archivos y SHA-256 (archivo alterado → 409), aprobación del admin, procedimientos, objetivos, verificación, mantenimiento con equipos asignados, tareas y descargas de la beca, sincronización al cerrar, versión desactualizada/retirada, permisos por rol, CSRF y XSS |

## Lo que NO cubre (se prueba a mano)
- **Correo**: no envía mensajes reales (sin `config/smtp.php`); solo comprueba que `recuperar_clave` no enumera correos.
- Inicio de sesión con Google y el límite de intentos de login (dependen de config/tiempo).
- Comportamiento visual y JavaScript del navegador → `tests/lint_js.php` + capturas.
