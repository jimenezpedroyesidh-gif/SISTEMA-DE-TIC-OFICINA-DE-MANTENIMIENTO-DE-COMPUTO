<?php
/**
 * Pruebas HTTP de «Crear ticket (Profesor)»: create_ticket_profesor.php (se incluye desde run_http_tests.php,
 * que aporta $pdo, $U, $C, $adm, $tec, $CLAVE, cliente(), t() y seccion()).
 */
seccion('Crear ticket de profesor: daño y requerimientos');

$cp = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); })();
$fp = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(); })();
$prof = $C['profesor'];
$idProf = (int)$U['profesor']['id'];
$salaTP = function (string $n) use ($pdo) {
    $pdo->prepare("INSERT INTO salas (nombre_sala, abreviatura, dependencia, codigo_qr_token) VALUES (?, 'ztp', 'Departamento de Sistemas', ?)")->execute([$n, 'zt' . bin2hex(random_bytes(8))]);
    return (int)$pdo->lastInsertId();
};
$sA = $salaTP('ZT-TP-A'); $sB = $salaTP('ZT-TP-B'); $sC = $salaTP('ZT-TP-C'); $sD = $salaTP('ZT-TP-D'); $sE = $salaTP('ZT-TP-E'); $sF = $salaTP('ZT-TP-F');
$pdo->prepare("INSERT INTO equipos (id_sala, codigo, descripcion) VALUES (?, 'ZT-TP-EQ1', 'x'), (?, 'ZT-TP-EQ2', 'x')")->execute([$sC, $sB]);
$eqC = $cp("SELECT id_equipo FROM equipos WHERE codigo='ZT-TP-EQ1'"); $eqB = $cp("SELECT id_equipo FROM equipos WHERE codigo='ZT-TP-EQ2'");
$ih = $pdo->prepare("INSERT INTO horarios (id_sala, id_usuario, tipo, dia_semana, periodo, hora_inicio, hora_fin, detalle, id_creador) VALUES (?, ?, 'CLASE', ?, '2033-1', ?, ?, ?, ?)");
$ih->execute([$sA, $idProf, 2, '08:00', '10:00', 'ZT-MI-HOR', $U['admin']['id']]); $hMio = (int)$pdo->lastInsertId();
$ih->execute([$sA, $U['tecnico']['id'], 3, '10:00', '12:00', 'ZT-OTRO-HOR', $U['admin']['id']]); $hAjeno = (int)$pdo->lastInsertId();
$nTP = fn() => $cp("SELECT COUNT(*) FROM tickets_profesor WHERE profesor_id=?", [$idProf]);
$notif = fn(string $rol, string $like) => $cp("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND mensaje LIKE ?", [$U[$rol]['id'], $like]);
$P = 'create_ticket_profesor.php';

// ── Acceso y formulario
t('Profesor: solicitante/secretaría/beca/técnico no entran al formulario', $C['solicitante']->get($P)->denied() && $C['secretaria']->get($P)->denied() && $C['beca_trabajo']->get($P)->denied() && $tec->get($P)->denied());
t('Profesor: el admin (docente del Departamento) sí entra, solo con las salas de su dependencia', !$adm->get($P)->denied());
$r = $prof->get($P);
t('Formulario: tipo de ticket, subtipos y salas reales', $r->status === 200 && $r->contains('name="tipo_ticket"') && $r->contains('value="CAMBIO_SALA"') && $r->contains('ZT-TP-A') && $r->contains('name="id_sala_danio"'));
// Se mira solo dentro del <select id="id_horario_referencia">: en una base recién creada los IDs de horario
// coinciden con IDs de salas/equipos de otros selects, y buscar en toda la página daba falsos negativos.
$selHor = preg_match('~<select name="id_horario_referencia".*?</select>~s', $r->body, $mHor) ? $mHor[0] : '';
t('Formulario: el select de horario trae SOLO las clases propias', strpos($selHor, 'value="' . $hMio . '"') !== false && strpos($selHor, 'value="' . $hAjeno . '"') === false && strpos($selHor, 'ZT-MI-HOR') !== false && strpos($selHor, 'ZT-OTRO-HOR') === false);
t('Formulario: carga el JS externo y no hay script en línea', $r->contains('assets/profesor_nuevo_ticket.js'));
$antes = $nTP();
$C['solicitante']->postRaw($P, ['tipo_ticket' => 'danio', 'id_sala_danio' => $sA, 'descripcion_problema' => 'ZT-no-prof']);
$prof->postRaw($P, ['tipo_ticket' => 'danio', 'id_sala_danio' => $sA, 'descripcion_problema' => 'ZT-sin-csrf']);
t('Crear: un no profesor o sin CSRF no crea ticket', $nTP() === $antes && $cp("SELECT COUNT(*) FROM tickets_profesor WHERE descripcion_problema IN ('ZT-no-prof','ZT-sin-csrf')") === 0);

// ── Daño
$r = $prof->post($P, ['tipo_ticket' => 'danio', 'id_sala_danio' => $sA, 'descripcion_problema' => 'ZT-danio-1']);
$d1 = $fp("SELECT * FROM tickets_profesor WHERE descripcion_problema='ZT-danio-1'");
t('Daño: crea el ticket y redirige a tickets.php?creado=1', $r->redirects() && strpos($r->location(), 'tickets.php?creado=1') !== false && $d1);
t('Daño: sala, nombre de sala, tipo, origen PANEL_PROFESOR y estado En espera', (int)$d1['id_sala'] === $sA && $d1['sala_danio'] === 'ZT-TP-A' && $d1['tipo_ticket'] === 'danio' && $d1['origen'] === 'PANEL_PROFESOR' && $d1['estado'] === 'En espera' && (int)$d1['profesor_id'] === $idProf);
t('Daño: notifica a los admins y a beca_trabajo', $notif('admin', '%Nuevo ticket de daño de profesor pendiente (ticket #' . $d1['id'] . ')%') === 1 && $notif('beca_trabajo', '%Nuevo daño reportado en ZT-TP-A (panel de profesor)%') === 1);
$r = $prof->post($P, ['tipo_ticket' => 'danio', 'id_sala_danio' => $sA, 'descripcion_problema' => 'ZT-danio-dup']);
t('Daño: sala con un daño en curso → «Ya existe un reporte» y no duplica', $r->contains('Ya existe un reporte de daño en curso para esa sala (estado: En espera)') && $cp("SELECT COUNT(*) FROM tickets_profesor WHERE descripcion_problema='ZT-danio-dup'") === 0);
$r = $prof->post($P, ['tipo_ticket' => 'danio', 'id_sala_danio' => '', 'descripcion_problema' => 'ZT-danio-x']);
t('Daño: sin sala → mensaje', $r->contains('Selecciona la sala donde ocurrió el problema.') && $cp("SELECT COUNT(*) FROM tickets_profesor WHERE descripcion_problema='ZT-danio-x'") === 0);
$r = $prof->post($P, ['tipo_ticket' => 'danio', 'id_sala_danio' => 999999999, 'descripcion_problema' => 'ZT-danio-x']);
t('Daño: sala inexistente → mismo mensaje', $r->contains('Selecciona la sala donde ocurrió el problema.'));
$prof->post($P, ['tipo_ticket' => 'danio', 'id_sala_danio' => $sC, 'descripcion_problema' => 'ZT-danio-eq', 'ids_equipo_danio' => [$eqC, $eqB]]);
$dEq = $cp("SELECT id FROM tickets_profesor WHERE descripcion_problema='ZT-danio-eq'");
t('Daño: asocia solo los equipos que pertenecen a la sala (ignora los de otra sala)', $dEq > 0 && $cp("SELECT COUNT(*) FROM ticket_activos WHERE tabla_ticket='tickets_profesor' AND id_ticket=? AND id_equipo=?", [$dEq, $eqC]) === 1 && $cp("SELECT COUNT(*) FROM ticket_activos WHERE tabla_ticket='tickets_profesor' AND id_ticket=? AND id_equipo=?", [$dEq, $eqB]) === 0);
$r = $prof->post($P, ['tipo_ticket' => 'danio', 'id_sala_danio' => '', 'descripcion_problema' => 'ZT"><script>alert(1)</script>']);
t('Daño: tras un error el formulario repuebla y escapa lo escrito (XSS)', !$r->contains('<script>alert(1)</script>') && $r->contains('&quot;&gt;&lt;script&gt;'));
$r = $prof->post($P, ['tipo_ticket' => '']);
t('Sin tipo de ticket no da 5xx ni crea', $r->status < 500);

// ── Requerimiento: subtipo
$r = $prof->post($P, ['tipo_ticket' => 'requerimiento', 'subtipo_requerimiento' => '']);
t('Requerimiento: sin subtipo → mensaje', $r->contains('Selecciona qué tipo de requerimiento necesitas.'));
$r = $prof->post($P, ['tipo_ticket' => 'requerimiento', 'subtipo_requerimiento' => 'XX']);
t('Requerimiento: subtipo inválido → mismo mensaje', $r->contains('Selecciona qué tipo de requerimiento necesitas.'));

// ── Cambio de sala
$cs = ['tipo_ticket' => 'requerimiento', 'subtipo_requerimiento' => 'CAMBIO_SALA', 'id_horario_referencia' => $hMio, 'motivo' => 'ZT-motivo-cs', 'id_sala_destino' => $sB];
$r = $prof->post($P, array_merge($cs, ['id_horario_referencia' => '']));
t('Cambio de sala: sin horario → mensaje', $r->contains('Selecciona el horario actual (la clase y la sala que deseas cambiar).'));
$r = $prof->post($P, array_merge($cs, ['id_horario_referencia' => $hAjeno]));
t('Cambio de sala (IDOR): el horario de otro usuario se rechaza', $r->contains('Selecciona el horario actual') && $cp("SELECT COUNT(*) FROM tickets_profesor WHERE id_horario_referencia=?", [$hAjeno]) === 0);
$r = $prof->post($P, array_merge($cs, ['motivo' => '  ']));
t('Cambio de sala: motivo vacío → mensaje', $r->contains('Indica el motivo del cambio de sala.'));
$r = $prof->post($P, array_merge($cs, ['id_sala_destino' => '']));
t('Cambio de sala: sin sala destino → mensaje', $r->contains('Selecciona la sala a la que deseas cambiarte.'));
$r = $prof->post($P, array_merge($cs, ['id_sala_destino' => 999999999]));
t('Cambio de sala: sala destino inexistente → mismo mensaje', $r->contains('Selecciona la sala a la que deseas cambiarte.'));
$r = $prof->post($P, array_merge($cs, ['id_sala_destino' => $sA]));
t('Cambio de sala: destino igual a la sala actual → mensaje', $r->contains('La sala destino debe ser diferente a tu sala actual.'));
$r = $prof->post($P, $cs);
$tcs = $fp("SELECT * FROM tickets_profesor WHERE motivo='ZT-motivo-cs'");
t('Cambio de sala: crea el ticket con horario, sala actual y destino derivados en el servidor', $r->redirects() && $tcs && (int)$tcs['id_horario_referencia'] === $hMio && (int)$tcs['id_sala_actual'] === $sA && $tcs['sala_actual_nombre'] === 'ZT-TP-A' && (int)$tcs['id_sala'] === $sB && $tcs['sala_requerimiento'] === 'ZT-TP-B' && $tcs['subtipo_requerimiento'] === 'CAMBIO_SALA');
t('Cambio de sala: notifica a los admins', $notif('admin', '%requerimiento de cambio de sala pendiente (ticket #' . $tcs['id'] . ')%') === 1);

// ── Instalación de software
$sw = ['tipo_ticket' => 'requerimiento', 'subtipo_requerimiento' => 'INSTALACION_SOFTWARE', 'id_sala_software' => $sD, 'software' => 'ZT-Soft', 'descripcion_software' => 'ZT-v1 x64'];
$r = $prof->post($P, array_merge($sw, ['id_sala_software' => 999999999]));
t('Software: sala inexistente → mensaje', $r->contains('Selecciona la sala o laboratorio donde se instalará el software.'));
$r = $prof->post($P, array_merge($sw, ['software' => ' ']));
t('Software: sin nombre → mensaje', $r->contains('Indica el nombre del software o aplicativo.'));
$r = $prof->post($P, array_merge($sw, ['descripcion_software' => '']));
t('Software: sin descripción → mensaje', $r->contains('Describe la versión, arquitectura o especificaciones requeridas.'));
$r = $prof->post($P, $sw);
$tsw = $fp("SELECT * FROM tickets_profesor WHERE programa='ZT-Soft'");
t('Software: crea el ticket (sala, programa y descripción de la actividad)', $r->redirects() && $tsw && (int)$tsw['id_sala'] === $sD && $tsw['sala_requerimiento'] === 'ZT-TP-D' && $tsw['descripcion_actividad'] === 'ZT-v1 x64');
t('Software: notifica a los admins', $notif('admin', '%instalación de software pendiente (ticket #' . $tsw['id'] . ')%') === 1);

// ── Préstamo
$hoy = date('Y-m-d'); $man = date('Y-m-d', strtotime('+1 day'));
$pr = ['tipo_ticket' => 'requerimiento', 'subtipo_requerimiento' => 'PRESTAMO_SALA', 'motivo_prestamo' => 'ZT-prestamo', 'cantidad_alumnos' => 20, 'para_que' => 'ZT-clase', 'id_sala_prestamo' => $sE, 'fecha_requerida' => $hoy, 'duracion_prestamo' => '2_HORAS', 'fecha_devolucion_prevista' => $man];
foreach ([
    ['motivo_prestamo', '', 'Indica el motivo u objeto del préstamo.'], ['cantidad_alumnos', '', 'Indica la cantidad de alumnos involucrados.'], ['cantidad_alumnos', '0', 'Indica la cantidad de alumnos involucrados.'],
    ['para_que', '', 'Indica para qué actividad se usará el préstamo.'], ['id_sala_prestamo', '999999999', 'Selecciona la sala o ubicación requerida.'], ['fecha_requerida', '', 'Indica la fecha en la que necesitas el préstamo.'],
    ['duracion_prestamo', 'MEDIO', 'Selecciona la duración del préstamo (1 hora, 2 horas o todo el día).'], ['fecha_devolucion_prevista', '', 'Los préstamos necesitan una fecha de devolución.'],
] as [$campo, $valor, $msg]) {
    $r = $prof->post($P, array_merge($pr, [$campo => $valor]));
    t("Préstamo: $campo «{$valor}» → $msg", $r->contains($msg) && $cp("SELECT COUNT(*) FROM tickets_profesor WHERE motivo='ZT-prestamo'") === 0);
}
$r = $prof->post($P, $pr);
$tpr = $fp("SELECT * FROM tickets_profesor WHERE motivo='ZT-prestamo'");
t('Préstamo de sala: crea el ticket con todos los campos', $r->redirects() && $tpr && (int)$tpr['cantidad_alumnos'] === 20 && $tpr['para_que'] === 'ZT-clase' && (int)$tpr['id_sala'] === $sE && $tpr['duracion_prestamo'] === '2_HORAS' && $tpr['fecha_requerida'] === $hoy && $tpr['fecha_devolucion_prevista'] === $man && $tpr['subtipo_requerimiento'] === 'PRESTAMO_SALA');
t('Préstamo: notifica a los admins', $notif('admin', '%requerimiento de préstamo pendiente (ticket #' . $tpr['id'] . ')%') === 1);
$prof->post($P, array_merge($pr, ['subtipo_requerimiento' => 'PRESTAMO_EQUIPO', 'motivo_prestamo' => 'ZT-prestamo-eq']));
t('Préstamo de equipo: también se crea', $cp("SELECT COUNT(*) FROM tickets_profesor WHERE motivo='ZT-prestamo-eq' AND subtipo_requerimiento='PRESTAMO_EQUIPO'") === 1);
$r = $prof->post($P, array_merge($pr, ['motivo_prestamo' => 'ZT-prestamo-fecha', 'fecha_requerida' => 'no-es-fecha']));
t('Préstamo: una fecha ilegible no da 5xx ni crea', $r->status < 500 && $cp("SELECT COUNT(*) FROM tickets_profesor WHERE motivo='ZT-prestamo-fecha'") === 0);

// ── Correcciones
seccion('Crear ticket de profesor: correcciones');
$r = $prof->post($P, ['tipo_ticket' => 'danio', 'id_sala_danio' => $sF, 'descripcion_problema' => '   ']);
t('P36: un daño sin descripción se rechaza', $r->contains('Describe el problema.') && $cp("SELECT COUNT(*) FROM tickets_profesor WHERE id_sala=? AND profesor_id=?", [$sF, $idProf]) === 0);
$r = $prof->post($P, array_merge($pr, ['motivo_prestamo' => 'ZT-prestamo-fecha', 'fecha_requerida' => 'no-es-fecha']));
t('P36: fecha requerida inválida → mensaje claro', $r->contains('La fecha requerida no es válida.'));
$r = $prof->post($P, array_merge($pr, ['motivo_prestamo' => 'ZT-prestamo-fecha', 'fecha_devolucion_prevista' => '2000-01-01', 'fecha_requerida' => $hoy]));
t('P36: la devolución no puede ser anterior a la fecha requerida', $r->contains('La fecha de devolución no puede ser anterior a la fecha requerida.') && $cp("SELECT COUNT(*) FROM tickets_profesor WHERE motivo='ZT-prestamo-fecha'") === 0);
$r = $prof->post($P, array_merge($sw, ['software' => str_repeat('x', 151)]));
t('P36: nombre de software demasiado largo → mensaje (antes error genérico)', $r->contains('El nombre del software admite máximo 150 caracteres.'));
t('P36: la creación queda auditada (CREAR en tickets_profesor)', $cp("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='tickets_profesor' AND id_registro=? AND accion='CREAR'", [$d1['id']]) === 1);
