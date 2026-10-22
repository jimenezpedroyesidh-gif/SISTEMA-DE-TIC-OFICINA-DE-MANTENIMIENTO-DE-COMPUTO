<?php
/**
 * Vista de horarios — página completa. Variables esperadas: ver HorariosPage.php.
 * Los trozos viven en este mismo directorio (calendario, resumen, acordeones, popovers).
 */
?>
<?php $paginaTitulo = 'Horarios'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php $tituloHeader = 'Horarios'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <?php
      $filtroActivoH = ($filtroSala || $filtroTipo !== '' || $filtroDia || $filtroPeriodo !== $periodoActual);
      $encabezado = [
          'salas' => ['Horarios por sala', 'Periodo ' . ($filtroPeriodo === 'TODOS' ? 'todos' : $filtroPeriodo) . ($filtroActivoH ? ' · con filtros' : '') . ' · cada sala en su pestaña, con préstamos y mantenimientos' . ($puedeModificar ? ' · toca una celda vacía para crear una franja o un bloque para editarlo' : '')],
          Rol::PROFESOR => ['Mi horario de clases', 'Tus clases de la semana en todas tus salas. Toca una clase para ver la materia, la sala y el horario.'],
          'beca_mantenimiento' => ['Mi semana', 'Tu turno y los mantenimientos programados de todas las salas, en un solo lugar.'],
          'beca_personal' => ['Mi turno', 'Tus horarios de la semana.'],
          'sin_acceso' => ['Horarios', 'Los horarios de las salas se ven con un permiso que activa el administrador.'],
      ][$modoVista];
      if ($alcance !== null) {
          $encabezado = ['Horarios de la secretaría', 'Turnos de las becas de la secretaría' . ($alcance['salas'] ? ' y las salas que te habilitó el administrador' : '') . '. Toca una celda vacía para crear una franja o un bloque para editarlo.'];
      }
      if ($rol === Rol::VIGILANTE) {
          $encabezado = ['Horarios de salas', 'Antes de prestar una sala revisa que no tenga clase, préstamo ni mantenimiento programado. La pestaña General muestra todas las salas juntas.'];
      }
    ?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1><?= h($encabezado[0]) ?></h1>
        <p><?= h($encabezado[1]) ?></p>
      </div>
      <?php if ($puedeModificar || $rol === Rol::ADMIN): ?>
      <div class="page-acciones">
        <?php if ($puedeModificar): ?>
          <button type="button" class="btn btn-sm" data-vf-abrir="acc-nueva-franja">+ Nueva franja</button>
          <button type="button" class="btn btn-ghost btn-sm" data-vf-abrir="acc-filtrar">Filtrar<?= $filtroActivoH ? ' (activo)' : '' ?></button>
          <?php if ($filtroActivoH): ?><a class="btn btn-ghost btn-sm" href="horarios.php">Quitar filtros</a><?php endif; ?>
        <?php endif; ?>
        <details class="menu-acciones" data-menu>
          <summary class="btn btn-ghost btn-sm">Más ▾</summary>
          <div class="menu-acciones-lista">
            <?php if ($puedeModificar && $alcance === null): ?><button type="button" class="menu-acciones-item" data-vf-abrir="acc-vaciar">Vaciar horario del semestre…</button><?php endif; ?>
            <?php if ($rol === Rol::ADMIN): ?><button type="button" class="menu-acciones-item" data-vf-abrir="acc-permisos-secretaria">Permisos de edición para secretaría</button><?php endif; ?>
          </div>
        </details>
      </div>
      <?php endif; ?>
    </div>

<?php if ($error): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
<?php if ($exito): ?><div class="exito mb-3"><?= h($exito) ?></div><?php endif; ?>
<?php if ($modoVista === 'salas' && $rol === Rol::VIGILANTE): ?>
<?php include __DIR__ . '/vigilante_hoy.php'; ?>
<?php include __DIR__ . '/calendario.php'; ?>
<?php elseif ($modoVista === 'salas'): ?>
<?php include __DIR__ . '/calendario.php'; ?>
<?php include __DIR__ . '/resumen.php'; ?>
<?php include __DIR__ . '/acordeones.php'; ?>
<?php include __DIR__ . '/popovers.php'; ?>
<?php elseif ($modoVista === 'sin_acceso'): ?>
    <section class="panel-box mw-640">
      <h2 class="panel-title">Sin acceso a los horarios de las salas</h2>
      <p>Para ver los horarios de cada salón (clases, turnos, préstamos y mantenimientos) pide al administrador que te active el permiso <strong>«Ver y editar horarios de salas»</strong> en Usuarios.</p>
    </section>
<?php else: ?>
<?php include __DIR__ . '/personal.php'; ?>
<?php endif; ?>
  <?php include LAYOUT_DIR . '/app_page_main_end.php'; ?>

<script type="application/json" id="horarios-config"><?= jsJson(['periodo' => $periodoActual, 'userId' => $userId, 'semanaActual' => $esSemanaActual]) ?></script>
<script src="<?= h(asset('assets/horarios.js')) ?>"></script>
</body>
</html>
