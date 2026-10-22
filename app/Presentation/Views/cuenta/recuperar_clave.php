<?php /** Vista de «Recuperar contraseña» (public/recuperar_clave.php). Solo presentación: recibe datos ya preparados por RecuperarClavePage. */ ?>
<?php $paginaPublica = true; $paginaTitulo = 'Recuperar contraseña'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php include LAYOUT_DIR . '/app_publico_inicio.php'; ?>
<div class="card text-center">
    <h2>Recuperar contraseña</h2>

    <?php if ($enviado): ?>
        <div class="exito mb-3">Si el correo está registrado y activo, te enviamos un enlace para restablecer tu contraseña. Revisa tu bandeja (y el spam). El enlace vence en <?= (int)PasswordReset::VIGENCIA_MINUTOS ?> minutos.</div>
    <?php else: ?>
        <p class="hint">Escribe tu correo institucional. Te enviaremos un enlace para elegir una contraseña nueva (también desbloquea tu cuenta si fue bloqueada).</p>
        <?php if ($error): ?>
            <div class="error mb-3"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <?= csrfField() ?>
            <input type="email" name="correo" placeholder="Correo institucional" required autofocus maxlength="100">
            <button type="submit" class="btn">Enviar enlace</button>
        </form>
    <?php endif; ?>

    <div class="extra-links"><p><a href="login.php">← Volver a iniciar sesión</a></p></div>
  </div>
</div>
</body>
</html>
