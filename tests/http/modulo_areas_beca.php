<?php
/**
 * Pruebas HTTP de las áreas de la beca trabajo (las asigna el admin), del panel de la beca de mantenimiento
 * (una sola tabla de reportes de daño + submenú) y del tablero de actividades de la secretaría.
 * Se incluye desde run_http_tests.php (aporta $pdo, $U, $C, $adm, $tec, $hash, $CLAVE, cliente(), t(), seccion()).
 */
seccion('Áreas de beca, panel de mantenimiento y tablero de actividades');
$cntA = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); })();

// Beca nueva para secretaría
$pdo->prepare("INSERT INTO users (codigo, correo, password, password_temporal, role, nombre_completo, cargo, estado_activo) VALUES ('ZT20', 'zt_becasec@ufps.edu.co', ?, 0, 'beca_trabajo', 'ZT beca secretaria', 'beca_trabajo', 1)")->execute([$hash]);
$U['becasec'] = ['id' => (int)$pdo->lastInsertId(), 'correo' => 'zt_becasec@ufps.edu.co', 'codigo' => 'ZT20'];
$idBS = $U['becasec']['id'];

// El admin asigna el área en Usuarios
$r = $adm->get('register.php?id=' . $idBS);
t('Usuarios: el formulario de la beca tiene «Área de la beca trabajo» con Redes deshabilitada', $r->contains('name="area_beca"') && (bool)preg_match('/<option value="redes"[^>]*disabled/', $r->body));
$datosBS = ['id' => $idBS, 'codigo' => 'ZT20', 'correo' => 'zt_becasec@ufps.edu.co', 'nombre_completo' => 'ZT beca secretaria', 'cargo' => 'beca_trabajo', 'role' => 'beca_trabajo', 'estado_activo' => '1'];
$adm->post('register.php?id=' . $idBS, $datosBS + ['area_beca' => 'redes']);
t('Usuarios: el área Redes todavía no se puede asignar', $cntA("SELECT COUNT(*) FROM users WHERE id=? AND area_beca IS NULL", [$idBS]) === 1);
$adm->post('register.php?id=' . $idBS, $datosBS + ['area_beca' => 'secretaria']);
t('Usuarios: el admin asigna la beca a Secretaría (y queda en auditoría)', $cntA("SELECT COUNT(*) FROM users WHERE id=? AND area_beca='secretaria'", [$idBS]) === 1
    && $cntA("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='users' AND id_registro=? AND detalle LIKE '%área sin asignar -> secretaria%'", [$idBS]) === 1);
t('Usuarios: el listado muestra el área de cada beca', $adm->get('usuarios.php')->contains('Secretaría'));
$C['secretaria']->post('register.php?id=' . $idBS, $datosBS + ['area_beca' => 'mantenimiento']);
t('Usuarios: nadie más que el admin cambia el área', $cntA("SELECT COUNT(*) FROM users WHERE id=? AND area_beca='secretaria'", [$idBS]) === 1);

// La beca de secretaría no ve tickets: solo su tablero, su turno y sus horas
$bs = cliente($U['becasec'], $CLAVE);
$r = $bs->get('tickets.php');
t('Beca de secretaría: «Mis tickets» la lleva a su tablero de actividades', $r->redirects() && strpos($r->location(), 'actividades.php') !== false);
foreach (['create_ticket_beca.php', 'mantenimientos_programados.php', 'escanear_qr.php', 'ficha_equipo.php'] as $pagBS) {
    t("Beca de secretaría: $pagBS no está disponible", strpos($bs->get($pagBS)->location(), 'actividades.php') !== false);
}
t('Beca de secretaría: los endpoints de tickets responden 403', $bs->get('listar_equipos_ajax.php?id_sala=1')->status === 403 && $bs->get('buscar_global.php?q=zt')->status === 403);
$r = $bs->get('actividades.php');
t('Beca de secretaría: ve el tablero, su turno y sus horas (sin enlaces a tickets)', $r->status === 200 && $r->contains('class="tablero"') && $r->contains('href="horarios.php"')
    && $r->contains('href="seguimiento_horas.php"') && !$r->contains('href="tickets.php?vista=bodega"') && !$r->contains('buscadorGlobalInput'));
t('Beca de secretaría: Mi turno y Mis horas abren', $bs->get('horarios.php')->status === 200 && $bs->get('seguimiento_horas.php')->status === 200);

// Avisos y asignaciones de tickets: solo becas de mantenimiento
$antesNotif = $cntA("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=?", [$idBS]);
$antesNotifBm = $cntA("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND mensaje LIKE 'Nuevo daño reportado en ZT-SALA-AREA%'", [$U['beca_trabajo']['id']]);
$tokArea = 'zta' . bin2hex(random_bytes(8));
$pdo->prepare("INSERT INTO salas (nombre_sala, abreviatura, dependencia, codigo_qr_token) VALUES ('ZT-SALA-AREA', 'ZTA', 'Departamento de Sistemas', ?)")->execute([$tokArea]);
$anonQr = new HttpClient($base);
$anonQr->reportarQr($tokArea, ['descripcion' => 'ZT-area-qr-reporte de prueba']);
t('Avisos: un reporte por QR avisa a las becas de mantenimiento, no a las de secretaría', $cntA("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=?", [$idBS]) === $antesNotif
    && $cntA("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND mensaje LIKE 'Nuevo daño reportado en ZT-SALA-AREA%'", [$U['beca_trabajo']['id']]) === $antesNotifBm + 1);
preg_match_all('~<select name="beca_id"[^>]*>(.*?)</select>~s', $tec->get('tickets.php')->body, $selsBeca);
t('Asignar ticket: la beca de secretaría no aparece entre las becas asignables', $selsBeca[1] !== [] && strpos(implode('', $selsBeca[1]), 'ZT beca secretaria') === false && strpos(implode('', $selsBeca[1]), 'ZT beca_trabajo') !== false);

// Panel de la beca de mantenimiento: una sola tabla de reportes de daño + submenú
$pdo->prepare("INSERT INTO tickets_profesor (profesor_id, tipo_ticket, origen, descripcion_problema, sala_danio, id_sala, estado) VALUES (?, 'danio', 'PANEL_PROFESOR', 'ZT-area-danio-prof', 'ZT-SALA', ?, 'En espera')")->execute([$U['profesor']['id'], $idSala]);
$idDanioP = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO tickets_profesor (profesor_id, tipo_ticket, origen, descripcion_problema, descripcion_actividad, id_sala, estado) VALUES (?, 'requerimiento', 'PANEL_PROFESOR', 'ZT-area-req', 'ZT-area-req-actividad', ?, 'En espera')")->execute([$U['profesor']['id'], $idSala]);
$idReqP = (int)$pdo->lastInsertId();
$bm = $C['beca_trabajo'];
$r = $bm->get('tickets.php');
t('Beca de mantenimiento: al entrar ve UNA tabla con los reportes de daño (profesor y QR sin sesión)', $r->status === 200 && $r->contains('id="reportes-danio"') && $r->contains('ZT-area-danio-prof')
    && $r->contains('ZT-area-qr-reporte') && !$r->contains('id="tickets-profesor"') && !$r->contains('id="tickets-qr"'));
t('Beca de mantenimiento: los requerimientos de profesores no aparecen (son del técnico)', !$r->contains('ZT-area-req-actividad'));
t('Beca de mantenimiento: bodega, tickets creados y checklist quedan en el submenú de Mis tickets', $r->contains('href="tickets.php?vista=bodega"') && $r->contains('href="tickets.php?vista=creados"')
    && $r->contains('href="tickets.php?vista=checklist"') && !$r->contains('id="bodega"') && !$r->contains('id="mis-tickets-beca"'));
t('Beca de mantenimiento: cada vista del submenú abre su sección', $bm->get('tickets.php?vista=bodega')->contains('id="bodega"') && $bm->get('tickets.php?vista=creados')->contains('id="mis-tickets-beca"'));
$r = $bm->post('tickets.php', ['tomar_profesor' => '1', 'ticket_id' => $idReqP]);
t('Beca de mantenimiento: no puede tomar un requerimiento (403)', $r->status === 403 && $cntA("SELECT COUNT(*) FROM tickets_profesor WHERE id=? AND tecnico_id IS NULL", [$idReqP]) === 1);
$bm->post('tickets.php', ['tomar_profesor' => '1', 'ticket_id' => $idDanioP]);
t('Beca de mantenimiento: toma un error de sala desde la tabla única', $cntA("SELECT COUNT(*) FROM tickets_profesor WHERE id=? AND tecnico_id=?", [$idDanioP, $U['beca_trabajo']['id']]) === 1);

// Tablero de actividades
t('Tablero: una beca de mantenimiento, el técnico y el profesor no tienen acceso', $bm->get('actividades.php')->status === 403 && $tec->get('actividades.php')->status === 403 && $C['profesor']->get('actividades.php')->status === 403);
$sec = $C['secretaria'];
t('Tablero: la secretaria lo ve en su menú y puede crear', $sec->get('tickets.php')->contains('href="actividades.php"') && $sec->get('actividades.php')->contains('data-vf-abrir="vf-nueva-actividad"'));
$sec->post('actividades.php', ['crear_actividad' => '1', 'titulo' => 'ZT-act-1 Organizar soportes', 'detalle' => 'Archivo del semestre', 'color' => 'azul', 'id_asignado' => $idBS, 'fecha_limite' => date('Y-m-d', strtotime('+3 days'))]);
$idAct = $cntA("SELECT id_actividad FROM actividades_tablero WHERE titulo='ZT-act-1 Organizar soportes'");
t('Tablero: la secretaria crea una pegatina asignada y la beca recibe el aviso', $idAct > 0 && $cntA("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND mensaje LIKE 'Nueva actividad para ti: ZT-act-1%'", [$idBS]) === 1);
$sec->post('actividades.php', ['crear_actividad' => '1', 'titulo' => 'ZT-act-mal', 'id_asignado' => $U['beca_trabajo']['id']]);
t('Tablero: solo se asigna a becas de secretaría', $cntA("SELECT COUNT(*) FROM actividades_tablero WHERE titulo='ZT-act-mal'") === 0);
$r = $bs->get('actividades.php');
t('Tablero: la beca ve su pegatina (color, fecha y botón para moverla), sin opciones de editar', $r->contains('id="act-' . $idAct . '"') && $r->contains('peg-azul') && $r->contains('Vence') && $r->contains('name="mover_actividad"') && !$r->contains('name="borrar_actividad"'));
$bs->post('actividades.php', ['crear_actividad' => '1', 'titulo' => 'ZT-act-beca']);
t('Tablero: la beca no crea actividades', $cntA("SELECT COUNT(*) FROM actividades_tablero WHERE titulo='ZT-act-beca'") === 0);
$bs->post('actividades.php', ['mover_actividad' => '1', 'id_actividad' => $idAct, 'estado' => 'EN_CURSO']);
t('Tablero: la beca mueve su pegatina a «En curso»', $cntA("SELECT COUNT(*) FROM actividades_tablero WHERE id_actividad=? AND estado='EN_CURSO'", [$idAct]) === 1);
$bs->post('actividades.php', ['mover_actividad' => '1', 'id_actividad' => $idAct, 'estado' => 'HECHO']);
t('Tablero: al terminarla, quien la creó recibe el aviso', $cntA("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND mensaje LIKE 'Actividad terminada: ZT-act-1%'", [$U['secretaria']['id']]) === 1);
$sec->post('actividades.php', ['editar_actividad' => '1', 'id_actividad' => $idAct, 'titulo' => 'ZT-act-1 editada', 'color' => 'verde', 'id_asignado' => '']);
t('Tablero: la secretaria edita la pegatina', $cntA("SELECT COUNT(*) FROM actividades_tablero WHERE id_actividad=? AND titulo='ZT-act-1 editada' AND color='verde'", [$idAct]) === 1);
$sec->post('actividades.php', ['borrar_actividad' => '1', 'id_actividad' => $idAct]);
t('Tablero: la secretaria la elimina', $cntA("SELECT COUNT(*) FROM actividades_tablero WHERE id_actividad=?", [$idAct]) === 0);
$sec->postRaw('actividades.php', ['crear_actividad' => '1', 'titulo' => 'ZT-act-sin-csrf']);
t('Tablero: sin token CSRF no se crea nada', $cntA("SELECT COUNT(*) FROM actividades_tablero WHERE titulo='ZT-act-sin-csrf'") === 0);
