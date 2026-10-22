<?php
/**
 * Vista de «Seguridad de la información» (public/seguridad.php). Solo presentación: los datos los prepara
 * SeguridadPage. Vistas (desde el menú lateral): Panel · Bitácora de accesos · Respaldos · Incidentes. Formularios en ventanas flotantes.
 */
$paginaTitulo = 'Seguridad'; include LAYOUT_DIR . '/app_page_head.php';
$tituloHeader = 'Seguridad de la información'; include LAYOUT_DIR . '/app_page_body_start.php';
$abrirForm = ($flash && !$flash['ok']) ? (string)($flash['form'] ?? '') : '';
$descripciones = [
    'panel' => 'Estado de los controles de seguridad (ISO/IEC 27001) y configuración del sistema.',
    'bitacora' => 'Quién entró, cuándo y desde dónde; intentos fallidos y cambios sensibles. Los registros no se pueden alterar sin que se note.',
    'respaldos' => 'Copias cifradas de la base de datos y de los archivos. Sin la frase de cifrado nadie puede abrirlas.',
    'incidentes' => 'Registro y seguimiento de incidentes de seguridad: clasificación, contención, causa y lección aprendida.',
];
?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1>Seguridad de la información</h1>
        <p><?= h($descripciones[$vista]) ?></p>
      </div>
    </div>

    <?php if ($flash && $abrirForm === ''): ?>
      <div class="mb-3 <?= $flash['ok'] ? 'exito' : 'error' ?>"><?= h($flash['mensaje']) ?></div>
    <?php endif; ?>

<?php include __DIR__ . '/' . ['panel' => '_panel.php', 'bitacora' => '_bitacora.php', 'respaldos' => '_respaldos.php', 'incidentes' => '_incidentes.php'][$vista]; ?>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
