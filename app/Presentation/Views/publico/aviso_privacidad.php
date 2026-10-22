<?php /** Vista pública del aviso de privacidad (public/aviso_privacidad.php). Solo presentación: recibe datos ya preparados por AvisoPrivacidadPage. */ ?>
<?php $paginaPublica = true; $paginaTitulo = 'Aviso de privacidad'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php include LAYOUT_DIR . '/app_publico_inicio.php'; ?>
<div class="card mw-640">
  <h2>Aviso de privacidad</h2>
  <p class="text-muted mb-3">Tratamiento de datos personales del Sistema de Soporte de mantenimiento (Ley 1581 de 2012 y Decreto 1377 de 2013).</p>

  <h3 class="panel-subtitle">Responsable</h3>
  <p><?= h($config['priv_responsable']) ?>.<?php if ($config['priv_correo'] !== ''): ?> Consultas y reclamos: <a href="mailto:<?= h($config['priv_correo']) ?>"><?= h($config['priv_correo']) ?></a>.<?php endif; ?></p>

  <h3 class="panel-subtitle">Qué datos se recogen</h3>
  <ul class="pl-18">
    <li><strong>Cuentas del sistema:</strong> nombre, código, cédula, correo institucional, cargo, dependencia y foto de perfil (opcional).</li>
    <li><strong>Reportes por QR sin cuenta:</strong> la descripción del daño, la sala, la fecha y la dirección IP desde la que se envió. No se pide nombre ni correo.</li>
    <li><strong>Registro de accesos:</strong> fecha, hora, IP y navegador de cada inicio y cierre de sesión e intentos fallidos.</li>
  </ul>

  <h3 class="panel-subtitle">Para qué se usan</h3>
  <ul class="pl-18">
    <li>Atender los daños y solicitudes de soporte de las salas y equipos de la universidad, y avisar su avance.</li>
    <li>Medir la calidad del servicio (tiempos de atención y encuesta de satisfacción, que es voluntaria).</li>
    <li>Proteger el sistema: detectar accesos indebidos y frenar reportes masivos o automáticos (la IP solo se usa para eso).</li>
  </ul>

  <h3 class="panel-subtitle">Cuánto tiempo se conservan</h3>
  <p>La IP de los reportes anónimos se borra a los <?= (int)$retencionIp ?> días. El registro de accesos se conserva <?= (int)$retencionBitacora ?> días. Los datos de los tickets se conservan como historial del servicio y del inventario de la universidad.</p>

  <h3 class="panel-subtitle">Tus derechos</h3>
  <p>Como titular puedes conocer, actualizar y rectificar tus datos; pedir prueba de esta autorización; ser informado del uso que se les da; revocar la autorización o pedir que se supriman cuando no exista un deber legal de conservarlos, y presentar quejas ante la Superintendencia de Industria y Comercio. Escríbenos al correo indicado arriba; respondemos consultas en máximo 10 días hábiles y reclamos en máximo 15 días hábiles.</p>

  <h3 class="panel-subtitle">Seguridad</h3>
  <p>Las contraseñas se guardan cifradas con un algoritmo de un solo sentido, las conexiones a otros sistemas se guardan cifradas, los respaldos van cifrados y el acceso a la información depende del rol de cada persona.</p>

  <p class="mt-3"><a href="index.php">← Volver al inicio</a></p>
</div>
</div>
</body>
</html>
