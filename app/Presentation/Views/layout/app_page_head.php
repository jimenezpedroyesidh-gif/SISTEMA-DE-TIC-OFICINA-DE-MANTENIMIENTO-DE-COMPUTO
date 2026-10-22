<?php
/**
 * Esqueleto común de página, parte 1: <!DOCTYPE> hasta los estilos.
 * Variables:
 *   $paginaTitulo   texto del <title> (sin escapar; aquí se escapa); se le añade « · <sigla>».
 *   $paginaPublica  true = variante «página pública» (login, reportar, consultar, recuperar, restablecer, alta de usuario, inicio):
 *                   misma hoja de estilos y cabecera, sin app.js (no hay shell ni sesión que lo necesite).
 *   $scriptsExtra  scripts extra del <head>: lista de rutas, o ruta => 'defer'. Sustituye los <script> sueltos de cada vista.
 * Después de incluirlo, la página puede escribir sus propios <script>/<style> del <head>
 * y luego incluir app_page_body_start.php (páginas con shell) o app_publico_inicio.php (páginas públicas).
 */

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(implode(' · ', array_filter([(string)($paginaTitulo ?? ''), institucion('sigla')])), ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="<?= h(hojaDeEstilos()) ?>">
<?php if (empty($paginaPublica)): ?>
<script src="<?= h(asset('assets/app.js')) ?>"></script>
<?php endif; ?>
<?php foreach ((array)($scriptsExtra ?? []) as $k => $v):
    $ruta = is_int($k) ? $v : $k; $defer = !is_int($k) && $v === 'defer'; ?>
<script src="<?= h(asset((string)$ruta)) ?>"<?= $defer ? ' defer' : '' ?>></script>
<?php endforeach; ?>
