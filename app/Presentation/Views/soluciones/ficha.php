<?php
/**
 * Vista de la ficha de una solución técnica (soluciones_tecnicas.php?id=N&sec=…, técnico y admin). Solo
 * presentación: los datos los prepara SolucionService::datosFicha según la sección. Encabezado, secciones
 * (navegación por enlaces, sin JS), datos editables (técnico) y cambio de estado (admin).
 */
$s = $solucion;
$paginaTitulo = $s['codigo'] . ' · ' . $s['nombre']; include LAYOUT_DIR . '/app_page_head.php';
$tituloHeader = 'Soluciones técnicas'; include LAYOUT_DIR . '/app_page_body_start.php';
$urlFicha = 'soluciones_tecnicas.php?id=' . (int)$s['id_solucion'];
$fh = static fn(?string $f): string => $f ? date('d/m/Y H:i', strtotime($f)) : '—';
$puedeEditar = $permisos['gestionar'] && $s['estado'] !== 'RETIRADA';
$estadosPosibles = array_keys(array_filter(SolucionService::TRANSICIONES, fn($desde) => in_array($s['estado'], $desde, true)));
// ¿El formulario que falló se vuelve a dibujar abierto (con el error y lo escrito)? Si no (otra sección, o ya no
// aplica: p. ej. el procedimiento ya fue reemplazado o la solución se suspendió), el error se muestra arriba.
$documenta = $permisos['gestionar'] && $s['estado'] !== 'RETIRADA';
$idReemplazo = (int)($_POST['id_reemplaza'] ?? 0);
$volvio = $error !== '' && match ($formulario) {
    'editar'        => $puedeEditar,
    'estado'        => $permisos['autorizar'] && (bool)$estadosPosibles,
    'version'       => $documenta && $seccion === 'versiones',
    'objetivos'     => $documenta && $seccion === 'resumen',
    'procedimiento' => $documenta && $seccion === 'procedimientos' && ($idReemplazo === 0 || in_array($idReemplazo, array_map('intval', array_column($procedimientos ?? [], 'id_procedimiento')), true)),
    'programar'     => $seccion === 'despliegue' && $permisos['gestionar'] && $s['estado'] === 'ACTIVA' && $s['id_vigente'] && !empty($porSala[(int)($_POST['id_sala'] ?? 0)]['programables']),
    default         => false,
};
$parciales = [
    'resumen' => '_ficha_resumen.php', 'versiones' => '_ficha_versiones.php', 'procedimientos' => '_ficha_procedimientos.php',
    'despliegue' => '_ficha_despliegue.php', 'verificaciones' => '_ficha_verificaciones.php',
];
?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1><?= h($s['codigo']) ?> · <?= h($s['nombre']) ?> <span class="chip <?= h($chipSolucion[$s['estado']] ?? '') ?>"><?= h($estadosSolucion[$s['estado']] ?? $s['estado']) ?></span></h1>
        <p>
          <?= h($s['tipo_nombre']) ?> ·
          <?= $s['version_vigente'] !== null ? 'versión vigente <strong>' . h($s['version_vigente']) . '</strong>' : 'sin versión aprobada' ?> ·
          responsable <?= $s['responsable_nombre'] ? h($s['responsable_nombre']) : 'sin asignar' ?> ·
          registrada por <?= h($s['creador_nombre']) ?> el <?= h($fh($s['creado_en'])) ?>
        </p>
      </div>
      <div class="page-acciones">
        <a class="btn btn-secondary btn-sm" href="soluciones_tecnicas.php?vista=catalogo">← Catálogo</a>
        <?php if ($puedeEditar): ?><button type="button" class="btn btn-secondary btn-sm" data-vf-abrir="vf-editar-solucion">Editar datos</button><?php endif; ?>
        <?php if ($permisos['autorizar'] && $estadosPosibles): ?><button type="button" class="btn btn-sm" data-vf-abrir="vf-estado-solucion">Cambiar estado</button><?php endif; ?>
      </div>
    </div>

<?php if ($error && !$volvio): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
<?php if ($exito): ?><div class="exito mb-3"><?= h($exito) ?></div><?php endif; ?>

<nav class="segmento mb-3 flex-wrap" aria-label="Secciones de la solución">
  <?php foreach ($secciones as $clave => $txt): ?>
    <a href="<?= h($urlFicha . '&sec=' . $clave) ?>" class="segmento-op<?= $seccion === $clave ? ' segmento-activo' : '' ?>"<?= $seccion === $clave ? ' aria-current="page"' : '' ?>><?= h($txt) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($cobertura): $c = $cobertura; ?>
<div class="stat-row">
  <div class="stat-card"><span class="stat-label">Equipos objetivo</span><span class="stat-value"><?= (int)$c['objetivo'] ?></span></div>
  <div class="stat-card"><span class="stat-label">Al día</span><span class="stat-value"><?= (int)$c['al_dia'] ?></span><progress class="sala-progreso" max="<?= max(1, (int)$c['objetivo']) ?>" value="<?= (int)$c['al_dia'] ?>" aria-label="Equipos al día"></progress><span class="hint"><?= (int)$c['verificados'] ?> verificados</span></div>
  <div class="stat-card"><span class="stat-label">Pendientes</span><span class="stat-value"><?= (int)$c['pendientes'] ?></span><span class="hint"><?= (int)$c['programados'] ?> programados</span></div>
  <div class="stat-card"><span class="stat-label">Sin verificar</span><span class="stat-value"><?= (int)$c['por_verificar'] ?></span></div>
  <div class="stat-card"><span class="stat-label">Con incidencia</span><span class="stat-value"><?= (int)$c['incidencias'] ?></span></div>
  <div class="stat-card"><span class="stat-label">Desactualizadas</span><span class="stat-value"><?= (int)$c['desactualizados'] ?></span></div>
</div>
<?php endif; ?>

<?php if ($seccion === 'historial'): ?>
  <section class="panel-box">
    <h2 class="panel-title">Historial <span class="hint">cada acción queda registrada; nada se sobrescribe</span></h2>
    <?php if (!$historial): ?>
      <p class="text-muted">Sin movimientos.</p>
    <?php else:
      // Mismo componente de línea de tiempo que la ficha de equipo y de componente.
      $eventosAuditoria = array_map(fn($ev) => [
          'accion' => SolucionService::ETIQUETAS_HISTORIAL[$ev['accion']] ?? etiquetaValor($ev['accion']),
          'fecha_hora' => $fh($ev['fecha']), 'usuario' => $ev['usuario'] ?? 'Sistema (sincronización)', 'detalle' => (string)$ev['detalle'], 'cambios' => [],
      ], $historial);
      include PARCIALES_DIR . '/_timeline_auditoria.php';
    endif; ?>
  </section>
<?php else: ?>
  <?php include __DIR__ . '/' . $parciales[$seccion]; ?>
<?php endif; ?>

<?php if ($puedeEditar): ?>
<?= vfAbrir('vf-editar-solucion', 'Editar datos de la solución', 'Los valores anteriores quedan en el historial.', $volvio && $formulario === 'editar', 'lg') ?>
  <?php if ($volvio && $formulario === 'editar'): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="accion_st" value="editar">
    <input type="hidden" name="id_solucion" value="<?= (int)$s['id_solucion'] ?>">
    <?php $fsValores = $volvio && $formulario === 'editar' ? $_POST : $s; $fsPrefijo = 'es'; include __DIR__ . '/_form_solucion.php'; ?>
    <button type="submit" class="btn">Guardar cambios</button>
  </form>
<?= vfCerrar() ?>
<?php endif; ?>

<?php if ($permisos['autorizar'] && $estadosPosibles): ?>
<?= vfAbrir('vf-estado-solucion', 'Cambiar estado de la solución', 'Suspendida: no se programa en mantenimientos. Retirada: queda solo como consulta.', $volvio && $formulario === 'estado') ?>
  <?php if ($volvio && $formulario === 'estado'): ?><div class="error mb-3"><?= h($error) ?></div><?php endif; ?>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="accion_st" value="estado">
    <input type="hidden" name="id_solucion" value="<?= (int)$s['id_solucion'] ?>">
    <div class="field">
      <label for="st-estado">Nuevo estado</label>
      <select id="st-estado" name="estado">
        <?php foreach ($estadosPosibles as $e): ?><option value="<?= h($e) ?>"><?= h($estadosSolucion[$e]) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="st-motivo">Motivo</label>
      <input type="text" id="st-motivo" name="motivo" maxlength="255" required>
    </div>
    <button type="submit" class="btn">Cambiar estado</button>
  </form>
<?= vfCerrar() ?>
<?php endif; ?>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
