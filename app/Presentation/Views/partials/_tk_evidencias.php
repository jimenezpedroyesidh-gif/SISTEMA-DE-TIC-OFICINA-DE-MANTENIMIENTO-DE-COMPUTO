<?php
/**
 * Galería de fotos de evidencia de un ticket (miniaturas que abren la foto completa). Las imágenes se
 * sirven por descargar_adjunto.php, que valida sesión y permisos (storage/ no es público).
 *
 * Variables:
 *   $evFotos     TicketEvidencia::deTickets()[tabla][id] (puede ser [])
 *   $evQuitarId  id del usuario que puede quitar SUS fotos (null = nadie); solo mientras el ticket esté abierto
 */
$evFotos = $evFotos ?? [];
?>
<?php if ($evFotos): ?>
  <div class="tk-galeria">
    <?php foreach ($evFotos as $f): ?>
      <figure class="tk-foto">
        <a href="descargar_adjunto.php?id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener">
          <img src="descargar_adjunto.php?id=<?= (int)$f['id'] ?>" alt="Evidencia del ticket" loading="lazy">
        </a>
        <figcaption><?= h(date('d/m H:i', strtotime((string)$f['fecha_subida']))) ?></figcaption>
        <?php if (!empty($evQuitarId) && (int)($f['id_usuario_subio'] ?? 0) === (int)$evQuitarId): ?>
          <form method="POST" class="m-0" data-confirm="¿Quitar esta foto?">
            <?= csrfField() ?>
            <input type="hidden" name="id_adjunto" value="<?= (int)$f['id'] ?>">
            <button class="tk-foto-quitar" name="evidencia_quitar" aria-label="Quitar foto">&times;</button>
          </form>
        <?php endif; ?>
      </figure>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php unset($evFotos, $evQuitarId); ?>
