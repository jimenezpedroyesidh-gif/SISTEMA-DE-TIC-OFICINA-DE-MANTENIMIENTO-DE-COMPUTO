<?php /** Vista pública del reporte de daño por QR (public/reportar.php). Solo presentación: recibe datos ya preparados por ReportarPage. */ ?>
<?php $paginaPublica = true;
// Plano de equipos (con y sin sesión): su archivo JS lo carga el head común (la vista no lleva etiquetas de script).
if (!empty($equiposSala) || !empty($frenteSala)) $scriptsExtra = ['assets/plano_sala.js' => 'defer'];
$paginaTitulo = 'Reportar daño · ' . $sala['nombre_sala']; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php include LAYOUT_DIR . '/app_publico_inicio.php'; ?>
<div class="card<?= !empty($equiposSala) || !empty($frenteSala) ? ' mw-640' : '' ?>">
<h2>Reportar daño</h2>
<p class="text-muted mb-3"><?= h($sala['nombre_sala']) ?></p>

<?php if ($exito): ?>
    <div class="exito">Reporte enviado. Gracias — el equipo de mantenimiento lo revisará pronto.</div>
    <div class="card mt-3 card-dashed">
      <p><strong>Guarda este código para consultar el estado más adelante (sin necesidad de cuenta):</strong></p>
      <p class="fs-14r ls-2 fw-bold"><?= h($codigoSeguimiento) ?></p>
      <p class="text-muted">Podrás consultarlo en <a href="consultar_estado.php">Consultar estado de mi reporte</a> desde la página principal.</p>
    </div>
<?php elseif ($duplicado): ?>
    <div class="exito">Este daño ya fue reportado antes y sigue en curso (estado actual: <strong><?= h($estadoExistente) ?></strong>). El equipo de mantenimiento ya lo tiene identificado — no es necesario volver a reportarlo.</div>
<?php else: ?>
    <?php if ($error): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
    <?php if ($profesorLogueado): ?>
    <p class="text-muted mb-3">Sesión iniciada como <?= h($nombreSesion) ?> — este reporte quedará guardado a tu nombre en tu panel.</p>
    <?php endif; ?>
    <form method="POST">
        <?= csrfField() ?>
        <?php if (!$profesorLogueado): ?>
        <div class="field">
            <label for="nombre_contacto">Tu nombre completo</label>
            <input type="text" id="nombre_contacto" name="nombre_contacto" required minlength="3" maxlength="100" autocomplete="name" value="<?= h($nombreContactoPost) ?>">
        </div>
        <div class="field">
            <label for="correo_contacto">Correo institucional</label>
            <input type="email" id="correo_contacto" name="correo_contacto" required maxlength="150" autocomplete="email" inputmode="email"
                   placeholder="nombre@<?= h(institucion('dominio_correo')) ?>" pattern="<?= h(correoPatronHtmlInstitucional()) ?>"
                   title="Debe ser tu correo @<?= h(institucion('dominio_correo')) ?>" value="<?= h($correoContactoPost) ?>">
            <p class="hint">Solo @<?= h(institucion('dominio_correo')) ?>. Lo usamos para contactarte sobre este reporte y enviarte el código de seguimiento.</p>
        </div>
        <?php endif; ?>
        <div class="field">
            <label>Describe el problema</label>
            <textarea name="descripcion" required placeholder="Ej: el videobeam no enciende, dos torres no prenden, el aire acondicionado gotea..."></textarea>
            <?php if (empty($equiposSala) && empty($frenteSala)): ?>
            <p class="text-muted mt-1">No necesitas indicar cuál equipo es — el técnico o el becario que tome este reporte identificará el equipo puntual al revisar la sala.</p>
            <?php endif; ?>
        </div>
        <?php if (!empty($equiposSala) || !empty($frenteSala)): ?>
        <div class="field">
            <label>Equipos con falla (opcional)</label>
            <p class="hint">Toca en el plano los equipos que fallan, como al elegir sillas. Si no sabes cuál es, déjalo sin marcar.</p>
            <?php $equiposSelector = $equiposSala; $nombreCampoSelector = 'ids_equipo_danio[]'; $seleccionadosSelector = $idsEquipoPost ?? []; $estadosSelector = $estadosSala ?? []; $frenteSelector = $frenteSala ?? []; $compsSeleccionadosSelector = $idsComponentePost ?? []; $distribucionSelector = $distribucionSala ?? null; include PARCIALES_DIR . '/plano_sala_selector.php'; ?>
        </div>
        <?php endif; ?>
        <?php if (!$profesorLogueado): ?>
        <?php /* Campo trampa: invisible para las personas; si un robot lo llena, el reporte se descarta. */ ?>
        <div class="sr-only" aria-hidden="true">
            <label for="<?= h(AntiSpamQr::CAMPO_TRAMPA) ?>">No llenes este campo</label>
            <input type="text" id="<?= h(AntiSpamQr::CAMPO_TRAMPA) ?>" name="<?= h(AntiSpamQr::CAMPO_TRAMPA) ?>" tabindex="-1" autocomplete="off">
        </div>
        <?php if ($preguntaReto !== ''): ?>
        <div class="field">
            <label for="reto">Verificación: <?= h($preguntaReto) ?></label>
            <input type="text" id="reto" name="reto" inputmode="numeric" maxlength="3" required autocomplete="off" placeholder="Escribe el resultado">
        </div>
        <?php endif; ?>
        <div class="field field-check">
            <label><input type="checkbox" name="acepto_privacidad" value="1" required> <span>Acepto el <a href="aviso_privacidad.php" target="_blank" rel="noopener">aviso de privacidad</a> (se guardan tu nombre, tu correo institucional y la IP del envío para contactarte y evitar abusos).</span></label>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn">Enviar reporte</button>
    </form>
<?php endif; ?>
</div>
</div>
</body>
</html>
