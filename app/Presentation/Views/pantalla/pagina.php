<?php
/**
 * Pantalla de la oficina de mantenimiento (televisor). Sin menú lateral ni header: ocupa toda la pantalla.
 * Solo presentación: $mantenimientos, $errores y $actualizado los prepara PantallaPage.
 */
$paginaPublica = true; // sin app.js ni shell: solo el script propio de la pantalla
$scriptsExtra = ['assets/pantalla.js' => 'defer'];
$paginaTitulo = 'Pantalla de mantenimiento';
include LAYOUT_DIR . '/app_page_head.php';
?>
</head>
<body class="tv">
<header class="tv-head">
  <div class="tv-marca">
    <strong>Oficina de Mantenimiento</strong>
    <span>Departamento de Sistemas e Informática · Aula Sur</span>
  </div>
  <div class="tv-reloj">
    <span class="tv-hora" id="tv-hora"><?= date('H:i') ?></span>
    <span class="tv-fecha" id="tv-fecha"><?= h(HorarioService::DIAS[(int)date('N')] ?? '') ?> <?= date('j/m/Y') ?></span>
  </div>
  <div class="tv-acciones">
    <button type="button" class="tv-btn" id="tv-fullscreen">Pantalla completa</button>
    <?php if (($_SESSION['role'] ?? '') === Rol::VISUALIZACION): ?>
      <a class="tv-btn tv-btn-ghost" href="logout.php">Salir</a>
    <?php else: ?>
      <a class="tv-btn tv-btn-ghost" href="tickets.php">Volver</a>
    <?php endif; ?>
  </div>
</header>

<main id="tv-columnas" data-url="pantalla.php?parcial=1" aria-live="polite">
  <?php require __DIR__ . '/columnas.php'; ?>
</main>
</body>
</html>
