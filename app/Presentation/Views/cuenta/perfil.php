<?php /** Vista de «Mi perfil» (public/perfil.php). Solo presentación: recibe datos ya preparados por PerfilPage. */ ?>
<?php $paginaTitulo = 'Mi perfil'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1>Mi perfil</h1>
        <p>Información institucional registrada en el sistema.</p>
      </div>
      <div class="page-acciones">
        <button type="button" class="btn btn-secondary btn-sm" data-vf-abrir="vf-foto"><?= !empty($usuario['foto_perfil']) ? 'Cambiar foto' : 'Subir foto' ?></button>
        <a href="cambiar_clave.php" class="btn btn-ghost btn-sm">Cambiar contraseña</a>
      </div>
    </div>

    <?php if (isset($_GET['clave']) && $_GET['clave'] === 'ok'): ?>
      <div class="exito mb-3">Contraseña actualizada correctamente.</div>
    <?php endif; ?>
    <?php if ($exitoFoto): ?><div class="exito mb-3"><?= h($exitoFoto) ?></div><?php endif; ?>
    <?php if ($avisoMfa !== ''): ?><div class="exito mb-3"><?= h($avisoMfa) ?></div><?php endif; ?>

    <div class="panel-box mw-640">
      <div class="perfil-cabecera">
        <span class="app-avatar app-avatar-lg">
          <?php if (!empty($usuario['foto_perfil'])): ?>
            <img src="<?= h(urlFotoPerfil($usuario['foto_perfil'])) ?>" alt="">
          <?php else: ?>
            <?= h(inicialesUsuario($usuario['nombre_completo'])) ?>
          <?php endif; ?>
        </span>
        <div>
          <strong><?= h($usuario['nombre_completo']) ?></strong>
          <p class="hint m-0"><?= h(etiquetaRol($usuario['role'])) ?></p>
        </div>
      </div>

      <table class="data shadow-none">
        <tbody>
        <?php foreach ($filasPerfil as $etiqueta => $valor): ?>
          <?php if ($valor === null || $valor === ''): continue; endif; ?>
          <tr>
            <td data-label="Campo"><strong><?= h($etiqueta) ?></strong></td>
            <td data-label="Valor"><?= h($valor) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($mfaAplica): ?>
    <div class="panel-box mw-640">
      <div class="conexion-cabecera">
        <h2 class="panel-title">Verificación en dos pasos</h2>
        <?= $mfaEstado['activo'] ? '<span class="chip solucionado">Activa</span>' : '<span class="chip espera">Sin activar</span>' ?>
      </div>
      <p class="text-muted"><?= $mfaEstado['activo']
          ? 'Al entrar se pide tu contraseña y un código de tu celular. Te quedan ' . (int)$mfaEstado['restantes'] . ' código(s) de recuperación.'
          : 'Tu rol tiene acceso a información sensible: protege la cuenta con un código del celular además de la contraseña.' ?></p>
      <a href="verificacion.php" class="btn btn-sm<?= $mfaEstado['activo'] ? ' btn-ghost' : '' ?>"><?= $mfaEstado['activo'] ? 'Administrar' : 'Activar ahora' ?></a>
    </div>
    <?php endif; ?>

    <?php /* Foto de perfil en ventana flotante; si la subida falló, se vuelve a abrir con el error. */ ?>
    <?= vfAbrir('vf-foto', 'Foto de perfil', 'JPG, PNG o WEBP · máximo 2 MB. Se recorta en círculo.', (bool)$errorFoto) ?>
      <?php if ($errorFoto): ?><div class="error mb-3"><?= h($errorFoto) ?></div><?php endif; ?>
      <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <div class="field">
          <label for="foto_perfil">Elegir imagen</label>
          <input type="file" id="foto_perfil" name="foto_perfil" accept="image/jpeg,image/png,image/webp" required>
        </div>
        <button type="submit" name="subir_foto" class="btn"><?= !empty($usuario['foto_perfil']) ? 'Cambiar foto' : 'Subir foto' ?></button>
      </form>
      <?php if (!empty($usuario['foto_perfil'])): ?>
        <form method="POST" class="mt-3" data-confirm="¿Quitar tu foto de perfil?">
          <?= csrfField() ?>
          <button type="submit" name="quitar_foto" class="btn btn-ghost btn-sm">Quitar la foto actual</button>
        </form>
      <?php endif; ?>
    <?= vfCerrar() ?>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
