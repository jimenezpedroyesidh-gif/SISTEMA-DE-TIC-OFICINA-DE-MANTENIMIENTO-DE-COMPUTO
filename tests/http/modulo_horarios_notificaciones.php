<?php
/**
 * Pruebas HTTP de los horarios por rol (vista condensada de profesor y becas, salas para técnico/admin) y de las
 * notificaciones (abrir un aviso lleva a la página correcta del rol, marca leído, no abre avisos ajenos).
 * Lo incluye run_http_tests.php (usa $pdo, $base, $U, $C, $CLAVE, $hash, t(), seccion(), cliente()).
 */
require_once __DIR__ . '/../../app/Domain/Horarios/HorarioPeriodo.php';
seccion('Horarios por rol (vista condensada)');
date_default_timezone_set('America/Bogota');
$periodoN = HorarioPeriodo::actual();
$lunesN = (new DateTime())->modify('monday this week')->format('Y-m-d');
$tokN = 'zth' . bin2hex(random_bytes(6));
$pdo->prepare("INSERT INTO salas (nombre_sala, abreviatura, codigo_qr_token) VALUES ('ZT-SALA-HN', 'ZTHN', ?)")->execute([$tokN]);
$salaN = (int)$pdo->lastInsertId();
$insH = $pdo->prepare("INSERT INTO horarios (id_sala, tipo, dia_semana, hora_inicio, hora_fin, detalle, periodo, id_usuario, id_creador) VALUES (?,?,?,?,?,?,?,?,?)");
$insH->execute([$salaN, 'CLASE', 2, '08:00', '10:00', 'ZT-HN Cálculo diferencial', $periodoN, $U['profesor']['id'], $U['admin']['id']]);
$insH->execute([$salaN, 'CLASE', 2, '10:00', '12:00', 'ZT-HN clase de otro profesor', $periodoN, null, $U['admin']['id']]);
$insH->execute([null, 'TURNO_BECA', 3, '07:00', '11:00', 'ZT-HN turno mantenimiento', $periodoN, $U['beca_trabajo']['id'], $U['admin']['id']]);
$insU = $pdo->prepare("INSERT INTO users (codigo, correo, password, password_temporal, role, nombre_completo, cargo, estado_activo, area_beca) VALUES (?,?,?,0,'beca_trabajo',?,'beca_trabajo',1,?)");
$insU->execute(['ZTHN1', 'zt_hn_redes@test.local', $hash, 'ZT beca redes', 'redes']);
$uRedes = ['id' => (int)$pdo->lastInsertId(), 'codigo' => 'ZTHN1', 'correo' => 'zt_hn_redes@test.local'];
$insH->execute([null, 'TURNO_BECA', 4, '14:00', '18:00', 'ZT-HN turno redes', $periodoN, $uRedes['id'], $U['admin']['id']]);
$pdo->prepare("INSERT INTO mantenimientos_programados (id_sala, tipo, descripcion, fecha_programada, id_creador) VALUES (?, 'MANTENIMIENTO_PREVENTIVO', 'ZT-HN limpieza de equipos', ?, ?)")
    ->execute([$salaN, $lunesN . ' 15:00:00', $U['admin']['id']]);

$r = $C['profesor']->get('horarios.php?semana=' . $lunesN);
t('profesor: «Mi horario de clases» con su clase en una sola semana (sin pestañas por sala)', $r->contains('Mi horario de clases') && $r->contains('ZT-HN Cálculo diferencial') && !$r->contains('sala-tab'));
t('profesor: no ve las clases de otros ni bloques «Ocupado»', !$r->contains('ZT-HN clase de otro profesor') && !$r->contains('Ocupado'));
t('profesor: al tocar la clase ve materia, sala, día, horario y su nombre', $r->contains('data-vf-abrir="hg-det-') && $r->contains('>Materia<') && $r->contains('ZT-SALA-HN') && $r->contains('08:00 a 10:00') && $r->contains('ZT profesor'));
t('profesor: ve el mantenimiento programado en su sala', $r->contains('ZT-HN limpieza de equipos'));
t('profesor: en el celular la semana va día por día', $r->contains('class="hg-agenda"') && $r->contains('hg-agenda-item'));
$r = $C['beca_trabajo']->get('horarios.php?semana=' . $lunesN);
t('beca de mantenimiento: «Mi semana» con su turno y los mantenimientos de todas las salas', $r->contains('Mi semana') && $r->contains('ZT-HN limpieza de equipos') && $r->contains('Mi turno') && !$r->contains('sala-tab'));
t('beca de mantenimiento: no ve clases ni turnos de otros', !$r->contains('ZT-HN Cálculo diferencial') && !$r->contains('ZT-HN turno redes'));
$cR = cliente($uRedes, $CLAVE);
$r = $cR->get('horarios.php?semana=' . $lunesN);
t('beca de redes: solo su propio turno, sin mantenimientos', $r->contains('Mi turno') && $r->contains('14:00') && !$r->contains('ZT-HN limpieza de equipos') && !$r->contains('ZT-HN turno mantenimiento'));
$r = $C['tecnico']->get('horarios.php?sala=' . $salaN . '&semana=' . $lunesN);
t('técnico: todas las salas por pestaña, con clases, turnos y mantenimientos', $r->contains('sala-tab') && $r->contains('ZT-HN clase de otro profesor') && $r->contains('ZT-HN limpieza de equipos'));
t('técnico: el mantenimiento se toca para ver su detalle', preg_match('/bloque-mantenimiento[^"]*"[^>]*data-vf-abrir="hg-det-/', $r->body) === 1 || $r->contains('Mantenimiento de sala'));

seccion('Notificaciones por rol');
$insN = $pdo->prepare("INSERT INTO notificaciones (id_usuario, tipo, mensaje, url_destino) VALUES (?, ?, ?, ?)");
$insN->execute([$U['admin']['id'], 'MANTENIMIENTO', 'ZT-NOT aviso con página antigua', 'panel_becario.php']);
$nViejo = (int)$pdo->lastInsertId();
$insN->execute([$U['admin']['id'], 'SISTEMA', 'ZT-NOT aviso sin destino', null]);
$nNulo = (int)$pdo->lastInsertId();
$insN->execute([$U['tecnico']['id'], 'SISTEMA', 'ZT-NOT aviso del técnico a página del admin', 'sla.php']);
$nTec = (int)$pdo->lastInsertId();
$r = $C['admin']->get('tickets.php');
t('campanita: cada aviso abre mis_notificaciones.php?ir= y hay «Ver todas»', $r->contains('mis_notificaciones.php?ir=' . $nViejo) && $r->contains('Ver todas las notificaciones') && !$r->contains('href="panel_becario.php"'));
$r = $C['admin']->get('mis_notificaciones.php?ir=' . $nViejo);
t('abrir aviso: una página antigua lleva a la página actual (sin error) y queda leído', $r->redirects() && $r->location() === 'mantenimientos_programados.php' && $C['admin']->get($r->location())->status === 200
    && (int)$pdo->query("SELECT leido FROM notificaciones WHERE id_notificacion=$nViejo")->fetchColumn() === 1);
$r = $C['admin']->get('mis_notificaciones.php?ir=' . $nNulo);
t('abrir aviso: sin destino lleva al inicio del rol', $r->redirects() && $r->location() === 'tickets.php');
$r = $C['tecnico']->get('mis_notificaciones.php?ir=' . $nTec);
t('abrir aviso: si la página no es del rol, va a su inicio (nunca a un error)', $r->redirects() && $r->location() === 'tickets.php');
$r = $C['admin']->get('mis_notificaciones.php?ir=' . $nTec);
t('abrir aviso: nadie abre ni marca avisos ajenos', $r->redirects() && $r->location() === 'mis_notificaciones.php' && (int)$pdo->query("SELECT leido FROM notificaciones WHERE id_notificacion=$nTec")->fetchColumn() === 1);
$r = $C['admin']->get('mis_notificaciones.php');
t('página de notificaciones: lista los avisos con tipo y cuándo', $r->status === 200 && $r->contains('ZT-NOT aviso con página antigua') && $r->contains('Mantenimiento') && $r->contains('hace'));
$insN->execute([$U['admin']['id'], 'SISTEMA', 'ZT-NOT pendiente', 'tickets.php']);
$C['admin']->post('mis_notificaciones.php', ['marcar_todas' => '1']);
t('página de notificaciones: «Marcar todas como leídas»', (int)$pdo->query("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=" . (int)$U['admin']['id'] . " AND leido=0")->fetchColumn() === 0);
$insU->execute(['ZTHN2', 'zt_hn_secre@test.local', $hash, 'ZT beca secre', 'secretaria']);
$rS = cliente(['codigo' => 'ZTHN2', 'correo' => 'zt_hn_secre@test.local'], $CLAVE)->get('mis_notificaciones.php');
t('página de notificaciones: también la abre la beca de secretaría (no la manda al tablero)', $rS->status === 200 && $rS->contains('Notificaciones'));

$pdo->exec("DELETE FROM horarios WHERE detalle LIKE 'ZT-HN%'");
$pdo->exec("DELETE FROM mantenimiento_equipos WHERE id_programado IN (SELECT id_programado FROM mantenimientos_programados WHERE descripcion LIKE 'ZT-HN%')");
$pdo->exec("DELETE FROM mantenimientos_programados WHERE descripcion LIKE 'ZT-HN%'");
$pdo->exec("DELETE FROM salas WHERE id_sala=$salaN");
