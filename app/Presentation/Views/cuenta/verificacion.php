<?php
/**
 * Vista de «Verificación en dos pasos» (public/verificacion.php). Solo presentación: los datos los prepara
 * VerificacionPage. Mientras el ingreso está a medias (código pendiente o configuración obligatoria) se usa
 * el layout centrado del inicio de sesión; desde «Mi perfil», el layout normal con menú.
 */
$scriptsExtra = $secreto !== '' ? ['assets/vendor/qrcodejs-1.0.0/qrcode.min.js'] : [];
$paginaTitulo = 'Verificación en dos pasos'; include LAYOUT_DIR . '/app_page_head.php';
if ($conShell) {
    $tituloHeader = 'Verificación en dos pasos'; include LAYOUT_DIR . '/app_page_body_start.php';
    echo '<section class="panel-box mw-520 m-0-auto">';
} else {
    include LAYOUT_DIR . '/app_publico_inicio.php';
    echo '<div class="card mw-520">';
}
?>
    <h2 class="panel-title">Verificación en dos pasos</h2>
    <?php if ($error): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>

    <?php if ($codigosRecuperacion !== null): ?>
      <div class="exito mb-3">Listo: la verificación en dos pasos quedó activa.</div>
      <p><strong>Guarda estos códigos de recuperación.</strong> Cada uno sirve una sola vez para entrar si pierdes o cambias el celular. No se volverán a mostrar.</p>
      <ul class="codigos-recuperacion">
        <?php foreach ($codigosRecuperacion as $c): ?><li><?= h($c) ?></li><?php endforeach; ?>
      </ul>
      <p class="hint-peligro">Cópialos en tu gestor de contraseñas o imprímelos y guárdalos en un lugar seguro.</p>
      <form method="POST" class="mt-3">
        <?= csrfField() ?>
        <button type="submit" name="continuar" class="btn">Ya los guardé, continuar</button>
      </form>

    <?php elseif ($modo === 'verificar'): ?>
      <p>Abre tu app autenticadora (Google Authenticator, Microsoft Authenticator…) y escribe el código de 6 dígitos de <strong><?= h(Totp::emisor()) ?></strong>.</p>
      <form method="POST">
        <?= csrfField() ?>
        <div class="field">
          <label for="codigo">Código</label>
          <input type="text" id="codigo" name="codigo" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus placeholder="123456">
        </div>
        <button type="submit" name="verificar_codigo" class="btn">Verificar</button>
      </form>
      <p class="hint mt-3">¿No tienes el celular? Escribe uno de tus códigos de recuperación (formato ABCD-EFGH). Si tampoco los tienes, pide al administrador que reinicie tu verificación.</p>
      <p class="mt-3"><a href="logout.php">Cancelar y salir</a></p>

    <?php elseif ($secreto !== ''): ?>
      <?php if ($modo === 'inscribir'): ?>
        <div class="nota-info mb-3">El administrador hizo obligatoria la verificación en dos pasos para tu rol. Configúrala para continuar (toma un minuto).</div>
      <?php else: ?>
        <p>Además de la contraseña, al entrar se pedirá un código que cambia cada 30 segundos en tu celular. Así nadie entra con tu cuenta aunque conozca la clave.</p>
      <?php endif; ?>
      <ol class="pl-18">
        <li>Instala en tu celular una app autenticadora: Google Authenticator, Microsoft Authenticator, Authy o FreeOTP.</li>
        <li>En la app elige «Agregar cuenta» → «Escanear código QR» y escanea este código:
          <div class="text-center my-2"><div class="qr-mfa" data-qr-texto="<?= h($uriQr) ?>" aria-label="Código QR para la app autenticadora"></div></div>
          <span class="hint">¿No puedes escanear? Elige «Ingresar clave» y escribe: <code><?= h(trim(chunk_split($secreto, 4, ' '))) ?></code> (basada en tiempo).</span>
        </li>
        <li>Escribe el código de 6 dígitos que muestra la app:</li>
      </ol>
      <form method="POST">
        <?= csrfField() ?>
        <div class="field">
          <label for="codigo">Código de la app</label>
          <input type="text" id="codigo" name="codigo" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required placeholder="123456">
        </div>
        <button type="submit" name="confirmar_inscripcion" class="btn">Activar</button>
      </form>
      <?php if ($modo === 'inscribir'): ?><p class="mt-3"><a href="logout.php">Salir</a></p><?php endif; ?>

    <?php else: ?>
      <div class="exito mb-3">La verificación en dos pasos está activa<?= $estadoMfa['activado_en'] ? ' desde el ' . h(date('d/m/Y', strtotime((string)$estadoMfa['activado_en']))) : '' ?>.</div>
      <p>Te quedan <strong><?= (int)$estadoMfa['restantes'] ?></strong> código(s) de recuperación. Si se te acaban o cambias de celular, desactívala y vuelve a activarla (o pide al administrador que la reinicie).</p>
      <?php if ($obligatoria): ?>
        <p class="hint">Es obligatoria para tu rol: no se puede desactivar.</p>
      <?php else: ?>
        <form method="POST" class="mt-3" data-confirm="¿Desactivar la verificación en dos pasos? Tu cuenta quedará protegida solo con la contraseña.">
          <?= csrfField() ?>
          <div class="field">
            <label for="codigo">Código actual de la app (o uno de recuperación)</label>
            <input type="text" id="codigo" name="codigo" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required>
          </div>
          <button type="submit" name="desactivar" class="btn btn-danger btn-sm">Desactivar</button>
        </form>
      <?php endif; ?>
      <p class="mt-3"><a href="perfil.php">← Volver a mi perfil</a></p>
    <?php endif; ?>
<?php if ($conShell): ?>
    </section>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
<?php else: ?>
  </div>
</div>
</body>
</html>
<?php endif; ?>
