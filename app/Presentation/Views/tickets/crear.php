<?php /** Vista de «Crear ticket» del solicitante y la secretaría (public/create_ticket.php). Solo presentación: recibe datos ya preparados por CrearTicketPage. */ ?>
<?php $paginaTitulo = 'Nuevo ticket'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php $tituloHeader = 'Nuevo ticket'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <section class="panel-box mw-520 m-0-auto">
      <h2 class="panel-title">Nuevo ticket</h2>

      <?php if($error): ?><div class="error mb-3"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="POST">
        <?= csrfField() ?>
        <?php if($rolUsuario === Rol::SECRETARIA): ?>
        <p class="text-muted">Equipos de cómputo que otras dependencias traen a la <strong><?= htmlspecialchars($oficinaMantenimiento) ?></strong>. Los daños de las salas se reportan por QR o los registra el técnico.</p>
        <div class="field">
          <label for="dependencia">Dependencia de donde viene el equipo</label>
          <input type="text" id="dependencia" name="dependencia" maxlength="100" required value="<?= htmlspecialchars((string)($viejo['dependencia'] ?? '')) ?>" placeholder="Ej: Facultad de Ingeniería, Biblioteca…">
        </div>
        <div class="field">
          <label for="equipo">Equipo de cómputo</label>
          <input type="text" id="equipo" name="equipo" maxlength="100" required value="<?= htmlspecialchars((string)($viejo['equipo'] ?? '')) ?>" placeholder="Ej: torre, portátil, todo en uno, impresora…">
        </div>
        <div class="form-grid">
          <div class="field">
            <label for="marca_modelo">Marca / modelo (opcional)</label>
            <input type="text" id="marca_modelo" name="marca_modelo" maxlength="100" value="<?= htmlspecialchars((string)($viejo['marca_modelo'] ?? '')) ?>">
          </div>
          <div class="field">
            <label for="numero_inventario">N.º de inventario (opcional)</label>
            <input type="text" id="numero_inventario" name="numero_inventario" maxlength="50" value="<?= htmlspecialchars((string)($viejo['numero_inventario'] ?? '')) ?>">
          </div>
        </div>
        <div class="field">
          <label for="radicado_datarsoft">N.º de radicado DatArSoft (opcional)</label>
          <input type="text" id="radicado_datarsoft" name="radicado_datarsoft" maxlength="40" autocomplete="off" value="<?= htmlspecialchars((string)($viejo['radicado_datarsoft'] ?? '')) ?>">
          <p class="hint">Para los servicios que llegan de otras dependencias con radicado en DatArSoft.</p>
        </div>
        <?php else: ?>
        <div class="field">
          <label for="dependencia">Dependencia</label>
          <input type="text" id="dependencia" name="dependencia" required>
        </div>

        <div class="field">
          <label for="ubicacion">Ubicación</label>
          <input type="text" id="ubicacion" name="ubicacion" required>
        </div>

        <div class="field">
          <label for="equipo">Equipo</label>
          <input type="text" id="equipo" name="equipo" required>
        </div>
        <?php endif; ?>

        <div class="field">
          <label for="descripcion">Descripción</label>
          <textarea id="descripcion" name="descripcion" required></textarea>
        </div>

        <div class="field">
          <label for="categoria">Categoría (opcional)</label>
          <select id="categoria" name="categoria">
            <option value="">Sin clasificar</option>
            <option value="HARDWARE">Hardware</option>
            <option value="RED">Red</option>
            <option value="SOFTWARE">Software</option>
            <option value="IMPRESORA">Impresora</option>
            <option value="PROYECTOR">Proyector</option>
            <option value="INFRAESTRUCTURA">Infraestructura</option>
            <option value="OTRO">Otro</option>
            <option value="SEGURIDAD">Incidente de seguridad (virus, acceso indebido, pérdida de datos)</option>
          </select>
        </div>

        <button type="submit" class="btn">Crear</button>
      </form>
    </section>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
