<?php /** Pestaña Becas activas. */ ?>
  <?php /* Becas Activas (dato real de asistencia facial, no de sesión web) */ ?>
  <section class="panel" id="becas-activas">
    <h2>Becas trabajo · estado en tiempo real (asistencia facial)</h2>
    <table class="data">
      <thead>
      <tr><th>Nombre</th><th>Estado</th></tr>
      </thead>
      <tbody>
      <?php foreach($becasUsuarios as $b):
        $codigo = $b['codigo'];
        $estado = $codigo && isset($estadoAsistencia[$codigo]) ? $estadoAsistencia[$codigo] : 'SIN DATO';
        $badgeClass = $estado === 'ACTIVO' ? 'solucionado' : ($estado === 'SIN DATO' ? 'espera' : 'danio');
      ?>
      <tr>
        <td data-label="Nombre"><?= h($b['nombre_completo']) ?></td>
        <td data-label="Estado"><span class="chip <?= $badgeClass ?>"><?= h($estado) ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (empty($codigosBecas)): ?>
      <p class="text-muted mt-2">Ningún becario tiene código registrado aún — sin código no se puede cruzar con la asistencia facial.</p>
    <?php endif; ?>
  </section>

