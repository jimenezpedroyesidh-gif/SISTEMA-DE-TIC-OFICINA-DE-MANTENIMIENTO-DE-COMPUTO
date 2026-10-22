<?php /** Sección Becas trabajo. */ ?>
<section class="panel-box" id="becas-trabajo">
  <div class="sec-title">
    <h2 class="panel-title">Becas trabajo</h2>
  </div>
  <div>
    <?php foreach($becas_activos as $b):
      $estado = $b['codigo'] && isset($estadoAsistenciaBecas[$b['codigo']]) ? $estadoAsistenciaBecas[$b['codigo']] : 'SIN DATO';
      $chipClass = $estado === 'ACTIVO' ? 'solucionado' : ($estado === 'SIN DATO' ? 'espera' : 'proceso');
    ?>
      <span class="chip <?= $chipClass ?>">
        <?= h($b['nombre_completo']) ?> — <?= $estado === 'ACTIVO' ? '● Activo (asistencia)' : ($estado === 'SIN DATO' ? '● Sin código registrado' : '● Inactivo') ?>
      </span>
    <?php endforeach; ?>
  </div>
</section>
