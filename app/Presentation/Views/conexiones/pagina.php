<?php
/** Vista de «Conexiones» (public/conexiones.php). Solo presentación: los datos los prepara ConexionesPage. Nunca imprime claves ni tokens. */
$paginaTitulo = 'Conexiones'; include LAYOUT_DIR . '/app_page_head.php';
$tituloHeader = 'Conexiones'; include LAYOUT_DIR . '/app_page_body_start.php';

/** Valor visible de un campo: lo guardado, o el valor inicial sugerido. */
$valor = function (string $clave, string $campo, string $inicial = '') use ($estado): string {
    $r = $estado[$clave] ?? null;
    return $r !== null ? (string)($r['datos'][$campo] ?? '') : $inicial;
};
$secreto = function (string $clave, string $campo) use ($estado): bool {
    return !empty($estado[$clave]['secretosGuardados'][$campo]);
};
$chip = function (string $clave) use ($estado): string {
    $r = $estado[$clave] ?? null;
    if ($r === null) return '<span class="chip">Sin configurar</span>';
    if ($r['prueba_ok'] === null) return '<span class="chip espera">Guardada, sin probar</span>';
    return $r['prueba_ok'] ? '<span class="chip solucionado">Conectada</span>' : '<span class="chip danio">Falló la prueba</span>';
};
$pg = ConexionesExternas::ASISTENCIA_PG; $gdb = ConexionesExternas::GLPI_DB; $gapi = ConexionesExternas::GLPI_API;
$pgDef = ConexionesService::PG_PREDETERMINADO;
?>
    <div class="app-dashboard-welcome">
      <h1>Conexiones</h1>
      <p>Bases de datos y servicios externos que usa el sistema. Las contraseñas y tokens se guardan cifrados y nunca se muestran.</p>
    </div>

    <?php
      // Si la última acción falló, su ventana vuelve a abrirse con el error para corregir sin buscarla.
      $abrirCon = ($flash && !$flash['ok']) ? (string)($flash['clave'] ?? '') : '';
    ?>
    <?php if ($flash && $abrirCon === ''): ?>
      <div class="mb-3 <?= $flash['ok'] ? 'callout-success' : 'error' ?>"><?= h($flash['mensaje']) ?></div>
    <?php endif; ?>

    <?php /* Cada conexión es una tarjeta de estado; sus datos se editan en una ventana flotante. */ ?>
    <div class="conexion-grid">
      <section class="panel-box conexion-card" id="<?= h($pg) ?>">
      <div class="conexion-cabecera">
        <h2 class="panel-title"><?= h(ConexionesService::TITULOS[$pg]) ?></h2>
        <?= $chip($pg) ?>
      </div>
      <p class="text-muted">De aquí salen las horas de las becas (Seguimiento de horas) y quién está activo. Solo se lee, nunca se escribe.</p>
      <?php if (!$pgsqlDisponible): ?>
        <div class="error mb-3">El servidor no tiene disponible la conexión a PostgreSQL (extensión <strong>pdo_pgsql</strong>). Pide a quien administra el servidor que la active.</div>
      <?php endif; ?>
      <?php $r = $estado[$pg]; include __DIR__ . '/_ultima_prueba.php'; ?>
        <div class="conexion-pie">
          <button type="button" class="btn btn-sm" data-vf-abrir="vf-con-<?= h($pg) ?>">Configurar</button>
        </div>
      </section>

      <section class="panel-box conexion-card" id="<?= h($gdb) ?>">
      <div class="conexion-cabecera">
        <h2 class="panel-title"><?= h(ConexionesService::TITULOS[$gdb]) ?></h2>
        <?= $chip($gdb) ?>
      </div>
      <p class="text-muted">Trae todo el inventario de GLPI y lo agrupa por tipo y ubicación en <a href="glpi.php">Inventario GLPI</a>. Usa un usuario de MySQL de <strong>solo lectura</strong>.</p>
      <?php $r = $estado[$gdb]; include __DIR__ . '/_ultima_prueba.php'; ?>
        <div class="conexion-pie">
          <button type="button" class="btn btn-sm" data-vf-abrir="vf-con-<?= h($gdb) ?>">Configurar</button>
        </div>
      </section>

      <section class="panel-box conexion-card" id="<?= h($gapi) ?>">
      <div class="conexion-cabecera">
        <h2 class="panel-title"><?= h(ConexionesService::TITULOS[$gapi]) ?></h2>
        <?= $chip($gapi) ?>
      </div>
      <p class="text-muted">Con esto el sistema registra en GLPI los componentes nuevos y los cambios de ubicación que aprueba el técnico (cola en <a href="glpi.php#cola">Inventario GLPI</a>).
        En GLPI: <em>Configuración → General → API</em> (activar la API REST y crear un cliente con su App-Token) y <em>Mis preferencias → Token de API</em> del usuario que hará los cambios.</p>
      <?php if (!$curlDisponible): ?>
        <div class="error mb-3">El servidor no tiene disponible la extensión <strong>curl</strong>. Pide a quien administra el servidor que la active.</div>
      <?php endif; ?>
      <?php $r = $estado[$gapi]; include __DIR__ . '/_ultima_prueba.php'; ?>
        <div class="conexion-pie">
          <button type="button" class="btn btn-sm" data-vf-abrir="vf-con-<?= h($gapi) ?>">Configurar</button>
        </div>
      </section>
    </div>

    <?= vfAbrir('vf-con-' . $pg, 'Configurar · ' . ConexionesService::TITULOS[$pg], 'Guarda los datos o pruébalos antes de guardar. Las claves vacías conservan la guardada.', $abrirCon === $pg, 'lg') ?>
      <?php if ($abrirCon === $pg): ?><div class="error mb-3"><?= h($flash['mensaje']) ?></div><?php endif; ?>
      <form method="POST" class="form-grid">
        <?= csrfField() ?>
        <input type="hidden" name="clave" value="<?= h($pg) ?>">
        <div class="field"><label for="pg_host">Host / IP</label><input id="pg_host" name="host" value="<?= h($valor($pg, 'host', $pgDef['host'])) ?>" placeholder="3.93.129.8"></div>
        <div class="field"><label for="pg_port">Puerto</label><input id="pg_port" name="port" inputmode="numeric" value="<?= h($valor($pg, 'port', $pgDef['port'])) ?>" placeholder="5432"></div>
        <div class="field"><label for="pg_db">Base de datos</label><input id="pg_db" name="dbname" value="<?= h($valor($pg, 'dbname', $pgDef['dbname'])) ?>"></div>
        <div class="field"><label for="pg_user">Usuario</label><input id="pg_user" name="user" autocomplete="off" value="<?= h($valor($pg, 'user', $pgDef['user'])) ?>"></div>
        <div class="field field-full"><label for="pg_pass">Contraseña</label>
          <input id="pg_pass" name="password" type="password" autocomplete="new-password" placeholder="<?= $secreto($pg, 'password') ? '•••••••• guardada — déjala vacía para conservarla' : 'Contraseña del usuario' ?>">
        </div>
        <?php include __DIR__ . '/_acciones.php'; ?>
      </form>
    <?= vfCerrar() ?>

    <?= vfAbrir('vf-con-' . $gdb, 'Configurar · ' . ConexionesService::TITULOS[$gdb], 'Guarda los datos o pruébalos antes de guardar. Las claves vacías conservan la guardada.', $abrirCon === $gdb, 'lg') ?>
      <?php if ($abrirCon === $gdb): ?><div class="error mb-3"><?= h($flash['mensaje']) ?></div><?php endif; ?>
      <form method="POST" class="form-grid">
        <?= csrfField() ?>
        <input type="hidden" name="clave" value="<?= h($gdb) ?>">
        <div class="field"><label for="g_host">IP del servidor de GLPI</label><input id="g_host" name="host" value="<?= h($valor($gdb, 'host')) ?>" placeholder="10.0.0.5"></div>
        <div class="field"><label for="g_port">Puerto</label><input id="g_port" name="port" inputmode="numeric" value="<?= h($valor($gdb, 'port', '3306')) ?>" placeholder="3306"></div>
        <div class="field"><label for="g_db">Base de datos</label><input id="g_db" name="dbname" value="<?= h($valor($gdb, 'dbname', 'glpi')) ?>" placeholder="glpi"></div>
        <div class="field"><label for="g_user">Usuario (solo lectura)</label><input id="g_user" name="user" autocomplete="off" value="<?= h($valor($gdb, 'user')) ?>"></div>
        <div class="field field-full"><label for="g_pass">Contraseña</label>
          <input id="g_pass" name="password" type="password" autocomplete="new-password" placeholder="<?= $secreto($gdb, 'password') ? '•••••••• guardada — déjala vacía para conservarla' : 'Contraseña del usuario' ?>">
        </div>
        <?php include __DIR__ . '/_acciones.php'; ?>
      </form>
    <?= vfCerrar() ?>

    <?= vfAbrir('vf-con-' . $gapi, 'Configurar · ' . ConexionesService::TITULOS[$gapi], 'Guarda los datos o pruébalos antes de guardar. Las claves vacías conservan la guardada.', $abrirCon === $gapi, 'lg') ?>
      <?php if ($abrirCon === $gapi): ?><div class="error mb-3"><?= h($flash['mensaje']) ?></div><?php endif; ?>
      <form method="POST" class="form-grid">
        <?= csrfField() ?>
        <input type="hidden" name="clave" value="<?= h($gapi) ?>">
        <div class="field field-full"><label for="a_url">URL de la API</label><input id="a_url" name="url" value="<?= h($valor($gapi, 'url')) ?>" placeholder="http://10.0.0.5/glpi/apirest.php"></div>
        <div class="field"><label for="a_app">App-Token</label>
          <input id="a_app" name="app_token" type="password" autocomplete="off" placeholder="<?= $secreto($gapi, 'app_token') ? '•••••••• guardado — déjalo vacío para conservarlo' : 'App-Token del cliente API' ?>"></div>
        <div class="field"><label for="a_user">Token de usuario</label>
          <input id="a_user" name="user_token" type="password" autocomplete="off" placeholder="<?= $secreto($gapi, 'user_token') ? '•••••••• guardado — déjalo vacío para conservarlo' : 'Token de API del usuario' ?>"></div>
        <div class="field field-full"><label for="a_bodega">Ubicación de GLPI para bodega / oficina de mantenimiento</label>
          <?php if ($ubicacionesGlpi): ?>
            <select id="a_bodega" name="ubicacion_bodega">
              <option value="">— Elegir —</option>
              <?php foreach ($ubicacionesGlpi as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (string)$u['id'] === $valor($gapi, 'ubicacion_bodega') ? 'selected' : '' ?>><?= h($u['completename']) ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input id="a_bodega" name="ubicacion_bodega" inputmode="numeric" value="<?= h($valor($gapi, 'ubicacion_bodega')) ?>" placeholder="id de la ubicación en GLPI (conecta la base de GLPI para elegirla de una lista)">
          <?php endif; ?>
          <small class="text-muted">A dónde se mueve en GLPI un componente que sale de una sala hacia revisión/bodega.</small>
        </div>
        <?php include __DIR__ . '/_acciones.php'; ?>
      </form>
    <?= vfCerrar() ?>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
