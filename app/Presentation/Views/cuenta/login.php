<?php /** Vista de «Iniciar sesión» (public/login.php). Solo presentación: recibe datos ya preparados por LoginPage. */ ?>
<?php $paginaPublica = true; $paginaTitulo = 'Iniciar sesión'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php include LAYOUT_DIR . '/app_publico_inicio.php'; ?>
<div class="card text-center">
    <h2>Iniciar sesión</h2>

    <?php if ($error): ?>
        <div class="error mb-3"><?= h($error) ?></div>
    <?php endif; ?>
    <?php if ($aviso): ?>
        <div class="exito mb-3"><?= h($aviso) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="text" name="codigo" placeholder="Código institucional" required autofocus>
        <input type="email" name="correo" placeholder="Correo institucional" required>
        <input type="password" name="password" placeholder="Contraseña" required>
        <button type="submit" class="btn">Ingresar</button>
    </form>

    <?php if ($googleHabilitado): ?>
        <p class="mt-3"><a class="btn btn-ghost" href="google_login.php">Ingresar con Google</a></p>
    <?php endif; ?>

    <div class="extra-links">
        <p><a href="recuperar_clave.php">¿Olvidaste tu contraseña?</a></p>
        <p>¿Olvidaste tu código o correo? Contacta al administrador del sistema.</p>
        <p><a href="aviso_privacidad.php">Aviso de privacidad</a></p>
    </div>
  </div>
</div>
</body>
</html>
