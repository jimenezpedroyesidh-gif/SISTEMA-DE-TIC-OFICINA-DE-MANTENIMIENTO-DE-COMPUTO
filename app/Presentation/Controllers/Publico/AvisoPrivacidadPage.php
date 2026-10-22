<?php
/**
 * Controlador del aviso de privacidad (public/aviso_privacidad.php), página pública: política de tratamiento
 * de datos personales (Ley 1581 de 2012 y Decreto 1377 de 2013) con el responsable, el correo de contacto y
 * los tiempos de conservación que configura el admin en «Seguridad». Ámbito global.
 */
$raiz = dirname(__DIR__, 4);

$config = ConfigSistema::todos($pdo);
$retencionIp = max(30, (int)$config['seg_retencion_ip_dias']);
$retencionBitacora = max(90, (int)$config['seg_retencion_bitacora_dias']);

require $raiz . '/app/Presentation/Views/publico/aviso_privacidad.php';
