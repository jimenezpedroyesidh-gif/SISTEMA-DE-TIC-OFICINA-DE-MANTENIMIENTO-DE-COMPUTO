<?php
/**
 * Pruebas HTTP de lo público (QR) y del solicitante: reportar.php, consultar_estado.php y create_ticket.php.
 * Se incluye desde run_http_tests.php (aporta $pdo, $U, $C, $adm, $tec, $anon, $base, cliente(), t() y
 * seccion()).
 */
seccion('Público/QR y solicitante: reportar, consultar_estado, create_ticket');

$cp = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); })();
$fp = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(PDO::FETCH_ASSOC); })();
$mkSala = function (string $nombre, ?string $dep) use ($pdo) {
    $tk = 'zt' . bin2hex(random_bytes(8));
    $pdo->prepare("INSERT INTO salas (nombre_sala, abreviatura, dependencia, codigo_qr_token) VALUES (?, 'ZP', ?, ?)")->execute([$nombre, $dep, $tk]);
    return [(int)$pdo->lastInsertId(), $tk];
};
[$sPa, $tkPa] = $mkSala('ZT-SALA-PUB-A', 'Departamento de Sistemas'); // salas del Departamento (las becas reciben el aviso)
[$sPb, $tkPb] = $mkSala('ZT-SALA-PUB-B', 'Departamento de Sistemas'); // salas del Departamento (las becas reciben el aviso)
[$sPc, $tkPc] = $mkSala('ZT-SALA-PUB-C', 'Departamento de Sistemas'); // salas del Departamento (las becas reciben el aviso)
[$sPn, $tkPn] = $mkSala('ZT-SALA-PUB-NOSIS', 'Biblioteca central');
$beca = $C['beca_trabajo'];
$uidP = fn(string $r) => (int)$U[$r]['id'];

// ── reportar.php (anónimo)
$an1 = new HttpClient($base);
$r = $an1->get('reportar.php?token=' . $tkPa);
t('reportar: muestra la sala y el formulario', $r->status === 200 && $r->contains('ZT-SALA-PUB-A') && $r->contains('name="descripcion"') && $r->contains('<title>Reportar daño · ZT-SALA-PUB-A · UFPS</title>'));
t('reportar: sin token → "QR inválido: falta el token."', $an1->get('reportar.php')->contains('QR inválido: falta el token.'));
t('reportar: token desconocido → "QR inválido o sala no encontrada."', $an1->get('reportar.php?token=ZZZ')->contains('QR inválido o sala no encontrada.'));
$r = $an1->reportarQr($tkPa, ['descripcion' => '   ']);
t('reportar: descripción vacía → mensaje y sin ticket', $r->contains('Describe el problema antes de enviar.') && $cp("SELECT COUNT(*) FROM tickets WHERE id_sala=?", [$sPa]) === 0);
t('reportar: sin sesión pide nombre y correo institucional', $an1->get('reportar.php?token=' . $tkPa)->contains('name="correo_contacto"') && $an1->get('reportar.php?token=' . $tkPa)->contains('name="nombre_contacto"'));
$r = $an1->reportarQr($tkPa, ['descripcion' => 'ZT-PUB sin correo'], ['correo_contacto' => null]);
t('reportar: sin correo institucional → mensaje y sin ticket', $r->contains('correo institucional (@ufps.edu.co)') && $cp("SELECT COUNT(*) FROM tickets WHERE id_sala=?", [$sPa]) === 0);
$r = $an1->reportarQr($tkPa, ['descripcion' => 'ZT-PUB correo gmail'], ['correo_contacto' => 'alguien@gmail.com']);
t('reportar: correo que no es @ufps.edu.co → rechazado', $r->contains('correo institucional (@ufps.edu.co)') && $cp("SELECT COUNT(*) FROM tickets WHERE id_sala=?", [$sPa]) === 0);
$r = $an1->reportarQr($tkPa, ['descripcion' => 'ZT-PUB sin nombre'], ['nombre_contacto' => '']);
t('reportar: sin nombre → mensaje y sin ticket', $r->contains('Escribe tu nombre completo.') && $cp("SELECT COUNT(*) FROM tickets WHERE id_sala=?", [$sPa]) === 0);
$r = $an1->reportarQr($tkPa, ['descripcion' => 'ZT-PUB videobeam sin imagen'], ['correo_contacto' => 'ZT.Persona.QR@UFPS.edu.co']);
$tkA = $fp("SELECT * FROM tickets WHERE id_sala=? AND descripcion='ZT-PUB videobeam sin imagen'", [$sPa]);
t('reportar anónimo: crea ticket QR_ANONIMO sin usuario ni equipo, ubicación = sala', $tkA && $tkA['origen'] === 'QR_ANONIMO' && $tkA['user_id'] === null && $tkA['id_equipo'] === null && $tkA['ubicacion'] === 'ZT-SALA-PUB-A' && $tkA['estado'] === 'En espera');
t('reportar anónimo: guarda código de seguimiento de 8 hex y lo muestra', $tkA && preg_match('/^[0-9A-F]{8}$/', (string)$tkA['codigo_seguimiento']) === 1 && $r->contains('Reporte enviado') && $r->contains((string)$tkA['codigo_seguimiento']));
t('reportar anónimo: guarda la IP de origen', $tkA && !empty($tkA['ip_origen']));
t('reportar anónimo: guarda nombre y correo institucional (en minúsculas)', $tkA && $tkA['nombre_contacto'] === 'ZT Persona QR' && $tkA['correo_contacto'] === 'zt.persona.qr@ufps.edu.co');
t('reportar anónimo: avisa a beca_trabajo (TICKET_ESTADO → tickets.php)', $cp("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND tipo='TICKET_ESTADO' AND url_destino='tickets.php' AND mensaje LIKE '%ZT-SALA-PUB-A%QR anónimo%'", [$uidP('beca_trabajo')]) === 1);
$r = $an1->reportarQr($tkPa, ['descripcion' => 'ZT-PUB segundo seguido']);
t('reportar: segundo envío a la misma sala en menos de un minuto (misma sesión) → límite', $r->contains('Ya enviaste un reporte hace poco') && $cp("SELECT COUNT(*) FROM tickets WHERE id_sala=?", [$sPa]) === 1);
$an2 = new HttpClient($base);
$r = $an2->reportarQr($tkPa, ['descripcion' => 'ZT-PUB mismo daño otra persona']);
t('reportar: daño ya en curso en esa sala → aviso de duplicado y sin ticket nuevo', $r->contains('ya fue reportado antes') && $r->contains('En espera') && $cp("SELECT COUNT(*) FROM tickets WHERE id_sala=?", [$sPa]) === 1);
$r = $an2->reportarQr($tkPb, ['descripcion' => '<script>ZT-PUB xss</script>']);
t('reportar: no refleja la descripción y escapa la sala', !$r->contains('<script>ZT-PUB xss</script>') && $cp("SELECT COUNT(*) FROM tickets WHERE id_sala=?", [$sPb]) === 1);
$pdo->exec("UPDATE tickets SET descripcion='ZT-PUB xss' WHERE id_sala=$sPb");

// ── reportar.php (profesor con sesión)
$prof = $C['profesor'];
$r = $prof->get('reportar.php?token=' . $tkPc);
t('reportar (profesor): avisa que el reporte quedará a su nombre', $r->contains('Sesión iniciada como ZT profesor'));
$r = $prof->post('reportar.php?token=' . $tkPc, ['descripcion' => 'ZT-PUB daño de profesor']);
$tpP = $fp("SELECT * FROM tickets_profesor WHERE id_sala=?", [$sPc]);
t('reportar (profesor): crea tickets_profesor danio origen QR_PROFESOR a su nombre y redirige a su panel', $r->redirects() && strpos($r->location(), 'tickets.php') !== false && $tpP && $tpP['tipo_ticket'] === 'danio' && $tpP['origen'] === 'QR_PROFESOR' && (int)$tpP['profesor_id'] === $uidP('profesor') && $tpP['sala_danio'] === 'ZT-SALA-PUB-C' && $tpP['descripcion_problema'] === 'ZT-PUB daño de profesor');
$pdo->exec("UPDATE tickets_profesor SET descripcion_problema='ZT-PUB daño de profesor' WHERE id_sala=$sPc");
t('reportar (profesor): notifica a admins (REQUERIMIENTO) y a beca_trabajo', $cp("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND tipo='REQUERIMIENTO' AND url_destino='tickets.php' AND mensaje LIKE '%vía QR%'", [$uidP('admin')]) >= 1 && $cp("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND mensaje LIKE '%ZT-SALA-PUB-C%profesor%'", [$uidP('beca_trabajo')]) === 1);
// Plano de la sala en el QR del profesor: marca equipos con falla (opcional); solo se guardan los de esa sala.
[$sPd, $tkPd] = $mkSala('ZT-SALA-PUB-D', null);
$mkEqP = function (int $sala, string $cod) use ($pdo) { $pdo->prepare("INSERT INTO equipos (id_sala, codigo, descripcion) VALUES (?, ?, 'ZT')")->execute([$sala, $cod]); return (int)$pdo->lastInsertId(); };
$eqD1 = $mkEqP($sPd, 'ZT-PD-PC01'); $eqD2 = $mkEqP($sPd, 'ZT-PD-PC02'); $eqAjeno = $mkEqP($sPa, 'ZT-PA-PC09');
$r = $prof->get('reportar.php?token=' . $tkPd);
t('reportar (profesor): muestra el plano de la sala con sus equipos', $r->contains('data-plano-selector') && $r->contains('ZT-PD-PC01') && $r->contains('name="ids_equipo_danio[]"'));
// Equipo con la torre retirada (traslado aprobado por el técnico): sale con X, no se puede marcar y se ve quién aprobó.
$pdo->prepare("INSERT INTO componentes (tipo, serial, codigo_inventario, estado, id_equipo_actual) VALUES ('monitor','ZT-PD-MON','ZT-PD-MON','ACTIVO',?)")->execute([$eqD2]);
$pdo->prepare("INSERT INTO componentes (tipo, serial, codigo_inventario, estado, id_equipo_actual) VALUES ('torre','ZT-PD-TOR','ZT-PD-TOR','EN_REVISION',NULL)")->execute();
$torD = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO historial_componente (id_componente, tipo_evento, id_equipo_anterior, estado_anterior, estado_nuevo, motivo, id_usuario_responsable) VALUES (?, 'DESASIGNACION', ?, 'ACTIVO', 'EN_REVISION', 'ZT', ?)")->execute([$torD, $eqD2, $uidP('tecnico')]);
$pdo->prepare("INSERT INTO movimientos_activos (id_componente, id_sala_origen, id_sala_destino, motivo, id_solicito, id_aprobo, id_ejecuto, fecha_aprobado, fecha_ejecutado) VALUES (?, ?, NULL, 'CAMBIO_PIEZA_SALIENTE', ?, ?, ?, NOW(), NOW())")->execute([$torD, $sPd, $uidP('beca_trabajo'), $uidP('tecnico'), $uidP('beca_trabajo')]);
$r = $prof->get('reportar.php?token=' . $tkPd);
t('plano: el equipo sin torre sale «no operativo» (X), sin poder marcarse, con quién lo trasladó y aprobó', $r->contains('no-operativo') && $r->contains('sala-puesto-x') && preg_match('/value="' . $eqD2 . '"[^>]*disabled/', $r->body) === 1 && $r->contains('Sin torre ZT-PD-TOR') && $r->contains('Aprobó: ZT tecnico'));
$rA = $an1->get('reportar.php?token=' . $tkPd);
t('reportar (anónimo): también ve el plano con los no operativos, pero sin nombres de personas (solo el rol)', $rA->contains('data-plano-selector') && $rA->contains('Sin torre ZT-PD-TOR') && !$rA->contains('Aprobó: ZT tecnico') && $rA->contains('Aprobó: Técnico'));
[$sPe, $tkPe] = $mkSala('ZT-SALA-PUB-E', null);
$eqE1 = $mkEqP($sPe, 'ZT-PE-PC01');
$an2 = new HttpClient($base);
$an2->reportarQr($tkPe, ['descripcion' => 'ZT-PUB anónimo con plano', 'ids_equipo_danio' => [$eqE1, $eqAjeno]]);
$tkE = $fp("SELECT id FROM tickets WHERE id_sala=? AND origen='QR_ANONIMO'", [$sPe]);
t('reportar (anónimo): los equipos marcados en el plano quedan en ticket_activos (solo los de esa sala)', $tkE && $cp("SELECT COUNT(*) FROM ticket_activos WHERE tabla_ticket='tickets' AND id_ticket=? AND id_equipo=?", [(int)$tkE['id'], $eqE1]) === 1 && $cp("SELECT COUNT(*) FROM ticket_activos WHERE tabla_ticket='tickets' AND id_ticket=?", [(int)$tkE['id']]) === 1);
// Videobeam de la sala (componente frente al tablero): se ve en el plano del QR y se puede marcar como afectado
[$sPf, $tkPf] = $mkSala('ZT-SALA-PUB-F', null);
$pdo->prepare("INSERT INTO componentes (tipo, serial, codigo_inventario, estado, id_sala_directa) VALUES ('videobeam','ZT-PF-VB','ZT-PF-VB','ACTIVO',?)")->execute([$sPf]);
$vbF = (int)$pdo->lastInsertId();
$r = $an2->get('reportar.php?token=' . $tkPf);
t('reportar: el videobeam de la sala aparece frente al tablero como componente marcable', $r->contains('name="ids_componente_danio[]" value="' . $vbF . '"'));
$an2->reportarQr($tkPf, ['descripcion' => 'ZT-PUB videobeam sin imagen F', 'ids_componente_danio' => [$vbF, 999999]]);
$tkF = $fp("SELECT id FROM tickets WHERE id_sala=? AND origen='QR_ANONIMO'", [$sPf]);
t('reportar: el videobeam marcado queda en ticket_activos como componente (solo los de esa sala)', $tkF && $cp("SELECT COUNT(*) FROM ticket_activos WHERE tabla_ticket='tickets' AND id_ticket=? AND id_componente=?", [(int)$tkF['id'], $vbF]) === 1 && $cp("SELECT COUNT(*) FROM ticket_activos WHERE tabla_ticket='tickets' AND id_ticket=?", [(int)$tkF['id']]) === 1);
$pdo->prepare("DELETE FROM movimientos_activos WHERE id_componente=?")->execute([$torD]);
$pdo->prepare("DELETE FROM historial_componente WHERE id_componente=?")->execute([$torD]);
$prof->post('reportar.php?token=' . $tkPd, ['descripcion' => 'ZT-PUB plano profesor', 'ids_equipo_danio' => [$eqD1, $eqAjeno]]);
$tpD = $fp("SELECT id FROM tickets_profesor WHERE id_sala=? AND origen='QR_PROFESOR'", [$sPd]);
t('reportar (profesor): guarda en ticket_activos solo los equipos marcados que son de esa sala', $tpD && $cp("SELECT COUNT(*) FROM ticket_activos WHERE tabla_ticket='tickets_profesor' AND id_ticket=? AND id_equipo=?", [(int)$tpD['id'], $eqD1]) === 1 && $cp("SELECT COUNT(*) FROM ticket_activos WHERE tabla_ticket='tickets_profesor' AND id_ticket=?", [(int)($tpD['id'] ?? 0)]) === 1);
$r = $prof->post('reportar.php?token=' . $tkPc, ['descripcion' => 'ZT-PUB profesor repite']);
t('reportar (profesor): un segundo reporte en menos de un minuto queda limitado', $r->contains('Ya enviaste un reporte hace poco'));
$r = $C['solicitante']->reportarQr($tkPn, ['descripcion' => 'ZT-PUB solicitante logueado']);
t('reportar: un usuario logueado que no es profesor se trata como anónimo (ticket QR_ANONIMO)', $cp("SELECT COUNT(*) FROM tickets WHERE id_sala=? AND origen='QR_ANONIMO' AND user_id IS NULL", [$sPn]) === 1);

// ── consultar_estado.php
$ce = new HttpClient($base);
$seg = ['ZTSEGP01' => 'En espera', 'ZTSEGP02' => 'En proceso', 'ZTSEGP03' => 'Solucionado', 'ZTSEGP04' => 'Aprobado', 'ZTSEGP05' => 'Cerrado', 'ZTSEGP06' => 'Rechazado'];
$ins = $pdo->prepare("INSERT INTO tickets (user_id, nombre_dependencia, ubicacion, descripcion, id_sala, origen, codigo_seguimiento, estado, fecha_terminacion) VALUES (NULL, NULL, 'ZT-SALA-PUB-A', 'ZT-PUB secreto interno', ?, 'QR_ANONIMO', ?, ?, ?)");
foreach ($seg as $cod => $est) { $ins->execute([$sPa, $cod, $est, $est === 'Cerrado' ? '2025-01-02 03:04:05' : null]); }
$esperado = ['En espera' => 'Recibido — todavía nadie lo ha tomado.', 'En proceso' => 'ya lo está atendiendo', 'Solucionado' => 'pendiente de aprobación final', 'Aprobado' => 'Resuelto y cerrado.', 'Cerrado' => 'Resuelto y cerrado.', 'Rechazado' => 'se está corrigiendo'];
$okEst = true; $detalle = '';
foreach ($seg as $cod => $est) {
    $c = new HttpClient($base);
    $r = $c->get('consultar_estado.php?codigo=' . $cod);
    if (!$r->contains($esperado[$est]) || !$r->contains('ZT-SALA-PUB-A') || $r->contains('ZT-PUB secreto interno')) { $okEst = false; $detalle .= " $est"; }
}
t('consultar_estado: traduce cada estado a un mensaje público y no expone la descripción interna', $okEst, $detalle);
$r = (new HttpClient($base))->get('consultar_estado.php?codigo=ZTSEGP05');
t('consultar_estado: un ticket terminado muestra la fecha de finalización', $r->contains('Finalizado:') && $r->contains('2025-01-02'));
$r = (new HttpClient($base))->get('consultar_estado.php?codigo=' . urlencode('zt-seg p01'));
t('consultar_estado: normaliza el código (minúsculas, guiones y espacios)', $r->contains('Recibido — todavía nadie lo ha tomado.'));
$r = (new HttpClient($base))->get('consultar_estado.php?codigo=' . urlencode('---'));
t('consultar_estado: código sin caracteres válidos → "Ingresa un código válido."', $r->contains('Ingresa un código válido.'));
$r = (new HttpClient($base))->get('consultar_estado.php?codigo=NOEXISTE1');
t('consultar_estado: código inexistente → mensaje', $r->contains('No se encontró ningún reporte con ese código.'));
$pdo->prepare("UPDATE tickets SET codigo_seguimiento='ZTSEGP07', origen='PANEL_INTERNO' WHERE descripcion='ZT-desc-uno'")->execute();
$r = (new HttpClient($base))->get('consultar_estado.php?codigo=ZTSEGP07');
t('consultar_estado: solo reportes QR_ANONIMO (un ticket de panel con código no se revela)', $r->contains('No se encontró ningún reporte con ese código.'));
$r = (new HttpClient($base))->get('consultar_estado.php');
t('consultar_estado: sin código muestra solo el formulario', $r->status === 200 && $r->contains('name="codigo"') && !$r->contains('class="error') && !$r->contains('Estado actual'));
$thr = new HttpClient($base); $ultimo = null;
for ($i = 0; $i < 9; $i++) { $ultimo = $thr->get('consultar_estado.php?codigo=NOEXISTE' . $i); }
t('consultar_estado: tras 8 intentos en un minuto → "Demasiados intentos"', $ultimo->contains('Demasiados intentos. Espera un minuto'));
$r = (new HttpClient($base))->get('consultar_estado.php?codigo=' . urlencode('"><script>alert(1)</script>'));
t('consultar_estado: el código no se refleja sin escapar', !$r->contains('<script>alert(1)</script>'));

// ── create_ticket.php (solicitante / secretaria)
$sol = $C['solicitante']; $sec = $C['secretaria'];
t('create_ticket: beca/profesor/técnico/admin no entran', $beca->get('create_ticket.php')->denied() && $C['profesor']->get('create_ticket.php')->denied() && $tec->get('create_ticket.php')->denied() && $adm->get('create_ticket.php')->denied());
$r = $sol->get('create_ticket.php');
t('create_ticket (solicitante): formulario con dependencia/ubicación/equipo y categoría', $r->status === 200 && $r->contains('name="dependencia"') && $r->contains('name="ubicacion"') && $r->contains('name="equipo"') && $r->contains('name="categoria"') && !$r->contains('name="id_sala"'));
$r = $sec->get('create_ticket.php');
t('create_ticket (secretaria): equipos de la oficina de mantenimiento (sin salas) y radicado DatArSoft opcional', $r->status === 200 && !$r->contains('name="id_sala"') && $r->contains('name="dependencia"') && $r->contains('name="equipo"') && $r->contains('name="radicado_datarsoft"') && !$r->contains('name="radicado_datarsoft" required'));
t('create_ticket: POST sin CSRF → 403', $sol->postRaw('create_ticket.php', ['dependencia' => 'a', 'ubicacion' => 'b', 'equipo' => 'c', 'descripcion' => 'ZT-PUB sin csrf'])->status === 403);
$r = $sol->post('create_ticket.php', ['dependencia' => 'ZT dep P', 'ubicacion' => 'ZT ubi P', 'equipo' => 'ZT eq P', 'descripcion' => 'ZT-PUB solicitante ok', 'categoria' => 'RED']);
$tsol = $fp("SELECT * FROM tickets WHERE descripcion='ZT-PUB solicitante ok'");
t('create_ticket (solicitante): crea ticket con categoría y redirige a tickets.php?new=1', $r->redirects() && strpos($r->location(), 'tickets.php?new=1') !== false && $tsol && (int)$tsol['user_id'] === $uidP('solicitante') && $tsol['categoria'] === 'RED' && $tsol['nombre_dependencia'] === 'ZT dep P');
$sol->post('create_ticket.php', ['dependencia' => 'd', 'ubicacion' => 'u', 'equipo' => 'e', 'descripcion' => 'ZT-PUB cat mala', 'categoria' => 'HACK']);
t('create_ticket: categoría fuera de la lista blanca queda sin clasificar (NULL)', $cp("SELECT COUNT(*) FROM tickets WHERE descripcion='ZT-PUB cat mala' AND categoria IS NULL") === 1);
$r = $sol->post('create_ticket.php', ['dependencia' => 'd', 'ubicacion' => 'u', 'equipo' => 'e', 'descripcion' => '   ']);
t('create_ticket: descripción vacía → "La descripción es obligatoria."', $r->contains('La descripción es obligatoria.'));
$r = $sol->post('create_ticket.php', ['dependencia' => '', 'ubicacion' => 'u', 'equipo' => 'e', 'descripcion' => 'ZT-PUB falta dep']);
t('create_ticket: falta dependencia → "Completa dependencia, ubicación y equipo."', $r->contains('Completa dependencia, ubicación y equipo.') && $cp("SELECT COUNT(*) FROM tickets WHERE descripcion='ZT-PUB falta dep'") === 0);
$r = $sol->post('create_ticket.php', ['dependencia' => str_repeat('x', 101), 'ubicacion' => 'u', 'equipo' => 'e', 'descripcion' => 'ZT-PUB dep larga']);
t('create_ticket: campos de más de 100 caracteres se rechazan', $r->contains('admiten máximo 100 caracteres') && $cp("SELECT COUNT(*) FROM tickets WHERE descripcion='ZT-PUB dep larga'") === 0);
$r = $sol->post('create_ticket.php', ['dependencia' => 'd', 'ubicacion' => 'u', 'equipo' => 'e', 'descripcion' => 'ZT-PUB ' . str_repeat('y', 5100)]);
t('create_ticket: descripción de más de 5000 caracteres se rechaza', $r->contains('máximo 5000 caracteres'));
$r = $sec->post('create_ticket.php', ['dependencia' => 'ZT Biblioteca', 'equipo' => 'ZT Torre', 'marca_modelo' => 'Dell 3080', 'numero_inventario' => 'INV-77', 'radicado_datarsoft' => 'rad-2026/0042', 'descripcion' => 'ZT-PUB sec externo']);
$tsec = $fp("SELECT * FROM tickets WHERE descripcion='ZT-PUB sec externo'");
t('create_ticket (secretaria): crea el ticket del equipo externo con radicado DatArSoft y sin sala', $r->redirects() && $tsec && $tsec['id_sala'] === null && $tsec['nombre_dependencia'] === 'ZT Biblioteca'
    && $tsec['equipo'] === 'ZT Torre' && $tsec['marca_modelo'] === 'Dell 3080' && $tsec['numero_inventario'] === 'INV-77' && $tsec['radicado_datarsoft'] === 'RAD-2026/0042', json_encode($tsec));
$r = $sec->post('create_ticket.php', ['dependencia' => 'ZT dep', 'equipo' => 'ZT eq', 'descripcion' => 'ZT-PUB sec sin radicado']);
t('create_ticket (secretaria): el radicado es opcional', $r->redirects() && $cp("SELECT COUNT(*) FROM tickets WHERE descripcion='ZT-PUB sec sin radicado' AND radicado_datarsoft IS NULL") === 1);
$r = $sec->post('create_ticket.php', ['dependencia' => 'ZT dep', 'equipo' => 'ZT eq', 'radicado_datarsoft' => '<script>', 'descripcion' => 'ZT-PUB sec radicado malo']);
t('create_ticket (secretaria): radicado con caracteres raros → rechazo', $r->contains('radicado de DatArSoft solo admite') && $cp("SELECT COUNT(*) FROM tickets WHERE descripcion='ZT-PUB sec radicado malo'") === 0);
$r = $sec->post('create_ticket.php', ['dependencia' => '', 'equipo' => 'X', 'descripcion' => 'ZT-PUB sec sin dep']);
t('create_ticket (secretaria): sin dependencia → mensaje', $r->contains('Indica la dependencia de donde viene el equipo') && $cp("SELECT COUNT(*) FROM tickets WHERE descripcion='ZT-PUB sec sin dep'") === 0);
// Bandeja unificada (5 por página): se buscan las primeras páginas de «Pendientes» del Departamento; el técnico
// tiene el permiso de otras dependencias, así que también se comprueba que NO esté en esa bandeja.
$enDepto = false; $enExt = false;
for ($pg = 1; $pg <= 4; $pg++) {
    $bodyTec = $tec->get('tickets.php?pagina_sistemas_pend=' . $pg . '&pagina_externos_pend=' . $pg)->body;
    $secDepto = (string)strstr((string)strstr($bodyTec, 'id="tickets-sistemas"'), 'id="tickets-externos"', true);
    $secExt = (string)strstr((string)strstr($bodyTec, 'id="tickets-externos"'), '</section>', true);
    $enDepto = $enDepto || strpos($secDepto, 'RAD-2026/0042') !== false;
    $enExt = $enExt || strpos($secExt, 'RAD-2026/0042') !== false;
}
t('create_ticket (secretaria): el técnico lo ve en la sección Departamento (no en Externos) con el radicado', $enDepto && !$enExt);
// Con varias salas del Departamento en la prueba la lista puede pasar de una página (5 por página): se revisan las primeras.
$admVeRad = false;
for ($pg = 1; $pg <= 4 && !$admVeRad; $pg++) { $admVeRad = strpos($adm->get('tickets.php?vista=departamento&pagina_depto_pend=' . $pg)->body, 'RAD-2026/0042') !== false; }
t('create_ticket (secretaria): el admin también lo ve en Departamento', $admVeRad);
$r = $sol->post('create_ticket.php', ['dependencia' => 'd', 'ubicacion' => 'u', 'equipo' => 'e', 'descripcion' => '<script>ZT-PUB x</script>']);
t('create_ticket: la descripción nunca se refleja sin escapar', !$r->contains('<script>ZT-PUB x</script>'));

// ── Correcciones
$an3 = new HttpClient($base);
$r = $an3->reportarQr($tkPn, ['descripcion' => 'ZT-PUB ' . str_repeat('z', 5100)]);
t('P40 reportar: descripción de más de 5000 caracteres se rechaza con mensaje (antes se guardaba sin límite)', $r->contains('La descripción admite máximo 5000 caracteres.') && $cp("SELECT COUNT(*) FROM tickets WHERE id_sala=?", [$sPn]) === 1);
$pdo->exec("UPDATE tickets_profesor SET fecha_creacion = fecha_creacion - INTERVAL 1 DAY WHERE id_sala=$sPc");
$prof2 = cliente($U['profesor'], $CLAVE);
$pdo->exec("UPDATE tickets_profesor SET estado='Cerrado' WHERE id_sala=$sPc");
$r = $prof2->postRaw('reportar.php?token=' . $tkPc, ['descripcion' => 'ZT-PUB profesor sin csrf']);
t('P40 reportar: un profesor con sesión no puede reportar sin token CSRF (antes se creaba el ticket a su nombre)', $r->status === 403 && $cp("SELECT COUNT(*) FROM tickets_profesor WHERE descripcion_problema='ZT-PUB profesor sin csrf'") === 0, 'status ' . $r->status);
$r = (new HttpClient($base))->get('reportar.php?token=' . $tkPc);
t('P40 reportar: el formulario público incluye el campo CSRF', $r->contains('name="csrf_token"'));
