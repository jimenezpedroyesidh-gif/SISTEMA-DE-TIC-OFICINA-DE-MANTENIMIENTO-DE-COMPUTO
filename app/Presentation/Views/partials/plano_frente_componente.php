<?php
/**
 * Videobeam / parlantes de la sala (componentes, no equipos de cómputo) en el plano, frente al tablero.
 * $modoFrente = 'puesto' pinta el botón del plano; 'detalle' pinta lo que se despliega al tocarlo (estado,
 * traslados con quién aprobó y enlace a su ficha). Espera $compFrente (un ítem de
 * PlanoSalaService::frentePorSalas).
 */
require_once LAYOUT_DIR . '/iconos.php';
$noOpFrente = !$compFrente['estado']['operativo'];
if (($modoFrente ?? 'puesto') === 'puesto'): ?>
  <button type="button" class="sala-puesto sala-puesto-<?= h($compFrente['tipo']) ?> sala-puesto-infra <?= $noOpFrente ? 'est-pendiente no-operativo' : 'est-realizado' ?>"
          data-puesto="c<?= (int)$compFrente['id_componente'] ?>" aria-pressed="false"
          aria-label="<?= h($compFrente['nombre'] . ' ' . $compFrente['codigo'] . ($noOpFrente ? ' — no operativo' : ' — operativo')) ?>">
    <?= iconoSvg($compFrente['tipo'], 'ico sala-puesto-ico') ?>
    <?php if ($noOpFrente): ?><span class="sala-puesto-x" aria-hidden="true"></span><?php endif; ?>
    <span class="sala-puesto-cod"><?= h($compFrente['nombre']) ?></span>
  </button>
<?php else: ?>
  <div class="sala-detalle" data-detalle="c<?= (int)$compFrente['id_componente'] ?>" hidden>
    <div class="sala-detalle-head">
      <div>
        <strong class="sala-detalle-cod"><?= h($compFrente['nombre']) ?></strong>
        <span class="hint d-block"><?= h($compFrente['codigo']) ?><?= $compFrente['marca'] !== '' ? ' · ' . h(trim($compFrente['marca'] . ' ' . $compFrente['modelo'])) : '' ?></span>
      </div>
      <span class="chip <?= $noOpFrente ? 'danio' : 'solucionado' ?>"><?= $noOpFrente ? 'No operativo' : 'Operativo' ?></span>
      <button type="button" class="sala-detalle-cerrar" data-sala-detalle-cerrar aria-label="Cerrar detalle"><?= iconoSvg('cerrar') ?></button>
    </div>
    <p class="hint">Componente de la sala (no es equipo de cómputo): no entra en el checklist de equipos.</p>
    <?php $estadoEq = $compFrente['estado']; include __DIR__ . '/plano_estado_equipo.php'; ?>
    <details class="menu-acciones" data-menu>
      <summary class="btn btn-secondary btn-sm">Acciones ▾</summary>
      <div class="menu-acciones-lista">
        <a class="menu-acciones-item" href="ficha_componente.php?id=<?= (int)$compFrente['id_componente'] ?>">Abrir ficha del <?= h(mb_strtolower($compFrente['nombre'])) ?></a>
      </div>
    </details>
  </div>
<?php endif;
