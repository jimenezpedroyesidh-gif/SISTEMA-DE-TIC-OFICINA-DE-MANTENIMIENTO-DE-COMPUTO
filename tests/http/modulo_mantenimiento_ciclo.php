<?php
/**
 * Pruebas del módulo de mantenimiento completo (se incluye desde run_http_tests.php, que aporta $pdo, $U, $C,
 * $adm, $tec, $CLAVE, cliente(), t() y seccion()):
 *  1. Programación por sala: duración, actividades, primer espacio libre, choque en toda la duración,
 *     reportes vinculados, reprogramación con historial y detección de cruces posteriores (préstamo).
 *  2. Campañas en varias salas: cronograma, secuencia (bloqueo y liberación), finalización y cancelación.
 *  3. Historial y análisis: permisos, métricas, filtros, CSV y acciones correctivas → mantenimiento.
 */
seccion('Mantenimiento: programación por sala, campañas, historial y acciones correctivas');

$q1 = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); })();
$filaC = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch() ?: []; })();
$idU = fn(string $r) => (int)$U[$r]['id'];
$becaC = $C['beca_trabajo'];
$profC = $C['profesor'];
$PNG1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$M = 'mantenimientos_programados.php';

// Salas de prueba con un PC (torre) cada una y una sin equipos.
$salaC = function (string $n, string $a, string $bloque = 'ZT Bloque') use ($pdo) {
    $pdo->prepare("INSERT INTO salas (nombre_sala, abreviatura, dependencia, bloque, codigo_qr_token) VALUES (?, ?, 'Departamento de Sistemas', ?, ?)")->execute([$n, $a, $bloque, 'zt' . bin2hex(random_bytes(8))]);
    return (int)$pdo->lastInsertId();
};
$pcEn = function (int $sala, string $cod) use ($pdo) {
    $pdo->prepare("INSERT INTO equipos (id_sala, codigo, descripcion) VALUES (?, ?, 'ZT')")->execute([$sala, $cod]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO componentes (tipo, serial, codigo_inventario, estado, id_equipo_actual) VALUES ('torre', ?, ?, 'ACTIVO', ?)")->execute(["ZT-SER-$cod", "ZT-SER-$cod", $id]);
    return $id;
};
$s1 = $salaC('ZT-SALA-C1', 'ZC1');
$s2 = $salaC('ZT-SALA-C2', 'ZC2');
$s3 = $salaC('ZT-SALA-C3', 'ZC3', 'ZT Otro bloque');
$s0 = $salaC('ZT-SALA-C0', 'ZC0');
$eq1 = $pcEn($s1, 'ZT-C1-PC01');
$eq2 = $pcEn($s2, 'ZT-C2-PC01');
$eq3 = $pcEn($s3, 'ZT-C3-PC01');

// Fechas: la semana siguiente (mismo semestre).
$lunesSig = new DateTime('monday next week');
$martes = (clone $lunesSig)->modify('+1 day')->format('Y-m-d');
$miercoles = (clone $lunesSig)->modify('+2 days')->format('Y-m-d');
$jueves = (clone $lunesSig)->modify('+3 days')->format('Y-m-d');
$periodoAct = date('Y') . '-' . (date('n') <= 6 ? '1' : '2');
$pdo->prepare("INSERT INTO horarios (id_sala, id_usuario, tipo, dia_semana, periodo, hora_inicio, hora_fin, detalle, id_creador) VALUES (?, ?, 'CLASE', 2, ?, '08:00', '10:00', 'ZT-clase-ciclo', ?)")
    ->execute([$s1, $idU('profesor'), $periodoAct, $idU('admin')]);
$idMantDe = fn(string $d) => (int)$q1("SELECT id_programado FROM mantenimientos_programados WHERE descripcion = ? ORDER BY id_programado DESC LIMIT 1", [$d]);
$baseProg = ['programar' => '1', 'id_sala' => $s1, 'tipo' => 'MANTENIMIENTO_PREVENTIVO', 'alcance_equipo' => 'TODOS'];
$cerrar = function (HttpClient $c, int $id, string $resultado = 'RESUELTO') use ($M, $PNG1) {
    return $c->postFile($M, ['cambiar_estado_mantenimiento' => '1', 'id_programado' => $id, 'nuevo_estado' => 'REALIZADO', 'resultado' => $resultado, 'observaciones_cierre' => 'ZT cierre'],
        ['evidencia[]' => ['zt_ciclo.png', $PNG1, 'image/png']]);
};
$hacerEquipos = function (int $idProg) use ($pdo, $tec, $M) {
    $s = $pdo->prepare("SELECT id_mant_equipo FROM mantenimiento_equipos WHERE id_programado = ? AND estado IN ('PENDIENTE','INCOMPLETO')");
    $s->execute([$idProg]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $idMe) {
        $tec->post($M, ['tomar_equipo' => '1', 'id_mant_equipo' => $idMe, 'id_programado' => $idProg]);
        $tec->post($M, ['marcar_equipo' => '1', 'id_mant_equipo' => $idMe, 'id_programado' => $idProg, 'nuevo_estado_equipo' => 'REALIZADO']);
    }
};

// ───────────────────────── 1. Programación por sala
$j = $tec->get("$M?ajax=disponibilidad&id_sala=$s1&fecha=" . urlencode($martes . 'T07:30') . '&duracion=60&buscar=1')->json();
t('Disponibilidad: 07:30 + 1 h choca con la clase de 08:00 (revisa toda la duración)', is_array($j) && !empty($j['choques']) && str_contains($j['choques'][0]['texto'] ?? '', 'clase'), json_encode($j));
t('Disponibilidad: el primer espacio libre es al terminar la clase (10:00)', ($j['sugerencia'] ?? '') === $martes . 'T10:00', json_encode($j));
$r = $profC->get("$M?ajax=disponibilidad&id_sala=$s1&buscar=1");
t('Disponibilidad: el profesor no la consulta', $r->denied() || $r->status === 403);
$r = $becaC->get("$M?ajax=disponibilidad&id_sala=$s1&buscar=1");
t('Disponibilidad: el beca recibe 403', $r->status === 403);

$r = $tec->post($M, $baseProg + ['descripcion' => 'ZT-ciclo-choque', 'fecha_programada' => $martes . 'T07:30', 'duracion_minutos' => 60]);
t('Programar: 07:30 de 1 h choca con la clase y pide confirmación', $idMantDe('ZT-ciclo-choque') === 0 && $r->contains('Marca la casilla de confirmación'));
$r = $tec->post($M, $baseProg + ['descripcion' => 'ZT-ciclo-choque', 'fecha_programada' => $martes . 'T07:00', 'duracion_minutos' => 60]);
t('Programar: 07:00 de 1 h termina antes de la clase y se crea sin aviso', $idMantDe('ZT-ciclo-choque') > 0);

// Reportes vinculables: QR tomado por el técnico + daño de profesor; uno sin tomar no se acepta.
$pdo->prepare("INSERT INTO tickets (user_id, origen, tipo_solicitud, categoria, descripcion, estado, nombre_dependencia, ubicacion, equipo, id_sala, id_equipo, tecnico_id) VALUES (?, 'QR_ANONIMO', 'INCIDENTE', 'HARDWARE', 'ZT-ciclo-qr', 'En proceso', 'ZT', 'ZT', 'ZT', ?, ?, ?)")
    ->execute([$idU('solicitante'), $s1, $eq1, $idU('tecnico')]);
$tQr = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO tickets_profesor (profesor_id, tipo_ticket, categoria, id_sala, estado, descripcion_problema, tecnico_id) VALUES (?, 'danio', 'HARDWARE', ?, 'En proceso', 'ZT-ciclo-prof', ?)")
    ->execute([$idU('profesor'), $s1, $idU('tecnico')]);
$tProf = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO tickets (user_id, origen, tipo_solicitud, descripcion, estado, nombre_dependencia, ubicacion, equipo, id_sala) VALUES (?, 'QR_ANONIMO', 'INCIDENTE', 'ZT-ciclo-qr-libre', 'En espera', 'ZT', 'ZT', 'ZT', ?)")
    ->execute([$idU('solicitante'), $s1]);
$tLibre = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ticket_activos (tabla_ticket, id_ticket, id_equipo, tipo_afectacion) VALUES ('tickets', ?, ?, 'DAÑADO'), ('tickets_profesor', ?, ?, 'DAÑADO')")->execute([$tQr, $eq1, $tProf, $eq1]);

$r = $tec->get($M);
t('Formulario: ofrece los reportes que el técnico tomó en la sala', $r->contains('value="tickets:' . $tQr . '"') && $r->contains('value="tickets_profesor:' . $tProf . '"') && !$r->contains('value="tickets:' . $tLibre . '"'));
$r = $tec->post($M, $baseProg + ['descripcion' => 'ZT-ciclo-vinc-malo', 'fecha_programada' => $martes . 'T10:00', 'vinculos' => ['tickets:' . $tLibre]]);
t('Programar: un reporte que no tomó el técnico no se vincula (y no crea nada)', $idMantDe('ZT-ciclo-vinc-malo') === 0 && $r->contains('ya no se puede atender'));
$r = $tec->post($M, $baseProg + ['descripcion' => 'ZT-ciclo-1', 'fecha_programada' => $martes . 'T10:00', 'duracion_minutos' => 90,
    'actividades' => "Limpiar equipos\nActualizar antivirus", 'id_responsable' => $idU('tecnico'), 'vinculos' => ['tickets:' . $tQr, 'tickets_profesor:' . $tProf]]);
$m1 = $idMantDe('ZT-ciclo-1');
$f1 = $filaC("SELECT * FROM mantenimientos_programados WHERE id_programado = ?", [$m1]);
t('Programar: guarda duración y actividades', $m1 > 0 && (int)($f1['duracion_minutos'] ?? 0) === 90 && str_contains((string)($f1['actividades'] ?? ''), 'antivirus'));
t('Programar: quedan 2 reportes vinculados', (int)$q1("SELECT COUNT(*) FROM mantenimiento_vinculos WHERE id_programado = ?", [$m1]) === 2);
t('Programar: avisa a los dueños de los reportes vinculados', (int)$q1("SELECT COUNT(*) FROM notificaciones WHERE id_usuario = ? AND mensaje LIKE ?", [$idU('profesor'), "%reporte #$tProf%"]) === 1);
$r = $tec->get("$M?elevar_ticket=$tQr");
t('Elevar: un reporte ya vinculado dice a qué mantenimiento fue', $r->contains("ya fue elevado al mantenimiento #$m1"));
$r = $tec->get('tickets.php');
t('Panel de tickets: el técnico sigue entrando (consulta con vínculos sin error)', $r->status === 200);
$r = $tec->get($M);
t('Listado: muestra duración, actividades y reportes que atiende', $r->contains('1 hora y media') && $r->contains('Actualizar antivirus') && $r->contains('Atiende también: #' . $tQr));
$cal = $tec->get('horarios.php?semana=' . $lunesSig->format('Y-m-d') . '&sala=' . $s1);
t('Horarios: la agenda de la sala sigue cargando con la duración nueva', $cal->status === 200);
$r = $tec->post($M, $baseProg + ['descripcion' => 'ZT-ciclo-solape', 'fecha_programada' => $martes . 'T11:00', 'duracion_minutos' => 60]);
t('Programar: se cruza con el mantenimiento de 10:00-11:30 de la misma sala', $idMantDe('ZT-ciclo-solape') === 0 && $r->contains('Marca la casilla'));

// Reprogramar
$becaC->post($M, ['reprogramar' => '1', 'id_programado' => $m1, 'nueva_fecha' => '']);
$adm->post($M, ['reprogramar' => '1', 'id_programado' => $m1, 'nueva_fecha' => '']);
t('Reprogramar: ni beca ni admin pueden', (int)$q1("SELECT COUNT(*) FROM mantenimiento_reprogramaciones WHERE id_programado = ?", [$m1]) === 0);
$r = $tec->post($M, ['reprogramar' => '1', 'id_programado' => $m1, 'nueva_fecha' => $martes . 'T09:00', 'motivo_reprogramacion' => 'ZT']);
t('Reprogramar: a una hora con clase pide confirmación', $r->contains('Marca la casilla') && (int)$q1("SELECT COUNT(*) FROM mantenimiento_reprogramaciones WHERE id_programado = ?", [$m1]) === 0);
$r = $tec->post($M, ['reprogramar' => '1', 'id_programado' => $m1, 'nueva_fecha' => $miercoles . 'T14:00', 'motivo_reprogramacion' => 'ZT a mano']);
$f1 = $filaC("SELECT * FROM mantenimientos_programados WHERE id_programado = ?", [$m1]);
t('Reprogramar: cambia la fecha y queda REPROGRAMADO', ($f1['estado'] ?? '') === 'REPROGRAMADO' && substr((string)$f1['fecha_programada'], 0, 16) === $miercoles . ' 14:00');
t('Reprogramar: deja el historial (fecha anterior, nueva, tipo MANUAL)', (int)$q1("SELECT COUNT(*) FROM mantenimiento_reprogramaciones WHERE id_programado = ? AND tipo = 'MANUAL' AND fecha_anterior LIKE ?", [$m1, "$martes 10:00%"]) === 1);

// Cruce posterior: se registra un préstamo de toda la jornada ese día → se detecta y se reprograma por conflicto.
$pdo->prepare("INSERT INTO tickets_profesor (profesor_id, tipo_ticket, subtipo_requerimiento, id_sala, estado, fecha_requerida, duracion_prestamo, motivo) VALUES (?, 'requerimiento', 'PRESTAMO_SALA', ?, 'En espera', ?, 'TODO_DIA', 'ZT préstamo')")
    ->execute([$idU('profesor'), $s1, $miercoles]);
$r = $tec->get($M);
t('Conflicto: el listado marca el cruce con el préstamo y ofrece reprogramar', $r->contains('Cruce') && $r->contains('préstamo de sala'));
$tec->post($M, ['reprogramar' => '1', 'id_programado' => $m1, 'nueva_fecha' => '', 'motivo_reprogramacion' => 'ZT conflicto']);
$f1 = $filaC("SELECT * FROM mantenimientos_programados WHERE id_programado = ?", [$m1]);
t('Conflicto: «primer espacio libre» lo saca del día del préstamo', substr((string)$f1['fecha_programada'], 0, 10) !== $miercoles);
t('Conflicto: el cambio queda registrado como CONFLICTO', (int)$q1("SELECT COUNT(*) FROM mantenimiento_reprogramaciones WHERE id_programado = ? AND tipo = 'CONFLICTO'", [$m1]) === 1);

// Cierre con resultado: cierra también los reportes vinculados.
$hacerEquipos($m1);
$r = $cerrar($tec, $m1, 'PARCIAL');
$f1 = $filaC("SELECT * FROM mantenimientos_programados WHERE id_programado = ?", [$m1]);
t('Cierre: REALIZADO guarda el resultado obtenido', ($f1['estado'] ?? '') === 'REALIZADO' && ($f1['resultado'] ?? '') === 'PARCIAL');
t('Cierre: los reportes vinculados quedan cerrados', $q1("SELECT estado FROM tickets WHERE id = ?", [$tQr]) === 'Cerrado' && $q1("SELECT estado FROM tickets_profesor WHERE id = ?", [$tProf]) === 'Cerrado');
t('Cierre: el reporte que no se vinculó sigue abierto', $q1("SELECT estado FROM tickets WHERE id = ?", [$tLibre]) === 'En espera');
$r = $cerrar($tec, $m1);
t('Cierre: un mantenimiento cerrado no se vuelve a cerrar', $r->contains('No puedes actualizar este mantenimiento'));

// ───────────────────────── 2. Campañas
$CP = 'mantenimiento_campanas.php';
t('Campañas: beca y profesor no entran', $becaC->get($CP)->denied() && $profC->get($CP)->denied());
$r = $adm->get($CP);
t('Campañas: el admin ve el panel pero no crea', $r->status === 200 && !$r->contains('+ Nueva campaña'));
t('Campañas: el técnico ve «Nueva campaña» y la opción de secuencia', $tec->get($CP)->contains('Finalizar una sala antes de continuar con la siguiente'));
$camp = ['crear_campana' => '1', 'nombre' => 'ZT-camp-seq', 'tipo' => 'INSTALACION_SOFTWARE', 'alcance_equipo' => 'TODOS', 'descripcion' => 'ZT-camp-seq',
    'fecha_inicio' => $miercoles . 'T08:00', 'duracion_minutos' => 60, 'secuencial' => '1'];
$r = $tec->post($CP, $camp + ['salas' => [$s2]]);
t('Campañas: con una sola sala pide al menos dos', $r->contains('al menos dos') && (int)$q1("SELECT COUNT(*) FROM mantenimiento_campanas WHERE nombre = 'ZT-camp-seq'") === 0);
$adm->post($CP, $camp + ['salas' => [$s2, $s3]]);
t('Campañas: el admin no puede crearlas', (int)$q1("SELECT COUNT(*) FROM mantenimiento_campanas WHERE nombre = 'ZT-camp-seq'") === 0);
$r = $tec->post($CP, $camp + ['salas' => [$s3, $s2, $s0]]);
$c1 = (int)$q1("SELECT id_campana FROM mantenimiento_campanas WHERE nombre = 'ZT-camp-seq'");
$iv = $c1 ? $pdo->query("SELECT * FROM mantenimientos_programados WHERE id_campana = $c1 ORDER BY orden_campana")->fetchAll() : [];
t('Campañas: crea una intervención por sala con equipos (omite la vacía y lo avisa)', count($iv) === 2 && $r->contains('Se omitieron') && $r->contains('ZT-SALA-C0'));
t('Campañas: respeta el orden elegido (C3 primero, C2 después)', (int)($iv[0]['id_sala'] ?? 0) === $s3 && (int)($iv[1]['id_sala'] ?? 0) === $s2);
t('Campañas: secuencial → la primera PROGRAMADA y la segunda PENDIENTE (bloqueada)', ($iv[0]['estado'] ?? '') === 'PROGRAMADO' && ($iv[1]['estado'] ?? '') === 'PENDIENTE');
t('Campañas: cronograma encadenado (la segunda empieza cuando termina la primera)', isset($iv[1]) && strtotime($iv[1]['fecha_programada']) >= strtotime($iv[0]['fecha_programada']) + 3600);
t('Campañas: software fuerza alcance cómputo y genera checklist', ($iv[0]['alcance_equipo'] ?? '') === 'COMPUTO' && (int)$q1("SELECT COUNT(*) FROM mantenimiento_equipos WHERE id_programado = ?", [(int)($iv[0]['id_programado'] ?? 0)]) === 1);
$segunda = (int)($iv[1]['id_programado'] ?? 0);
$meSeg = (int)$q1("SELECT id_mant_equipo FROM mantenimiento_equipos WHERE id_programado = ?", [$segunda]);
$r = $becaC->post($M, ['tomar_equipo' => '1', 'id_mant_equipo' => $meSeg, 'id_programado' => $segunda]);
t('Secuencia: en la sala bloqueada no se pueden tomar equipos', $q1("SELECT estado FROM mantenimiento_equipos WHERE id_mant_equipo = ?", [$meSeg]) === 'PENDIENTE' && $r->contains('terminar la sala anterior'));
$r = $tec->post($M, ['cambiar_estado_mantenimiento' => '1', 'id_programado' => $segunda, 'nuevo_estado' => 'EN_PROCESO']);
t('Secuencia: la sala bloqueada no se puede iniciar', $q1("SELECT estado FROM mantenimientos_programados WHERE id_programado = ?", [$segunda]) === 'PENDIENTE');
$r = $tec->get("$CP?id=$c1");
t('Campañas: el panel muestra avance, bloqueadas y reprogramadas', $r->contains('Bloqueadas') && $r->contains('Reprogramadas') && $r->contains('Bloqueada'));
$primera = (int)$iv[0]['id_programado'];
$r = $cerrar($tec, $primera);
t('Secuencia: sin la checklist completa no se cierra (no basta con cambiar el estado)', $q1("SELECT estado FROM mantenimientos_programados WHERE id_programado = ?", [$primera]) === 'PROGRAMADO');
$hacerEquipos($primera);
$cerrar($tec, $primera);
t('Secuencia: al cerrar la primera, la segunda queda liberada', in_array($q1("SELECT estado FROM mantenimientos_programados WHERE id_programado = ?", [$segunda]), ['PROGRAMADO', 'REPROGRAMADO'], true));
t('Secuencia: avisa que ya puede empezar la siguiente sala', (int)$q1("SELECT COUNT(*) FROM notificaciones WHERE id_usuario = ? AND mensaje LIKE ?", [$idU('tecnico'), '%ZT-camp-seq%ya puede empezar%']) === 1);
$hacerEquipos($segunda);
$cerrar($tec, $segunda);
t('Campañas: con todas las salas cerradas queda FINALIZADA', $q1("SELECT estado FROM mantenimiento_campanas WHERE id_campana = ?", [$c1]) === 'FINALIZADA');
$r = $tec->get("$M?campana=$c1&estado=REALIZADO");
t('Mantenimientos: filtro por campaña muestra sus salas', $r->contains('Campaña «ZT-camp-seq»'));

// Independiente con coordinador: no le quedan dos salas a la misma hora; cancelar.
$tec->post($CP, ['crear_campana' => '1', 'nombre' => 'ZT-camp-ind', 'tipo' => 'MANTENIMIENTO_PREVENTIVO', 'alcance_equipo' => 'TODOS', 'descripcion' => 'ZT-camp-ind',
    'fecha_inicio' => $jueves . 'T08:00', 'duracion_minutos' => 60, 'id_responsable' => $idU('tecnico'), 'salas' => [$s2, $s3]]);
$c2 = (int)$q1("SELECT id_campana FROM mantenimiento_campanas WHERE nombre = 'ZT-camp-ind'");
$iv2 = $c2 ? $pdo->query("SELECT * FROM mantenimientos_programados WHERE id_campana = $c2 ORDER BY orden_campana")->fetchAll() : [];
t('Campañas independientes: todas PROGRAMADAS', count($iv2) === 2 && $iv2[0]['estado'] === 'PROGRAMADO' && $iv2[1]['estado'] === 'PROGRAMADO');
t('Campañas independientes: el coordinador no queda en dos salas a la vez', count($iv2) === 2 && abs(strtotime($iv2[0]['fecha_programada']) - strtotime($iv2[1]['fecha_programada'])) >= 3600);
$r = $tec->post($CP, ['cancelar_campana' => '1', 'id_campana' => $c2, 'motivo' => 'ZT ya no aplica']);
t('Campañas: cancelar deja las salas sin empezar canceladas y la campaña CANCELADA',
    (int)$q1("SELECT COUNT(*) FROM mantenimientos_programados WHERE id_campana = ? AND estado = 'CANCELADO'", [$c2]) === 2 && $q1("SELECT estado FROM mantenimiento_campanas WHERE id_campana = ?", [$c2]) === 'CANCELADA');

// ───────────────────────── 3. Historial y análisis + acciones correctivas
$H = 'historial_mantenimiento.php';
t('Historial: beca y profesor no entran', $becaC->get($H)->denied() && $profC->get($H)->denied());
t('Historial: admin y técnico entran', $adm->get($H)->status === 200 && $tec->get($H)->status === 200);
// Segundo reporte del mismo equipo → reincidente.
$pdo->prepare("INSERT INTO tickets (user_id, origen, tipo_solicitud, categoria, descripcion, estado, nombre_dependencia, ubicacion, equipo, id_sala, id_equipo) VALUES (?, 'QR_ANONIMO', 'INCIDENTE', 'HARDWARE', 'ZT-ciclo-qr2', 'En espera', 'ZT', 'ZT', 'ZT', ?, ?)")
    ->execute([$idU('solicitante'), $s1, $eq1]);
$r = $tec->get("$H?periodo=rango&desde=" . date('Y-m-d', strtotime('-1 day')) . '&hasta=' . date('Y-m-d', strtotime('+30 days')));
t('Historial: la sala con más intervenciones aparece en el informe', $r->contains('ZT-SALA-C1'));
t('Historial: el equipo con 2+ reportes aparece como reincidente', $r->contains('ZT-C1-PC01'));
t('Historial: fallas más frecuentes por tipo (hardware)', $r->contains('Fallas más frecuentes') && $r->contains('Hardware'));
$r = $tec->get("$H?periodo=rango&desde=" . date('Y-m-d', strtotime('-1 day')) . '&hasta=' . date('Y-m-d', strtotime('+30 days')) . "&sala=$s1");
t('Historial por sala: línea de tiempo con mantenimiento, reportes y diagnóstico', $r->contains('Historial completo') && $r->contains('ZT-ciclo-qr') && $r->contains('Mantenimiento preventivo #' . $m1) && $r->contains('ZT cierre'));
$r = $tec->get("$H?periodo=rango&desde=" . date('Y-m-d', strtotime('-1 day')) . '&hasta=' . date('Y-m-d', strtotime('+30 days')) . '&categoria=RED');
t('Historial: filtrar por tipo de incidencia (red) deja fuera los de hardware', !$r->contains('ZT-C1-PC01'));
$r = $tec->get("$H?periodo=rango&desde=" . date('Y-m-d', strtotime('-1 day')) . '&hasta=' . date('Y-m-d', strtotime('+30 days')) . '&bloque=' . urlencode('ZT Otro bloque'));
t('Historial: filtrar por bloque', $r->contains('ZT-SALA-C3') && !$r->contains('>ZC1 — ZT-SALA-C1<'));
$r = $tec->get("$H?equipo=NO-EXISTE-ZT");
t('Historial: equipo inexistente lo avisa', $r->contains('No hay ningún equipo con el código'));
$r = $tec->get("$H?periodo=rango&desde=" . date('Y-m-d', strtotime('-1 day')) . '&hasta=' . date('Y-m-d', strtotime('+30 days')) . "&sala=$s1&exportar=csv");
t('Historial: exporta CSV con el detalle', stripos($r->header('content-type'), 'text/csv') !== false && str_contains($r->body, 'ZT-ciclo-qr'));

$destinoC = (function (string $p) { [$a, $n] = array_map('intval', explode('-', $p)); return $n === 1 ? "$a-2" : ($a + 1) . '-1'; })($periodoAct);
$accionBase = ['crear_accion' => '1', 'periodo_origen' => $periodoAct, 'periodo_destino' => $destinoC, 'tipo_accion' => 'REEMPLAZAR_EQUIPO',
    'prioridad' => 'ALTA', 'fecha_objetivo' => date('Y-m-d', strtotime('+60 days')), 'id_responsable' => $idU('tecnico')];
$becaC->post($H, array_merge($accionBase, ['id_sala' => $s1, 'causa' => 'ZT-acc-beca']));
t('Acciones: el beca no puede crearlas', (int)$q1("SELECT COUNT(*) FROM mantenimiento_acciones WHERE causa = 'ZT-acc-beca'") === 0);
$r = $tec->post($H, array_merge($accionBase, ['id_sala' => $s1, 'equipo' => 'ZT-C1-PC01', 'causa' => 'ZT-acc-1']));
$a1 = $filaC("SELECT * FROM mantenimiento_acciones WHERE causa = 'ZT-acc-1'");
t('Acciones: se crea para el semestre siguiente con sala, equipo, responsable y prioridad', ($a1['periodo_destino'] ?? '') === $destinoC && (int)$a1['id_sala'] === $s1 && (int)$a1['id_equipo'] === $eq1 && $a1['prioridad'] === 'ALTA' && $a1['estado'] === 'PENDIENTE');
$r = $tec->post($H, array_merge($accionBase, ['id_sala' => $s1, 'causa' => 'ZT-acc-mal', 'fecha_objetivo' => '31-31-2026']));
t('Acciones: fecha objetivo inválida se rechaza', $r->contains('fecha objetivo no es válida') && (int)$q1("SELECT COUNT(*) FROM mantenimiento_acciones WHERE causa = 'ZT-acc-mal'") === 0);
$adm->post($H, array_merge($accionBase, ['id_sala' => 0, 'tipo_accion' => 'ESTANDARIZAR_SOFTWARE', 'causa' => 'ZT-acc-2']));
$a2 = $filaC("SELECT * FROM mantenimiento_acciones WHERE causa = 'ZT-acc-2'");
t('Acciones: el admin (jefe) también las define', !empty($a2));
$r = $tec->get("$H?vista=acciones&destino=$destinoC");
t('Acciones: se listan con «Programar» (con sala) y «Crear campaña» (sin sala)', $r->contains('ZT-acc-1') && $r->contains('desde_accion=' . (int)$a1['id_accion']) && $r->contains('mantenimiento_campanas.php?desde_accion=' . (int)$a2['id_accion']));
$r = $adm->get("$H?vista=acciones&destino=$destinoC");
t('Acciones: el admin no ve «Programar» (programa el técnico)', $r->contains('ZT-acc-1') && !$r->contains('desde_accion='));
$r = $tec->get("$M?desde_accion=" . (int)$a1['id_accion']);
t('Acciones → Programar: formulario abierto con la acción y la sala', $r->contains('name="id_accion_origen" value="' . (int)$a1['id_accion'] . '"') && $r->contains('Acción correctiva #' . (int)$a1['id_accion']));
$tec->post($M, ['programar' => '1', 'id_sala' => $s1, 'tipo' => 'OTRO', 'alcance_equipo' => 'TODOS', 'descripcion' => 'ZT-ciclo-accion',
    'fecha_programada' => $jueves . 'T14:00', 'id_accion_origen' => (int)$a1['id_accion']]);
$mA = $idMantDe('ZT-ciclo-accion');
t('Acciones: al programarla queda PROGRAMADA y ligada al mantenimiento', $mA > 0 && $filaC("SELECT estado, id_programado FROM mantenimiento_acciones WHERE id_accion = ?", [(int)$a1['id_accion']]) == ['estado' => 'PROGRAMADA', 'id_programado' => $mA]);
$hacerEquipos($mA);
$cerrar($tec, $mA);
t('Acciones: al realizar el mantenimiento la acción queda HECHA', $q1("SELECT estado FROM mantenimiento_acciones WHERE id_accion = ?", [(int)$a1['id_accion']]) === 'HECHA');
$r = $tec->get('mantenimiento_campanas.php?desde_accion=' . (int)$a2['id_accion']);
t('Acciones → Crear campaña: formulario de campaña abierto con la acción', $r->contains('name="id_accion_origen" value="' . (int)$a2['id_accion'] . '"') && $r->contains('Estandarizar software'));
$tec->post($H . '?vista=acciones', ['estado_accion' => 'DESCARTADA', 'id_accion' => (int)$a2['id_accion']], "$H?vista=acciones");
t('Acciones: se puede descartar una pendiente', $q1("SELECT estado FROM mantenimiento_acciones WHERE id_accion = ?", [(int)$a2['id_accion']]) === 'DESCARTADA');

// Bloque de la sala (filtro del historial) se edita en «Salas y QR».
$tec->post('gestionar_salas.php', ['editar_abreviatura' => '1', 'id_sala' => $s0, 'abreviatura' => 'ZC0', 'id_unidad' => '', 'bloque' => 'ZT Bloque nuevo']);
t('Salas: el bloque se guarda desde «Editar sala»', $q1("SELECT bloque FROM salas WHERE id_sala = ?", [$s0]) === 'ZT Bloque nuevo');

// ───────────────────────── 4. Conexión con Horarios
$viernes = (clone $lunesSig)->modify('+4 days')->format('Y-m-d');
$tec->post($M, ['programar' => '1', 'id_sala' => $s2, 'tipo' => 'MANTENIMIENTO_PREVENTIVO', 'alcance_equipo' => 'TODOS', 'descripcion' => 'ZT-ciclo-aviso-prest',
    'fecha_programada' => $viernes . 'T07:00', 'id_responsable' => $idU('tecnico')]);
$tec->post($M, ['programar' => '1', 'id_sala' => $s3, 'tipo' => 'MANTENIMIENTO_PREVENTIVO', 'alcance_equipo' => 'TODOS', 'descripcion' => 'ZT-ciclo-aviso-hor',
    'fecha_programada' => $viernes . 'T07:00']); // sin coordinador: el aviso le llega a quien lo programó
$avisos = fn(string $patron) => (int)$q1("SELECT COUNT(*) FROM notificaciones WHERE id_usuario = ? AND mensaje LIKE ?", [$idU('tecnico'), $patron]);
$profC->post('create_ticket_profesor.php', ['tipo_ticket' => 'requerimiento', 'subtipo_requerimiento' => 'PRESTAMO_SALA', 'motivo_prestamo' => 'ZT-prest-aviso',
    'cantidad_alumnos' => 10, 'para_que' => 'ZT', 'id_sala_prestamo' => $s2, 'fecha_requerida' => $viernes, 'duracion_prestamo' => 'TODO_DIA', 'fecha_devolucion_prevista' => $viernes]);
t('Horarios ↔ mantenimiento: un préstamo nuevo que se cruza avisa al coordinador', $avisos('Nuevo préstamo%ahora se cruza%') >= 1);
$adm->post('horarios.php', ['crear_horario' => '1', 'id_sala' => $s3, 'tipo' => 'CLASE', 'periodo' => $periodoAct, 'dia_semana' => 5, 'hora_inicio' => '07:00', 'hora_fin' => '09:00', 'detalle' => 'ZT-clase-aviso']);
t('Horarios ↔ mantenimiento: una clase nueva encima del mantenimiento avisa al coordinador', $avisos('Nueva franja en el horario%ahora se cruza%') >= 1);
$r = $tec->get('horarios.php?semana=' . $lunesSig->format('Y-m-d') . '&sala=' . $s1);
t('Horarios: el técnico puede programar un mantenimiento desde una celda vacía', $r->contains('id="pc-link-mant"') && $r->contains('data-fecha="' . $martes . '"'));
t('Horarios: el admin no ve ese atajo (programa el técnico)', !$adm->get('horarios.php?semana=' . $lunesSig->format('Y-m-d'))->contains('id="pc-link-mant"'));
$r = $tec->get("$M?nuevo=1&sala=$s1&fecha=" . urlencode($jueves . 'T15:00'));
t('Horarios → Programar: el formulario abre con la sala, el día y la hora de la celda', $r->contains('data-vf-abierta') && $r->contains('value="' . $jueves . 'T15:00"') && $r->contains('value="' . $s1 . '" selected'));

// Sin errores de servidor en las páginas nuevas para ningún rol.
$sin500n = true;
foreach (['mantenimiento_campanas.php', "mantenimiento_campanas.php?id=$c1", 'historial_mantenimiento.php', 'historial_mantenimiento.php?vista=acciones', $M] as $pag) {
    foreach ($C as $rolC => $c) { if ($c->get($pag)->status >= 500) { $sin500n = false; echo "   500 en $pag ($rolC)\n"; } }
}
t('Páginas nuevas: ninguna responde 5xx', $sin500n);
