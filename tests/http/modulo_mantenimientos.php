<?php
/**
 * Pruebas HTTP del módulo Mantenimiento programado (se incluye desde run_http_tests.php, que aporta $pdo, $U,
 * $C, $adm, $tec, $hash, $CLAVE, cliente(), t() y seccion()).
 */
seccion('Mantenimiento programado: programar, estado, checklist, permisos');

$cm = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); })();
$fm = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(); })();
$beca = $C['beca_trabajo'];
$PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$uid = fn(string $r) => (int)$U[$r]['id'];

// Segundo beca
$pdo->prepare("INSERT INTO users (codigo, correo, password, password_temporal, role, nombre_completo, cargo, estado_activo) VALUES ('ZT09', 'zt_beca2@test.local', ?, 0, 'beca_trabajo', 'ZT beca2', 'Prueba', 1)")->execute([$hash]);
$U['beca2'] = ['id' => (int)$pdo->lastInsertId(), 'correo' => 'zt_beca2@test.local', 'codigo' => 'ZT09'];
$beca2 = cliente($U['beca2'], $CLAVE);

// Salas: una con 2 equipos (cómputo + videobeam), otra sin equipos
$nuevaSala = function (string $n, string $a) use ($pdo) {
    $pdo->prepare("INSERT INTO salas (nombre_sala, abreviatura, dependencia, codigo_qr_token) VALUES (?, ?, 'x', ?)")->execute([$n, $a, 'zt' . bin2hex(random_bytes(8))]);
    return (int)$pdo->lastInsertId();
};
$sM = $nuevaSala('ZT-SALA-M', 'ZTM');
$sVacia = $nuevaSala('ZT-SALA-M0', 'ZM0');
$sCierre = $nuevaSala('ZT-SALA-M1', 'ZM1');
$mkEq = function (int $sala, string $cod) use ($pdo) { $pdo->prepare("INSERT INTO equipos (id_sala, codigo, descripcion) VALUES (?,?,?)")->execute([$sala, $cod, 'ZT']); return (int)$pdo->lastInsertId(); };
$mkComp = function (string $tipo, string $ser, ?int $eq) use ($pdo) {
    $pdo->prepare("INSERT INTO componentes (tipo, serial, codigo_inventario, estado, id_equipo_actual) VALUES (?, ?, ?, 'ACTIVO', ?)")->execute([$tipo, $ser, $ser, $eq]);
    return (int)$pdo->lastInsertId();
};
$eqPC = $mkEq($sM, 'ZT-EQ-PC');
$eqVB = $mkEq($sM, 'ZT-EQ-VB');
$mkComp('torre', 'ZT-SER-TORRE', $eqPC);
$mkComp('videobeam', 'ZT-SER-VB', $eqVB);
$compEntrante = $mkComp('torre', 'ZT-SER-NUEVA', null);
// Videobeam registrado directo a la sala (frente al tablero), no como equipo de cómputo.
$pdo->prepare("INSERT INTO componentes (tipo, serial, codigo_inventario, estado, id_sala_directa) VALUES ('videobeam', 'ZT-SER-VBS', 'ZT-SER-VBS', 'ACTIVO', ?)")->execute([$sM]);
$vbSala = (int)$pdo->lastInsertId();

$fechaFutura = date('Y-m-d\TH:i', strtotime('+30 days 10:00'));
$prog = ['programar' => '1', 'id_sala' => $sM, 'tipo' => 'MANTENIMIENTO_PREVENTIVO', 'alcance_equipo' => 'TODOS', 'descripcion' => 'ZT-mant-1', 'fecha_programada' => $fechaFutura];
$diaP = 0;
$P = function (array $o = []) use ($prog, &$diaP) { return array_merge($prog, ['fecha_programada' => date('Y-m-d\TH:i', strtotime('+' . (++$diaP + 1) . ' days 10:00'))], $o); };
$idMant = fn(string $desc) => $cm("SELECT id_programado FROM mantenimientos_programados WHERE descripcion=?", [$desc]);

// ── Acceso
t('Mantenimientos: solicitante/profesor/secretaría no entran', $C['solicitante']->get('mantenimientos_programados.php')->denied() && $C['profesor']->get('mantenimientos_programados.php')->denied() && $C['secretaria']->get('mantenimientos_programados.php')->denied());
$r = $tec->get('mantenimientos_programados.php');
t('Mantenimientos: el técnico ve el formulario de programar y el filtro', $r->contains('Programar nuevo') && $r->contains('Filtrar'));
$r = $adm->get('mantenimientos_programados.php');
t('Mantenimientos: el admin ve filtro pero NO el formulario de programar', !$r->contains('Programar nuevo') && $r->contains('Filtrar'));
$r = $beca->get('mantenimientos_programados.php');
t('Mantenimientos: el beca no ve formulario ni filtro', !$r->contains('Programar nuevo') && !$r->contains('Filtrar'));

// ── Programar
$r = $tec->post('mantenimientos_programados.php', $prog);
$id1 = $idMant('ZT-mant-1');
t('Programar: el técnico crea el mantenimiento', $id1 > 0 && $r->contains('Mantenimiento programado correctamente'));
t('Programar: estado PROGRAMADO y creador = técnico', ($fm("SELECT estado, id_creador FROM mantenimientos_programados WHERE id_programado=?", [$id1])['estado'] ?? '') === 'PROGRAMADO' && $cm("SELECT id_creador FROM mantenimientos_programados WHERE id_programado=?", [$id1]) === $uid('tecnico'));
t('Programar: alcance TODOS genera checklist con los 2 equipos en PENDIENTE', $cm("SELECT COUNT(*) FROM mantenimiento_equipos WHERE id_programado=? AND estado='PENDIENTE'", [$id1]) === 2);
t('Programar: deja auditoría CREAR', $cm("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='mantenimientos_programados' AND id_registro=? AND accion='CREAR'", [$id1]) === 1);
$adm->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-admin']), 'mantenimientos_programados.php');
t('Programar: el admin no puede programar', $idMant('ZT-mant-admin') === 0);
$beca->postRaw('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-beca']));
t('Programar: el beca no puede programar', $idMant('ZT-mant-beca') === 0);
$tec->postRaw('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-nocsrf']));
t('Programar: sin CSRF no crea', $idMant('ZT-mant-nocsrf') === 0);
$r = $tec->post('mantenimientos_programados.php', $P(['descripcion' => '']));
t('Programar: descripción vacía → mensaje y no crea', $r->contains('Completa sala, tipo, descripción y fecha') && $cm("SELECT COUNT(*) FROM mantenimientos_programados WHERE descripcion='' AND id_sala=?", [$sM]) === 0);
$r = $tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-tipo', 'tipo' => 'XX']));
t('Programar: tipo inválido se rechaza', $idMant('ZT-mant-tipo') === 0 && $r->contains('Completa sala'));
$r = $tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-url', 'enlace_referencia' => 'no-es-url']));
t('Programar: enlace que no es URL se rechaza', $idMant('ZT-mant-url') === 0 && $r->contains('no es una URL válida'));
$tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-url2', 'enlace_referencia' => 'https://example.com/guia']));
t('Programar: enlace válido se guarda', ($fm("SELECT enlace_referencia FROM mantenimientos_programados WHERE descripcion='ZT-mant-url2'")['enlace_referencia'] ?? '') === 'https://example.com/guia');
$r = $tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-sw-vb', 'tipo' => 'INSTALACION_SOFTWARE', 'id_equipo' => $eqVB]));
t('Programar: software sobre un videobeam se rechaza', $idMant('ZT-mant-sw-vb') === 0 && $r->contains('No se puede programar una instalación de software'));
$tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-sw', 'tipo' => 'INSTALACION_SOFTWARE', 'alcance_equipo' => 'VIDEOBEAM']));
$idSw = $idMant('ZT-mant-sw');
t('Programar: software fuerza alcance COMPUTO (1 equipo en checklist)', $idSw > 0 && ($fm("SELECT alcance_equipo FROM mantenimientos_programados WHERE id_programado=?", [$idSw])['alcance_equipo'] ?? '') === 'COMPUTO' && $cm("SELECT COUNT(*) FROM mantenimiento_equipos WHERE id_programado=?", [$idSw]) === 1);
$tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-vb', 'alcance_equipo' => 'VIDEOBEAM']));
$idVb = $idMant('ZT-mant-vb');
t('Programar: alcance VIDEOBEAM se crea sin checklist de equipos', $idVb > 0 && $cm("SELECT COUNT(*) FROM mantenimiento_equipos WHERE id_programado=?", [$idVb]) === 0);
$r = $tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-vb-vacia', 'id_sala' => $sVacia, 'alcance_equipo' => 'VIDEOBEAM', 'confirmar_sin_equipos' => '1']));
t('Programar: VIDEOBEAM en una sala sin videobeam se rechaza', $idMant('ZT-mant-vb-vacia') === 0 && $r->contains('no tiene ningún videobeam'));
$r = $tec->get('mantenimientos_programados.php');
$modal = function (int $id) use ($r): string { $i = strpos($r->body, 'id="mant-' . $id . '"'); return $i === false ? '' : substr($r->body, $i, (int)(strpos($r->body, 'class="sala-modal"', $i + 10) ?: strlen($r->body)) - $i); };
t('Lista: VIDEOBEAM muestra solo el videobeam de la sala y no abre plano de equipos', $r->contains('ficha_componente.php?id=' . $vbSala) && !$r->contains('data-sala-abrir="mant-' . $idVb . '"'));
t('Plano: el mantenimiento general de sala sí muestra el videobeam', strpos($modal($id1), 'data-puesto="c' . $vbSala . '"') !== false);
t('Plano: en cómputo / software no aparece el videobeam de la sala', $modal($idSw) !== '' && strpos($modal($idSw), 'data-puesto="c' . $vbSala . '"') === false);
$tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-alc', 'alcance_equipo' => 'RARO']));
t('Programar: alcance desconocido cae a TODOS', ($fm("SELECT alcance_equipo FROM mantenimientos_programados WHERE descripcion='ZT-mant-alc'")['alcance_equipo'] ?? '') === 'TODOS');
$r = $tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-vacia', 'id_sala' => $sVacia]));
t('Programar: sala sin equipos exige confirmación', $idMant('ZT-mant-vacia') === 0 && $r->contains('no tiene ningún equipo registrado'));
$tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-vacia', 'id_sala' => $sVacia, 'confirmar_sin_equipos' => '1']));
$idVac = $idMant('ZT-mant-vacia');
t('Programar: confirmando, sala sin equipos se crea con checklist vacío', $idVac > 0 && $cm("SELECT COUNT(*) FROM mantenimiento_equipos WHERE id_programado=?", [$idVac]) === 0);
$tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-resp-beca', 'id_responsable' => $uid('beca_trabajo')]));
t('Programar: responsable beca (manipulado) se descarta', $cm("SELECT COUNT(*) FROM mantenimientos_programados WHERE descripcion='ZT-mant-resp-beca' AND id_responsable IS NULL") === 1);
$tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-resp-tec', 'id_responsable' => $uid('tecnico')]));
t('Programar: responsable técnico se guarda', $cm("SELECT COUNT(*) FROM mantenimientos_programados WHERE descripcion='ZT-mant-resp-tec' AND id_responsable=?", [$uid('tecnico')]) === 1);

// Choque de horario
$periodoM = date('Y') . '-' . (date('n') <= 6 ? '1' : '2');
$martesM = (new DateTime('monday this week'))->modify('+1 day')->format('Y-m-d');
$pdo->prepare("INSERT INTO horarios (id_sala, id_usuario, tipo, dia_semana, periodo, hora_inicio, hora_fin, detalle, id_creador) VALUES (?, ?, 'CLASE', 2, ?, '08:00', '10:00', 'ZT-clase-choque', ?)")->execute([$sM, $uid('profesor'), $periodoM, $uid('admin')]);
$choque = $P(['descripcion' => 'ZT-mant-choque', 'fecha_programada' => $martesM . 'T09:00']);
$r = $tec->post('mantenimientos_programados.php', $choque);
t('Programar: choque de horario avisa y no crea sin confirmar', $idMant('ZT-mant-choque') === 0 && $r->contains('Marca la casilla de confirmación'));
$tec->post('mantenimientos_programados.php', $choque + ['confirmar_choque' => '1']);
t('Programar: con confirmar_choque se crea igual', $idMant('ZT-mant-choque') > 0);

// Validaciones nuevas (cambio funcional menor: antes 500)
$r = $tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-salaX', 'id_sala' => 99999999, 'confirmar_sin_equipos' => '1']));
t('Programar: sala inexistente → mensaje de validación (sin 500)', $r->status < 500 && $r->contains('no existe') && $idMant('ZT-mant-salaX') === 0, 'status ' . $r->status);
$r = $tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-eqX', 'id_equipo' => 99999999]));
t('Programar: equipo inexistente → mensaje de validación (sin 500)', $r->status < 500 && $r->contains('no existe') && $idMant('ZT-mant-eqX') === 0, 'status ' . $r->status);
$r = $tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-fechaX', 'fecha_programada' => 'esto-no-es-fecha']));
t('Programar: fecha inválida → mensaje de validación (sin 500)', $r->status < 500 && $r->contains('fecha') && $idMant('ZT-mant-fechaX') === 0, 'status ' . $r->status);
$r = $tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-eqOtraSala', 'alcance_equipo' => 'SELECCION', 'ids_equipo' => [$mkEq($sVacia, 'ZT-EQ-OTRA')]]));
t('Programar: un equipo de otra sala se rechaza', $idMant('ZT-mant-eqOtraSala') === 0 && $r->contains('no es de esta sala'));
$r = $tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-sel-vacia', 'alcance_equipo' => 'SELECCION']));
t('Programar: «ciertos equipos» sin elegir ninguno se rechaza', $idMant('ZT-mant-sel-vacia') === 0 && $r->contains('Elige al menos un equipo'));
$tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-sel', 'alcance_equipo' => 'SELECCION', 'ids_equipo' => [$eqPC]]));
$idSel = $idMant('ZT-mant-sel');
t('Programar: «ciertos equipos» crea la checklist solo con los elegidos', $idSel > 0 && $cm("SELECT COUNT(*) FROM mantenimiento_equipos WHERE id_programado=?", [$idSel]) === 1
    && $cm("SELECT COUNT(*) FROM mantenimiento_equipos WHERE id_programado=? AND id_equipo=?", [$idSel, $eqPC]) === 1
    && ($fm("SELECT alcance_equipo FROM mantenimientos_programados WHERE id_programado=?", [$idSel])['alcance_equipo'] ?? '') === 'SELECCION');
$r = $tec->get('mantenimientos_programados.php');
t('Formulario: orden sala → tipo → alcance → equipos', ($pS = strpos($r->body, 'id="id_sala"')) !== false && $pS < strpos($r->body, 'id="tipo"')
    && strpos($r->body, 'id="tipo"') < strpos($r->body, 'name="alcance_equipo" value="SELECCION"') && strpos($r->body, 'name="alcance_equipo" value="SELECCION"') < strpos($r->body, 'id="lista-equipos"'));

// ── Elevar desde ticket
$tk = $pdo->prepare("INSERT INTO tickets (user_id, origen, tipo_solicitud, descripcion, estado, nombre_dependencia, ubicacion, equipo, id_sala, tecnico_id, firmado) VALUES (?, ?, 'INCIDENTE', ?, 'En proceso', 'ZT', 'ZT', 'ZT', ?, ?, ?)");
$mkT = function (string $desc, ?int $tecnico, int $firmado = 0, string $origen = 'QR_ANONIMO') use ($tk, $pdo, $sM, $uid) { $tk->execute([$uid('solicitante'), $origen, $desc, $sM, $tecnico, $firmado]); return (int)$pdo->lastInsertId(); };
$tOk = $mkT('ZT-elev-ok', $uid('tecnico'));
$tNoTomado = $mkT('ZT-elev-notomado', null);
$tFirmado = $mkT('ZT-elev-firmado', $uid('tecnico'), 1);
$tPanel = $mkT('ZT-elev-panel', $uid('tecnico'), 0, 'PANEL_INTERNO');
$r = $tec->get('mantenimientos_programados.php?elevar_ticket=' . $tOk);
t('Elevar: ticket tomado → formulario con prefill', $r->contains('elevado desde ticket #' . $tOk) && $r->contains('name="id_ticket_origen"'));
$r = $tec->get('mantenimientos_programados.php?elevar_ticket=' . $tNoTomado);
t('Elevar: ticket no tomado → mensaje', $r->contains('Debes tomar este ticket') && !$r->contains('name="id_ticket_origen"'));
$r = $tec->get('mantenimientos_programados.php?elevar_ticket=' . $tFirmado);
t('Elevar: ticket firmado → mensaje', $r->contains('ya fue marcado como Solucionado/Firmado'));
$r = $tec->get('mantenimientos_programados.php?elevar_ticket=' . $tPanel);
t('Elevar: ticket de panel interno no es elegible (sin prefill)', !$r->contains('name="id_ticket_origen"'));
$r = $adm->get('mantenimientos_programados.php?elevar_ticket=' . $tOk);
t('Elevar: el admin no obtiene prefill', !$r->contains('name="id_ticket_origen"'));
$elev = $P(['descripcion' => 'ZT-mant-elev', 'id_ticket_origen' => $tOk]);
$tec->post('mantenimientos_programados.php', array_merge($elev, ['id_ticket_origen' => $tNoTomado, 'descripcion' => 'ZT-mant-elev-nt']));
t('Elevar (POST): ticket no tomado se rechaza', $idMant('ZT-mant-elev-nt') === 0);
$tec->post('mantenimientos_programados.php', array_merge($elev, ['id_ticket_origen' => $tFirmado, 'descripcion' => 'ZT-mant-elev-f']));
t('Elevar (POST): ticket firmado se rechaza', $idMant('ZT-mant-elev-f') === 0);
$tec->post('mantenimientos_programados.php', $elev);
$idElev = $idMant('ZT-mant-elev');
t('Elevar (POST): se crea ligado al ticket y deja auditoría con el origen', $idElev > 0 && $cm("SELECT id_ticket_origen FROM mantenimientos_programados WHERE id_programado=?", [$idElev]) === $tOk && $cm("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='mantenimientos_programados' AND id_registro=? AND detalle LIKE ?", [$idElev, "%ticket #$tOk%"]) === 1);
t('Elevar: avisa al dueño del ticket (notificación MANTENIMIENTO)', $cm("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND tipo='MANTENIMIENTO' AND mensaje LIKE ?", [$uid('solicitante'), "%ticket #$tOk%"]) === 1);
$r = $tec->post('mantenimientos_programados.php', array_merge($elev, ['descripcion' => 'ZT-mant-elev-dup']));
t('Elevar: segunda elevación del mismo ticket se rechaza', $idMant('ZT-mant-elev-dup') === 0 && $r->contains("ya fue elevado al mantenimiento #$idElev"));
$r = $tec->get('mantenimientos_programados.php?elevar_ticket=' . $tOk);
t('Elevar (GET): ticket ya elevado avisa', $r->contains("ya fue elevado al mantenimiento #$idElev"));

// tickets_profesor
$tpI = $pdo->prepare("INSERT INTO tickets_profesor (profesor_id, tipo_ticket, subtipo_requerimiento, id_sala, motivo, estado, descripcion_problema, tecnico_id, firmado) VALUES (?, ?, ?, ?, 'ZT', ?, ?, ?, ?)");
$mkTP = function (string $desc, string $tipo = 'danio', ?string $sub = null, string $estado = 'En proceso', ?int $tecnico = null, int $firmado = 0) use ($tpI, $pdo, $sM, $uid) { $tpI->execute([$uid('profesor'), $tipo, $sub, $sM, $estado, $desc, $tecnico, $firmado]); return (int)$pdo->lastInsertId(); };
$pOk = $mkTP('ZT-elevp-ok', 'danio', null, 'En proceso', $uid('tecnico'));
$pNoTom = $mkTP('ZT-elevp-nt', 'danio');
$pSol = $mkTP('ZT-elevp-sol', 'danio', null, 'Solucionado', $uid('tecnico'));
$pNoElegible = $mkTP('ZT-elevp-ne', 'requerimiento', 'CAMBIO_SALA', 'En proceso', $uid('tecnico'));
$r = $tec->get('mantenimientos_programados.php?elevar_ticket_profesor=' . $pOk);
t('Elevar profesor: ticket de daño tomado → prefill', $r->contains('elevado desde ticket de profesor #' . $pOk) && $r->contains('name="id_ticket_profesor_origen"'));
$r = $tec->get('mantenimientos_programados.php?elevar_ticket_profesor=' . $pNoTom);
t('Elevar profesor: no tomado → mensaje', $r->contains('Debes tomar este ticket'));
$r = $tec->get('mantenimientos_programados.php?elevar_ticket_profesor=' . $pSol);
t('Elevar profesor: solucionado → mensaje', $r->contains('ya fue marcado como Solucionado/Firmado'));
$r = $tec->get('mantenimientos_programados.php?elevar_ticket_profesor=' . $pNoElegible);
t('Elevar profesor: cambio de sala no es elegible', !$r->contains('name="id_ticket_profesor_origen"'));
$tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-elevp', 'id_ticket_profesor_origen' => $pOk]));
$idElevP = $idMant('ZT-mant-elevp');
t('Elevar profesor (POST): se crea ligado y avisa al profesor', $idElevP > 0 && $cm("SELECT id_ticket_profesor_origen FROM mantenimientos_programados WHERE id_programado=?", [$idElevP]) === $pOk && $cm("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND tipo='MANTENIMIENTO' AND mensaje LIKE ?", [$uid('profesor'), "%ticket #$pOk%"]) === 1);
$r = $tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-elevp2', 'id_ticket_profesor_origen' => $pOk]));
t('Elevar profesor: segunda elevación se rechaza', $idMant('ZT-mant-elevp2') === 0 && $r->contains("ya fue elevado al mantenimiento #$idElevP"));
$tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-elevp3', 'id_ticket_profesor_origen' => $pNoTom]));
t('Elevar profesor (POST): no tomado se rechaza', $idMant('ZT-mant-elevp3') === 0);

// ── Listado y filtros
$r = $tec->get('mantenimientos_programados.php');
t('Listado: muestra los mantenimientos y la checklist', $r->contains('ZT-mant-1') && $r->contains('Checklist de equipos (0/2)'));
t('Listado: sala sin equipos avisa que no hay checklist', $r->contains('Esta sala no tiene equipos registrados'));
$r = $tec->get('mantenimientos_programados.php?sala=' . $sVacia);
t('Filtro por sala', $r->contains('ZT-mant-vacia') && !$r->contains('ZT-mant-1'));
$r = $tec->get('mantenimientos_programados.php?estado=REALIZADO');
t('Filtro por estado (sin realizados no aparece ZT-mant-1)', !$r->contains('ZT-mant-1'));
$r = $beca->get('mantenimientos_programados.php?sala=' . $sVacia);
t('Beca: el filtro por GET se ignora (ve los activos)', $r->contains('ZT-mant-1') && $r->contains('ZT-mant-vacia'));
$tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-xss <script>alert(1)</script>']));
$r = $tec->get('mantenimientos_programados.php');
t('XSS: la descripción se escapa en el listado', $r->contains('ZT-xss &lt;script&gt;') && !$r->contains('<script>alert(1)</script>'));

// ── Cambiar estado
$post = fn($c, array $d, array $f = []) => $f ? $c->postFile('mantenimientos_programados.php', $d, $f) : $c->post('mantenimientos_programados.php', $d);
$estadoDe = fn(int $id) => (string)($fm("SELECT estado FROM mantenimientos_programados WHERE id_programado=?", [$id])['estado'] ?? '');
$r = $post($beca, ['cambiar_estado_mantenimiento' => '1', 'id_programado' => $id1, 'nuevo_estado' => 'EN_PROCESO']);
t('Estado: el beca no puede cambiarlo', $estadoDe($id1) === 'PROGRAMADO' && $r->contains('No puedes actualizar este mantenimiento'));
$r = $post($tec, ['cambiar_estado_mantenimiento' => '1', 'id_programado' => 99999999, 'nuevo_estado' => 'EN_PROCESO']);
t('Estado: id inexistente → mensaje', $r->status < 500 && $r->contains('No puedes actualizar este mantenimiento'));
$r = $post($tec, ['cambiar_estado_mantenimiento' => '1', 'id_programado' => $id1, 'nuevo_estado' => 'BORRADO']);
t('Estado: estado inválido se rechaza', $estadoDe($id1) === 'PROGRAMADO');
$post($tec, ['cambiar_estado_mantenimiento' => '1', 'id_programado' => $id1, 'nuevo_estado' => 'EN_PROCESO']);
t('Estado: EN_PROCESO por el técnico fija responsable y audita', $estadoDe($id1) === 'EN_PROCESO' && $cm("SELECT id_responsable FROM mantenimientos_programados WHERE id_programado=?", [$id1]) === $uid('tecnico') && $cm("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='mantenimientos_programados' AND id_registro=? AND accion='EDITAR' AND detalle='estado=EN_PROCESO'", [$id1]) === 1);
$tec->postRaw('mantenimientos_programados.php', ['cambiar_estado_mantenimiento' => '1', 'id_programado' => $idSw, 'nuevo_estado' => 'EN_PROCESO']);
t('Estado: sin CSRF no cambia', $estadoDe($idSw) === 'PROGRAMADO');
$r = $post($tec, ['cambiar_estado_mantenimiento' => '1', 'id_programado' => $id1, 'nuevo_estado' => 'REALIZADO'], ['evidencia[]' => ['zt_evidencia.png', $PNG, 'image/png']]);
t('Estado: REALIZADO con checklist incompleta se rechaza', $estadoDe($id1) === 'EN_PROCESO' && $r->contains('todavía hay equipos de la sala pendientes'));

// ── Checklist
$me = fn(int $prog, int $eq) => (int)$cm("SELECT id_mant_equipo FROM mantenimiento_equipos WHERE id_programado=? AND id_equipo=?", [$prog, $eq]);
$meEstado = fn(int $m) => (string)($fm("SELECT estado FROM mantenimiento_equipos WHERE id_mant_equipo=?", [$m])['estado'] ?? '');
$mPC = $me($id1, $eqPC); $mVB = $me($id1, $eqVB);
$r = $post($adm, ['tomar_equipo' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1]);
t('Checklist: el admin no toma equipos (solo supervisa)', $meEstado($mPC) === 'PENDIENTE');
$r = $post($adm, ['asignar_equipo_tecnico' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1, 'id_beca_asignar' => $uid('beca_trabajo')]);
t('Asignar: el admin no asigna equipos (solo supervisa)', $meEstado($mPC) === 'PENDIENTE');
$r = $adm->get('mantenimientos_programados.php');
t('Plano: el admin ve el plano en modo supervisión, sin botones de tomar ni asignar', $r->contains('Vista de supervisión') && !$r->contains('name="tomar_equipo"') && !$r->contains('name="asignar_equipo_tecnico"'));
$r = $beca->get('mantenimientos_programados.php');
t('Plano: el beca ve el plano de la sala con «Tomar este equipo» y sin asignar', $r->contains('class="sala-modal"') && $r->contains('name="tomar_equipo"') && !$r->contains('name="asignar_equipo_tecnico"'));
$r = $post($C['profesor'], ['tomar_equipo' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1]);
t('Checklist: un profesor no puede tomar', $meEstado($mPC) === 'PENDIENTE');
$r = $post($beca, ['tomar_equipo' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1]);
t('Checklist: el beca toma un equipo (TOMADO, asignado a él)', $meEstado($mPC) === 'TOMADO' && $cm("SELECT id_usuario_asignado FROM mantenimiento_equipos WHERE id_mant_equipo=?", [$mPC]) === $uid('beca_trabajo') && $r->contains('Tomaste este equipo'));
t('Checklist: tomar notifica al técnico responsable', $cm("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND mensaje LIKE ?", [$uid('tecnico'), "%tomó un equipo del mantenimiento #$id1%"]) === 1);
$r = $beca->get('tickets.php?vista=checklist');
t('Panel beca: la checklist usa el mismo plano de sala (tarjeta + «Plano de equipos» + acciones de vuelta al panel)', $r->contains('id="checklist-equipos"') && $r->contains('data-sala-abrir="mant-' . $id1 . '"')
    && $r->contains('data-sala-accion="mantenimientos_programados.php?volver=panel"') && $r->contains('class="sala-modal"') && $r->contains('name="tomar_equipo"') && $r->contains('name="marcar_equipo"') && !$r->contains('comp-card-static'));
$r = $beca2->post('mantenimientos_programados.php?volver=panel', ['tomar_equipo' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1], 'tickets.php');
t('Panel beca: una acción desde el panel vuelve a tickets.php con el resultado', $r->redirects() && strpos($r->location(), 'tickets.php?vista=checklist') !== false && $beca2->get('tickets.php?vista=checklist')->contains('ya fue tomado por otra persona'));
$r = $post($beca2, ['tomar_equipo' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1]);
t('Checklist: otro beca no puede tomar uno ya tomado', $r->contains('ya fue tomado por otra persona') && $cm("SELECT id_usuario_asignado FROM mantenimiento_equipos WHERE id_mant_equipo=?", [$mPC]) === $uid('beca_trabajo'));
$r = $post($beca2, ['marcar_equipo' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1, 'nuevo_estado_equipo' => 'REALIZADO']);
t('Checklist: otro beca no puede marcar el equipo ajeno (403)', $r->status === 403 && $meEstado($mPC) === 'TOMADO' && $r->contains('ya fue tomado por otro becario'));
$r = $post($beca2, ['marcar_equipo' => '1', 'id_mant_equipo' => $mVB, 'id_programado' => $id1, 'nuevo_estado_equipo' => 'REALIZADO']);
t('Checklist: no se puede marcar sin tomar primero (403)', $r->status === 403 && $meEstado($mVB) === 'PENDIENTE' && $r->contains('Primero debes tomar'));
$r = $post($beca, ['marcar_equipo' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1, 'nuevo_estado_equipo' => 'INCOMPLETO', 'observaciones_equipo' => '']);
t('Checklist: INCOMPLETO sin observación se rechaza', $meEstado($mPC) === 'TOMADO' && $r->contains('deja una observación'));
$r = $post($beca, ['marcar_equipo' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1, 'nuevo_estado_equipo' => 'RARO']);
t('Checklist: estado de equipo inválido se rechaza', $meEstado($mPC) === 'TOMADO' && $r->contains('Datos incompletos'));
$r = $post($beca, ['marcar_equipo' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1, 'nuevo_estado_equipo' => 'INCOMPLETO', 'observaciones_equipo' => 'falta cable']);
t('Checklist: INCOMPLETO con observación', $meEstado($mPC) === 'INCOMPLETO' && $r->contains('Equipo actualizado en la checklist'));
t('Checklist: marcar notifica al técnico', $cm("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND mensaje LIKE ?", [$uid('tecnico'), "%INCOMPLETO en el mantenimiento #$id1%"]) === 1);
// Incompleto = amarillo, y otro beca puede retomarlo para terminarlo
$r = $beca2->get('mantenimientos_programados.php');
t('Checklist: el equipo incompleto se pinta amarillo (est-incompleto) y ofrece «Retomar»', preg_match('/est-incompleto[^"]*"\s+data-puesto="' . $mPC . '"/', $r->body) === 1 && $r->contains('Retomar y terminar este equipo'));
$r = $post($beca2, ['tomar_equipo' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1]);
t('Checklist: otro beca retoma un equipo INCOMPLETO (TOMADO, a su nombre, conserva la observación)', $meEstado($mPC) === 'TOMADO' && $cm("SELECT id_usuario_asignado FROM mantenimiento_equipos WHERE id_mant_equipo=?", [$mPC]) === (int)$U['beca2']['id']
    && $cm("SELECT COUNT(*) FROM mantenimiento_equipos WHERE id_mant_equipo=? AND observaciones='falta cable'", [$mPC]) === 1 && $r->contains('Tomaste este equipo'));
$post($beca2, ['marcar_equipo' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1, 'nuevo_estado_equipo' => 'INCOMPLETO', 'observaciones_equipo' => 'falta cable']);
t('Checklist: quien lo retomó puede volver a marcarlo', $meEstado($mPC) === 'INCOMPLETO');
// El técnico asigna a un beca y marca como respaldo
$r = $post($tec, ['asignar_equipo_tecnico' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1, 'id_beca_asignar' => $uid('profesor')]);
t('Asignar: no se puede asignar a un profesor', $r->contains('becario/técnico válido') && $meEstado($mPC) === 'INCOMPLETO');
$r = $post($beca, ['asignar_equipo_tecnico' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1, 'id_beca_asignar' => $uid('beca_trabajo')]);
t('Asignar: el beca no puede usar la asignación del técnico', $meEstado($mPC) === 'INCOMPLETO');
$r = $post($tec, ['asignar_equipo_tecnico' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1, 'id_beca_asignar' => $uid('beca_trabajo')]);
t('Asignar: el técnico asigna (pasa a TOMADO) y avisa al beca', $meEstado($mPC) === 'TOMADO' && $r->contains('Equipo asignado a ZT beca_trabajo') && $cm("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND mensaje LIKE ?", [$uid('beca_trabajo'), "%te asignó un equipo en el mantenimiento #$id1%"]) === 1);
$r = $post($tec, ['asignar_equipo_tecnico' => '1', 'id_mant_equipo' => 99999999, 'id_programado' => $id1, 'id_beca_asignar' => $uid('beca_trabajo')]);
t('Asignar: equipo inexistente → mensaje', $r->status < 500 && $r->contains('no existe'));
// Liberar
$post($beca2, ['liberar_equipo' => '1', 'id_mant_equipo' => $mPC]);
t('Liberar: otro beca no puede liberar el equipo ajeno', $meEstado($mPC) === 'TOMADO');
$r = $post($beca, ['liberar_equipo' => '1', 'id_mant_equipo' => $mPC]);
t('Liberar: el dueño libera (PENDIENTE y sin asignado)', $meEstado($mPC) === 'PENDIENTE' && $cm("SELECT COUNT(*) FROM mantenimiento_equipos WHERE id_mant_equipo=? AND id_usuario_asignado IS NULL", [$mPC]) === 1 && $r->contains('Equipo liberado'));
// Pieza propuesta y aprobación
$post($beca, ['tomar_equipo' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1]);
$post($beca, ['marcar_equipo' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1, 'nuevo_estado_equipo' => 'REALIZADO', 'id_componente_entrante' => $compEntrante]);
t('Marcar: REALIZADO guarda la pieza entrante propuesta', $meEstado($mPC) === 'REALIZADO' && $cm("SELECT id_componente_entrante FROM mantenimiento_equipos WHERE id_mant_equipo=?", [$mPC]) === $compEntrante);
$r = $tec->get('mantenimientos_programados.php');
t('Marcar: el técnico ve «Aprobar cambio de pieza»', $r->contains('Aprobar cambio de pieza'));
$post($adm, ['aprobar_pieza_equipo' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1]);
t('Pieza: el admin no puede aprobar', $cm("SELECT COUNT(*) FROM componentes WHERE id_componente=? AND id_equipo_actual IS NULL", [$compEntrante]) === 1);
$r = $post($tec, ['aprobar_pieza_equipo' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1]);
t('Pieza: el técnico aprueba y se aplica al inventario', $r->contains('Cambio de pieza aprobado') && $cm("SELECT COUNT(*) FROM componentes WHERE id_componente=? AND id_equipo_actual=?", [$compEntrante, $eqPC]) === 1);
$r = $post($tec, ['aprobar_pieza_equipo' => '1', 'id_mant_equipo' => $mPC, 'id_programado' => $id1]);
t('Pieza: aprobar dos veces se rechaza (ya fue aplicado)', $r->contains('ya fue aplicado') && $cm("SELECT COUNT(*) FROM historial_componente WHERE id_componente=? AND tipo_evento='ASIGNACION'", [$compEntrante]) === 1);
t('Pieza: tras aprobar, la checklist ya no ofrece el botón y lo muestra como aplicado', $r->contains('Cambio de pieza aprobado por un técnico'));
// Revertir
$post($beca, ['revertir_equipo' => '1', 'id_mant_equipo' => $mPC]);
t('Revertir: el beca no puede', $meEstado($mPC) === 'REALIZADO');
$r = $post($tec, ['revertir_equipo' => '1', 'id_mant_equipo' => $mPC]);
t('Revertir: el técnico devuelve a PENDIENTE', $meEstado($mPC) === 'PENDIENTE' && $r->contains('Marca revertida'));
// Completar el segundo y cerrar
foreach ([$mPC, $mVB] as $m) {
    $post($tec, ['tomar_equipo' => '1', 'id_mant_equipo' => $m, 'id_programado' => $id1]);
    $post($tec, ['marcar_equipo' => '1', 'id_mant_equipo' => $m, 'id_programado' => $id1, 'nuevo_estado_equipo' => 'REALIZADO']);
}
t('Marcar: el técnico marca ambos como respaldo; el último avisa revisión final', $meEstado($mPC) === 'REALIZADO' && $meEstado($mVB) === 'REALIZADO');

// Cierre: evidencia
$r = $post($tec, ['cambiar_estado_mantenimiento' => '1', 'id_programado' => $id1, 'nuevo_estado' => 'REALIZADO']);
t('Cierre: REALIZADO sin foto de evidencia se rechaza', $estadoDe($id1) === 'EN_PROCESO' && $r->contains('Adjunta al menos una foto de evidencia'));
$r = $post($tec, ['cambiar_estado_mantenimiento' => '1', 'id_programado' => $id1, 'nuevo_estado' => 'REALIZADO'], ['evidencia[]' => ['zt_malo.php', '<?php echo 1;', 'image/png']]);
t('Cierre: un archivo no válido NO cuenta como evidencia (hallazgo corregido)', $estadoDe($id1) === 'EN_PROCESO' && $cm("SELECT COUNT(*) FROM ticket_adjuntos WHERE id_programado=?", [$id1]) === 0 && $r->contains('Adjunta al menos una foto de evidencia'));
$r = $post($tec, ['cambiar_estado_mantenimiento' => '1', 'id_programado' => $id1, 'nuevo_estado' => 'REALIZADO', 'observaciones_cierre' => 'ZT-ok'], ['evidencia[]' => ['zt_evidencia.png', $PNG, 'image/png']]);
t('Cierre: REALIZADO con evidencia válida', $estadoDe($id1) === 'REALIZADO' && $cm("SELECT COUNT(*) FROM ticket_adjuntos WHERE id_programado=?", [$id1]) === 1 && $r->contains('Mantenimiento actualizado'));
t('Cierre: guarda observaciones y fecha_realizado', $cm("SELECT COUNT(*) FROM mantenimientos_programados WHERE id_programado=? AND observaciones_cierre='ZT-ok' AND fecha_realizado IS NOT NULL", [$id1]) === 1);
$r = $tec->get('mantenimientos_programados.php?estado=REALIZADO');
t('Cierre: el listado muestra la evidencia', $r->contains('Evidencia (1)') && $r->contains('descargar_adjunto.php?id='));
$post($tec, ['cambiar_estado_mantenimiento' => '1', 'id_programado' => $idElev, 'nuevo_estado' => 'CANCELADO', 'observaciones_cierre' => 'ZT-cancel']);
t('Cierre: CANCELADO sin evidencia; avisa al dueño del ticket', $estadoDe($idElev) === 'CANCELADO' && $cm("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND mensaje LIKE ?", [$uid('solicitante'), "%ticket #$tOk quedó Cancelado%"]) === 1);
t('Cierre: CANCELADO no cierra el ticket de origen', $cm("SELECT COUNT(*) FROM tickets WHERE id=? AND estado='En proceso'", [$tOk]) === 1);

// Cierre de un mantenimiento elevado desde ticket (REALIZADO): cierra el ticket
$tOk2 = $mkT('ZT-elev-ok2', $uid('tecnico'));
$tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-elev2', 'id_ticket_origen' => $tOk2, 'id_sala' => $sCierre, 'confirmar_sin_equipos' => '1']));
$idElev2 = $idMant('ZT-mant-elev2');
$r = $post($tec, ['cambiar_estado_mantenimiento' => '1', 'id_programado' => $idElev2, 'nuevo_estado' => 'REALIZADO'], ['evidencia[]' => ['zt_evidencia.png', $PNG, 'image/png']]);
t('Cierre elevado: REALIZADO cierra el ticket de origen', $estadoDe($idElev2) === 'REALIZADO' && $cm("SELECT COUNT(*) FROM tickets WHERE id=? AND estado='Cerrado'", [$tOk2]) === 1);
t('Cierre elevado: notifica participantes (creador) y dueño', $cm("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND mensaje LIKE ?", [$uid('tecnico'), "%ticket #$tOk2%Cerrado definitivamente%"]) === 1 && $cm("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND mensaje LIKE ?", [$uid('solicitante'), "%ticket #$tOk2 quedó Probado y resuelto%"]) === 1);
// …y desde ticket de profesor
$pOk2 = $mkTP('ZT-elevp-ok2', 'danio', null, 'En proceso', $uid('tecnico'));
$tec->post('mantenimientos_programados.php', $P(['descripcion' => 'ZT-mant-elevp-b', 'id_ticket_profesor_origen' => $pOk2, 'id_sala' => $sCierre, 'confirmar_sin_equipos' => '1']));
$idElevP2 = $idMant('ZT-mant-elevp-b');
$post($tec, ['cambiar_estado_mantenimiento' => '1', 'id_programado' => $idElevP2, 'nuevo_estado' => 'REALIZADO'], ['evidencia[]' => ['zt_evidencia.png', $PNG, 'image/png']]);
t('Cierre elevado (profesor): cierra y firma el ticket de profesor', $cm("SELECT COUNT(*) FROM tickets_profesor WHERE id=? AND estado='Cerrado' AND firmado=1", [$pOk2]) === 1);
