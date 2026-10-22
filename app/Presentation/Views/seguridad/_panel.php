<?php
/** Vista parcial de «Seguridad»: diagnóstico de controles, configuración y guías de implantación. Solo presentación. */
$chipDiag = ['ok' => ['Bien', 'chip solucionado'], 'alerta' => ['Revisar', 'chip espera'], 'riesgo' => ['Riesgo', 'chip danio']];
$siNo = static fn(string $v): string => $v === '1' ? '<span class="chip solucionado">Activo</span>' : '<span class="chip">Apagado</span>';
$riesgos = count(array_filter($diagnostico, static fn($d) => $d['estado'] === 'riesgo'));
?>
    <div class="stat-row stat-row-4">
      <a class="stat-card" href="seguridad.php?vista=bitacora&amp;evento=LOGIN_OK">
        <span class="stat-label">Ingresos (24 h)</span>
        <span class="stat-value"><?= (int)$resumen['ingresos'] ?></span>
        <span class="hint">Inicios de sesión correctos</span>
      </a>
      <a class="stat-card" href="seguridad.php?vista=bitacora&amp;evento=LOGIN_FALLIDO">
        <span class="stat-label">Intentos fallidos (24 h)</span>
        <span class="stat-value"><?= (int)$resumen['fallidos'] ?></span>
        <span class="hint"><?= (int)$resumen['bloqueos'] ?> cuenta(s) bloqueada(s) · <?= (int)$resumen['qr'] ?> reporte(s) QR frenados</span>
      </a>
      <a class="stat-card" href="seguridad.php?vista=incidentes">
        <span class="stat-label">Incidentes abiertos</span>
        <span class="stat-value"><?= (int)$incidentesAbiertos ?></span>
        <span class="hint">Sin cerrar en el registro</span>
      </a>
      <div class="stat-card">
        <span class="stat-label">Controles con riesgo</span>
        <span class="stat-value"><?= $riesgos ?></span>
        <span class="hint">de <?= count($diagnostico) ?> revisados en el diagnóstico</span>
      </div>
    </div>

    <section class="panel-box">
      <h2 class="panel-title">Diagnóstico de seguridad <span class="hint">se calcula al abrir esta página con el estado real del servidor</span></h2>
      <div class="tabla-scroll">
        <table class="data">
          <thead><tr><th>Control</th><th>Estado</th><th>Detalle</th><th>Qué hacer</th><th>ISO 27001</th></tr></thead>
          <tbody>
            <?php foreach ($diagnostico as $d): ?>
              <tr>
                <td data-label="Control"><strong><?= h($d['control']) ?></strong></td>
                <td data-label="Estado"><span class="<?= $chipDiag[$d['estado']][1] ?>"><?= h($chipDiag[$d['estado']][0]) ?></span></td>
                <td data-label="Detalle"><?= h($d['detalle']) ?></td>
                <td data-label="Qué hacer"><?= $d['accion'] !== '' ? h($d['accion']) : '<span class="text-muted">—</span>' ?></td>
                <td data-label="ISO 27001"><span class="hint"><?= h($d['iso']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel-box">
      <div class="conexion-cabecera">
        <h2 class="panel-title">Configuración</h2>
        <button type="button" class="btn btn-sm" data-vf-abrir="vf-config-seguridad">Cambiar configuración</button>
      </div>
      <div class="tabla-scroll">
        <table class="data">
          <thead><tr><th>Opción</th><th>Valor</th><th>Para qué sirve</th></tr></thead>
          <tbody>
            <tr><td data-label="Opción">Forzar HTTPS</td><td data-label="Valor"><?= $siNo($config['seg_forzar_https']) ?></td><td data-label="Para qué sirve">Toda visita por http:// pasa a https:// (contraseñas y datos cifrados en la red).</td></tr>
            <tr><td data-label="Opción">HSTS</td><td data-label="Valor"><?= $siNo($config['seg_hsts']) ?></td><td data-label="Para qué sirve">El navegador recuerda usar solo HTTPS durante un año.</td></tr>
            <tr><td data-label="Opción">Verificación en dos pasos obligatoria</td><td data-label="Valor"><?= $siNo($config['seg_mfa_obligatorio']) ?></td><td data-label="Para qué sirve">Admin y técnicos deben usar un código de su celular además de la contraseña.</td></tr>
            <tr><td data-label="Opción">Pregunta de verificación en el QR</td><td data-label="Valor"><?= $siNo($config['seg_qr_captcha']) ?></td><td data-label="Para qué sirve">Frena robots que llenen la bandeja con reportes falsos.</td></tr>
            <tr><td data-label="Opción">Reportes por QR por hora y por red</td><td data-label="Valor"><?= (int)$config['seg_qr_max_por_hora'] ?></td><td data-label="Para qué sirve">Límite de reportes anónimos desde una misma IP.</td></tr>
            <tr><td data-label="Opción">Retención de la bitácora</td><td data-label="Valor"><?= (int)$config['seg_retencion_bitacora_dias'] ?> días</td><td data-label="Para qué sirve">Tiempo que se conservan los registros de acceso.</td></tr>
            <tr><td data-label="Opción">Retención de la IP de los reportes anónimos</td><td data-label="Valor"><?= (int)$config['seg_retencion_ip_dias'] ?> días</td><td data-label="Para qué sirve">Pasado ese tiempo la IP se borra sola (minimización de datos, Ley 1581).</td></tr>
            <tr><td data-label="Opción">Respaldos a conservar</td><td data-label="Valor"><?= (int)$config['seg_respaldos_conservar'] ?></td><td data-label="Para qué sirve">Los más antiguos se borran al generar uno nuevo.</td></tr>
            <tr><td data-label="Opción">Responsable de los datos personales</td><td data-label="Valor"><?= h($config['priv_responsable']) ?></td><td data-label="Para qué sirve">Aparece en el <a href="aviso_privacidad.php" target="_blank" rel="noopener">aviso de privacidad</a>.</td></tr>
            <tr><td data-label="Opción">Correo para consultas y reclamos</td><td data-label="Valor"><?= $config['priv_correo'] !== '' ? h($config['priv_correo']) : '<span class="texto-peligro">Sin definir</span>' ?></td><td data-label="Para qué sirve">Canal para que los titulares ejerzan sus derechos.</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <div class="panel-grid">
      <section class="panel-box">
        <h2 class="panel-title">Activar HTTPS en el servidor</h2>
        <ol class="pl-18">
          <li>Consigue un certificado: el institucional o uno gratuito de Let's Encrypt (en Windows: <em>win-acme</em>; en Linux: <em>certbot</em>).</li>
          <li>En XAMPP edita <code>apache/conf/extra/httpd-ssl.conf</code>: <code>SSLCertificateFile</code> y <code>SSLCertificateKeyFile</code> con las rutas del certificado, y reinicia Apache.</li>
          <li>Entra al sistema por <code>https://</code> y activa aquí «Forzar HTTPS». Cuando todo funcione unos días, activa HSTS.</li>
          <li>Si el servidor queda detrás de un proxy o balanceador, este debe enviar <code>X-Forwarded-Proto: https</code>.</li>
        </ol>
      </section>
      <section class="panel-box">
        <h2 class="panel-title">Usuario de base de datos con permisos mínimos</h2>
        <ol class="pl-18">
          <li>Abre <code>tools/usuario_minimo_bd.sql</code>, cambia la contraseña de ejemplo por una larga y ejecútalo como root (phpMyAdmin → SQL).</li>
          <li>Pon ese usuario y su contraseña en <code>config/database.php</code>.</li>
          <li>Ese usuario solo puede leer y escribir datos: no puede borrar tablas ni tocar otras bases. Para importar <code>tickets_db.sql</code> se sigue usando root.</li>
          <li>Vuelve a esta página: el diagnóstico debe marcar «Usuario de la base de datos» en verde.</li>
        </ol>
      </section>
      <section class="panel-box">
        <h2 class="panel-title">Control de versiones (Git)</h2>
        <ol class="pl-18">
          <li>Trabaja el código en un repositorio privado (GitHub o GitLab institucional) con una rama <code>main</code> protegida.</li>
          <li>Cada cambio entra por una rama y una solicitud de cambio revisada; el CI (<code>.github/workflows/ci.yml</code>) corre las pruebas antes de aceptarlo.</li>
          <li>Nunca subas <code>config/*.php</code> reales, <code>storage/</code> ni respaldos: ya están en <code>.gitignore</code>.</li>
          <li>Publica en el servidor solo versiones etiquetadas (<code>git tag v1.4.0</code>) para poder volver atrás.</li>
        </ol>
      </section>
      <section class="panel-box">
        <h2 class="panel-title">Revisiones periódicas sugeridas</h2>
        <ol class="pl-18">
          <li>Cada semana: revisar la bitácora (intentos fallidos y cambios de rol) y verificar el último respaldo.</li>
          <li>Cada mes: revisar cuentas sin uso y permisos en «Usuarios»; depurar la bitácora según la retención.</li>
          <li>Cada semestre: probar la restauración completa en otro equipo y revisar este diagnóstico con la dirección.</li>
          <li>Ante un incidente: registrarlo de inmediato en «Incidentes» y cerrar con la lección aprendida.</li>
        </ol>
      </section>
    </div>

    <?= vfAbrir('vf-config-seguridad', 'Configuración de seguridad', 'HTTPS y HSTS solo se pueden activar entrando por https:// (así no te quedas por fuera).', $abrirForm === 'config', 'lg') ?>
      <?php if ($abrirForm === 'config'): ?><div class="error mb-3"><?= h($flash['mensaje']) ?></div><?php endif; ?>
      <form method="POST" class="form-grid">
        <?= csrfField() ?>
        <input type="hidden" name="form" value="config">
        <p class="panel-subtitle field-full">Acceso</p>
        <div class="field field-full field-check"><label><input type="checkbox" name="seg_forzar_https" value="1" <?= $config['seg_forzar_https'] === '1' ? 'checked' : '' ?><?= $esHttps ? '' : ' disabled' ?>> Forzar HTTPS<?= $esHttps ? '' : ' (entra por https:// para activarlo)' ?></label></div>
        <div class="field field-full field-check"><label><input type="checkbox" name="seg_hsts" value="1" <?= $config['seg_hsts'] === '1' ? 'checked' : '' ?><?= $esHttps ? '' : ' disabled' ?>> Activar HSTS (un año)</label></div>
        <div class="field field-full field-check"><label><input type="checkbox" name="seg_mfa_obligatorio" value="1" <?= $config['seg_mfa_obligatorio'] === '1' ? 'checked' : '' ?>> Verificación en dos pasos obligatoria para admin y técnico</label></div>
        <p class="hint field-full">Al activarla, quien no la tenga configurada deberá hacerlo en su próximo ingreso (escanea un QR con Google Authenticator, Microsoft Authenticator o similar). Configúrala tú primero desde «Mi perfil».</p>
        <p class="panel-subtitle field-full">Reporte por QR (público)</p>
        <div class="field field-full field-check"><label><input type="checkbox" name="seg_qr_captcha" value="1" <?= $config['seg_qr_captcha'] === '1' ? 'checked' : '' ?>> Pedir una pregunta de verificación (suma sencilla)</label></div>
        <div class="field"><label for="seg_qr_max">Reportes anónimos por hora desde una misma red</label><input type="number" id="seg_qr_max" name="seg_qr_max_por_hora" min="1" max="100" value="<?= (int)$config['seg_qr_max_por_hora'] ?>"></div>
        <p class="panel-subtitle field-full">Retención</p>
        <div class="field"><label for="seg_ret_b">Bitácora de accesos (días)</label><input type="number" id="seg_ret_b" name="seg_retencion_bitacora_dias" min="90" max="3650" value="<?= (int)$config['seg_retencion_bitacora_dias'] ?>"></div>
        <div class="field"><label for="seg_ret_i">IP de reportes anónimos (días)</label><input type="number" id="seg_ret_i" name="seg_retencion_ip_dias" min="30" max="1825" value="<?= (int)$config['seg_retencion_ip_dias'] ?>"></div>
        <div class="field"><label for="seg_resp">Respaldos a conservar</label><input type="number" id="seg_resp" name="seg_respaldos_conservar" min="1" max="100" value="<?= (int)$config['seg_respaldos_conservar'] ?>"></div>
        <p class="panel-subtitle field-full">Datos personales (Ley 1581 de 2012)</p>
        <div class="field field-full"><label for="priv_resp">Responsable del tratamiento</label><input type="text" id="priv_resp" name="priv_responsable" maxlength="200" required value="<?= h($config['priv_responsable']) ?>"></div>
        <div class="field field-full"><label for="priv_correo">Correo para consultas y reclamos</label><input type="email" id="priv_correo" name="priv_correo" maxlength="150" value="<?= h($config['priv_correo']) ?>" placeholder="protecciondatos@<?= h(institucion('dominio_correo')) ?>"></div>
        <?php if (!$esHttps): ?>
          <?php /* Las casillas deshabilitadas no se envían: se conserva lo guardado. */ ?>
          <?php if ($config['seg_forzar_https'] === '1'): ?><input type="hidden" name="seg_forzar_https" value="1"><?php endif; ?>
          <?php if ($config['seg_hsts'] === '1'): ?><input type="hidden" name="seg_hsts" value="1"><?php endif; ?>
        <?php endif; ?>
        <button type="submit" name="guardar_config" class="btn btn-sm">Guardar configuración</button>
      </form>
    <?= vfCerrar() ?>
