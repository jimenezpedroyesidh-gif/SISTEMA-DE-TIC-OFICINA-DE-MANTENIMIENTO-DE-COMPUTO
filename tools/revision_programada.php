<?php
/**
 * Revisión del SLA sin depender de que alguien use el sistema (opcional; normalmente la hace sola cada página
 * con sesión, como máximo cada 5 minutos): sincroniza fechas de servicio, envía avisos de «por vencer» y
 * «vencido», borra la IP de los reportes anónimos vencidos y abre los incidentes de seguridad pendientes.
 *   php tools/revision_programada.php        (en cron, cada 10 minutos)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por consola.'); }
require __DIR__ . '/../bootstrap.php';

echo SlaService::revisionPeriodica($pdo, true) ? "Revisión hecha.\n" : "No se pudo hacer la revisión (¿falta importar database/tickets_db.sql?).\n";
