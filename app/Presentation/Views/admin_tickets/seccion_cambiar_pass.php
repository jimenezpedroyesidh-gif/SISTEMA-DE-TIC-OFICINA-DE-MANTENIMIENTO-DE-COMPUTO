<?php /** Pestaña Cambiar contraseña del admin. */ ?>
  <?php /* Cambiar Contraseña */ ?>
  <section class="panel" id="cambiar-pass">
    <h2>Cambiar contraseña</h2>
    <?php if(isset($mensaje_pass)): ?><p class="exito mb-3"><?= h($mensaje_pass) ?></p><?php endif; ?>
    <form method="POST">
      <?= csrfField() ?>
      <div class="field">
        <label>Contraseña actual</label>
        <input type="password" name="clave_actual_admin" required>
      </div>
      <div class="field">
        <label>Nueva Contraseña</label>
        <input type="password" name="nueva_pass" required minlength="<?= (int)PoliticaClave::MIN ?>">
      </div>
      <button type="submit" name="cambiar_pass" class="btn btn-info">Cambiar Contraseña</button>
    </form>
  </section>
