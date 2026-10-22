<?php
/**
 * Galería de evidencias de una solución técnica (capturas de un procedimiento, fotos de una verificación o de la
 * ejecución en un equipo). Fotos en miniatura y PDF como enlace; todo se sirve por descargar_solucion.php, que valida
 * sesión y permisos (storage/ no es público). Mismo componente visual que la evidencia de tickets (.tk-galeria).
 *
 * Variable: $galeria = filas de SolucionRepository::evidenciasPor() (puede ser []).
 */
?>
<?php if (!empty($galeria)): ?>
  <div class="tk-galeria">
    <?php foreach ($galeria as $ev): $urlEv = 'descargar_solucion.php?evidencia=' . (int)$ev['id_evidencia']; ?>
      <figure class="tk-foto">
        <a href="<?= h($urlEv) ?>" target="_blank" rel="noopener" title="<?= h($ev['descripcion'] ?: $ev['nombre_original']) ?>">
          <?php if ($ev['mime_type'] === 'application/pdf'): ?>
            <span class="chip">PDF</span> <?= h($ev['nombre_original']) ?>
          <?php else: ?>
            <img src="<?= h($urlEv) ?>" alt="<?= h($ev['descripcion'] ?: 'Evidencia') ?>" loading="lazy">
          <?php endif; ?>
        </a>
        <figcaption><?= h(date('d/m H:i', strtotime((string)$ev['fecha_subida']))) ?> · <?= h($ev['subio']) ?></figcaption>
      </figure>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
