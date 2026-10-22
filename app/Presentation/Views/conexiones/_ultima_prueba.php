<?php /** Resultado de la última prueba de una conexión ($r = resumen de ConexionesExternas, o null). */ ?>
      <?php if (!empty($r['prueba_en'])): ?>
        <p class="hint mt-2"><?= $r['prueba_ok'] ? '' : '' ?> Última prueba <?= h(date('d/m/Y H:i', strtotime((string)$r['prueba_en']))) ?>: <?= h((string)$r['prueba_mensaje']) ?></p>
      <?php elseif (!empty($r['actualizado_en'])): ?>
        <p class="hint mt-2">Guardada el <?= h(date('d/m/Y H:i', strtotime((string)$r['actualizado_en']))) ?>. Aún no se ha probado.</p>
      <?php endif; ?>
