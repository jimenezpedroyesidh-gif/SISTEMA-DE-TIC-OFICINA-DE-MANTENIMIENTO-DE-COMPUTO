<?php
/**
 * Pruebas HTTP del módulo Salas y Horarios (se incluye desde run_http_tests.php, que aporta $pdo, $U
 * (usuarios), $C (clientes por rol), $adm, $tec, $idSala, $base, t() y seccion()).
 */
require_once __DIR__ . '/../../app/Support/autoload.php';
seccion('Salas y Horarios: horarios (crear, editar, solape, excepciones, vaciar, permisos, visibilidad)');

$cnt = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); })();
$fila = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(); })();

$periodoHoy = date('Y') . '-' . (date('n') <= 6 ? '1' : '2');
// Martes de la semana en curso (día ISO 2) → fecha real para excepciones y choques
$martes = (new DateTime('monday this week'))->modify('+1 day')->format('Y-m-d');
$miercoles = (new DateTime($martes))->modify('+1 day')->format('Y-m-d');

$pdo->prepare("INSERT INTO salas (nombre_sala, abreviatura, dependencia, codigo_qr_token) VALUES ('ZT-SALA-H', 'ZTH', 'x', ?)")->execute(['zt' . bin2hex(random_bytes(8))]);
$idSalaH = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO salas (nombre_sala, abreviatura, dependencia, codigo_qr_token) VALUES ('ZT-SALA-H2', 'ZTH2', 'x', ?)")->execute(['zt' . bin2hex(random_bytes(8))]);
$idSalaH2 = (int)$pdo->lastInsertId();

$base_h = ['id_sala' => $idSalaH, 'tipo' => 'CLASE', 'dia_semana' => 2, 'hora_inicio' => '08:00', 'hora_fin' => '10:00',
           'detalle' => 'ZT-clase-visible', 'periodo' => $periodoHoy, 'id_usuario' => $U['profesor']['id']];
$nH = fn() => $cnt("SELECT COUNT(*) FROM horarios WHERE detalle LIKE 'ZT-%'");

// ── Crear
$r = $adm->post('horarios.php', ['crear_horario' => '1'] + $base_h);
$idH = $cnt("SELECT id_horario FROM horarios WHERE detalle='ZT-clase-visible'");
t('Horarios: crear una clase válida', $idH > 0 && $r->contains('Horario creado correctamente'));
t('Horarios: la creación deja auditoría', $cnt("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='horarios' AND id_registro=? AND accion='CREAR'", [$idH]) === 1);
$r = $adm->post('horarios.php', ['crear_horario' => '1'] + array_merge($base_h, ['detalle' => 'ZT-solape', 'hora_inicio' => '09:00', 'hora_fin' => '11:00']));
t('Horarios: solape en misma sala/día/periodo se rechaza', $cnt("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-solape'") === 0 && $r->contains('se cruza'));
$r = $adm->post('horarios.php', ['crear_horario' => '1'] + array_merge($base_h, ['detalle' => 'ZT-contiguo', 'hora_inicio' => '10:00', 'hora_fin' => '12:00']));
t('Horarios: franja contigua (10:00 pegada a 10:00) sí se acepta', $cnt("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-contiguo'") === 1);
$r = $adm->post('horarios.php', ['crear_horario' => '1'] + array_merge($base_h, ['detalle' => 'ZT-otrodia', 'dia_semana' => 3]));
t('Horarios: misma franja en otro día se acepta', $cnt("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-otrodia'") === 1);
$r = $adm->post('horarios.php', ['crear_horario' => '1'] + array_merge($base_h, ['detalle' => 'ZT-sala2', 'id_sala' => $idSalaH2]));
t('Horarios: misma franja en otra sala se acepta', $cnt("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-sala2'") === 1);
foreach ([
    'tipo inválido' => ['tipo' => 'XX'], 'día 0' => ['dia_semana' => 0], 'día 8' => ['dia_semana' => 8],
    'hora vacía' => ['hora_inicio' => ''], 'hora mal formada' => ['hora_fin' => '25:99'],
    'fin <= inicio' => ['hora_inicio' => '14:00', 'hora_fin' => '13:00'], 'periodo inválido' => ['periodo' => '2026-3'],
    'clase sin sala' => ['id_sala' => ''],
] as $nombre => $cambio) {
    $adm->post('horarios.php', ['crear_horario' => '1'] + array_merge($base_h, ['detalle' => 'ZT-inv', 'hora_inicio' => '15:00', 'hora_fin' => '16:00'], $cambio));
    t("Horarios: se rechaza $nombre", $cnt("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-inv'") === 0);
}
// Turno de beca sin sala: solape por persona
$tb = ['tipo' => 'TURNO_BECA', 'id_sala' => '', 'id_usuario' => $U['beca_trabajo']['id'], 'dia_semana' => 4, 'hora_inicio' => '14:00', 'hora_fin' => '16:00', 'detalle' => 'ZT-turno-beca', 'periodo' => $periodoHoy];
$adm->post('horarios.php', ['crear_horario' => '1'] + $tb);
t('Horarios: turno de beca sin sala se crea', $cnt("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-turno-beca'") === 1);
$adm->post('horarios.php', ['crear_horario' => '1'] + array_merge($tb, ['detalle' => 'ZT-turno-beca2', 'hora_inicio' => '15:00', 'hora_fin' => '17:00']));
t('Horarios: turno de la misma persona que se cruza se rechaza', $cnt("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-turno-beca2'") === 0);
// Varias becas/técnicos en el mismo horario y la misma sala: permitido (solo choca la misma persona)
$pdo->prepare("INSERT INTO users (codigo, correo, password, password_temporal, role, nombre_completo, cargo, estado_activo) VALUES ('ZT0H', 'zt_becah@test.local', ?, 0, 'beca_trabajo', 'ZT beca-H2', 'Prueba', 1)")->execute([$hash]);
$U['becaH2'] = ['id' => (int)$pdo->lastInsertId(), 'correo' => 'zt_becah@test.local', 'codigo' => 'ZT0H'];
$ts = ['tipo' => 'TURNO_BECA', 'id_sala' => $idSalaH, 'dia_semana' => 5, 'hora_inicio' => '06:00', 'hora_fin' => '10:00', 'periodo' => $periodoHoy];
$adm->post('horarios.php', ['crear_horario' => '1'] + $ts + ['id_usuario' => $U['beca_trabajo']['id'], 'detalle' => 'ZT-misma-franja-1']);
$r = $adm->post('horarios.php', ['crear_horario' => '1'] + $ts + ['id_usuario' => $U['becaH2']['id'], 'detalle' => 'ZT-misma-franja-2']);
t('Horarios: dos becas en la misma sala y la misma hora se aceptan', $cnt("SELECT COUNT(*) FROM horarios WHERE detalle IN ('ZT-misma-franja-1','ZT-misma-franja-2')") === 2);
$adm->post('horarios.php', ['crear_horario' => '1'] + array_merge($ts, ['tipo' => 'TURNO_TECNICO', 'hora_inicio' => '07:00', 'hora_fin' => '09:00', 'id_usuario' => $U['tecnico']['id'], 'detalle' => 'ZT-misma-franja-tec']));
t('Horarios: un técnico en la misma franja que las becas se acepta', $cnt("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-misma-franja-tec'") === 1);
$adm->post('horarios.php', ['crear_horario' => '1'] + array_merge($ts, ['tipo' => 'CLASE', 'id_usuario' => $U['profesor']['id'], 'detalle' => 'ZT-clase-con-becas']));
t('Horarios: una clase en la sala donde hay turnos de beca se acepta (los turnos no ocupan el salón)', $cnt("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-clase-con-becas'") === 1);
$r = $adm->post('horarios.php', ['crear_horario' => '1'] + $ts + ['id_usuario' => $U['becaH2']['id'], 'hora_inicio' => '09:00', 'hora_fin' => '11:00', 'detalle' => 'ZT-misma-franja-3']);
t('Horarios: la misma beca con otro turno que se cruza se rechaza (mensaje de persona)', $cnt("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-misma-franja-3'") === 0 && $r->contains('otro turno de la misma persona'));
$viernes = (new DateTime($martes))->modify('+3 days')->format('Y-m-d');
$r = $adm->get('horarios.php?sala=' . $idSalaH . '&semana=' . $viernes);
t('Calendario: las franjas simultáneas se agrupan en un recuadro con una línea por persona', $r->contains('hg-grupo') && $r->contains('ZT beca-H2') && $r->contains('ZT beca_trabajo'));
$r = cliente($U['becaH2'], $CLAVE)->get('horarios.php?semana=' . $viernes);
t('Horarios: cada beca ve solo su turno y su nombre (no el de la otra beca del mismo horario)', $r->contains('ZT beca-H2') && !$r->contains('ZT beca_trabajo') && !$r->contains('ZT-clase-con-becas'));

// ── Editar
$adm->post('horarios.php', ['editar_horario' => '1', 'id_horario' => $idH] + array_merge($base_h, ['detalle' => 'ZT-clase-visible', 'hora_fin' => '09:30']));
t('Horarios: editar (no choca consigo mismo)', (string)$cnt("SELECT HOUR(hora_fin)*100+MINUTE(hora_fin) FROM horarios WHERE id_horario=?", [$idH]) === '930');
$adm->post('horarios.php', ['editar_horario' => '1', 'id_horario' => $idH] + array_merge($base_h, ['hora_fin' => '11:00']));
$fin = $fila("SELECT hora_fin FROM horarios WHERE id_horario=?", [$idH]);
t('Horarios: editar para chocar con otra franja se rechaza', substr($fin['hora_fin'], 0, 5) === '09:30');
$adm->post('horarios.php', ['editar_horario' => '1', 'id_horario' => 0] + $base_h);
t('Horarios: editar sin id válido no cambia nada', $nH() >= 1);

// ── Excepciones
$ex = ['guardar_excepcion' => '1', 'id_horario' => $idH, 'fecha' => $martes, 'tipo_excepcion' => 'CANCELADO', 'motivo' => 'ZT-motivo'];
$r = $adm->post('horarios.php', $ex);
t('Excepción: cancelar solo esta semana', $cnt("SELECT COUNT(*) FROM excepciones_horario WHERE id_horario=? AND fecha=? AND tipo_excepcion='CANCELADO'", [$idH, $martes]) === 1 && $r->contains('Cambio guardado'));
t('Excepción: la creación queda auditada (CREAR)', $cnt("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='excepciones_horario' AND id_registro=? AND accion='CREAR'", [$idH]) === 1);
$adm->post('horarios.php', array_merge($ex, ['tipo_excepcion' => 'CAMBIO_SALA', 'nueva_id_sala' => $idSalaH2]));
t('Excepción: la actualización queda auditada (EDITAR)', $cnt("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='excepciones_horario' AND id_registro=? AND accion='EDITAR'", [$idH]) === 1);
t('Excepción: guardar de nuevo la misma fecha actualiza (no duplica)', $cnt("SELECT COUNT(*) FROM excepciones_horario WHERE id_horario=? AND fecha=?", [$idH, $martes]) === 1
    && $cnt("SELECT nueva_id_sala FROM excepciones_horario WHERE id_horario=? AND fecha=?", [$idH, $martes]) === $idSalaH2);
$r = $adm->post('horarios.php', array_merge($ex, ['fecha' => $miercoles]));
t('Excepción: fecha de otro día de la semana se rechaza', $cnt("SELECT COUNT(*) FROM excepciones_horario WHERE id_horario=? AND fecha=?", [$idH, $miercoles]) === 0 && $r->contains('no corresponde al día'));
$r = $adm->post('horarios.php', array_merge($ex, ['fecha' => '31/12/2026']));
t('Excepción: fecha mal formada se rechaza', $r->contains('Fecha inválida'));
$r = $adm->post('horarios.php', array_merge($ex, ['tipo_excepcion' => 'XX']));
t('Excepción: tipo inválido se rechaza', $r->contains('Selecciona qué tipo'));
$r = $adm->post('horarios.php', array_merge($ex, ['tipo_excepcion' => 'CAMBIO_SALA', 'nueva_id_sala' => '']));
t('Excepción: cambio de sala sin sala se rechaza', $r->contains('sala nueva'));
$r = $adm->post('horarios.php', array_merge($ex, ['tipo_excepcion' => 'CAMBIO_HORA', 'nueva_hora_inicio' => '12:00', 'nueva_hora_fin' => '11:00']));
t('Excepción: cambio de hora con fin <= inicio se rechaza', $r->contains('hora de inicio y fin válidas'));
$adm->post('horarios.php', array_merge($ex, ['tipo_excepcion' => 'CAMBIO_HORA', 'nueva_hora_inicio' => '12:00', 'nueva_hora_fin' => '13:00']));
$e = $fila("SELECT * FROM excepciones_horario WHERE id_horario=? AND fecha=?", [$idH, $martes]);
t('Excepción: cambio de hora válido (y limpia la sala anterior)', substr($e['nueva_hora_inicio'], 0, 5) === '12:00' && $e['nueva_id_sala'] === null);
$r = $adm->post('horarios.php', array_merge($ex, ['id_horario' => 99999999]));
t('Excepción: horario inexistente da mensaje (sin 500)', $r->status < 500 && $r->contains('ya no existe'));
$idExc = (int)$e['id_excepcion'];
$r = $adm->get('horarios.php?semana=' . $martes . '&periodo=' . $periodoHoy);
t('Calendario: la excepción aparece en la semana correspondiente', $r->status === 200 && $r->contains('12:00'));
$adm->post('horarios.php', ['borrar_excepcion' => '1', 'id_excepcion' => $idExc]);
t('Excepción: quitar la excepción', $cnt("SELECT COUNT(*) FROM excepciones_horario WHERE id_excepcion=?", [$idExc]) === 0);

// ── AJAX de choques (consumido por mantenimientos.js)
$ch = $adm->get('horarios.php?ajax=verificar_choque&id_sala=' . $idSalaH . '&fecha=' . urlencode($martes . 'T08:30'));
$j = $ch->json();
t('AJAX choques: devuelve el horario recurrente que ocupa la franja', is_array($j) && !empty($j['choques']) && stripos($ch->header('content-type'), 'json') !== false, substr($ch->body, 0, 150));
$j2 = $adm->get('horarios.php?ajax=verificar_choque&id_sala=' . $idSalaH . '&fecha=' . urlencode($martes . 'T06:00'))->json();
t('AJAX choques: sin evento a esa hora devuelve lista vacía', is_array($j2) && $j2['choques'] === []);
$j3 = $adm->get('horarios.php?ajax=verificar_choque&id_sala=0&fecha=')->json();
t('AJAX choques: parámetros vacíos devuelven lista vacía', is_array($j3) && $j3['choques'] === []);
t('AJAX choques: la clave usa los nombres que lee mantenimientos.js', is_array($j) && isset($j['choques'][0]['tipo'], $j['choques'][0]['hora_inicio'], $j['choques'][0]['hora_fin']));
$r = $C['profesor']->get('horarios.php?ajax=verificar_choque&id_sala=' . $idSalaH . '&fecha=' . urlencode($martes . 'T08:30'));
t('AJAX choques: profesor recibe 403 JSON', $r->status === 403 && ($r->json()['error'] ?? '') !== '');
$r = $anon->get('horarios.php?ajax=verificar_choque&id_sala=' . $idSalaH);
t('AJAX choques: anónimo bloqueado', $r->denied());

// ── Filtros, semanas y vistas por rol
foreach (['sala=' . $idSalaH, 'tipo=CLASE', 'dia=2', 'periodo=TODOS', 'periodo=' . $periodoHoy, 'periodo=zzz', 'tipo=XX', 'dia=99', 'sala=abc', 'semana=2026-10-05', 'semana=basura', 'semana[]=1'] as $q) {
    $r = $adm->get('horarios.php?' . $q);
    t("Horarios: filtro ?$q responde sin error", $r->status === 200 && !preg_match('/SQLSTATE|Stack trace|Warning:|Notice:|Fatal/i', $r->body), 'status ' . $r->status);
}
$r = $adm->get('horarios.php?sala=' . $idSalaH . '&semana=' . $martes);
t('Horarios: admin ve el detalle de la clase', $r->contains('ZT-clase-visible'));
$r = $C['profesor']->get('horarios.php?semana=' . $martes);
t('Horarios: profesor ve su propia clase con detalle', $r->contains('ZT-clase-visible'));
$adm->post('horarios.php', ['crear_horario' => '1', 'id_sala' => $idSalaH, 'tipo' => 'CLASE', 'dia_semana' => 2, 'hora_inicio' => '13:00', 'hora_fin' => '14:00', 'detalle' => 'ZT-clase-ajena-secreta', 'periodo' => $periodoHoy, 'id_usuario' => $U['secretaria']['id']]);
$r = $C['profesor']->get('horarios.php?semana=' . $martes);
t('Horarios: profesor NO ve el detalle de la clase de otra persona en su sala', !$r->contains('ZT-clase-ajena-secreta'));
$r = $C['beca_trabajo']->get('horarios.php?semana=' . $martes);
t('Horarios: beca no ve clases ni detalles de otros', !$r->contains('ZT-clase-visible') && !$r->contains('ZT-clase-ajena-secreta'));
$r = $C['beca_trabajo']->get('horarios.php?semana=' . (new DateTime($martes))->modify('+2 days')->format('Y-m-d'));
t('Horarios: beca ve su propio turno', $r->contains('ZT-turno-beca'));
$r = $C['secretaria']->get('horarios.php?sala=' . $idSalaH);
t('Horarios: secretaria sin permiso no ve las salas (aviso para pedir el permiso)', !$r->contains('ZT-clase-visible') && $r->contains('Sin acceso a los horarios de las salas') && !$C['secretaria']->get('tickets.php')->contains('href="horarios.php"'));

// ── Quién puede escribir
$r = $C['profesor']->post('horarios.php', ['crear_horario' => '1'] + array_merge($base_h, ['detalle' => 'ZT-prof-no', 'hora_inicio' => '17:00', 'hora_fin' => '18:00']));
t('Horarios: profesor no puede crear', $cnt("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-prof-no'") === 0);
$C['beca_trabajo']->post('horarios.php', ['borrar_horario' => '1', 'id_horario' => $idH]);
t('Horarios: beca no puede borrar', $cnt("SELECT COUNT(*) FROM horarios WHERE id_horario=?", [$idH]) === 1);
$C['secretaria']->post('horarios.php', ['crear_horario' => '1'] + array_merge($base_h, ['detalle' => 'ZT-sec-no', 'hora_inicio' => '17:00', 'hora_fin' => '18:00']));
t('Horarios: secretaria sin permiso no puede crear', $cnt("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-sec-no'") === 0);

// ── Permiso de edición para secretaría
$idSec = $U['secretaria']['id'];
$tec->post('horarios.php', ['cambiar_permiso_secretaria' => '1', 'id_usuario' => $idSec, 'otorgar' => 1]);
t('Permiso secretaría: el técnico no puede otorgarlo', $cnt("SELECT permiso_horarios_extra FROM users WHERE id=?", [$idSec]) === 0);
$adm->postRaw('horarios.php', ['cambiar_permiso_secretaria' => '1', 'id_usuario' => $idSec, 'otorgar' => 1]);
t('Permiso secretaría: sin CSRF no cambia', $cnt("SELECT permiso_horarios_extra FROM users WHERE id=?", [$idSec]) === 0);
$r = $adm->post('horarios.php', ['cambiar_permiso_secretaria' => '1', 'id_usuario' => $U['profesor']['id'], 'otorgar' => 1]);
t('Permiso secretaría: no se puede dar a otro rol', $cnt("SELECT permiso_horarios_extra FROM users WHERE id=?", [$U['profesor']['id']]) === 0 && $r->contains('no tiene rol de secretaria'));
$r = $adm->post('horarios.php', ['cambiar_permiso_secretaria' => '1', 'id_usuario' => $idSec, 'otorgar' => 1]);
t('Permiso secretaría: el admin lo otorga', $cnt("SELECT permiso_horarios_extra FROM users WHERE id=?", [$idSec]) === 1 && $r->contains('otorgado'));
t('Permiso secretaría: la otorgación queda auditada', $cnt("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='users' AND id_registro=? AND detalle LIKE 'permiso_horarios_extra=1%'", [$idSec]) >= 1);
// Alcance: con el permiso, sin salas habilitadas, solo ve y edita los turnos de las becas de la secretaría.
$pdo->prepare("INSERT INTO users (codigo, correo, password, password_temporal, role, nombre_completo, cargo, estado_activo, area_beca) VALUES ('ZTSECB1', 'zt_becasec@test.local', ?, 0, 'beca_trabajo', 'ZT beca secretaria', 'Prueba', 1, 'secretaria')")->execute([$hash]);
$idBecaSec = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO horarios (id_sala, tipo, dia_semana, hora_inicio, hora_fin, detalle, id_usuario, id_creador, periodo) VALUES (NULL, 'TURNO_BECA', 2, '13:00', '15:00', 'ZT-turno-becasec', ?, ?, ?)")
    ->execute([$idBecaSec, $U['admin']['id'], HorarioPeriodo::actual()]);
$idTurnoSec = (int)$pdo->lastInsertId();
$rSec = $C['secretaria']->get('horarios.php?semana=' . $martes);
t('Secretaría: con permiso y sin salas ve SOLO los turnos de sus becas (ni clases ni otras becas)', $rSec->contains('ZT beca secretaria') && !$rSec->contains('ZT-clase-visible') && !$rSec->contains('ZT-turno-beca<') && $C['secretaria']->get('tickets.php')->contains('Horarios de salas'));
t('Secretaría: no ve «Vaciar horario del semestre»', !$rSec->contains('data-vf-abrir="acc-vaciar"'));
$C['secretaria']->post('horarios.php', ['crear_horario' => '1'] + array_merge($base_h, ['detalle' => 'ZT-sec-sala-no', 'hora_inicio' => '17:00', 'hora_fin' => '18:00']));
t('Secretaría: no puede crear en una sala que no le habilitaron', $cnt("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-sec-sala-no'") === 0);
$C['secretaria']->post('horarios.php', ['crear_horario' => '1', 'id_sala' => '', 'tipo' => 'TURNO_BECA', 'dia_semana' => 3, 'hora_inicio' => '08:00', 'hora_fin' => '10:00', 'id_usuario' => $idBecaSec, 'detalle' => 'ZT-sec-turno-si', 'periodo' => HorarioPeriodo::actual()]);
t('Secretaría: sí crea el turno de una beca de la secretaría', $cnt("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-sec-turno-si'") === 1);
$C['secretaria']->post('horarios.php', ['crear_horario' => '1', 'id_sala' => '', 'tipo' => 'TURNO_BECA', 'dia_semana' => 3, 'hora_inicio' => '08:00', 'hora_fin' => '10:00', 'id_usuario' => $U['beca_trabajo']['id'], 'detalle' => 'ZT-sec-turno-otra', 'periodo' => HorarioPeriodo::actual()]);
t('Secretaría: no crea turnos de becas de mantenimiento', $cnt("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-sec-turno-otra'") === 0);
$C['secretaria']->post('horarios.php', ['editar_horario' => '1', 'id_horario' => $idTurnoSec, 'id_sala' => '', 'tipo' => 'TURNO_BECA', 'dia_semana' => 2, 'hora_inicio' => '13:00', 'hora_fin' => '16:00', 'id_usuario' => $idBecaSec, 'detalle' => 'ZT-turno-becasec', 'periodo' => HorarioPeriodo::actual()]);
t('Secretaría: edita el turno de su beca', $cnt("SELECT COUNT(*) FROM horarios WHERE id_horario=? AND hora_fin='16:00:00'", [$idTurnoSec]) === 1);
$C['secretaria']->post('horarios.php', ['borrar_horario' => '1', 'id_horario' => $idH]);
t('Secretaría: no borra una clase de una sala que no le habilitaron', $cnt("SELECT COUNT(*) FROM horarios WHERE id_horario=?", [$idH]) === 1);
$C['secretaria']->post('horarios.php', ['vaciar_periodo' => '1', 'periodo_borrar' => HorarioPeriodo::actual(), 'confirmacion' => 'BORRAR']);
t('Secretaría: no puede vaciar el semestre', $cnt("SELECT COUNT(*) FROM horarios WHERE id_horario=?", [$idH]) === 1);
// El admin le habilita una sala: ahora la ve y la edita
$C['secretaria']->post('horarios.php', ['guardar_salas_secretaria' => '1', 'id_usuario' => $idSec, 'salas' => [$idSalaH]]);
t('Secretaría: ella misma no puede habilitarse salas', $cnt("SELECT COUNT(*) FROM secretaria_salas WHERE id_usuario=?", [$idSec]) === 0);
$r = $adm->post('horarios.php', ['guardar_salas_secretaria' => '1', 'id_usuario' => $idSec, 'salas' => [$idSalaH, 99999999]]);
t('Secretaría: el admin le habilita salas (las inexistentes se ignoran)', $cnt("SELECT COUNT(*) FROM secretaria_salas WHERE id_usuario=?", [$idSec]) === 1 && $r->contains('1 sala(s) habilitada(s)'));
$rSec = $C['secretaria']->get('horarios.php?sala=' . $idSalaH . '&semana=' . $martes);
t('Permiso secretaría: con la sala habilitada ve sus horarios', $rSec->contains('ZT-clase-visible') && $rSec->contains('sala-tab'));
$C['secretaria']->post('horarios.php', ['crear_horario' => '1'] + array_merge($base_h, ['detalle' => 'ZT-sec-si', 'hora_inicio' => '17:00', 'hora_fin' => '18:00']));
t('Permiso secretaría: en su sala sí puede crear', $cnt("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-sec-si'") === 1);
$pdo->prepare("DELETE FROM secretaria_salas WHERE id_usuario=?")->execute([$idSec]);
$r = $adm->post('horarios.php', ['cambiar_permiso_secretaria' => '1', 'id_usuario' => $idSec, 'otorgar' => 0]);
t('Permiso secretaría: el admin lo revoca', $cnt("SELECT permiso_horarios_extra FROM users WHERE id=?", [$idSec]) === 0 && $r->contains('revocado'));
$C['secretaria']->post('horarios.php', ['crear_horario' => '1'] + array_merge($base_h, ['detalle' => 'ZT-sec-no2', 'hora_inicio' => '18:00', 'hora_fin' => '19:00']));
t('Permiso secretaría: revocado vuelve a solo lectura', $cnt("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-sec-no2'") === 0);

// ── Ids inexistentes (decisión del paso: mensaje de validación, no 500)
$r = $adm->post('horarios.php', ['crear_horario' => '1'] + array_merge($base_h, ['detalle' => 'ZT-sala-falsa', 'id_sala' => 99999999, 'hora_inicio' => '19:00', 'hora_fin' => '20:00']));
t('Ids: sala inexistente al crear → mensaje (no 500)', $r->status < 500 && $cnt("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-sala-falsa'") === 0 && $r->contains('sala'), 'status ' . $r->status);
$r = $adm->post('horarios.php', ['crear_horario' => '1'] + array_merge($base_h, ['detalle' => 'ZT-usr-falso', 'id_usuario' => 99999999, 'hora_inicio' => '19:00', 'hora_fin' => '20:00']));
t('Ids: usuario inexistente al crear → mensaje (no 500)', $r->status < 500 && $cnt("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-usr-falso'") === 0, 'status ' . $r->status);
$r = $adm->post('horarios.php', ['editar_horario' => '1', 'id_horario' => $idH] + array_merge($base_h, ['id_sala' => 99999999]));
t('Ids: sala inexistente al editar → mensaje (no 500)', $r->status < 500 && $cnt("SELECT id_sala FROM horarios WHERE id_horario=?", [$idH]) === $idSalaH, 'status ' . $r->status);
$r = $adm->post('horarios.php', array_merge($ex, ['tipo_excepcion' => 'CAMBIO_SALA', 'nueva_id_sala' => 99999999]));
t('Ids: sala inexistente en excepción → mensaje (no 500)', $r->status < 500, 'status ' . $r->status);
$r = $adm->post('horarios.php', array_merge($ex, ['tipo_excepcion' => 'CAMBIO_HORA', 'nueva_hora_inicio' => 'abc', 'nueva_hora_fin' => '13:00']));
t('Excepción: hora con formato inválido → mensaje (no 500)', $r->status < 500 && $r->contains('hora de inicio y fin válidas'), 'status ' . $r->status);

// ── Vaciar periodo
$pdo->prepare("INSERT INTO horarios (id_sala, tipo, dia_semana, periodo, hora_inicio, hora_fin, detalle, id_creador) VALUES (?, 'CLASE', 1, '2031-1', '08:00', '09:00', 'ZT-vaciar-1', ?), (?, 'CLASE', 1, '2031-1', '09:00', '10:00', 'ZT-vaciar-2', ?)")
    ->execute([$idSalaH, $U['admin']['id'], $idSalaH, $U['admin']['id']]);
$antes = $cnt("SELECT COUNT(*) FROM horarios WHERE periodo=?", [$periodoHoy]);
$r = $adm->post('horarios.php', ['vaciar_periodo' => '1', 'periodo_borrar' => '2031-1', 'confirmacion' => 'borrar']);
t('Vaciar periodo: sin escribir BORRAR en mayúsculas no borra', $cnt("SELECT COUNT(*) FROM horarios WHERE periodo='2031-1'") === 2 && $r->contains('escribir BORRAR'));
$r = $adm->post('horarios.php', ['vaciar_periodo' => '1', 'periodo_borrar' => '2031-9', 'confirmacion' => 'BORRAR']);
t('Vaciar periodo: periodo inválido se rechaza', $cnt("SELECT COUNT(*) FROM horarios WHERE periodo='2031-1'") === 2);
$tec->postRaw('horarios.php', ['vaciar_periodo' => '1', 'periodo_borrar' => '2031-1', 'confirmacion' => 'BORRAR']);
t('Vaciar periodo: sin CSRF no borra', $cnt("SELECT COUNT(*) FROM horarios WHERE periodo='2031-1'") === 2);
$C['profesor']->post('horarios.php', ['vaciar_periodo' => '1', 'periodo_borrar' => '2031-1', 'confirmacion' => 'BORRAR']);
t('Vaciar periodo: profesor no puede', $cnt("SELECT COUNT(*) FROM horarios WHERE periodo='2031-1'") === 2);
$r = $adm->post('horarios.php', ['vaciar_periodo' => '1', 'periodo_borrar' => '2031-1', 'confirmacion' => 'BORRAR']);
t('Vaciar periodo: borra solo ese periodo y avisa la cantidad', $cnt("SELECT COUNT(*) FROM horarios WHERE periodo='2031-1'") === 0 && $cnt("SELECT COUNT(*) FROM horarios WHERE periodo=?", [$periodoHoy]) === $antes && $r->contains('2 franjas'));
t('Vaciar periodo: queda auditado (antes la acción quedaba fuera del enum y no se registraba)', $cnt("SELECT COUNT(*) FROM auditoria WHERE accion='BORRAR' AND detalle LIKE 'VACIAR_PERIODO periodo=2031-1%'") === 1);
$pdo->prepare("INSERT INTO horarios (id_sala, tipo, dia_semana, periodo, hora_inicio, hora_fin, detalle, id_creador) VALUES (?, 'CLASE', 1, '2032-1', '08:00', '09:00', 'ZT-vaciar-exc', ?)")->execute([$idSalaH, $U['admin']['id']]);
$idVE = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO excepciones_horario (id_horario, fecha, tipo_excepcion, id_creador) VALUES (?, '2032-02-02', 'CANCELADO', ?)")->execute([$idVE, $U['admin']['id']]);
$r = $adm->post('horarios.php', ['vaciar_periodo' => '1', 'periodo_borrar' => '2032-1', 'confirmacion' => 'BORRAR']);
t('Vaciar periodo: también funciona si los horarios tienen excepciones (sin 500)', $r->status < 500 && $cnt("SELECT COUNT(*) FROM horarios WHERE periodo='2032-1'") === 0 && $cnt("SELECT COUNT(*) FROM excepciones_horario WHERE id_horario=?", [$idVE]) === 0, 'status ' . $r->status);

// ── Borrar
$adm->postRaw('horarios.php', ['borrar_horario' => '1', 'id_horario' => $idH]);
t('Horarios: borrar sin CSRF no borra', $cnt("SELECT COUNT(*) FROM horarios WHERE id_horario=?", [$idH]) === 1);
$adm->post('horarios.php', ['guardar_excepcion' => '1', 'id_horario' => $idH, 'fecha' => $martes, 'tipo_excepcion' => 'CANCELADO']);
$r = $adm->post('horarios.php', ['borrar_horario' => '1', 'id_horario' => $idH]);
t('Horarios: borrar un horario que tiene excepciones asociadas (sin 500)', $r->status < 500 && $cnt("SELECT COUNT(*) FROM horarios WHERE id_horario=?", [$idH]) === 0 && $cnt("SELECT COUNT(*) FROM excepciones_horario WHERE id_horario=?", [$idH]) === 0, 'status ' . $r->status);
$r = $adm->post('horarios.php', ['borrar_horario' => '1', 'id_horario' => 99999999]);
t('Horarios: borrar un id inexistente no da 500', $r->status < 500);

// ───────────────────────────────────────────────────────────── Salas
seccion('Salas y Horarios: salas (crear, editar, oficina, personal, unidades)');
$flagOficina = $pdo->query("SELECT id_sala FROM salas WHERE es_oficina_mantenimiento=1")->fetchAll(PDO::FETCH_COLUMN);
$flagAdminSistema = $pdo->query("SELECT id_unidad FROM unidades_organizacionales WHERE es_administradora_sistema=1")->fetchAll(PDO::FETCH_COLUMN);
$idUnidadExistente = (int)$pdo->query("SELECT id_unidad FROM unidades_organizacionales ORDER BY id_unidad LIMIT 1")->fetchColumn();

$r = $tec->get('gestionar_salas.php');
t('Salas: la lista muestra salas con su token QR', $r->contains('ZT-SALA') && $r->contains($tokenSala));
t('Salas: la página incluye el enlace público de reporte', $r->contains('reportar.php?token='));
$r = $tec->post('gestionar_salas.php', ['crear_sala' => '1', 'nombre_sala' => 'ZT-SALA-NUEVA', 'abreviatura' => 'ZTN', 'id_unidad' => $idUnidadExistente]);
$sn = $fila("SELECT * FROM salas WHERE nombre_sala='ZT-SALA-NUEVA'");
t('Salas: crear sala', $sn && $r->contains('creada correctamente'));
t('Salas: token QR aleatorio de 32 hex y único', $sn && preg_match('/^[0-9a-f]{32}$/', $sn['codigo_qr_token']) === 1);
t('Salas: la dependencia se deriva de la unidad elegida y se enlaza en salas_unidades', $sn && $sn['dependencia'] !== null && $cnt("SELECT COUNT(*) FROM salas_unidades WHERE id_sala=? AND id_unidad=?", [$sn['id_sala'], $idUnidadExistente]) === 1);
$r = $tec->post('gestionar_salas.php', ['crear_sala' => '1', 'nombre_sala' => '   ', 'abreviatura' => 'X']);
t('Salas: nombre vacío se rechaza', $r->contains('obligatorio'));
$r = $tec->post('gestionar_salas.php', ['crear_sala' => '1', 'nombre_sala' => 'ZT-SALA-NUEVA', 'abreviatura' => 'DUP']);
t('Salas: nombre duplicado → mensaje (sin 500)', $r->status < 500 && $cnt("SELECT COUNT(*) FROM salas WHERE nombre_sala='ZT-SALA-NUEVA'") === 1);
$r = $tec->post('gestionar_salas.php', ['crear_sala' => '1', 'nombre_sala' => 'ZT-SALA-UNIDAD-FALSA', 'abreviatura' => 'X', 'id_unidad' => 99999999]);
t('Salas: unidad inexistente no rompe la creación (sin 500)', $r->status < 500);
$idSN = (int)$sn['id_sala'];
$tec->post('gestionar_salas.php', ['editar_abreviatura' => '1', 'id_sala' => $idSN, 'abreviatura' => 'ZTX', 'id_unidad' => '']);
t('Salas: editar abreviatura y quitar la unidad (sincroniza salas_unidades)', $cnt("SELECT COUNT(*) FROM salas WHERE id_sala=? AND abreviatura='ZTX' AND dependencia IS NULL", [$idSN]) === 1 && $cnt("SELECT COUNT(*) FROM salas_unidades WHERE id_sala=?", [$idSN]) === 0);
$tec->post('gestionar_salas.php', ['editar_abreviatura' => '1', 'id_sala' => $idSN, 'abreviatura' => 'ZTY', 'id_unidad' => $idUnidadExistente]);
t('Salas: editar y volver a asignar la unidad', $cnt("SELECT COUNT(*) FROM salas_unidades WHERE id_sala=? AND id_unidad=?", [$idSN, $idUnidadExistente]) === 1);
$tec->post('gestionar_salas.php', ['editar_abreviatura' => '1', 'id_sala' => 0, 'abreviatura' => 'ZTZ']);
t('Salas: editar sin id válido no cambia nada', $cnt("SELECT COUNT(*) FROM salas WHERE abreviatura='ZTZ'") === 0);

// Oficina de mantenimiento (única)
$tec->post('gestionar_salas.php', ['marcar_oficina_mantenimiento' => '1', 'id_sala' => $idSN]);
t('Oficina: marcar una sala la deja como única', $cnt("SELECT COUNT(*) FROM salas WHERE es_oficina_mantenimiento=1") === 1 && $cnt("SELECT es_oficina_mantenimiento FROM salas WHERE id_sala=?", [$idSN]) === 1);
$tec->post('gestionar_salas.php', ['marcar_oficina_mantenimiento' => '1', 'id_sala' => $idSala]);
t('Oficina: marcar otra desmarca la anterior', $cnt("SELECT COUNT(*) FROM salas WHERE es_oficina_mantenimiento=1") === 1 && $cnt("SELECT es_oficina_mantenimiento FROM salas WHERE id_sala=?", [$idSala]) === 1);
$C['solicitante']->post('gestionar_salas.php', ['quitar_oficina_mantenimiento' => '1']);
t('Oficina: un solicitante no puede cambiarla', $cnt("SELECT COUNT(*) FROM salas WHERE es_oficina_mantenimiento=1") === 1);
$tec->postRaw('gestionar_salas.php', ['quitar_oficina_mantenimiento' => '1']);
t('Oficina: sin CSRF no cambia', $cnt("SELECT COUNT(*) FROM salas WHERE es_oficina_mantenimiento=1") === 1);

// Personal de la oficina
$tec->post('gestionar_salas.php', ['asignar_staff_oficina' => '1', 'id_sala' => $idSala, 'staff_user_id' => $U['tecnico']['id']]);
$tec->post('gestionar_salas.php', ['asignar_staff_oficina' => '1', 'id_sala' => $idSala, 'staff_user_id' => $U['beca_trabajo']['id']]);
t('Personal: técnico y beca se asignan con el rol derivado del usuario', $cnt("SELECT COUNT(*) FROM oficina_staff WHERE id_sala=? AND user_id=? AND rol='tecnico'", [$idSala, $U['tecnico']['id']]) === 1
    && $cnt("SELECT COUNT(*) FROM oficina_staff WHERE id_sala=? AND user_id=? AND rol='beca'", [$idSala, $U['beca_trabajo']['id']]) === 1);
$tec->post('gestionar_salas.php', ['asignar_staff_oficina' => '1', 'id_sala' => $idSala, 'staff_user_id' => $U['tecnico']['id']]);
t('Personal: asignar dos veces no duplica', $cnt("SELECT COUNT(*) FROM oficina_staff WHERE id_sala=? AND user_id=?", [$idSala, $U['tecnico']['id']]) === 1);
$r = $tec->post('gestionar_salas.php', ['asignar_staff_oficina' => '1', 'id_sala' => $idSala, 'staff_user_id' => $U['profesor']['id']]);
t('Personal: un profesor no se puede asignar', $cnt("SELECT COUNT(*) FROM oficina_staff WHERE user_id=?", [$U['profesor']['id']]) === 0 && $r->contains('ni beca de trabajo'));
$r = $tec->get('gestionar_salas.php');
t('Personal: la página lista al personal asignado', $r->contains('ZT beca_trabajo'));
$tec->post('gestionar_salas.php', ['quitar_staff_oficina' => '1', 'id_sala' => $idSala, 'staff_user_id' => $U['tecnico']['id']]);
t('Personal: quitar a una persona', $cnt("SELECT COUNT(*) FROM oficina_staff WHERE id_sala=? AND user_id=?", [$idSala, $U['tecnico']['id']]) === 0 && $cnt("SELECT COUNT(*) FROM oficina_staff WHERE id_sala=?", [$idSala]) === 1);
$tec->post('gestionar_salas.php', ['asignar_staff_oficina' => '1', 'id_sala' => 99999999, 'staff_user_id' => $U['tecnico']['id']]);
$r = $tec->post('gestionar_salas.php', ['asignar_staff_oficina' => '1', 'id_sala' => 99999999, 'staff_user_id' => $U['tecnico']['id']]);
t('Personal: sala inexistente → sin 500', $r->status < 500);
t('Personal: sala inexistente → mensaje de validación y sin fila', $r->contains('no existe') && $cnt("SELECT COUNT(*) FROM oficina_staff WHERE id_sala=99999999") === 0);
$antes = $cnt("SELECT COUNT(*) FROM salas WHERE es_oficina_mantenimiento=1");
$r = $tec->post('gestionar_salas.php', ['marcar_oficina_mantenimiento' => '1', 'id_sala' => 99999999]);
t('Oficina: sala inexistente → mensaje y no se pierde la oficina actual', $r->contains('no existe') && $cnt("SELECT COUNT(*) FROM salas WHERE es_oficina_mantenimiento=1") === $antes);

// Unidades organizacionales
$r = $tec->post('gestionar_salas.php', ['crear_unidad' => '1', 'nombre_unidad' => 'ZT-UNIDAD-TEC', 'tipo_unidad' => 'OFICINA']);
t('Unidades: el técnico no puede crear', $cnt("SELECT COUNT(*) FROM unidades_organizacionales WHERE nombre='ZT-UNIDAD-TEC'") === 0 && $r->contains('Solo un administrador'));
$r = $adm->post('gestionar_salas.php', ['crear_unidad' => '1', 'nombre_unidad' => 'ZT-UNIDAD', 'tipo_unidad' => 'OFICINA']);
t('Unidades: el admin crea una unidad', $cnt("SELECT COUNT(*) FROM unidades_organizacionales WHERE nombre='ZT-UNIDAD' AND es_administradora_sistema=0") === 1 && $r->contains('creada'));
$r = $adm->post('gestionar_salas.php', ['crear_unidad' => '1', 'nombre_unidad' => 'ZT-UNIDAD', 'tipo_unidad' => 'OFICINA']);
t('Unidades: nombre duplicado bajo el mismo padre → mensaje (sin 500)', $r->status < 500);
$r = $adm->post('gestionar_salas.php', ['crear_unidad' => '1', 'nombre_unidad' => 'ZT-UNIDAD-X', 'tipo_unidad' => 'NADA']);
t('Unidades: tipo inválido se rechaza', $cnt("SELECT COUNT(*) FROM unidades_organizacionales WHERE nombre='ZT-UNIDAD-X'") === 0 && $r->contains('obligatorios'));
$adm->post('gestionar_salas.php', ['crear_unidad' => '1', 'nombre_unidad' => 'ZT-UNIDAD-ADMIN', 'tipo_unidad' => 'DEPARTAMENTO', 'es_administradora_sistema' => '1']);
t('Unidades: solo una unidad administradora del sistema', $cnt("SELECT COUNT(*) FROM unidades_organizacionales WHERE es_administradora_sistema=1") === 1 && $cnt("SELECT es_administradora_sistema FROM unidades_organizacionales WHERE nombre='ZT-UNIDAD-ADMIN'") === 1);
// restaurar las banderas globales que la prueba movió
$pdo->exec("UPDATE unidades_organizacionales SET es_administradora_sistema=0");
if ($flagAdminSistema) $pdo->exec("UPDATE unidades_organizacionales SET es_administradora_sistema=1 WHERE id_unidad IN (" . implode(',', array_map('intval', $flagAdminSistema)) . ")");
$pdo->exec("UPDATE salas SET es_oficina_mantenimiento=0");
if ($flagOficina) $pdo->exec("UPDATE salas SET es_oficina_mantenimiento=1 WHERE id_sala IN (" . implode(',', array_map('intval', $flagOficina)) . ")");

// Indicador «ocupada ahora» (no depende de la hora: solo que la página cargue con una clase en curso)
$diaHoy = (int)date('N');
$pdo->prepare("INSERT INTO horarios (id_sala, tipo, dia_semana, periodo, hora_inicio, hora_fin, detalle, id_creador) VALUES (?, 'CLASE', ?, ?, '00:00:00', '23:59:59', 'ZT-ahora', ?)")->execute([$idSala, $diaHoy, $periodoHoy, $U['admin']['id']]);
$r = $tec->get('gestionar_salas.php');
t('Salas: con una clase en curso la página sigue cargando', $r->status === 200 && !preg_match('/SQLSTATE|Warning:|Notice:/', $r->body));
// Acceso
foreach (['solicitante', 'profesor', 'beca_trabajo', 'secretaria'] as $rol) {
    $C[$rol]->post('gestionar_salas.php', ['crear_sala' => '1', 'nombre_sala' => "ZT-SALA-$rol", 'abreviatura' => 'X']);
    t("Salas: $rol no puede crear salas", $cnt("SELECT COUNT(*) FROM salas WHERE nombre_sala=?", ["ZT-SALA-$rol"]) === 0);
}
$anon->postRaw('gestionar_salas.php', ['crear_sala' => '1', 'nombre_sala' => 'ZT-SALA-anon', 'abreviatura' => 'X']);
t('Salas: anónimo no puede crear salas', $cnt("SELECT COUNT(*) FROM salas WHERE nombre_sala='ZT-SALA-anon'") === 0);
$tec->postRaw('gestionar_salas.php', ['crear_sala' => '1', 'nombre_sala' => 'ZT-SALA-sincsrf', 'abreviatura' => 'X']);
t('Salas: sin CSRF no crea', $cnt("SELECT COUNT(*) FROM salas WHERE nombre_sala='ZT-SALA-sincsrf'") === 0);
// XSS en nombres
$tec->post('gestionar_salas.php', ['crear_sala' => '1', 'nombre_sala' => 'ZT-<img src=x onerror=alert(1)>', 'abreviatura' => '"><script>alert(1)</script>']);
$r = $tec->get('gestionar_salas.php');
t('Salas: XSS en nombre y abreviatura está escapado', !preg_match('/<img src=x onerror|<script>alert\(1\)<\/script>/i', $r->body));
$r = $adm->get('horarios.php');
t('Horarios: XSS en nombre de sala está escapado', !preg_match('/<img src=x onerror|<script>alert\(1\)<\/script>/i', $r->body));

// ── Distribución del salón (filas, mesas, número de puesto y punto de red)
seccion('Distribución del salón');
$r = $tec->get('distribucion_sala.php?id=' . $idSala);
t('Distribución: el técnico abre el editor con la propuesta inicial', $r->status === 200 && $r->contains('id="dist-datos"') && $r->contains('aún no tiene distribución guardada'));
t('Distribución: el admin también puede maquetar', $adm->get('distribucion_sala.php?id=' . $idSala)->status === 200);
t('Distribución: beca y profesor no tienen acceso', $C['beca_trabajo']->get('distribucion_sala.php?id=' . $idSala)->denied() && $C['profesor']->get('distribucion_sala.php?id=' . $idSala)->denied());
t('Distribución: sala inexistente → 404 sin error', $tec->get('distribucion_sala.php?id=999999999')->status === 404);
$estH = ['docente' => ['ubicacion' => 'atras', 'lado' => 'der'], 'filas' => [['mesas' => [
    ['pasillo' => true, 'puestos' => [['id_equipo' => $idEquipo, 'numero' => '7', 'red' => 'ZT-RED-07'], ['numero' => '8']]],
    ['puestos' => [['numero' => '9'], ['numero' => '10'], ['numero' => '11']]],
]]]];
$tec->postRaw('distribucion_sala.php?id=' . $idSala, ['guardar_distribucion' => '1', 'id_sala' => $idSala, 'estructura' => json_encode($estH)]);
t('Distribución: sin token CSRF no se guarda', $cnt("SELECT COUNT(*) FROM sala_distribucion WHERE id_sala=?", [$idSala]) === 0);
$r = $tec->post('distribucion_sala.php?id=' . $idSala, ['guardar_distribucion' => '1', 'id_sala' => $idSala, 'estructura' => json_encode($estH)]);
t('Distribución: se guarda (PRG) y queda en auditoría', $r->redirects() && $cnt("SELECT COUNT(*) FROM sala_distribucion WHERE id_sala=?", [$idSala]) === 1
    && $cnt("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='salas' AND id_registro=? AND detalle LIKE 'Distribución del salón:%'", [$idSala]) >= 1);
t('Distribución: tras guardar se ve el mensaje de éxito', $tec->get('distribucion_sala.php?id=' . $idSala)->contains('Distribución guardada'));
$estMal = $estH; $estMal['filas'][0]['mesas'][1]['puestos'][0]['id_equipo'] = $idEquipo;
$r = $tec->post('distribucion_sala.php?id=' . $idSala, ['guardar_distribucion' => '1', 'id_sala' => $idSala, 'estructura' => json_encode($estMal)]);
t('Distribución: un equipo en dos puestos se rechaza con explicación y no pisa lo guardado', $r->status === 200 && $r->contains('está en dos puestos')
    && strpos((string)$pdo->query("SELECT estructura FROM sala_distribucion WHERE id_sala=$idSala")->fetchColumn(), 'ZT-RED-07') !== false);
$r = $tec->get('ficha_equipo.php?q=ZT-SALA&todas=1');
t('Distribución: la ficha del salón dibuja las mesas con el número y el punto de red del puesto', $r->contains('class="sala-mesa') && $r->contains('ZT-RED-07') && $r->contains('sala-puesto-num') && $r->contains('Fondo del salón'));
$j = $C['profesor']->get('listar_equipos_ajax.php?plano=1&distribucion=1&id_sala=' . $idSala)->json();
t('Distribución: el plano del profesor recibe {equipos, distribucion}', is_array($j) && isset($j['equipos'], $j['distribucion']['filas']) && $j['distribucion']['docente']['ubicacion'] === 'atras');
t('Distribución: sin ?distribucion=1 la respuesta sigue siendo la lista de siempre', array_is_list((array)$C['profesor']->get('listar_equipos_ajax.php?id_sala=' . $idSala)->json()));
$tec->post('distribucion_sala.php?id=' . $idSala, ['restablecer_distribucion' => '1', 'id_sala' => $idSala]);
t('Distribución: restablecer vuelve al reparto automático', $cnt("SELECT COUNT(*) FROM sala_distribucion WHERE id_sala=?", [$idSala]) === 0);
