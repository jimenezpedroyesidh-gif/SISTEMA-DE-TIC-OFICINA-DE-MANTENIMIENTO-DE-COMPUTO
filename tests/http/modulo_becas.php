<?php
/**
 * Pruebas del módulo Becas: create_ticket_beca.php, seguimiento_horas.php (+PDF), solicitudes_baja.php y
 * revisar_componentes_bodega.php. Se incluyen desde run_http_tests.php (aporta $pdo, $U, $C, $adm, $tec,
 * $CLAVE, cliente(), t() y seccion()).
 */
seccion('Becas: create_ticket_beca, seguimiento_horas(+PDF), solicitudes_baja, revisar_componentes_bodega');

$cb = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); })();
$fb = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(PDO::FETCH_ASSOC); })();
$beca = $C['beca_trabajo'];
$uidB = fn(string $r) => (int)$U[$r]['id'];

// Datos: dos salas, equipos, piezas
$pdo->prepare("INSERT INTO salas (nombre_sala, abreviatura, dependencia, codigo_qr_token) VALUES ('ZT-SALA-BEC1', 'ZB1', NULL, ?)")->execute(['zt' . bin2hex(random_bytes(8))]);
$sB1 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO salas (nombre_sala, abreviatura, dependencia, codigo_qr_token) VALUES ('ZT-SALA-BEC2', 'ZB2', NULL, ?)")->execute(['zt' . bin2hex(random_bytes(8))]);
$sB2 = (int)$pdo->lastInsertId();
$mkEq = function (int $sala, string $cod) use ($pdo) { $pdo->prepare("INSERT INTO equipos (id_sala, codigo, descripcion) VALUES (?,?,?)")->execute([$sala, $cod, 'ZT']); return (int)$pdo->lastInsertId(); };
$eqB1 = $mkEq($sB1, 'ZT-BEC-EQ1');
$eqB2 = $mkEq($sB1, 'ZT-BEC-EQ2');
$mkCo = function (string $tipo, string $ser, string $estado, ?int $eq) use ($pdo) {
    $pdo->prepare("INSERT INTO componentes (tipo, serial, codigo_inventario, estado, id_equipo_actual, marca) VALUES (?,?,?,?,?, 'ZT')")->execute([$tipo, $ser, $ser, $estado, $eq]);
    return (int)$pdo->lastInsertId();
};
$cSal = $mkCo('torre', 'ZT-BEC-SAL', 'ACTIVO', $eqB1);
$cEnt = $mkCo('torre', 'ZT-BEC-ENT', 'ACTIVO', null);
$cOcup = $mkCo('monitor', 'ZT-BEC-OCUP', 'ACTIVO', $eqB2);
$cRev1 = $mkCo('mouse', 'ZT-BEC-REV1', 'EN_REVISION', null);
$cRev2 = $mkCo('mouse', 'ZT-BEC-REV2', 'EN_REVISION', null);
$cRev3 = $mkCo('mouse', 'ZT-BEC-REV3', 'EN_REVISION', null);
$cDef = $mkCo('teclado', 'ZT-BEC-DEF', 'DEFECTUOSO', null);
$cAct = $mkCo('teclado', 'ZT-BEC-ACT', 'ACTIVO', null);

// ── create_ticket_beca: acceso
t('Becas: profesor/solicitante/secretaría no entran a create_ticket_beca', $C['profesor']->get('create_ticket_beca.php')->denied() && $C['solicitante']->get('create_ticket_beca.php')->denied() && $C['secretaria']->get('create_ticket_beca.php')->denied());
$r = $beca->get('create_ticket_beca.php');
t('create_ticket_beca: la beca ve el formulario con ambos tipos y la sala ZT', $r->status === 200 && $r->contains('id="form-cambio_pieza"') && $r->contains('id="form-cambio_posicion"') && $r->contains('ZT-SALA-BEC1') && $r->contains('crear_ticket_beca.js') && $r->contains('Sugerir mantenimiento general'));
t('create_ticket_beca: técnico y admin también lo ven (sin aviso de mantenimiento general)', $tec->get('create_ticket_beca.php')->status === 200 && !$tec->get('create_ticket_beca.php')->contains('Sugerir mantenimiento general') && $adm->get('create_ticket_beca.php')->status === 200);
t('create_ticket_beca: POST sin CSRF → 403', $beca->postRaw('create_ticket_beca.php', ['tipo_ticket_beca' => 'cambio_pieza'])->status === 403);

// ── cambio_pieza
$pz = ['tipo_ticket_beca' => 'cambio_pieza', 'id_sala_pieza' => $sB1, 'id_equipo' => $eqB1, 'id_componente_saliente' => $cSal, 'id_componente_entrante' => '', 'descripcion_pieza' => 'ZT-BEC pieza saliente'];
$r = $beca->post('create_ticket_beca.php', $pz);
$tk = $fb("SELECT * FROM tickets WHERE descripcion='ZT-BEC pieza saliente'");
t('cambio_pieza (beca, solo saliente): crea ticket BECA_DIRECTO sin técnico y mensaje de bodega', $tk && $tk['origen'] === 'BECA_DIRECTO' && $tk['tecnico_id'] === null && (int)$tk['id_componente_saliente'] === $cSal && $tk['id_componente_entrante'] === null && (int)$tk['user_id'] === $uidB('beca_trabajo') && $r->contains('se propone sacar la pieza a bodega/reparar'));
t('cambio_pieza: rellena dependencia/ubicación/equipo con los datos de sala y equipo', $tk && $tk['nombre_dependencia'] === 'ZT-SALA-BEC1' && $tk['ubicacion'] === 'ZT-SALA-BEC1' && $tk['equipo'] === 'ZT-BEC-EQ1' && (int)$tk['id_sala'] === $sB1 && (int)$tk['id_equipo'] === $eqB1);
t('cambio_pieza: deja auditoría CREAR', $tk && $cb("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='tickets' AND id_registro=? AND accion='CREAR'", [$tk['id']]) === 1);
$r = $beca->post('create_ticket_beca.php', array_merge($pz, ['id_componente_saliente' => '', 'id_componente_entrante' => $cEnt, 'descripcion_pieza' => 'ZT-BEC pieza entrante']));
t('cambio_pieza (solo entrante libre): se crea y avisa de devolución', $cb("SELECT COUNT(*) FROM tickets WHERE descripcion='ZT-BEC pieza entrante' AND id_componente_entrante=?", [$cEnt]) === 1 && $r->contains('se propone devolver la pieza ya reparada'));
$r = $beca->post('create_ticket_beca.php', array_merge($pz, ['id_componente_entrante' => $cEnt, 'descripcion_pieza' => 'ZT-BEC pieza ambas']));
t('cambio_pieza (ambas): mensaje de cambio directo', $r->contains('Ticket de cambio de pieza creado. Queda pendiente de asignación técnica y aprobación.'));
$r = $beca->post('create_ticket_beca.php', array_merge($pz, ['id_componente_saliente' => '', 'id_componente_entrante' => $cOcup, 'descripcion_pieza' => 'ZT-BEC entrante ocupada']));
t('cambio_pieza: entrante ya instalada en otro equipo → rechazo y sin ticket', $r->contains('El componente de reemplazo ya no está disponible') && $cb("SELECT COUNT(*) FROM tickets WHERE descripcion='ZT-BEC entrante ocupada'") === 0);
$r = $beca->post('create_ticket_beca.php', array_merge($pz, ['id_componente_saliente' => '', 'id_componente_entrante' => 999999999, 'descripcion_pieza' => 'ZT-BEC entrante fantasma']));
t('cambio_pieza: entrante inexistente → rechazo y sin ticket', $r->contains('El componente de reemplazo ya no está disponible') && $cb("SELECT COUNT(*) FROM tickets WHERE descripcion='ZT-BEC entrante fantasma'") === 0);
foreach ([['id_sala_pieza' => ''], ['id_equipo' => ''], ['id_componente_saliente' => ''], ['descripcion_pieza' => '   ']] as $i => $malo) {
    $r = $beca->post('create_ticket_beca.php', array_merge($pz, ['descripcion_pieza' => 'ZT-BEC incompleto' . $i], $malo));
    t("cambio_pieza: campos obligatorios #$i → mensaje y sin ticket", $r->contains('Completa sala, equipo, al menos una pieza') && $cb("SELECT COUNT(*) FROM tickets WHERE descripcion LIKE 'ZT-BEC incompleto%' AND user_id=?", [$uidB('beca_trabajo')]) === 0 && true);
}
$r = $tec->post('create_ticket_beca.php', array_merge($pz, ['descripcion_pieza' => 'ZT-BEC pieza tecnico']));
$tkT = $fb("SELECT * FROM tickets WHERE descripcion='ZT-BEC pieza tecnico'");
t('cambio_pieza (técnico): origen TECNICO_DIRECTO y autoasignado', $tkT && $tkT['origen'] === 'TECNICO_DIRECTO' && (int)$tkT['tecnico_id'] === $uidB('tecnico'));
$r = $beca->post('create_ticket_beca.php', array_merge($pz, ['descripcion_pieza' => '<script>ZT-BEC xss</script>']));
t('cambio_pieza: la descripción con HTML se guarda pero nunca se refleja sin escapar', !$r->contains('<script>ZT-BEC xss</script>'));
$pdo->exec("DELETE FROM tickets WHERE descripcion LIKE '%ZT-BEC xss%'");

// ── enlace con ticket de daño del profesor
$pdo->prepare("INSERT INTO tickets_profesor (profesor_id, tipo_ticket, descripcion_problema, sala_danio, id_sala, tecnico_id) VALUES (?, 'danio', 'ZT-BEC daño prof', 'ZT-SALA-BEC1', ?, ?)")->execute([$uidB('profesor'), $sB1, $uidB('beca_trabajo')]);
$tpOwn = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO tickets_profesor (profesor_id, tipo_ticket, descripcion_problema, sala_danio, id_sala, tecnico_id) VALUES (?, 'danio', 'ZT-BEC daño ajeno', 'ZT-SALA-BEC1', ?, ?)")->execute([$uidB('profesor'), $sB1, $uidB('tecnico')]);
$tpAjeno = (int)$pdo->lastInsertId();
$r = $beca->get("create_ticket_beca.php?id_ticket_profesor_origen=$tpOwn");
t('create_ticket_beca: GET con ticket de daño propio muestra el enlace al reporte', $r->contains("reporte de daño #$tpOwn"));
$r = $beca->get("create_ticket_beca.php?id_ticket_profesor_origen=$tpAjeno");
t('create_ticket_beca: GET con ticket de daño de otro técnico NO muestra enlace (IDOR)', !$r->contains("reporte de daño #$tpAjeno"));
$beca->post('create_ticket_beca.php', array_merge($pz, ['descripcion_pieza' => 'ZT-BEC enlazado', 'id_ticket_profesor_origen' => $tpOwn]));
$tkE = $fb("SELECT tabla_ticket_relacionado t, id_ticket_relacionado i FROM tickets WHERE descripcion='ZT-BEC enlazado'");
t('cambio_pieza: con origen propio queda enlazado a tickets_profesor', $tkE && $tkE['t'] === 'tickets_profesor' && (int)$tkE['i'] === $tpOwn);
$beca->post('create_ticket_beca.php', array_merge($pz, ['descripcion_pieza' => 'ZT-BEC enlace ajeno', 'id_ticket_profesor_origen' => $tpAjeno]));
$tkA = $fb("SELECT tabla_ticket_relacionado t, id_ticket_relacionado i FROM tickets WHERE descripcion='ZT-BEC enlace ajeno'");
t('cambio_pieza: con origen ajeno el ticket se crea pero SIN enlace', $tkA && $tkA['t'] === null && $tkA['i'] === null);

// ── cambio_posicion
$ps = ['tipo_ticket_beca' => 'cambio_posicion', 'id_equipo_mover' => $eqB1, 'id_sala_destino' => $sB2, 'descripcion_posicion' => 'ZT-BEC mover'];
$r = $beca->post('create_ticket_beca.php', $ps);
$tkP = $fb("SELECT * FROM tickets WHERE descripcion='ZT-BEC mover'");
t('cambio_posicion (beca): ticket En espera sin técnico, con sala origen/destino', $tkP && $tkP['estado'] === 'En espera' && $tkP['tecnico_id'] === null && (int)$tkP['id_sala'] === $sB1 && (int)$tkP['id_sala_destino'] === $sB2 && (int)$tkP['id_equipo'] === $eqB1 && $tkP['origen'] === 'BECA_DIRECTO' && $r->contains('Un técnico debe tomarlo'));
t('cambio_posicion: el equipo NO se mueve todavía y se audita', $cb("SELECT id_sala FROM equipos WHERE id_equipo=?", [$eqB1]) === $sB1 && $cb("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='tickets' AND id_registro=? AND accion='CREAR'", [$tkP['id']]) === 1);
$r = $tec->post('create_ticket_beca.php', array_merge($ps, ['descripcion_posicion' => 'ZT-BEC mover tec']));
$tkPT = $fb("SELECT * FROM tickets WHERE descripcion='ZT-BEC mover tec'");
t('cambio_posicion (técnico): nace Solucionado, autoasignado y avisa', $tkPT && $tkPT['estado'] === 'Solucionado' && (int)$tkPT['tecnico_id'] === $uidB('tecnico') && $tkPT['origen'] === 'TECNICO_DIRECTO' && $r->contains('Apruébalo en tu panel'));
$r = $beca->post('create_ticket_beca.php', array_merge($ps, ['id_sala_destino' => $sB1, 'descripcion_posicion' => 'ZT-BEC misma sala']));
t('cambio_posicion: misma sala → mensaje y sin ticket', $r->contains('La sala destino debe ser distinta') && $cb("SELECT COUNT(*) FROM tickets WHERE descripcion='ZT-BEC misma sala'") === 0);
$r = $beca->post('create_ticket_beca.php', array_merge($ps, ['descripcion_posicion' => '']));
t('cambio_posicion: sin motivo → mensaje', $r->contains('Selecciona el equipo, la sala destino y describe el motivo del cambio.'));
$r = $beca->post('create_ticket_beca.php', array_merge($ps, ['id_equipo_mover' => 999999999, 'descripcion_posicion' => 'ZT-BEC eq fantasma']));
t('cambio_posicion: equipo inexistente → mensaje', $r->contains('Selecciona el equipo, la sala destino'));
$r = $beca->post('create_ticket_beca.php', ['tipo_ticket_beca' => 'otro']);
t('create_ticket_beca: tipo desconocido → "Selecciona un tipo de ticket válido."', $r->contains('Selecciona un tipo de ticket válido.'));

// ── seguimiento_horas (solo becas tienen horas; admin ve todas; otros roles solo con permiso)
$H = 'seguimiento_horas.php'; $HP = 'seguimiento_horas_pdf.php';
$codTec = (string)$fb("SELECT codigo FROM users WHERE id=?", [$uidB('tecnico')])['codigo'];
$codBeca = (string)$fb("SELECT codigo FROM users WHERE id=?", [$uidB('beca_trabajo')])['codigo'];
t('Horas: técnico/profesor/solicitante/secretaría SIN permiso no entran ni a la página ni al PDF', $tec->get($H)->denied() && $C['profesor']->get($H)->denied() && $C['solicitante']->get($H)->denied() && $C['secretaria']->get($H)->denied()
    && $tec->get($HP)->denied() && $C['profesor']->get($HP)->denied() && $C['secretaria']->get($HP)->denied());
$r = $beca->get($H);
t('Horas: la beca ve sus propias horas («Mis horas»)', $r->status === 200 && $r->contains('Mis horas') && $r->contains('Seguimiento de horas'));
t('Horas: la beca sin permiso no ve el listado de becas', !$r->contains('id="listado-becas"'));
t('Horas: ninguna referencia al formato institucional', !$r->contains('FO-BU') && !$r->contains('Monitoría'));
$r = $adm->get($H);
t('Horas: el admin ve el listado de becas, sin horas propias', $r->status === 200 && $r->contains('id="listado-becas"') && $r->contains('ver_id=' . $uidB('beca_trabajo') . '&') && !$r->contains('ver_id=' . $uidB('admin') . '&') && !$r->contains('ver_id=' . $uidB('tecnico') . '&') && !$r->contains('Mis horas'));
t('Horas: el menú del admin dice «Horas de becas»', $r->contains('Horas de becas'));
$r = $adm->get($H . '?ver_id=' . $uidB('beca_trabajo') . '&anio=2025&mes=3');
t('Horas: el admin abre una beca con ?ver_id', $r->status === 200 && $r->contains('ZT beca_trabajo'));
$r = $adm->get($H . '?ver_id=' . $uidB('tecnico'));
t('Horas: ?ver_id de un técnico no muestra nada (los técnicos no tienen horas)', $r->status === 200 && !$r->contains('ZT tecnico —'));
$r = $beca->get($H . '?ver_id=' . $uidB('admin'));
t('Horas: la beca con ?ver_id ajeno sigue viendo solo las suyas', $r->status === 200 && !$r->contains('ZT admin') && $r->contains('Mis horas'));
$r = $beca->get($H . '?anio=abc&mes=99');
t('Horas: mes/año inválidos no rompen la página', $r->status === 200);
$r = $beca->get($H . '?anio=' . urlencode('"><script>alert(1)</script>'));
t('Horas: parámetros no se reflejan sin escapar', $r->status === 200 && !$r->contains('<script>alert(1)</script>'));
t('Horas: el menú del técnico sin permiso no muestra el módulo', !$tec->get('tickets.php')->contains('seguimiento_horas.php'));

// Permiso «Ver horas de todas las becas»: el admin se lo activa a un técnico, un profesor y una secretaria
foreach (['tecnico', 'profesor', 'secretaria'] as $rolH) {
    $adm->post('usuarios.php', ['alternar_permiso' => '1', 'id_usuario' => $uidB($rolH), 'permiso' => 'permiso_ver_horas_becas']);
}
t('Horas: el admin activa el permiso a técnico, profesor y secretaria desde Usuarios', $cb("SELECT COUNT(*) FROM users WHERE id IN (?,?,?) AND permiso_ver_horas_becas=1", [$uidB('tecnico'), $uidB('profesor'), $uidB('secretaria')]) === 3);
$adm->post('usuarios.php', ['alternar_permiso' => '1', 'id_usuario' => $uidB('admin'), 'permiso' => 'permiso_ver_horas_becas']);
t('Horas: el permiso no se le puede dar a un admin', $cb("SELECT permiso_ver_horas_becas FROM users WHERE id=?", [$uidB('admin')]) === 0);
$r = $tec->get($H);
t('Horas: el técnico con permiso ve el listado de becas y el enlace en su menú', $r->status === 200 && $r->contains('id="listado-becas"') && $r->contains('ver_id=' . $uidB('beca_trabajo')) && $r->contains('href="seguimiento_horas.php"'));
t('Horas: el profesor y la secretaria con permiso también entran', $C['profesor']->get($H)->status === 200 && $C['secretaria']->get($H)->status === 200 && !$C['profesor']->get($H)->denied());
$r = $tec->get($HP . '?codigo=' . urlencode($codTec));
t('Horas (PDF): con permiso, pedir el código de un técnico no sirve (solo becas)', $r->contains('No existe ninguna beca trabajo con ese código.'));
$r = $tec->get($HP . '?todas=1&anio=2025&mes=3');
t('Horas (PDF): el técnico con permiso descarga el de todas sin 500', $r->status === 200);
$r = $C['solicitante']->get($H);
t('Horas: el solicitante sin permiso sigue sin entrar', $r->denied());
foreach (['tecnico', 'profesor', 'secretaria'] as $rolH) {
    $adm->post('usuarios.php', ['alternar_permiso' => '1', 'id_usuario' => $uidB($rolH), 'permiso' => 'permiso_ver_horas_becas']);
}
t('Horas: al desactivar el permiso el técnico vuelve a quedar fuera', $tec->get($H)->denied());

// ── PDF
$r = $beca->get($HP . '?anio=2025&mes=3');
t('Horas (PDF): la beca recibe su PDF sin 500 (o aviso si no tiene código)', $r->status === 200 && (strncmp($r->body, '%PDF', 4) === 0 || $r->contains('No tienes código')), 'status ' . $r->status);
t('Horas (PDF): el PDF no lleva el formato institucional', strncmp($r->body, '%PDF', 4) !== 0 || strpos($r->body, 'FO-BU') === false);
$r = $beca->get($HP . '?codigo=ZT01&anio=2025&mes=3');
t('Horas (PDF): la beca no puede pedir el de otra (?codigo ignorado)', !$r->contains('No existe ninguna beca'));
$r = $adm->get($HP);
t('Horas (PDF): admin sin ?codigo ni ?todas → aviso', $r->contains('Falta el código de la beca a consultar.'));
$r = $adm->get($HP . '?codigo=ZT-NO-EXISTE');
t('Horas (PDF): código inexistente → aviso', $r->contains('No existe ninguna beca trabajo con ese código.'));
$r = $adm->get($HP . '?codigo=' . urlencode($codBeca) . '&anio=2025&mes=3');
t('Horas (PDF): admin descarga el de una beca', $r->status === 200 && strncmp($r->body, '%PDF', 4) === 0, 'status ' . $r->status);
$r = $adm->get($HP . '?todas=1&anio=2025&mes=3');
t('Horas (PDF): admin ?todas=1 responde sin 500', $r->status === 200);
t('Horas (PDF): sin sesión deniega', (new HttpClient($base))->get($HP)->denied());
t('Horas: las URLs viejas seguimiento_beca*.php ya no existen', (new HttpClient($base))->get('seguimiento_beca.php')->status === 404);

// ── revisar_componentes_bodega
t('Becas: beca/profesor/solicitante/secretaría no entran a revisar_componentes_bodega', $beca->get('revisar_componentes_bodega.php')->denied() && $C['profesor']->get('revisar_componentes_bodega.php')->denied() && $C['solicitante']->get('revisar_componentes_bodega.php')->denied() && $C['secretaria']->get('revisar_componentes_bodega.php')->denied());
$r = $tec->get('revisar_componentes_bodega.php');
t('revisar_componentes_bodega: técnico ve EN_REVISION y DEFECTUOSO, no los ACTIVO', $r->status === 200 && $r->contains('ZT-BEC-REV1') && $r->contains('ZT-BEC-DEF') && !$r->contains('ZT-BEC-ACT'));
t('revisar_componentes_bodega: el admin también entra', $adm->get('revisar_componentes_bodega.php')->status === 200);
t('revisar_componentes_bodega: POST sin CSRF → 403', $tec->postRaw('revisar_componentes_bodega.php', ['revisar_componente' => 1, 'id_componente' => $cRev1, 'nuevo_estado' => 'ACTIVO'])->status === 403);
$r = $tec->post('revisar_componentes_bodega.php', ['revisar_componente' => 1, 'id_componente' => $cRev1, 'nuevo_estado' => 'ACTIVO', 'observacion' => 'ZT-BEC ok']);
t('revisar: EN_REVISION → ACTIVO con mensaje', $r->contains('ZT-BEC-REV1') && $r->contains('pasó a ACTIVO') && $fb("SELECT estado FROM componentes WHERE id_componente=?", [$cRev1])['estado'] === 'ACTIVO');
t('revisar: deja historial REVISION (EN_REVISION→ACTIVO) con la observación', $cb("SELECT COUNT(*) FROM historial_componente WHERE id_componente=? AND tipo_evento='REVISION' AND estado_anterior='EN_REVISION' AND estado_nuevo='ACTIVO' AND observacion='ZT-BEC ok' AND id_usuario_responsable=?", [$cRev1, $uidB('tecnico')]) === 1);
$r = $tec->post('revisar_componentes_bodega.php', ['revisar_componente' => 1, 'id_componente' => $cRev1, 'nuevo_estado' => 'DEFECTUOSO']);
t('revisar: un componente que ya no está EN_REVISION se rechaza', $r->contains('El componente ya no está en revisión') && $fb("SELECT estado FROM componentes WHERE id_componente=?", [$cRev1])['estado'] === 'ACTIVO');
$r = $tec->post('revisar_componentes_bodega.php', ['revisar_componente' => 1, 'id_componente' => $cRev2, 'nuevo_estado' => 'DE_BAJA']);
t('revisar: estado destino inválido → "Estado destino inválido." y sin cambios', $r->contains('Estado destino inválido.') && $fb("SELECT estado FROM componentes WHERE id_componente=?", [$cRev2])['estado'] === 'EN_REVISION');
$r = $adm->post('revisar_componentes_bodega.php', ['revisar_componente' => 1, 'id_componente' => $cRev2, 'nuevo_estado' => 'DEFECTUOSO', 'observacion' => '']);
t('revisar: el admin pasa EN_REVISION → DEFECTUOSO', $r->contains('pasó a DEFECTUOSO') && $fb("SELECT estado FROM componentes WHERE id_componente=?", [$cRev2])['estado'] === 'DEFECTUOSO');

// ── solicitar baja
$r = $tec->post('revisar_componentes_bodega.php', ['solicitar_baja' => 1, 'id_componente' => $cDef, 'concepto_tecnico' => '   ']);
t('solicitar_baja: sin concepto técnico → mensaje y sin solicitud', $r->contains('Escribe el concepto técnico del daño antes de solicitar la baja.') && $cb("SELECT COUNT(*) FROM solicitudes_baja WHERE id_componente=?", [$cDef]) === 0);
$r = $tec->post('revisar_componentes_bodega.php', ['solicitar_baja' => 1, 'id_componente' => $cAct, 'concepto_tecnico' => 'ZT-BEC concepto']);
t('solicitar_baja: componente ACTIVO no se puede dar de baja desde aquí', $r->contains('Ese componente ya no está disponible para solicitar su baja.') && $cb("SELECT COUNT(*) FROM solicitudes_baja WHERE id_componente=?", [$cAct]) === 0);
$antesNot = $cb("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND url_destino='solicitudes_baja.php'", [$uidB('admin')]);
$r = $tec->post('revisar_componentes_bodega.php', ['solicitar_baja' => 1, 'id_componente' => $cDef, 'concepto_tecnico' => 'ZT-BEC concepto']);
$sol = $fb("SELECT * FROM solicitudes_baja WHERE id_componente=?", [$cDef]);
t('solicitar_baja: crea solicitud PENDIENTE a nombre del técnico', $sol && $sol['estado'] === 'PENDIENTE' && (int)$sol['id_solicito'] === $uidB('tecnico') && strpos($sol['motivo'], 'ZT-BEC concepto') !== false);
t('solicitar_baja: notifica a los admins activos (solicitudes_baja.php)', $cb("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND url_destino='solicitudes_baja.php'", [$uidB('admin')]) === $antesNot + 1);
$r = $tec->get('revisar_componentes_bodega.php');
t('revisar_componentes_bodega: la pieza con solicitud pendiente deja de ofrecerse', !$r->contains('ZT-BEC-DEF'));

// ── solicitudes_baja
t('solicitudes_baja: solo admin (técnico, beca, profesor denegados)', $tec->get('solicitudes_baja.php')->denied() && $beca->get('solicitudes_baja.php')->denied() && $C['profesor']->get('solicitudes_baja.php')->denied());
$r = $adm->get('solicitudes_baja.php');
t('solicitudes_baja: el admin ve la solicitud pendiente', $r->status === 200 && $r->contains('ZT-BEC-DEF') && $r->contains('name="decision"') || $r->contains('resolver_solicitud'));
t('solicitudes_baja: POST sin CSRF → 403', $adm->postRaw('solicitudes_baja.php', ['resolver_solicitud' => 1, 'id_solicitud' => $sol['id'], 'decision' => 'aprobar'])->status === 403);
$r = $adm->post('solicitudes_baja.php', ['resolver_solicitud' => 1, 'id_solicitud' => $sol['id'], 'decision' => 'aprobar', 'observacion_aprobacion' => 'ZT-BEC listo']);
$sol2 = $fb("SELECT * FROM solicitudes_baja WHERE id=?", [$sol['id']]);
t('solicitudes_baja: aprobar → APROBADA, componente DE_BAJA y mensaje', $sol2['estado'] === 'APROBADA' && (int)$sol2['id_aprobo'] === $uidB('admin') && $fb("SELECT estado FROM componentes WHERE id_componente=?", [$cDef])['estado'] === 'DE_BAJA' && $r->contains('Baja aprobada'));
t('solicitudes_baja: aprobar deja historial BAJA', $cb("SELECT COUNT(*) FROM historial_componente WHERE id_componente=? AND tipo_evento='BAJA'", [$cDef]) >= 1);
$nt = $fb("SELECT mensaje, tipo, url_destino FROM notificaciones WHERE id_usuario=? ORDER BY id_notificacion DESC LIMIT 1", [$uidB('tecnico')]);
t('solicitudes_baja: notifica al solicitante (SISTEMA, ficha_componente) con APROBADA', $nt && $nt['tipo'] === 'SISTEMA' && strpos((string)$nt['url_destino'], 'ficha_componente.php?id=' . $cDef) === 0 && stripos($nt['mensaje'], 'aprobada') !== false, json_encode($nt));
$r = $adm->post('solicitudes_baja.php', ['resolver_solicitud' => 1, 'id_solicitud' => $sol['id'], 'decision' => 'rechazar']);
t('solicitudes_baja: resolver dos veces → "ya no está pendiente"', $r->contains('Esa solicitud ya no está pendiente'));
$r = $adm->get('solicitudes_baja.php');
t('solicitudes_baja: la resuelta aparece en el historial', $r->contains('ZT-BEC-DEF'));
// rechazar con otra pieza
$tec->post('revisar_componentes_bodega.php', ['solicitar_baja' => 1, 'id_componente' => $cRev2, 'concepto_tecnico' => 'ZT-BEC otro']);
$sol3 = $fb("SELECT id FROM solicitudes_baja WHERE id_componente=? AND estado='PENDIENTE'", [$cRev2]);
$r = $adm->post('solicitudes_baja.php', ['resolver_solicitud' => 1, 'id_solicitud' => $sol3['id'], 'decision' => 'rechazar', 'observacion_aprobacion' => 'ZT-BEC sigue sirviendo']);
t('solicitudes_baja: rechazar → RECHAZADA, el componente NO se da de baja', $r->contains('Solicitud rechazada.') && $cb("SELECT COUNT(*) FROM solicitudes_baja WHERE id=? AND estado='RECHAZADA'", [$sol3['id']]) === 1 && $fb("SELECT estado FROM componentes WHERE id_componente=?", [$cRev2])['estado'] === 'DEFECTUOSO');
$r = $adm->post('solicitudes_baja.php', ['resolver_solicitud' => 1, 'id_solicitud' => 999999999, 'decision' => 'aprobar']);
t('solicitudes_baja: id inexistente → "ya no está pendiente" sin 500', $r->status === 200 && $r->contains('ya no está pendiente'));

// ── Correcciones
$r = $beca->post('create_ticket_beca.php', array_merge($pz, ['id_sala_pieza' => 999999999, 'descripcion_pieza' => 'ZT-BEC sala fantasma']));
t('P39 cambio_pieza: sala inexistente se rechaza con mensaje (antes: 500 por clave foránea)', $r->status === 200 && $r->contains('Completa sala, equipo') && $cb("SELECT COUNT(*) FROM tickets WHERE descripcion='ZT-BEC sala fantasma'") === 0, 'status ' . $r->status);
$r = $beca->post('create_ticket_beca.php', array_merge($pz, ['id_equipo' => 999999999, 'descripcion_pieza' => 'ZT-BEC equipo fantasma']));
t('P39 cambio_pieza: equipo inexistente se rechaza con mensaje', $r->status === 200 && $r->contains('Completa sala, equipo') && $cb("SELECT COUNT(*) FROM tickets WHERE descripcion='ZT-BEC equipo fantasma'") === 0, 'status ' . $r->status);
$r = $beca->post('create_ticket_beca.php', array_merge($pz, ['id_componente_saliente' => 999999999, 'descripcion_pieza' => 'ZT-BEC saliente fantasma']));
t('P39 cambio_pieza: saliente inexistente se rechaza con mensaje', $r->status === 200 && $cb("SELECT COUNT(*) FROM tickets WHERE descripcion='ZT-BEC saliente fantasma'") === 0, 'status ' . $r->status);
$r = $beca->post('create_ticket_beca.php', array_merge($ps, ['id_sala_destino' => 999999999, 'descripcion_posicion' => 'ZT-BEC destino fantasma']));
t('P39 cambio_posicion: sala destino inexistente se rechaza (antes: 500)', $r->status === 200 && $cb("SELECT COUNT(*) FROM tickets WHERE descripcion='ZT-BEC destino fantasma'") === 0, 'status ' . $r->status);
$r = $tec->post('revisar_componentes_bodega.php', ['solicitar_baja' => 1, 'id_componente' => $cRev3, 'concepto_tecnico' => 'ZT-BEC dup']);
$tec->post('revisar_componentes_bodega.php', ['solicitar_baja' => 1, 'id_componente' => $cRev3, 'concepto_tecnico' => 'ZT-BEC dup 2']);
t('P39 solicitar_baja: repetir la solicitud no duplica la pendiente (antes: duplicaba)', $cb("SELECT COUNT(*) FROM solicitudes_baja WHERE id_componente=? AND estado='PENDIENTE'", [$cRev3]) === 1);
