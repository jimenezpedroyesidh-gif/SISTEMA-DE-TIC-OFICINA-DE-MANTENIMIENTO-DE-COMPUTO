<?php /** Vista parcial de «Mantenimientos programados» (filtro). Solo presentación: recibe datos ya preparados por MantenimientosPage. */ ?>
<?php if ($puedeVerCompleto): ?>
<?php /* Filtro como barra compacta (no ventana): los filtros deben verse siempre para saber qué se está mostrando. */ ?>
<section class="panel-box barra-filtro">
  <form method="GET" class="barra-filtro-form" aria-label="Filtrar mantenimientos">
    <div class="field">
      <label for="sala">Sala</label>
      <select id="sala" name="sala">
        <option value="0">Todas</option>
        <?php foreach ($salas as $s): ?>
          <option value="<?= (int)$s['id_sala'] ?>" <?= $filtroSala===(int)$s['id_sala']?'selected':'' ?>><?= h($s['nombre_sala']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="estado">Estado</label>
      <select id="estado" name="estado">
        <option value="">Todos</option>
        <?php foreach (['PENDIENTE' => 'En espera (campaña)', 'PROGRAMADO' => 'Programado', 'REPROGRAMADO' => 'Reprogramado', 'EN_PROCESO' => 'En proceso', 'REALIZADO' => 'Realizado', 'CANCELADO' => 'Cancelado'] as $valorEstado => $textoEstado): ?>
          <option value="<?= h($valorEstado) ?>" <?= $filtroEstado === $valorEstado ? 'selected' : '' ?>><?= h($textoEstado) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($filtroCampana): ?><input type="hidden" name="campana" value="<?= (int)$filtroCampana ?>"><?php endif; ?>
    <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
    <?php if ($filtroSala || $filtroEstado !== '' || $filtroCampana): ?><a class="btn btn-ghost btn-sm" href="mantenimientos_programados.php">Quitar filtros</a><?php endif; ?>
  </form>
</section>
<?php endif; ?>
