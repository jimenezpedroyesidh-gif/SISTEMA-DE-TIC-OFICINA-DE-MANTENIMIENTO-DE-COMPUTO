<?php
/**
 * Esqueleto común de página, parte 2: cierra <head>, abre <body>, encabezado, barra lateral y <main
 * class="app-main">. Variable: $tituloHeader (se pasa a app_header.php). Requiere lo mismo que app_header.php
 * (sesión, ui_shell.php cargado por bootstrap y $shell preparado por el controlador).
 */
?>
</head>
<body>
<?php include __DIR__ . '/app_header.php'; ?>
<div class="app-shell">
  <?php include __DIR__ . '/app_sidebar.php'; ?>
  <main class="app-main">
