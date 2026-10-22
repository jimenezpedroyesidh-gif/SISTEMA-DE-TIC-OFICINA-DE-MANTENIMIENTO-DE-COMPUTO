<?php /** Vista de «Restablecer contraseña» (public/restablecer_clave.php). Solo presentación: recibe datos ya preparados por RestablecerClavePage. */ ?>
<?php $paginaPublica = true; $paginaTitulo = 'Restablecer contraseña'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php include LAYOUT_DIR . '/app_publico_inicio.php'; ?>
<div class="card text-center">
    <h2>Restablecer contraseña</h2>

    <?php if (!$valido): ?>
        <div class="error mb-3"><?= $error !== '' ? h($error) : 'El enlace no es válido o ya venció. Solicita uno nuevo.' ?></div>
        <div class="extra-links"><p><a href="recuperar_clave.php">Solicitar un enlace nuevo</a></p></div>
    <?php else: ?>
        <?php if ($error): ?>
            <div class="error mb-3"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <input type="password" name="nueva_clave" placeholder="Nueva contraseña (mín. <?= (int)PasswordReset::MIN_LONGITUD_CLAVE ?> caracteres)" required minlength="<?= (int)PoliticaClave::MIN ?>" autofocus>
            <input type="password" name="confirmar_clave" placeholder="Confirmar nueva contraseña" required minlength="<?= (int)PoliticaClave::MIN ?>">
            <button type="submit" class="btn">Guardar contraseña</button>
        </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
