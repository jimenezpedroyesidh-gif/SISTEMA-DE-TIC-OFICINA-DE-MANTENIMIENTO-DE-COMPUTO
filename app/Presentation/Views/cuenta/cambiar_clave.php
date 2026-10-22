<?php /** Vista de «Cambiar contraseña» (public/cambiar_clave.php). Solo presentación: recibe datos ya preparados por CambiarClavePage. */ ?>
<?php $paginaPublica = true; $paginaTitulo = 'Cambiar contraseña'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php if ($obligatorio): ?>
<?php /* Clave temporal: layout mínimo sin menú, porque el servidor bloquea cualquier otra página hasta cambiarla. */ ?>
<?php include LAYOUT_DIR . '/app_publico_inicio.php'; ?>
  <div class="card text-center">
    <h2>Cambiar contraseña</h2>
    <p class="hint">Tu cuenta tiene una contraseña temporal. Debes cambiarla antes de continuar.</p>

    <?php if ($error): ?>
        <div class="error mb-3"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <?= csrfField() ?>
        <input type="password" name="clave_actual" placeholder="Contraseña temporal que te dieron" required>
        <input type="password" name="nueva_clave" placeholder="Nueva contraseña (mín. <?= (int)PoliticaClave::MIN ?> caracteres)" required minlength="<?= (int)PoliticaClave::MIN ?>">
        <input type="password" name="confirmar_clave" placeholder="Confirmar nueva contraseña" required minlength="<?= (int)PoliticaClave::MIN ?>">
        <button type="submit" class="btn">Guardar contraseña</button>
    </form>
  </div>
</div>
<?php else: ?>
<?php /* Cambio VOLUNTARIO (desde perfil.php): usuario con acceso normal,
     así que sí lleva el layout estándar del resto del sistema. */ ?>
<?php $tituloHeader = 'Cambiar contraseña'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <section class="panel-box mw-420 m-0-auto">
      <h2 class="panel-title">Cambiar contraseña</h2>

      <?php if ($error): ?>
          <div class="error mb-3"><?= h($error) ?></div>
      <?php endif; ?>

      <form method="POST">
          <?= csrfField() ?>
          <div class="field">
            <label for="clave_actual">Contraseña actual</label>
            <input type="password" id="clave_actual" name="clave_actual" required>
          </div>
          <div class="field">
            <label for="nueva_clave">Nueva contraseña (mín. <?= (int)PoliticaClave::MIN ?> caracteres)</label>
            <input type="password" id="nueva_clave" name="nueva_clave" required minlength="<?= (int)PoliticaClave::MIN ?>">
          </div>
          <div class="field">
            <label for="confirmar_clave">Confirmar nueva contraseña</label>
            <input type="password" id="confirmar_clave" name="confirmar_clave" required minlength="<?= (int)PoliticaClave::MIN ?>">
          </div>
          <button type="submit" class="btn">Guardar contraseña</button>
      </form>

      <div class="extra-links mt-3">
          <p><a href="perfil.php">← Volver a mi perfil</a></p>
      </div>
    </section>
  </main>
</div>
<?php endif; ?>
</body>
</html>
