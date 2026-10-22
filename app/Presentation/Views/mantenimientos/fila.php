<?php /** Vista parcial de «Mantenimientos programados» (fila del listado). Solo presentación: recibe datos ya preparados por MantenimientosPage. */ ?>
<?php
          $puedeEjecutarEste = in_array($rol, [Rol::ADMIN, Rol::TECNICO], true);
          // La asignación real es por equipo (id_usuario_asignado), no por todo el mantenimiento:
          // cualquier beca o técnico toma un equipo PENDIENTE desde el plano de la sala.
          // El admin abre el mismo plano solo para supervisar (no toma ni asigna).
          $checklist = $checklistPorProgramado[$m['id_programado']] ?? [];
          $totalEquiposChecklist = count($checklist);
          $realizadosChecklist = count(array_filter($checklist, fn($c) => $c['estado'] === 'REALIZADO'));
          // Los excluidos por traslado no cuentan como pendientes reales —
          // se muestran aparte en el contador, para no dar la impresión de
          // que falta revisar un equipo que ya ni siquiera está en la sala.
          $excluidosChecklist = count(array_filter($checklist, fn($c) => $c['estado'] === 'EXCLUIDO_TRASLADO'));
          $adjuntosMant = $adjuntosPorProgramado[(int)$m['id_programado']] ?? [];
          $idM = (int)$m['id_programado'];
          $activo = in_array($m['estado'], MantenimientoService::ESTADOS_ACTIVOS, true);
          $sinEmpezar = in_array($m['estado'], ['PENDIENTE', 'PROGRAMADO', 'REPROGRAMADO'], true);
          $conflicto = $conflictosPorProgramado[$idM] ?? null;
          $vinculos = $vinculosPorProgramado[$idM] ?? [];
          $reprogramaciones = $reprogramacionesPorProgramado[$idM] ?? [];
          $actividadesLista = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($m['actividades'] ?? '')))));
        ?>
        <tr>
          <td data-label="Fecha">
            <?= h(date('d/m/Y H:i', strtotime((string)$m['fecha_programada']))) ?>
            <br><span class="hint"><?= h($duraciones[(int)($m['duracion_minutos'] ?? 60)] ?? ((int)($m['duracion_minutos'] ?? 60) . ' min')) ?></span>
            <?php if ($reprogramaciones): ?>
              <details class="mt-1">
                <summary class="hint">Reprogramado <?= count($reprogramaciones) ?> <?= count($reprogramaciones) === 1 ? 'vez' : 'veces' ?></summary>
                <ul class="lista-compacta">
                  <?php foreach ($reprogramaciones as $rp): ?>
                    <li><?= h(date('d/m H:i', strtotime((string)$rp['fecha_anterior']))) ?> → <?= h(date('d/m H:i', strtotime((string)$rp['fecha_nueva']))) ?>
                      <span class="hint">(<?= h(['MANUAL' => 'a mano', 'CONFLICTO' => 'por un cruce', 'SECUENCIA' => 'por la campaña'][$rp['tipo']] ?? $rp['tipo']) ?><?= $rp['usuario_nombre'] ? ', ' . h($rp['usuario_nombre']) : '' ?><?= $rp['motivo'] ? ': ' . h($rp['motivo']) : '' ?>)</span></li>
                  <?php endforeach; ?>
                </ul>
              </details>
            <?php endif; ?>
          </td>
          <td data-label="Sala / Equipo">
            <?= h($m['abreviatura'] && $m['abreviatura'] !== $m['nombre_sala'] ? $m['abreviatura'] . ' — ' . $m['nombre_sala'] : $m['nombre_sala']) ?><?= $m['equipo_codigo'] ? ' — ' . h($m['equipo_codigo']) : '' ?>
            <?php if ($m['ticket_origen_id']): ?><br><span class="hint">Originado por ticket #<?= (int)$m['ticket_origen_id'] ?></span><?php endif; ?>
            <?php if (!empty($m['id_ticket_profesor_origen'])): ?><br><span class="hint">Originado por ticket de profesor #<?= (int)$m['id_ticket_profesor_origen'] ?></span><?php endif; ?>
            <?php if (!empty($m['id_campana'])): ?>
              <br><a class="hint" href="mantenimiento_campanas.php?id=<?= (int)$m['id_campana'] ?>">Campaña «<?= h($m['campana_nombre']) ?>» · sala <?= (int)$m['orden_campana'] ?></a>
            <?php endif; ?>
          </td>
          <td data-label="Tipo">
            <?= h(etiquetaValor($m['tipo'])) ?>
            <?php if (($m['alcance_equipo'] ?? 'TODOS') !== 'TODOS'): ?>
              <br><span class="hint">Alcance: <?= h(mb_strtolower(MantenimientoService::ALCANCE_TEXTO[$m['alcance_equipo']][0] ?? $m['alcance_equipo'])) ?><?= $m['alcance_equipo'] === 'SELECCION' ? ' (' . $totalEquiposChecklist . ')' : '' ?></span>
            <?php endif; ?>
          </td>
          <td data-label="Descripción">
            <?= nl2br(h($m['descripcion'])) ?>
            <?php if ($actividadesLista): ?>
              <details class="mt-1">
                <summary class="hint">Actividades (<?= count($actividadesLista) ?>)</summary>
                <ul class="lista-compacta">
                  <?php foreach ($actividadesLista as $act): ?><li><?= h($act) ?></li><?php endforeach; ?>
                </ul>
              </details>
            <?php endif; ?>
            <?php if ($vinculos): ?>
              <br><span class="hint">Atiende también: <?= h(implode(', ', array_map(fn($v) => '#' . (int)$v['id_ticket'] . ($v['tabla_ticket'] === TablaTicket::PROFESOR ? ' (profesor)' : ''), $vinculos))) ?></span>
            <?php endif; ?>
            <?php if (!empty($m['enlace_referencia'])): ?>
              <br><a href="<?= h($m['enlace_referencia']) ?>" target="_blank" rel="noopener">Ver referencia</a>
            <?php endif; ?>
          </td>
          <td data-label="Responsable"><?= $m['responsable_nombre'] ? h($m['responsable_nombre']) : 'Sin asignar' ?></td>
          <td data-label="Estado">
            <span class="chip <?= $chipEstado[$m['estado']] ?? '' ?>"><?= $m['estado'] === 'PENDIENTE' ? TicketWorkflow::EN_ESPERA : h(etiquetaValor($m['estado'])) ?></span>
            <?php if ($m['estado'] === 'PENDIENTE'): ?><br><span class="hint">Empieza cuando termine la sala anterior de la campaña.</span><?php endif; ?>
            <?php if (!empty($m['resultado'])): ?><br><span class="hint">Resultado: <?= h($resultados[$m['resultado']] ?? $m['resultado']) ?></span><?php endif; ?>
            <?php if ($conflicto): ?>
              <br><span class="chip danio">Cruce</span> <span class="hint">La sala tiene <?= h(MantenimientoAgenda::texto($conflicto)) ?>.<?= $puedeProgramar ? ' Reprográmalo.' : '' ?></span>
            <?php endif; ?>
          </td>
          <td data-label="Acción">
            <?php if ($puedeEjecutarEste && $activo): ?>
              <details>
                <summary>Actualizar</summary>
                <form method="POST" class="mt-2" enctype="multipart/form-data">
                  <?= csrfField() ?>
                  <input type="hidden" name="id_programado" value="<?= (int)$m['id_programado'] ?>">
                  <div class="field">
                    <label>Nuevo estado</label>
                    <select name="nuevo_estado">
                      <?php if (in_array($m['estado'], ['PROGRAMADO', 'REPROGRAMADO'], true)): ?>
                        <option value="EN_PROCESO">Marcar en proceso</option>
                      <?php endif; ?>
                      <?php if ($m['estado'] !== 'PENDIENTE'): ?>
                        <option value="REALIZADO">Marcar realizado</option>
                      <?php endif; ?>
                      <option value="CANCELADO">Cancelar</option>
                    </select>
                  </div>
                  <div class="field">
                    <label>Resultado obtenido (al marcar realizado)</label>
                    <select name="resultado">
                      <?php foreach ($resultados as $clave => $texto): ?><option value="<?= h($clave) ?>"><?= h($texto) ?></option><?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field">
                    <label>Actividades realizadas y observaciones técnicas</label>
                    <textarea name="observaciones_cierre" placeholder="Qué se hizo, qué se encontró, motivo de cancelación…"></textarea>
                  </div>
                  <div class="field">
                    <label>Foto(s) de evidencia (obligatorio solo para "Marcar realizado")</label>
                    <input type="file" name="evidencia[]" accept="image/jpeg,image/png,image/webp" multiple>
                    <p class="text-muted fs-85">
                      Fotos generales del resultado — no hace falta una por cada equipo. Ej. si fue instalación de software, una o varias fotos donde se vean los equipos con la pantalla del software ya instalado.
                    </p>
                  </div>
                  <button type="submit" name="cambiar_estado_mantenimiento" class="btn btn-sm">Guardar</button>
                </form>
              </details>
            <?php endif; ?>
            <?php if ($puedeProgramar && $sinEmpezar): ?>
              <details class="mt-2">
                <summary>Reprogramar<?= $conflicto ? ' (tiene un cruce)' : '' ?></summary>
                <form method="POST" class="mt-2">
                  <?= csrfField() ?>
                  <input type="hidden" name="id_programado" value="<?= $idM ?>">
                  <div class="field">
                    <label for="nueva-fecha-<?= $idM ?>">Nueva fecha (vacía = primer espacio libre)</label>
                    <input type="datetime-local" id="nueva-fecha-<?= $idM ?>" name="nueva_fecha">
                  </div>
                  <div class="field">
                    <label for="motivo-rep-<?= $idM ?>">Motivo</label>
                    <input type="text" id="motivo-rep-<?= $idM ?>" name="motivo_reprogramacion" maxlength="255" value="<?= $conflicto ? h('Cruce con ' . MantenimientoAgenda::texto($conflicto)) : '' ?>">
                  </div>
                  <label class="flex-row items-center gap-2 fw-normal"><input class="w-auto" type="checkbox" name="confirmar_choque" value="1"> Programar igual si la fecha elegida tiene un cruce</label>
                  <button type="submit" name="reprogramar" class="btn btn-sm mt-2">Reprogramar</button>
                </form>
              </details>
            <?php endif; ?>
            <?php if (!($puedeEjecutarEste && $activo) && !empty($m['observaciones_cierre'])): ?>
              <span class="hint"><?= h($m['observaciones_cierre']) ?></span>
            <?php elseif (!($puedeEjecutarEste && $activo) && !($puedeProgramar && $sinEmpezar)): ?>
              <em class="text-muted">—</em>
            <?php endif; ?>

            <?php if (!empty($adjuntosMant)): ?>
              <details class="mt-2">
                <summary>Evidencia (<?= count($adjuntosMant) ?>)</summary>
                <div class="mt-2">
                  <?php foreach ($adjuntosMant as $adj): ?>
                    <a href="descargar_adjunto.php?id=<?= (int)$adj['id'] ?>" target="_blank" rel="noopener" class="d-inline-block m-2px">
                      <?php if (str_starts_with($adj['mime_type'], 'image/')): ?>
                        <img src="descargar_adjunto.php?id=<?= (int)$adj['id'] ?>" alt="Evidencia" class="evidencia-miniatura">
                      <?php else: ?>
                        <?= h($adj['nombre_original']) ?>
                      <?php endif; ?>
                    </a>
                  <?php endforeach; ?>
                </div>
              </details>
            <?php endif; ?>

            <?php if (($m['alcance_equipo'] ?? '') === 'VIDEOBEAM'): ?>
              <?php $videobeams = MantenimientoService::frenteSegunAlcance($frentePorSala[(int)$m['id_sala']] ?? [], 'VIDEOBEAM'); ?>
              <?php if ($videobeams): ?>
                <ul class="lista-compacta mt-2">
                  <?php foreach ($videobeams as $vb): $vbOk = $vb['estado']['operativo']; ?>
                    <li><a href="ficha_componente.php?id=<?= (int)$vb['id_componente'] ?>"><?= h($vb['nombre'] . ' ' . $vb['codigo']) ?></a>
                      <span class="chip <?= $vbOk ? 'solucionado' : 'danio' ?>"><?= $vbOk ? 'Operativo' : 'No operativo' ?></span></li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="hint hint-peligro">Esta sala no tiene videobeam registrado.</p>
              <?php endif; ?>
            <?php elseif ($totalEquiposChecklist > 0): ?>
              <button type="button" class="btn btn-secondary btn-sm sala-abrir mt-2" data-sala-abrir="mant-<?= (int)$m['id_programado'] ?>" aria-haspopup="dialog">
                <?= iconoSvg('pc') ?> Checklist de equipos (<?= $realizadosChecklist ?>/<?= $totalEquiposChecklist ?><?= $excluidosChecklist > 0 ? ", $excluidosChecklist excluido" . ($excluidosChecklist > 1 ? 's' : '') . " por traslado" : '' ?>)
              </button>
              <progress class="sala-progreso" max="<?= $totalEquiposChecklist ?>" value="<?= $realizadosChecklist ?>" aria-label="Avance de la checklist"></progress>
            <?php elseif (in_array($m['tipo'], ['MANTENIMIENTO_PREVENTIVO', 'INSTALACION_SOFTWARE'], true)): ?>
              <p class="hint hint-peligro">
                Esta sala no tiene equipos registrados en el inventario — no hay checklist que marcar. Registra los equipos de <?= h($m['nombre_sala'] ?? 'la sala') ?> en "Gestionar salas" para que becas y técnico puedan sumarse.
              </p>
            <?php endif; ?>
          </td>
        </tr>
