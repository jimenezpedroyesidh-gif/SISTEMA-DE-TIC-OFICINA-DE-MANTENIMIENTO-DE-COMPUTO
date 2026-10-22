<?php
/**
 * Pruebas de: roles vigilante y visualización (TV), pestaña «General» de horarios, pantalla de la oficina
 * y cierre de semestre (archivado de tickets). Se incluye desde run_http_tests.php ($pdo, $U, $C, $hash, $CLAVE,
 * cliente(), t(), seccion()).
 */
require_once __DIR__ . '/../../app/Support/autoload.php';
seccion('Roles vigilante y visualización, horarios por sala, pantalla TV y cierre de semestre');

$cr = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); })();
$fr = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(); })();
$insU = $pdo->prepare("INSERT INTO users (codigo, correo, password, password_temporal, role, nombre_completo, cargo, estado_activo) VALUES (?,?,?,0,?,?,?,1)");
$insU->execute(['ZTVIG01', 'zt_vigilante@test.local', $hash, 'vigilante', 'ZT vigilante', 'vigilante']);
$U['vigilante'] = ['id' => (int)$pdo->lastInsertId(), 'correo' => 'zt_vigilante@test.local', 'codigo' => 'ZTVIG01'];
$insU->execute(['ZTTV01', 'zt_tv@test.local', $hash, 'visualizacion', 'ZT pantalla', 'pantalla']);
$U['visualizacion'] = ['id' => (int)$pdo->lastInsertId(), 'correo' => 'zt_tv@test.local', 'codigo' => 'ZTTV01'];
$vig = new HttpClient($base);
$r = $vig->login($U['vigilante']['codigo'], $U['vigilante']['correo'], $CLAVE);
t('Vigilante: al entrar va a horarios.php', $r->redirects() && strpos($r->location(), 'horarios.php') !== false, $r->location());
$tv = new HttpClient($base);
$r = $tv->login($U['visualizacion']['codigo'], $U['visualizacion']['correo'], $CLAVE);
t('Pantalla TV: al entrar va a pantalla.php', $r->redirects() && strpos($r->location(), 'pantalla.php') !== false, $r->location());

// ── Datos: 2 salas, una clase, un turno de beca y un mantenimiento HOY
$mkS = function (string $n, string $a) use ($pdo) {
    $pdo->prepare("INSERT INTO salas (nombre_sala, abreviatura, dependencia, codigo_qr_token) VALUES (?, ?, 'Departamento de Sistemas', ?)")->execute([$n, $a, 'zt' . bin2hex(random_bytes(8))]);
    return (int)$pdo->lastInsertId();
};
$sV1 = $mkS('ZT-SALA-V1', 'ZV1');
$sV2 = $mkS('ZT-SALA-V2', 'ZV2');
$diaHoy = (int)date('N');
$diaClase = $diaHoy === 7 ? 1 : $diaHoy;
$periodoAct = HorarioPeriodo::actual();
$pdo->prepare("INSERT INTO horarios (id_sala, tipo, dia_semana, hora_inicio, hora_fin, detalle, id_usuario, id_creador, periodo) VALUES (?, 'CLASE', ?, '07:00', '09:00', 'ZT-clase-vig', ?, ?, ?)")
    ->execute([$sV1, $diaClase, $U['profesor']['id'], $U['admin']['id'], $periodoAct]);
$pdo->prepare("INSERT INTO horarios (id_sala, tipo, dia_semana, hora_inicio, hora_fin, detalle, id_usuario, id_creador, periodo) VALUES (?, 'TURNO_BECA', ?, '10:00', '12:00', 'ZT-turno-vig', ?, ?, ?)")
    ->execute([$sV2, $diaClase, $U['beca_trabajo']['id'], $U['admin']['id'], $periodoAct]);
$pdo->prepare("INSERT INTO mantenimientos_programados (id_sala, tipo, descripcion, fecha_programada, id_responsable, id_creador, estado) VALUES (?, 'MANTENIMIENTO_PREVENTIVO', 'ZT-mant-vig', ?, ?, ?, 'PROGRAMADO')")
    ->execute([$sV2, date('Y-m-d') . ' 14:00:00', $U['tecnico']['id'], $U['admin']['id']]);
$idMantV = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO equipos (id_sala, codigo, descripcion) VALUES (?, 'ZT-EQ-V2', 'ZT')")->execute([$sV2]);
$eqV2 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO mantenimiento_equipos (id_programado, id_equipo, estado, id_usuario_asignado) VALUES (?, ?, 'TOMADO', ?)")->execute([$idMantV, $eqV2, $U['beca_trabajo']['id']]);

// ── Horarios: pestaña General primero (admin) y vista del vigilante
$r = $C['admin']->get('horarios.php');
t('Horarios: la primera pestaña es «General» (todas las salas) y las salas van después', preg_match('/class="btn btn-ghost btn-sm sala-tab active" data-sala="-1"/', $r->body) === 1 && $r->contains('data-sala="' . $sV1 . '"'));
t('Horarios: en la pestaña General cada bloque lleva la etiqueta de su sala', $r->contains('class="hg-sala-tag"') && $r->contains('>ZV1</span>'));
$r = $vig->get('horarios.php');
t('Vigilante: ve los horarios de las salas (solo lectura, sin crear ni editar)', $r->status === 200 && $r->contains('Horarios de salas') && $r->contains('ZV1') && !$r->contains('data-click="horarios.crear"') && !$r->contains('data-click="horarios.editar"') && !$r->contains('name="crear_horario"'));
t('Vigilante: no ve los turnos del personal (solo clases, préstamos y mantenimientos)', !$r->contains('ZT-turno-vig') && !$r->contains('ZT beca_trabajo'));
t('Vigilante: «Hoy en las salas» marca la sala con mantenimiento como «No prestar»', $r->contains('Hoy en las salas') && $r->contains('No prestar · mantenimiento'));
$r = $vig->post('horarios.php', ['crear_horario' => '1', 'id_sala' => $sV1, 'tipo' => 'CLASE', 'dia_semana' => 2, 'hora_inicio' => '15:00', 'hora_fin' => '16:00', 'detalle' => 'ZT-vig-crea']);
t('Vigilante: no puede crear horarios por POST', $cr("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-vig-crea'") === 0);
foreach (['tickets.php', 'mantenimientos_programados.php', 'gestionar_inventario.php', 'pantalla.php', 'usuarios.php', 'archivo_semestral.php'] as $pg) {
    $r = $vig->get($pg);
    t("Vigilante: $pg no le abre (vuelve a su inicio)", $r->status !== 200 || $r->toLogin() || $r->redirects(), 'status ' . $r->status);
}
$r = $vig->get('buscar_global.php?q=ZT');
t('Vigilante: el buscador global (AJAX) responde 403', $r->status === 403);
$r = $vig->get('perfil.php');
t('Vigilante: sí puede abrir su perfil', $r->status === 200);

// ── Pantalla de la oficina (TV)
$pdo->prepare("INSERT INTO tickets (user_id, ubicacion, descripcion, id_sala, origen, codigo_seguimiento, nombre_contacto, correo_contacto) VALUES (NULL, 'ZT-SALA-V1', 'ZT-err-qr-sin', ?, 'QR_ANONIMO', 'ZTTV0001', 'ZT Persona TV', 'zt.tv@ufps.edu.co')")->execute([$sV1]);
$pdo->prepare("INSERT INTO tickets_profesor (profesor_id, tipo_ticket, id_sala, sala_danio, descripcion_problema, origen, tecnico_id, estado) VALUES (?, 'danio', ?, 'ZT-SALA-V2', 'ZT-err-qr-prof', 'QR_PROFESOR', ?, 'En proceso')")
    ->execute([$U['profesor']['id'], $sV2, $U['tecnico']['id']]);
$r = $tv->get('pantalla.php');
t('Pantalla TV: dos columnas (mantenimientos programados y errores de sala)', $r->status === 200 && $r->contains('Mantenimientos programados') && $r->contains('Errores de sala (QR)') && $r->contains('id="tv-columnas"'));
t('Pantalla TV: el mantenimiento muestra su técnico y quién está trabajando', $r->contains('ZV2') && $r->contains('ZT tecnico') && $r->contains('Trabajando: ZT beca_trabajo'));
t('Pantalla TV: errores por QR con y sin sesión, con quién responde', $r->contains('ZT-err-qr-sin') && $r->contains('ZT-err-qr-prof') && $r->contains('Téc. ZT tecnico') && $r->contains('nadie aún') && $r->contains('ZT Persona TV'));
t('Pantalla TV: sin menú lateral y con actualización automática', !$r->contains('id="appSidebar"') && $r->contains('assets/pantalla.js') && $r->contains('data-url="pantalla.php?parcial=1"'));
$r = $tv->get('pantalla.php?parcial=1');
t('Pantalla TV: ?parcial=1 devuelve solo las columnas', $r->status === 200 && $r->contains('tv-col') && !$r->contains('<html'));
foreach (['tickets.php', 'horarios.php', 'perfil.php', 'mantenimientos_programados.php'] as $pg) {
    $r = $tv->get($pg);
    t("Pantalla TV: $pg no le abre", $r->status !== 200 || $r->redirects() || $r->toLogin(), 'status ' . $r->status);
}
t('Pantalla TV: el técnico también puede abrirla', $C['tecnico']->get('pantalla.php')->status === 200);
$r = $C['profesor']->get('pantalla.php');
t('Pantalla TV: un profesor no puede', $r->status !== 200 || $r->redirects() || $r->toLogin());

// ── Cierre de semestre
$adm = $C['admin'];
$r = $C['tecnico']->get('archivo_semestral.php');
t('Archivo: solo el admin entra', $r->status !== 200 || $r->redirects() || $r->toLogin());
$antes = HorarioPeriodo::anterior(HorarioPeriodo::anterior($periodoAct)); // un semestre ya terminado
[$desdeA] = ArchivoSemestralService::rango($antes);
$fechaA = date('Y-m-d H:i:s', strtotime($desdeA . ' +20 days 10:00'));
$insT = $pdo->prepare("INSERT INTO tickets (user_id, tecnico_id, ubicacion, descripcion, solucion, id_sala, id_equipo, origen, estado, fecha_creacion, codigo_seguimiento, nombre_contacto, correo_contacto) VALUES (?, ?, 'ZT-SALA-V1', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$insT->execute([null, $U['tecnico']['id'], 'ZT-arch-cerrado', 'ZT cambio de cable', $sV1, $eqV2, 'QR_ANONIMO', 'Cerrado', $fechaA, 'ZTARCH01', 'ZT Persona A', 'zt.a@ufps.edu.co']);
$tCerrado = (int)$pdo->lastInsertId();
$insT->execute([null, null, 'ZT-arch-abierto', null, $sV1, null, 'QR_ANONIMO', 'En espera', $fechaA, 'ZTARCH02', null, null]);
$tAbierto = (int)$pdo->lastInsertId();
$insT->execute([null, $U['tecnico']['id'], 'ZT-arch-solucionado', null, $sV1, null, 'QR_ANONIMO', 'Solucionado', $fechaA, 'ZTARCH03', null, null]);
$tSolucionado = (int)$pdo->lastInsertId();
$insT->execute([null, $U['tecnico']['id'], 'ZT-arch-con-mant', null, $sV1, null, 'QR_ANONIMO', 'Cerrado', $fechaA, 'ZTARCH04', null, null]);
$tConMant = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO mantenimientos_programados (id_sala, tipo, descripcion, fecha_programada, id_creador, id_ticket_origen, estado) VALUES (?, 'OTRO', 'ZT-mant-arch', NOW(), ?, ?, 'EN_PROCESO')")->execute([$sV1, $U['admin']['id'], $tConMant]);
$pdo->prepare("INSERT INTO tickets_profesor (profesor_id, tipo_ticket, id_sala, descripcion_problema, estado, fecha_creacion) VALUES (?, 'danio', ?, 'ZT-arch-prof', 'Aprobado', ?)")->execute([$U['profesor']['id'], $sV1, $fechaA]);
$tpCerrado = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ticket_comentarios (tabla_ticket, id_ticket, id_usuario, comentario) VALUES ('tickets', ?, ?, 'ZT comentario')")->execute([$tCerrado, $U['tecnico']['id']]);
$pdo->prepare("INSERT INTO ticket_activos (tabla_ticket, id_ticket, id_equipo, tipo_afectacion) VALUES ('tickets', ?, ?, 'DAÑADO')")->execute([$tCerrado, $eqV2]);
$pdo->prepare("INSERT INTO componentes (tipo, serial, codigo_inventario, estado, id_equipo_actual) VALUES ('torre', 'ZT-SER-ARCH', 'ZT-SER-ARCH', 'ACTIVO', ?)")->execute([$eqV2]);
$compA = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO historial_componente (id_componente, tipo_evento, id_ticket, motivo, id_usuario_responsable) VALUES (?, 'REVISION', ?, 'ZT revisión', ?)")->execute([$compA, $tCerrado, $U['tecnico']['id']]);
$pdo->prepare("INSERT INTO encuestas_satisfaccion (tabla_ticket, id_ticket, calificacion, canal) VALUES ('tickets', ?, 5, 'CODIGO')")->execute([$tCerrado]);

$r = $adm->get('archivo_semestral.php?periodo=' . $antes);
t('Archivo: la vista previa cuenta solo los terminados sin nada abierto', $r->status === 200 && $r->contains('Cerrar el semestre ' . $antes) && $r->contains('ARCHIVAR ' . $antes));
$r = $adm->post('archivo_semestral.php', ['archivar_periodo' => '1', 'periodo' => $antes, 'confirmacion' => 'archivar', 'borrar_adjuntos' => '1']);
t('Archivo: sin el texto de confirmación exacto no se archiva nada', $cr("SELECT COUNT(*) FROM tickets WHERE id=?", [$tCerrado]) === 1 && $adm->get('archivo_semestral.php?periodo=' . $antes)->contains('Escribe exactamente'));
$r = $adm->post('archivo_semestral.php', ['archivar_periodo' => '1', 'periodo' => $periodoAct === HorarioPeriodo::actual() ? HorarioPeriodo::siguiente($periodoAct) : $periodoAct, 'confirmacion' => 'ARCHIVAR ' . HorarioPeriodo::siguiente($periodoAct)]);
t('Archivo: un semestre que no ha terminado no se puede cerrar', $adm->get('archivo_semestral.php')->contains('todavía no termina'));
$r = $adm->post('archivo_semestral.php', ['archivar_periodo' => '1', 'periodo' => $antes, 'confirmacion' => 'ARCHIVAR ' . $antes, 'borrar_adjuntos' => '1']);
t('Archivo: el admin archiva y ve el resultado', $r->redirects() && $adm->get('archivo_semestral.php')->contains('archivado:'));
t('Archivo: los terminados salen de las tablas operativas', $cr("SELECT COUNT(*) FROM tickets WHERE id=?", [$tCerrado]) === 0 && $cr("SELECT COUNT(*) FROM tickets_profesor WHERE id=?", [$tpCerrado]) === 0);
t('Archivo: sin contestar, sin solucionar del todo y con mantenimiento abierto se quedan', $cr("SELECT COUNT(*) FROM tickets WHERE id IN (?,?,?)", [$tAbierto, $tSolucionado, $tConMant]) === 3);
$a = $fr("SELECT * FROM tickets_archivo WHERE tabla_origen='tickets' AND id_original=?", [$tCerrado]);
t('Archivo: queda el resumen (sala, contacto, técnico, solución, equipo, calificación, comentarios)', $a && $a['periodo'] === $antes && $a['sala_nombre'] === 'ZT-SALA-V1' && $a['correo_contacto'] === 'zt.a@ufps.edu.co'
    && $a['tecnico'] === 'ZT tecnico' && $a['solucion'] === 'ZT cambio de cable' && $a['equipos_afectados'] === 'ZT-EQ-V2' && (int)$a['calificacion'] === 5 && (int)$a['total_comentarios'] === 1, json_encode($a));
t('Archivo: el ticket de profesor también queda resumido', $cr("SELECT COUNT(*) FROM tickets_archivo WHERE tabla_origen='tickets_profesor' AND id_original=? AND tipo='ERROR_SALA'", [$tpCerrado]) === 1);
t('Archivo: el historial del componente se conserva y apunta al archivo', $cr("SELECT COUNT(*) FROM historial_componente WHERE id_componente=? AND id_ticket IS NULL AND id_ticket_archivado=?", [$compA, (int)$a['id_archivo']]) === 1);
t('Archivo: el detalle operativo se borra (comentarios, encuesta, activos)', $cr("SELECT COUNT(*) FROM ticket_comentarios WHERE tabla_ticket='tickets' AND id_ticket=?", [$tCerrado]) === 0
    && $cr("SELECT COUNT(*) FROM encuestas_satisfaccion WHERE tabla_ticket='tickets' AND id_ticket=?", [$tCerrado]) === 0 && $cr("SELECT COUNT(*) FROM ticket_activos WHERE tabla_ticket='tickets' AND id_ticket=?", [$tCerrado]) === 0);
t('Archivo: el equipo conserva su vínculo con el ticket archivado', $cr("SELECT COUNT(*) FROM tickets_archivo_activos WHERE id_archivo=? AND id_equipo=?", [(int)$a['id_archivo'], $eqV2]) === 1);
$r = $adm->get('ficha_equipo.php?id=' . $eqV2);
t('Ficha del equipo: muestra los tickets archivados', $r->contains('Tickets archivados en cierres de semestre') && $r->contains('ZT-arch-cerrado'));
$r = (new HttpClient($base))->get('consultar_estado.php?codigo=ZTARCH01');
t('Consulta pública: un reporte archivado se sigue consultando con su código', $r->contains('Resuelto y cerrado.'));
$r = $adm->get('archivo_semestral.php?b_periodo=' . $antes . '&q=ZT-arch');
t('Archivo: búsqueda en el archivo', $r->contains('ZT-arch-cerrado') && $r->contains('ZT-arch-prof'));
$r = $adm->get('archivo_semestral.php?b_periodo=' . $antes . '&csv=1');
t('Archivo: descarga CSV con BOM y punto y coma', strpos($r->body, "\xEF\xBB\xBF") === 0 && strpos($r->body, 'periodo;tabla_origen') !== false && strpos($r->body, 'ZT-arch-cerrado') !== false);
t('Archivo: queda auditado', $cr("SELECT COUNT(*) FROM auditoria WHERE id_usuario=? AND tabla_afectada='tickets_archivo'", [$U['admin']['id']]) >= 1);

// Limpieza propia del archivo (los tickets ya no existen en las tablas operativas)
$pdo->exec("DELETE aa FROM tickets_archivo_activos aa JOIN tickets_archivo a ON a.id_archivo = aa.id_archivo WHERE a.descripcion LIKE 'ZT-%'");
$pdo->exec("DELETE FROM tickets_archivo WHERE descripcion LIKE 'ZT-%'");
$pdo->prepare("DELETE FROM archivo_lotes WHERE id_admin=? AND id_lote NOT IN (SELECT id_lote FROM tickets_archivo)")->execute([$U['admin']['id']]);
$pdo->prepare("DELETE FROM historial_componente WHERE id_componente=?")->execute([$compA]);

// ── Horarios: filas vacías a la mitad
$r = $C['admin']->get('horarios.php');
t('Horarios: las horas con contenido conservan el alto y las vacías van a la mitad', preg_match('/class="horario-grid" style="grid-template-rows: var\(--hg-fila-encabezado\)( var\(--hg-fila(-vacia)?\)){16}"/', $r->body) === 1
    && strpos($r->body, 'var(--hg-fila-vacia)') !== false && strpos($r->body, 'var(--hg-fila) ') !== false);
t('Horarios: la vista personal (profesor) también compacta las horas vacías', strpos($C['profesor']->get('horarios.php')->body, 'var(--hg-fila-vacia)') !== false || $C['profesor']->get('horarios.php')->contains('No tienes clases'));

// ── Horas de becas: reporte por rango de fechas
$rh = $C['beca_trabajo']->get('seguimiento_horas.php?desde=2026-02-03&hasta=2026-03-15');
t('Horas: la beca consulta un rango «desde / hasta»', $rh->status === 200 && $rh->contains('del 03/02/2026 al 15/03/2026') && $rh->contains('name="desde"') && $rh->contains('seguimiento_horas_pdf.php?desde=2026-02-03&amp;hasta=2026-03-15'));
$rh = $C['admin']->get('seguimiento_horas.php?desde=2026-02-03&hasta=2026-03-15');
t('Horas: el admin ve el listado con el rango y el PDF de todas usa el mismo rango', $rh->contains('todas=1&amp;desde=2026-02-03&amp;hasta=2026-03-15') && $rh->contains('Horas del periodo'));
$rh = $C['beca_trabajo']->get('seguimiento_horas.php?desde=2026-05-01&hasta=2026-04-01');
t('Horas: rango al revés → mensaje y vuelve al mes', $rh->contains('debe ser anterior o igual'));
$rh = $C['beca_trabajo']->get('seguimiento_horas.php?desde=2024-01-01&hasta=2026-01-01');
t('Horas: rango de más de un año → mensaje', $rh->contains('El rango máximo es de un año'));
$rp = $C['beca_trabajo']->get('seguimiento_horas_pdf.php?desde=2026-02-03&hasta=2026-03-15');
t('Horas: el PDF por rango se genera (o avisa si la beca no tiene código)', $rp->status === 200 && (strpos($rp->body, '%PDF') === 0 || strpos($rp->body, 'código') !== false));
