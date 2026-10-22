<?php
/**
 * Vista parcial de «Mantenimientos programados»: plano de la sala (checklist de equipos).
 * Sección aparte (ventana a pantalla completa en el celular) que se abre con el botón
 * «Checklist de equipos» de la columna Acción. Cada equipo es un puesto tipo «silla de cine»;
 * al tocarlo se despliega su información y las acciones que permite el rol:
 *   - beca: tomar el equipo, marcarlo y liberarlo (solo el que tomó).
 *   - técnico: tomar, asignar/reasignar, marcar como respaldo, liberar, revertir, aprobar pieza y aprobar/rechazar
 *     el traslado de una pieza a la oficina de mantenimiento (bodega).
 *   - beca/técnico: además de Realizado / Incompleto, «Trasladar a la oficina de mantenimiento (bodega)» cuando el
 *     equipo no se puede arreglar en la sala (queda «Traslado por aprobar» hasta que el técnico decide).
 *   - admin: solo supervisa (ve el estado de cada equipo, no opera).
 * Solo presentación: recibe $m, $checklist, $rol, $userId, $puedeMarcarEquipos, $responsablesPosibles
 * y $chipEquipo ya preparados por MantenimientosPage (o por el panel de beca). Opcional $accionPlano: URL a la que
 * se envían las acciones (desde el panel de beca, mantenimientos_programados.php?volver=panel).
 * El comportamiento está en assets/plano_sala.js.
 */
$idProg = (int)$m['id_programado'];
$idModal = 'mant-' . $idProg;
$esTecnicoPlano = $rol === Rol::TECNICO;
$totalPlano = count($checklist);
$hechosPlano = count(array_filter($checklist, fn($c) => $c['estado'] === 'REALIZADO'));
$plano = planoSala($checklist, 'equipo_codigo', 'equipo_descripcion', $distribucionPorSala[(int)$m['id_sala']] ?? null);
$etiquetaEstadoEquipo = ['PENDIENTE' => 'Pendiente', 'TOMADO' => 'Tomado', 'REALIZADO' => 'Realizado', 'INCOMPLETO' => 'Incompleto', 'EXCLUIDO_TRASLADO' => 'Trasladado', 'TRASLADO_PENDIENTE' => 'Traslado por aprobar'];
$estadosPlano = $estadoEquipos ?? [];
$clasePuesto = static function (array $eq) use ($userId, $estadosPlano): string {
    $c = match ($eq['estado']) {
        'TOMADO' => (int)$eq['id_usuario_asignado'] === $userId ? 'est-mio' : 'est-tomado',
        'REALIZADO' => 'est-realizado',
        'INCOMPLETO' => 'est-incompleto',
        'EXCLUIDO_TRASLADO' => 'est-excluido',
        'TRASLADO_PENDIENTE' => 'est-traslado',
        default => 'est-pendiente',
    };
    if (!empty($eq['id_componente_saliente']) || !empty($eq['id_componente_entrante'])) $c .= ' con-pieza';
    if (isset($estadosPlano[(int)$eq['id_equipo']]) && !$estadosPlano[(int)$eq['id_equipo']]['operativo']) $c .= ' no-operativo';
    return $c;
};
$pintarPuesto = static function (array $eq) use ($clasePuesto, $idModal, $etiquetaEstadoEquipo, $estadosPlano): void {
    $tipo = $eq['_tipo'] ?? 'pc';
    $noOperativo = isset($estadosPlano[(int)$eq['id_equipo']]) && !$estadosPlano[(int)$eq['id_equipo']]['operativo'];
    $quien = $eq['estado'] === 'TOMADO' ? inicialesUsuario((string)($eq['asignado_nombre'] ?? '')) : ''; ?>
    <button type="button" class="sala-puesto sala-puesto-<?= h($tipo) ?> <?= $clasePuesto($eq) ?>"
            data-puesto="<?= (int)$eq['id_mant_equipo'] ?>" data-modal="<?= h($idModal) ?>" aria-pressed="false"
            aria-label="<?= h($eq['equipo_codigo'] . ' — ' . ($etiquetaEstadoEquipo[$eq['estado']] ?? $eq['estado']) . ($noOperativo ? ' — no operativo' : '')) ?>">
      <?= iconoSvg($tipo, 'ico sala-puesto-ico') ?>
      <?php if ($noOperativo): ?><span class="sala-puesto-x" aria-hidden="true"></span><?php endif; ?>
      <?= numPuestoSala($eq) ?>
      <span class="sala-puesto-cod"><?= h($eq['_cod'] ?? $eq['equipo_codigo']) ?></span>
      <?php if ($quien !== ''): ?><span class="sala-puesto-quien"><?= h($quien) ?></span><?php endif; ?>
    </button>
<?php };
$todosPlano = $plano['todos'];
?>
<div class="sala-modal" id="<?= h($idModal) ?>" hidden role="dialog" aria-modal="true" aria-labelledby="<?= h($idModal) ?>-titulo"<?= !empty($accionPlano) ? ' data-sala-accion="' . h($accionPlano) . '"' : '' ?>>
  <div class="sala-modal-caja">
    <header class="sala-modal-head">
      <div class="sala-modal-titulos">
        <h2 class="sala-modal-titulo" id="<?= h($idModal) ?>-titulo">
          <?= h($m['abreviatura'] ?: $m['nombre_sala']) ?> · <?= h(etiquetaValor($m['tipo'])) ?>
        </h2>
        <p class="sala-modal-sub"><?= h($m['fecha_programada']) ?> · <?= $hechosPlano ?> de <?= $totalPlano ?> equipos realizados</p>
        <progress class="sala-progreso" max="<?= $totalPlano ?>" value="<?= $hechosPlano ?>" aria-hidden="true"></progress>
      </div>
      <button type="button" class="sala-modal-cerrar" data-sala-cerrar aria-label="Cerrar plano"><?= iconoSvg('cerrar') ?></button>
    </header>

    <div class="sala-modal-cuerpo">
      <div class="sala-plano" data-lado-activo="izq">
        <ul class="sala-leyenda" aria-label="Convenciones">
          <li><span class="sala-swatch est-pendiente"></span>Pendiente</li>
          <li><span class="sala-swatch est-tomado"></span>Tomado</li>
          <?php if ($puedeMarcarEquipos): ?><li><span class="sala-swatch est-mio"></span>Mío</li><?php endif; ?>
          <li><span class="sala-swatch est-realizado"></span>Realizado</li>
          <li><span class="sala-swatch est-incompleto"></span>Incompleto</li>
          <li><span class="sala-swatch est-traslado"></span>Traslado a bodega</li>
          <li><span class="sala-swatch no-operativo"></span>No operativo</li>
        </ul>
        <?php if (!$puedeMarcarEquipos): ?>
          <p class="nota-info sala-nota">Vista de supervisión: el administrador ve el avance de cada equipo, pero no toma ni asigna equipos.</p>
        <?php endif; ?>

        <div class="sala-frente">
          <div class="sala-tablero">Frente del salón · Tablero</div>
          <?php $frenteMant = MantenimientoService::frenteSegunAlcance($frentePorSala[(int)$m['id_sala']] ?? [], $m['alcance_equipo'] ?? null); ?>
          <?php if ($plano['frente'] || $frenteMant): ?>
            <div class="sala-frente-puestos">
              <?php foreach ($frenteMant as $compFrente) { $modoFrente = 'puesto'; include PARCIALES_DIR . '/plano_frente_componente.php'; } ?>
              <?php foreach ($plano['frente'] as $eq) { if (!empty($eq['_vacio'])) echo puestoVacioSala($eq); else $pintarPuesto($eq); } ?>
            </div>
          <?php endif; ?>
        </div>

        <?php $planoL = $plano; $pintarL = $pintarPuesto; include PARCIALES_DIR . '/plano_sala_lados.php'; ?>
      </div>

      <aside class="sala-detalle-panel" aria-live="polite">
        <div class="sala-detalle-vacio">
          <?= iconoSvg('pc', 'ico sala-detalle-vacio-ico') ?>
          <p>Toca un equipo del plano para ver su información<?= $puedeMarcarEquipos ? ($esTecnicoPlano ? ', tomarlo o asignarlo' : ' y tomarlo') : '' ?>.</p>
        </div>

        <?php foreach ($frenteMant as $compFrente) { $modoFrente = 'detalle'; include PARCIALES_DIR . '/plano_frente_componente.php'; } ?>
        <?php foreach ($todosPlano as $eq): $idMe = (int)$eq['id_mant_equipo']; ?>
          <div class="sala-detalle" data-detalle="<?= $idMe ?>" hidden>
            <div class="sala-detalle-head">
              <div>
                <strong class="sala-detalle-cod"><?= h($eq['equipo_codigo']) ?></strong>
                <?php if (!empty($eq['equipo_descripcion'])): ?><span class="hint d-block"><?= h($eq['equipo_descripcion']) ?></span><?php endif; ?>
              </div>
              <span class="chip <?= $chipEquipo[$eq['estado']] ?? '' ?>"><?= h($etiquetaEstadoEquipo[$eq['estado']] ?? $eq['estado']) ?></span>
              <button type="button" class="sala-detalle-cerrar" data-sala-detalle-cerrar aria-label="Cerrar detalle"><?= iconoSvg('cerrar') ?></button>
            </div>

            <dl class="sala-datos">
              <?php if (($eq['_num'] ?? '') !== ''): ?><dt>Puesto</dt><dd>N.º <?= h($eq['_num']) ?></dd><?php endif; ?>
              <?php if (($eq['_red'] ?? '') !== ''): ?><dt>Punto de red</dt><dd><?= h($eq['_red']) ?></dd><?php endif; ?>
              <?php if (!empty($eq['asignado_nombre'])): ?><dt>Asignado a</dt><dd><?= h($eq['asignado_nombre']) ?></dd><?php endif; ?>
              <?php if (!empty($eq['marcado_por'])): ?><dt>Última marca</dt><dd><?= h($eq['marcado_por']) ?><?= !empty($eq['fecha_actualizacion']) ? ' · ' . h($eq['fecha_actualizacion']) : '' ?></dd><?php endif; ?>
              <?php if (!empty($eq['observaciones'])): ?><dt>Observación</dt><dd><?= h($eq['observaciones']) ?></dd><?php endif; ?>
            </dl>
            <?php $estadoEq = $estadosPlano[(int)$eq['id_equipo']] ?? null; include PARCIALES_DIR . '/plano_estado_equipo.php'; ?>

            <div class="sala-acciones">
              <?php if ($eq['estado'] === 'TRASLADO_PENDIENTE'): ?>
                <div class="nota-info">
                  <strong>Traslado a la oficina de mantenimiento (bodega)</strong> — pendiente de aprobación<?= $esTecnicoPlano ? '' : ' del técnico' ?>.<br>
                  Pieza que se lleva: <?= h(etiquetaValor((string)($eq['saliente_tipo'] ?? ''))) ?> <?= h($eq['saliente_codigo'] ?? '') ?>
                </div>
                <?php if ($esTecnicoPlano): ?>
                  <div class="tk-acciones mt-2">
                    <form method="POST" data-sala-form data-confirm="La pieza pasa a bodega (en revisión) y el equipo sale de este mantenimiento. ¿Aprobar?">
                      <?= csrfField() ?>
                      <input type="hidden" name="id_mant_equipo" value="<?= $idMe ?>">
                      <input type="hidden" name="id_programado" value="<?= $idProg ?>">
                      <button type="submit" name="aprobar_traslado_equipo" class="btn btn-sm">Aprobar traslado</button>
                    </form>
                    <details class="sala-mas">
                      <summary>Rechazar ▾</summary>
                      <form method="POST" data-sala-form>
                        <?= csrfField() ?>
                        <input type="hidden" name="id_mant_equipo" value="<?= $idMe ?>">
                        <input type="hidden" name="id_programado" value="<?= $idProg ?>">
                        <label for="rech-<?= $idMe ?>">¿Por qué no se traslada?</label>
                        <textarea id="rech-<?= $idMe ?>" name="motivo_rechazo" rows="2" placeholder="Ej.: se puede arreglar en la sala cambiando el cable."></textarea>
                        <button type="submit" name="rechazar_traslado_equipo" class="btn btn-danger btn-sm">Rechazar traslado</button>
                      </form>
                    </details>
                  </div>
                <?php endif; ?>
              <?php elseif ((!empty($eq['id_componente_saliente']) || !empty($eq['id_componente_entrante'])) && empty($eq['pieza_pendiente'])): ?>
                <p class="hint">Cambio de pieza aprobado por un técnico y aplicado al inventario.</p>
              <?php elseif (!empty($eq['id_componente_saliente']) || !empty($eq['id_componente_entrante'])): ?>
                <p class="hint">Propone cambio de pieza — pendiente de aprobación<?= $esTecnicoPlano ? '' : ' de un técnico' ?></p>
                <?php if ($esTecnicoPlano): ?>
                  <form method="POST" data-sala-form>
                    <?= csrfField() ?>
                    <input type="hidden" name="id_mant_equipo" value="<?= $idMe ?>">
                    <input type="hidden" name="id_programado" value="<?= $idProg ?>">
                    <button type="submit" name="aprobar_pieza_equipo" class="btn btn-info btn-sm">Aprobar cambio de pieza</button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>

              <?php if ($eq['estado'] === 'PENDIENTE' && $puedeMarcarEquipos): ?>
                <form method="POST" data-sala-form>
                  <?= csrfField() ?>
                  <input type="hidden" name="id_mant_equipo" value="<?= $idMe ?>">
                  <input type="hidden" name="id_programado" value="<?= $idProg ?>">
                  <button type="submit" name="tomar_equipo" class="btn">Tomar este equipo</button>
                </form>
                <?php if ($esTecnicoPlano): ?>
                  <details class="sala-mas">
                  <summary>Asignar a otra persona ▾</summary>
                  <form method="POST" class="sala-form-asignar" data-sala-form>
                    <?= csrfField() ?>
                    <input type="hidden" name="id_mant_equipo" value="<?= $idMe ?>">
                    <input type="hidden" name="id_programado" value="<?= $idProg ?>">
                    <label for="asig-<?= $idMe ?>">Asignar a un becario o técnico</label>
                    <div class="sala-fila-form">
                      <select id="asig-<?= $idMe ?>" name="id_beca_asignar" required>
                        <option value="">-- Elegir --</option>
                        <?php foreach ($responsablesPosibles as $r): ?>
                          <option value="<?= (int)$r['id'] ?>"><?= h($r['nombre_completo']) ?> (<?= h(etiquetaRol((string)$r['role'])) ?>)</option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" name="asignar_equipo_tecnico" class="btn btn-secondary btn-sm">Asignar</button>
                    </div>
                  </form>
                  </details>
                <?php endif; ?>
              <?php elseif ($eq['estado'] === 'PENDIENTE'): ?>
                <p class="hint">Pendiente: todavía nadie lo ha tomado.</p>

              <?php elseif ($eq['estado'] === 'TOMADO' && ($esTecnicoPlano || ($puedeMarcarEquipos && (int)$eq['id_usuario_asignado'] === $userId))): ?>
                <form method="POST" class="sala-form-marcar" data-sala-form>
                  <?= csrfField() ?>
                  <input type="hidden" name="id_mant_equipo" value="<?= $idMe ?>">
                  <input type="hidden" name="id_programado" value="<?= $idProg ?>">
                  <div class="field">
                    <label for="est-<?= $idMe ?>">Resultado</label>
                    <select id="est-<?= $idMe ?>" name="nuevo_estado_equipo">
                      <option value="REALIZADO">Realizado</option>
                      <option value="INCOMPLETO">Incompleto</option>
                      <option value="TRASLADO">Trasladar a la oficina de mantenimiento (bodega)</option>
                    </select>
                  </div>
                  <div class="field">
                    <label for="obs-<?= $idMe ?>">Observación <span class="hint">(obligatoria si queda incompleto o se traslada)</span></label>
                    <textarea id="obs-<?= $idMe ?>" name="observaciones_equipo" rows="2"></textarea>
                  </div>
                  <details data-mostrar-cuando="nuevo_estado_equipo=REALIZADO,INCOMPLETO">
                    <summary>¿Cambiaste una pieza? (opcional)</summary>
                    <?php $prefix = "me{$idMe}_"; $requerido = false; $soloSaliente = false; $nombreSaliente = 'id_componente_saliente'; $idSalaContexto = $eq['id_sala'] ?? null; include PARCIALES_DIR . '/buscador_componente_widget.php'; ?>
                  </details>
                  <div class="tk-cierre" data-mostrar-cuando="nuevo_estado_equipo=TRASLADO" hidden>
                    <p class="hint m-0">No se pudo arreglar en la sala: elige la pieza que se lleva a la oficina de mantenimiento (la torre, normalmente). El técnico aprueba el traslado; al aprobarlo la pieza queda en bodega «en revisión» y, si es la torre o el todo en uno, el equipo queda no operativo.</p>
                    <?php $prefix = "me{$idMe}_tr_"; $requerido = false; $soloSaliente = true; $nombreSaliente = 'id_componente_traslado'; $idSalaContexto = $eq['id_sala'] ?? null; include PARCIALES_DIR . '/buscador_componente_widget.php'; ?>
                  </div>
                  <?php unset($soloSaliente, $nombreSaliente); ?>
                  <button type="submit" name="marcar_equipo" class="btn btn-sm">Guardar marca</button>
                </form>
                <details class="sala-mas">
                <summary>Más acciones ▾</summary>
                <?php if ($esTecnicoPlano): ?>
                  <form method="POST" class="sala-form-asignar" data-sala-form>
                    <?= csrfField() ?>
                    <input type="hidden" name="id_mant_equipo" value="<?= $idMe ?>">
                    <input type="hidden" name="id_programado" value="<?= $idProg ?>">
                    <label for="reasig-<?= $idMe ?>">Reasignar a otra persona</label>
                    <div class="sala-fila-form">
                      <select id="reasig-<?= $idMe ?>" name="id_beca_asignar" required>
                        <option value="">-- Elegir --</option>
                        <?php foreach ($responsablesPosibles as $r): ?>
                          <option value="<?= (int)$r['id'] ?>"><?= h($r['nombre_completo']) ?> (<?= h(etiquetaRol((string)$r['role'])) ?>)</option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" name="asignar_equipo_tecnico" class="btn btn-secondary btn-sm">Reasignar</button>
                    </div>
                  </form>
                <?php endif; ?>
                <form method="POST" data-sala-form>
                  <?= csrfField() ?>
                  <input type="hidden" name="id_mant_equipo" value="<?= $idMe ?>">
                  <button type="submit" name="liberar_equipo" class="btn btn-ghost btn-sm">Liberar equipo</button>
                </form>
                </details>

              <?php elseif ($eq['estado'] === 'TOMADO'): ?>
                <p class="hint">En proceso: lo está trabajando <?= h($eq['asignado_nombre'] ?? 'otra persona') ?>.</p>

              <?php elseif ($eq['estado'] === 'INCOMPLETO' && $puedeMarcarEquipos): ?>
                <p class="hint">Quedó incompleto<?= !empty($eq['marcado_por']) ? ' (lo marcó ' . h($eq['marcado_por']) . ')' : '' ?>. Cualquier becario o el técnico puede retomarlo para terminarlo.</p>
                <form method="POST" data-sala-form>
                  <?= csrfField() ?>
                  <input type="hidden" name="id_mant_equipo" value="<?= $idMe ?>">
                  <input type="hidden" name="id_programado" value="<?= $idProg ?>">
                  <button type="submit" name="tomar_equipo" class="btn">Retomar y terminar este equipo</button>
                </form>
                <?php if ($esTecnicoPlano): ?>
                  <form method="POST" data-sala-form data-confirm="¿Reabrir este equipo (vuelve a Pendiente)?">
                    <?= csrfField() ?>
                    <input type="hidden" name="id_mant_equipo" value="<?= $idMe ?>">
                    <button type="submit" name="revertir_equipo" class="btn btn-ghost btn-sm">Reabrir intervención (a Pendiente)</button>
                  </form>
                <?php endif; ?>

              <?php elseif ($esTecnicoPlano && in_array($eq['estado'], ['REALIZADO', 'INCOMPLETO'], true)): ?>
                <form method="POST" data-sala-form data-confirm="¿Reabrir este equipo (vuelve a Pendiente)?">
                  <?= csrfField() ?>
                  <input type="hidden" name="id_mant_equipo" value="<?= $idMe ?>">
                  <button type="submit" name="revertir_equipo" class="btn btn-ghost btn-sm"><?= $eq['estado'] === 'INCOMPLETO' ? 'Reabrir intervención' : 'Revertir a pendiente' ?></button>
                </form>

              <?php elseif ($eq['estado'] === 'INCOMPLETO'): ?>
                <p class="hint">Quedó incompleto: el técnico revisa y decide si lo reabre.</p>

              <?php elseif ($eq['estado'] === 'EXCLUIDO_TRASLADO'): ?>
                <p class="hint">Trasladado fuera de la sala — excluido de este mantenimiento.</p>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </aside>
    </div>
  </div>
</div>
