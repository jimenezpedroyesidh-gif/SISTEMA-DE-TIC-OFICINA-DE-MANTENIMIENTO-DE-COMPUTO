<?php /** Vista de la portada pública (public/index.php). Solo presentación. */
$paginaPublica = true; $paginaTitulo = institucion('sistema');
include LAYOUT_DIR . '/app_page_head.php';
include LAYOUT_DIR . '/app_publico_inicio.php';
?>
  <div class="card text-center">
    <h1><?= h(institucion('sistema') . ' · ' . institucion('sigla')) ?></h1>
    <p class="mt-4"><?= h(institucion('oficina')) ?><br><?= h(institucion('ubicacion')) ?></p>
    <p class="mt-4"><a href="login.php" class="btn">Iniciar sesión</a></p>
    <p class="mt-3"><a href="consultar_estado.php" class="btn btn-secondary">Consultar estado de mi reporte (sin cuenta)</a></p>
    <p class="hint mt-4"><?= h(institucion('nombre')) ?> &mdash; <?= h(institucion('ciudad')) ?></p>
    <p class="hint"><?= h(institucion('oficina')) ?> &mdash; <?= h(institucion('contacto')) ?></p>
    <p class="hint"><a href="aviso_privacidad.php">Aviso de privacidad y tratamiento de datos</a></p>
  </div>
</div>
</body>
</html>
