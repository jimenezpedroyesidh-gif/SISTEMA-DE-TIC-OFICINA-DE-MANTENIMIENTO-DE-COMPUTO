<?php
/**
 * Pruebas HTTP de AJAX, descargas y PDF: buscar_global, buscar_componente_ajax, listar_equipos_ajax,
 * listar_modelos_ajax, notificacion_marcar_leida, descargar_adjunto, ticket_pdf y ticket_profesor_pdf
 * (seguimiento_horas_pdf ya está en modulo_becas.php). Se incluye desde run_http_tests.php (aporta $pdo, $U,
 * $C, $adm, $tec, $anon, $base, t() y seccion()).
 */
seccion('AJAX, descargas y PDF: buscadores, listados, notificaciones, adjuntos, ticket_pdf, ticket_profesor_pdf');

$ce = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); })();
$fe = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(PDO::FETCH_ASSOC); })();
$ue = fn(string $r) => (int)$U[$r]['id'];
$mkT = function (int $uid, string $dep, string $desc = 'ZT-EP ticket', ?int $tecnico = null) use ($pdo) {
    $pdo->prepare("INSERT INTO tickets (user_id, tecnico_id, origen, tipo_solicitud, descripcion, estado, nombre_dependencia, ubicacion, equipo) VALUES (?, ?, 'PANEL_INTERNO', 'INCIDENTE', ?, 'En espera', ?, 'ZT', 'ZT-EP-EQ')")->execute([$uid, $tecnico, $desc, $dep]);
    return (int)$pdo->lastInsertId();
};
$mkTP = function (int $uid, string $sala, string $tipo = 'danio', ?string $sub = null) use ($pdo) {
    $pdo->prepare("INSERT INTO tickets_profesor (profesor_id, tipo_ticket, subtipo_requerimiento, sala_danio, sala_requerimiento, descripcion_problema, motivo, programa, estado) VALUES (?, ?, ?, ?, ?, 'ZT-EP daño prof', 'ZT-EP motivo', 'ZT-EP programa', 'En espera')")
        ->execute([$uid, $tipo, $sub, $tipo === 'danio' ? $sala : null, $tipo === 'danio' ? null : $sala]);
    return (int)$pdo->lastInsertId();
};
$tSol = $mkT($ue('solicitante'), 'ZT-EP-DEP-SOL');
$tOtro = $mkT($ue('otro'), 'ZT-EP-DEP-OTRO');
$tBeca = $mkT($ue('beca_trabajo'), 'ZT-EP-DEP-BECA');
$pdo->prepare("INSERT INTO tickets (user_id, origen, tipo_solicitud, descripcion, estado, nombre_dependencia, ubicacion, id_sala, codigo_seguimiento) VALUES (NULL, 'QR_ANONIMO', 'INCIDENTE', 'ZT-EP qr anonimo', 'En espera', NULL, 'ZT-SALA', ?, 'ZTEPQR01')")->execute([$idSala]);
$tQr = (int)$pdo->lastInsertId();
$tpProf = $mkTP($ue('profesor'), 'ZT-EP-SALA-PROF');
$tpReq = $mkTP($ue('profesor'), 'ZT-EP-SALA-REQ', 'requerimiento', 'PRESTAMO_SALA');
for ($i = 0; $i < 10; $i++) $mkT($ue('solicitante'), "ZT-EP-MUCHOS-$i");
$pdo->prepare("INSERT INTO componentes (tipo, serial, codigo_inventario, estado, id_equipo_actual, marca) VALUES ('mouse', 'ZT-EP-LIBRE', 'ZT-EP-LIBRE', 'ACTIVO', NULL, 'ZTMarcaEP')")->execute();
$cLibreEp = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO componentes (tipo, serial, codigo_inventario, estado, id_equipo_actual, marca) VALUES ('teclado', 'ZT-EP-INST', 'ZT-EP-INST', 'ACTIVO', ?, 'ZTMarcaEP')")->execute([$idEquipo]);
$cInstEp = (int)$pdo->lastInsertId();

// ── buscar_global.php
$r = $anon->get('buscar_global.php?q=ZT-EP');
t('buscar_global: sin sesión → 403 JSON {"error":"No autorizado"}', $r->status === 403 && $r->json() === ['error' => 'No autorizado'] && stripos($r->header('content-type'), 'application/json') === 0);
$r = $adm->get('buscar_global.php?q=z');
t('buscar_global: menos de 2 caracteres → listas vacías', $r->json() === ['tickets' => [], 'usuarios' => [], 'componentes' => []]);
$j = $C['solicitante']->get('buscar_global.php?q=' . urlencode('ZT-EP-DEP'))->json();
t('buscar_global (solicitante): solo encuentra sus propios tickets, con url de PDF y etiqueta', count($j['tickets']) === 1 && $j['tickets'][0]['id'] === $tSol && $j['tickets'][0]['tipo'] === 'normal' && $j['tickets'][0]['etiqueta'] === "#$tSol — ZT-EP-DEP-SOL" && $j['tickets'][0]['estado'] === 'En espera' && $j['tickets'][0]['url'] === "ticket_pdf.php?id=$tSol" && array_keys($j['tickets'][0]) === ['id', 'tipo', 'etiqueta', 'estado', 'url']);
t('buscar_global (solicitante): sin usuarios, sin componentes y sin tickets de profesor', $j['usuarios'] === [] && $j['componentes'] === [] && !in_array('profesor', array_column($j['tickets'], 'tipo'), true));
$j = $adm->get('buscar_global.php?q=' . urlencode('ZT-EP-DEP'))->json();
$ids = array_column($j['tickets'], 'id');
t('buscar_global (admin): ve tickets de todos', in_array($tSol, $ids, true) && in_array($tOtro, $ids, true));
$j = $adm->get('buscar_global.php?q=' . urlencode('ZT-EP-SALA'))->json();
t('buscar_global (admin): encuentra tickets de profesor con url a su PDF', count($j['tickets']) === 2 && $j['tickets'][0]['tipo'] === 'profesor' && strpos($j['tickets'][0]['url'], 'ticket_profesor_pdf.php?id=') === 0);
$j = $adm->get('buscar_global.php?q=' . $tSol)->json();
t('buscar_global: una consulta numérica busca por id', count(array_filter($j['tickets'], fn($x) => $x['tipo'] === 'normal' && $x['id'] === $tSol)) === 1);
$j = $adm->get('buscar_global.php?q=' . urlencode('ZT-EP-MUCHOS'))->json();
t('buscar_global: máximo 8 tickets', count($j['tickets']) === 8);
$j = $C['profesor']->get('buscar_global.php?q=' . urlencode('ZT-EP-SALA'))->json();
t('buscar_global (profesor): solo tickets de profesor propios', count($j['tickets']) === 2 && array_unique(array_column($j['tickets'], 'tipo')) === ['profesor']);
$j = $C['otro']->get('buscar_global.php?q=' . urlencode('ZT-EP-SALA'))->json();
t('buscar_global (otro solicitante): no ve tickets de profesor', $j['tickets'] === []);
$j = $adm->get('buscar_global.php?q=' . urlencode('ZT solicitante'))->json();
t('buscar_global (admin): busca usuarios por nombre con etiqueta y rol', count(array_filter($j['usuarios'], fn($u) => $u['rol'] === 'solicitante' && strpos($u['etiqueta'], 'ZT solicitante') === 0 && $u['id'] === $ue('solicitante'))) === 1);
t('buscar_global (técnico y secretaría) también ven usuarios; beca y profesor no', count($tec->get('buscar_global.php?q=' . urlencode('ZT solicitante'))->json()['usuarios']) >= 1 && count($C['secretaria']->get('buscar_global.php?q=' . urlencode('ZT solicitante'))->json()['usuarios']) >= 1
    && $C['beca_trabajo']->get('buscar_global.php?q=' . urlencode('ZT solicitante'))->json()['usuarios'] === [] && $C['profesor']->get('buscar_global.php?q=' . urlencode('ZT solicitante'))->json()['usuarios'] === []);
$j = $tec->get('buscar_global.php?q=' . urlencode('ZT-EP-LIBRE'))->json();
t('buscar_global (técnico): encuentra componentes con url a su ficha', count($j['componentes']) === 1 && $j['componentes'][0]['url'] === "ficha_componente.php?id=$cLibreEp" && strpos($j['componentes'][0]['etiqueta'], 'ZT-EP-LIBRE — Mouse') === 0);
t('buscar_global: solicitante/profesor/secretaría no ven componentes', $C['solicitante']->get('buscar_global.php?q=ZT-EP-LIBRE')->json()['componentes'] === [] && $C['secretaria']->get('buscar_global.php?q=ZT-EP-LIBRE')->json()['componentes'] === []);
$j = $C['beca_trabajo']->get('buscar_global.php?q=' . urlencode('ZT-EP-DEP-BECA'))->json();
t('buscar_global (beca): ve su ticket y la url apunta a su pantalla, no al PDF', count($j['tickets']) === 1 && $j['tickets'][0]['url'] === 'tickets.php');
$j = $C['beca_trabajo']->get('buscar_global.php?q=' . urlencode('ZT-EP-DEP-OTRO'))->json();
t('buscar_global (beca): no ve tickets que no le corresponden', $j['tickets'] === []);
$j = $adm->get('buscar_global.php?q=' . urlencode("ZT-EP' OR '1'='1"))->json();
t('buscar_global: comillas en la consulta no rompen ni devuelven todo', is_array($j) && $j['tickets'] === []);
$j = $adm->get('buscar_global.php?q=' . urlencode('%%'))->json();
t('buscar_global: devuelve JSON válido con comodines', is_array($j) && isset($j['tickets']));

// ── buscar_componente_ajax.php
t('buscar_componente_ajax: sin sesión y roles sin inventario → 403 JSON', $anon->get('buscar_componente_ajax.php?q=ZT-EP')->status === 403 && $C['solicitante']->get('buscar_componente_ajax.php?q=ZT-EP')->status === 403 && $C['profesor']->get('buscar_componente_ajax.php?q=ZT-EP')->status === 403 && $C['secretaria']->get('buscar_componente_ajax.php?q=ZT-EP')->status === 403);
t('buscar_componente_ajax: consulta corta → []', $tec->get('buscar_componente_ajax.php?q=z')->json() === [] && $tec->get('buscar_componente_ajax.php')->json() === []);
$j = $tec->get('buscar_componente_ajax.php?q=' . urlencode('ZT-EP-LIBRE'))->json();
t('buscar_componente_ajax: resultado con todas las claves y ubicación "En bodega"', count($j) === 1 && array_keys($j[0]) === ['id', 'tipo', 'serial', 'codigo_inventario', 'marca', 'modelo', 'estado', 'ubicacion', 'etiqueta'] && $j[0]['id'] === $cLibreEp && $j[0]['ubicacion'] === 'En bodega' && $j[0]['etiqueta'] === 'ZT-EP-LIBRE — Mouse ZTMarcaEP');
$j = $tec->get('buscar_componente_ajax.php?q=' . urlencode('ZT-EP-INST'))->json();
t('buscar_componente_ajax: pieza instalada muestra "sala — equipo"', count($j) === 1 && $j[0]['ubicacion'] === 'ZT-SALA — ZT-EQ1');
$j = $tec->get('buscar_componente_ajax.php?q=' . urlencode('ZT-EP') . '&disponibles=1')->json();
t('buscar_componente_ajax: disponibles=1 excluye las piezas ya instaladas', in_array($cLibreEp, array_column($j, 'id'), true) && !in_array($cInstEp, array_column($j, 'id'), true));
$j = $C['beca_trabajo']->get('buscar_componente_ajax.php?id=' . $cInstEp)->json();
t('buscar_componente_ajax: ?id= devuelve esa pieza (beca también puede)', count($j) === 1 && $j[0]['id'] === $cInstEp);
t('buscar_componente_ajax: ?id= inexistente → []', $tec->get('buscar_componente_ajax.php?id=999999999')->json() === []);
$j = $adm->get('buscar_componente_ajax.php?q=' . urlencode("x' OR '1'='1"))->json();
t('buscar_componente_ajax: comillas no devuelven todo el inventario', is_array($j) && count($j) === 0);

// ── listar_equipos_ajax.php
t('listar_equipos_ajax: sin sesión → 403 con []', $anon->get('listar_equipos_ajax.php?id_sala=' . $idSala)->status === 403 && $anon->get('listar_equipos_ajax.php?id_sala=' . $idSala)->json() === []);
$j = $C['solicitante']->get('listar_equipos_ajax.php?id_sala=' . $idSala)->json();
t('listar_equipos_ajax: cualquier rol con sesión lista los equipos de la sala (id, codigo, descripcion, es_computo)', count($j) >= 1 && array_keys($j[0]) === ['id_equipo', 'codigo', 'descripcion', 'es_computo'] && $j[0]['codigo'] === 'ZT-EQ1');
t('listar_equipos_ajax: sala 0, vacía o inválida → []', $tec->get('listar_equipos_ajax.php')->json() === [] && $tec->get('listar_equipos_ajax.php?id_sala=abc')->json() === [] && $tec->get('listar_equipos_ajax.php?id_sala=999999999')->json() === []);

// ── listar_modelos_ajax.php
$pdo->exec("INSERT INTO fabricantes (nombre) VALUES ('ZTFab-EP')"); $idFab = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO modelos_activo (id_fabricante, nombre) VALUES (?, 'ZTMod-EP-B')")->execute([$idFab]);
$pdo->prepare("INSERT INTO modelos_activo (id_fabricante, nombre) VALUES (?, 'ZTMod-EP-A')")->execute([$idFab]);
t('listar_modelos_ajax: solo admin/técnico (otros → 403 con [])', $C['beca_trabajo']->get('listar_modelos_ajax.php?id_fabricante=' . $idFab)->status === 403 && $C['solicitante']->get('listar_modelos_ajax.php?id_fabricante=' . $idFab)->json() === [] && $anon->get('listar_modelos_ajax.php?id_fabricante=' . $idFab)->status === 403);
$j = $tec->get('listar_modelos_ajax.php?id_fabricante=' . $idFab)->json();
t('listar_modelos_ajax: modelos del fabricante ordenados por nombre (id_modelo, nombre)', count($j) === 2 && $j[0]['nombre'] === 'ZTMod-EP-A' && $j[1]['nombre'] === 'ZTMod-EP-B' && array_keys($j[0]) === ['id_modelo', 'nombre']);
t('listar_modelos_ajax: fabricante 0/inválido → []', $adm->get('listar_modelos_ajax.php')->json() === [] && $adm->get('listar_modelos_ajax.php?id_fabricante=x')->json() === []);

// ── notificacion_marcar_leida.php
$mkN = fn(int $uid) => (function () use ($pdo, $uid) { $pdo->prepare("INSERT INTO notificaciones (id_usuario, tipo, mensaje) VALUES (?, 'SISTEMA', 'ZT-EP notif')")->execute([$uid]); return (int)$pdo->lastInsertId(); })();
$leida = fn(int $id) => (int)$pdo->query("SELECT leido FROM notificaciones WHERE id_notificacion=$id")->fetchColumn();
$n1 = $mkN($ue('solicitante')); $n2 = $mkN($ue('solicitante')); $n3 = $mkN($ue('solicitante')); $nAdm = $mkN($ue('admin'));
$sol = $C['solicitante'];
$r = $anon->postRaw('notificacion_marcar_leida.php', ['id_notificacion' => $n1]);
t('notificacion_marcar_leida: sin sesión → 403 JSON', $r->status === 403 && $r->json() === ['error' => 'No autorizado'] && $leida($n1) === 0);
$r = $sol->get('notificacion_marcar_leida.php');
t('notificacion_marcar_leida: GET → 405 JSON "Método no permitido"', $r->status === 405 && $r->json() === ['error' => 'Método no permitido']);
t('notificacion_marcar_leida: POST sin CSRF → 403 y no marca', $sol->postRaw('notificacion_marcar_leida.php', ['id_notificacion' => $n1])->status === 403 && $leida($n1) === 0);
$r = $sol->post('notificacion_marcar_leida.php', ['id_notificacion' => $n1], 'tickets.php');
t('notificacion_marcar_leida: marca la propia → {"ok":true}', $r->json() === ['ok' => true] && $leida($n1) === 1);
$r = $sol->post('notificacion_marcar_leida.php', ['id_notificacion' => $nAdm], 'tickets.php');
t('notificacion_marcar_leida: la de otro usuario → {"ok":false} y sin cambio (IDOR)', $r->json() === ['ok' => false] && $leida($nAdm) === 0);
$r = $sol->post('notificacion_marcar_leida.php', ['id_notificacion' => 0], 'tickets.php');
t('notificacion_marcar_leida: sin id → 400 "id_notificacion requerido"', $r->status === 400 && $r->json() === ['error' => 'id_notificacion requerido']);
$r = $sol->post('notificacion_marcar_leida.php', ['marcar_todas' => '1'], 'tickets.php');
t('notificacion_marcar_leida: marcar_todas marca solo las propias', $r->json() === ['ok' => true] && $leida($n2) === 1 && $leida($n3) === 1 && $leida($nAdm) === 0);

// ── descargar_adjunto.php
$dirAd = __DIR__ . '/../../storage/adjuntos/';
@mkdir($dirAd, 0775, true);
$mkAd = function (string $tabla, int $idT, string $contenido, string $mime, string $nombreOrig, int $uid) use ($pdo, $dirAd) {
    $alm = 'zt_' . bin2hex(random_bytes(6)) . '.bin';
    file_put_contents($dirAd . $alm, $contenido);
    $pdo->prepare("INSERT INTO ticket_adjuntos (tabla_ticket, id_ticket, nombre_original, nombre_almacenado, mime_type, tamano_bytes, id_usuario_subio) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$tabla, $idT, $nombreOrig, $alm, $mime, strlen($contenido), $uid]);
    return [(int)$pdo->lastInsertId(), $alm];
};
[$adS, $almS] = $mkAd('tickets', $tSol, 'ADJ-EP-SOL', 'image/png', 'zt_ep "foto".png', $ue('solicitante'));
[$adP, $almP] = $mkAd('tickets_profesor', $tpProf, 'ADJ-EP-PROF', 'application/pdf', 'zt_ep_prof.pdf', $ue('profesor'));
[$adB, $almB] = $mkAd('tickets', $tBeca, 'ADJ-EP-BECA', 'image/jpeg', 'zt_ep_beca.jpg', $ue('beca_trabajo'));
$r = $sol->get("descargar_adjunto.php?id=$adS");
t('adjunto: el dueño recibe el contenido con tipo, longitud, nosniff y nombre saneado (inline)', $r->status === 200 && $r->body === 'ADJ-EP-SOL' && $r->header('content-type') === 'image/png' && $r->header('content-length') === '10' && $r->header('x-content-type-options') === 'nosniff' && $r->header('content-disposition') === 'inline; filename="zt_ep _foto_.png"', $r->header('content-disposition'));
t('adjunto: técnico y admin pueden', $tec->get("descargar_adjunto.php?id=$adS")->body === 'ADJ-EP-SOL' && $adm->get("descargar_adjunto.php?id=$adS")->body === 'ADJ-EP-SOL');
$r = $C['otro']->get("descargar_adjunto.php?id=$adS");
t('adjunto: otro solicitante → 403 "No tienes acceso a este archivo."', $r->status === 403 && $r->body === 'No tienes acceso a este archivo.');
t('adjunto: el profesor dueño del ticket de profesor lo descarga; otro profesor/solicitante no', $C['profesor']->get("descargar_adjunto.php?id=$adP")->body === 'ADJ-EP-PROF' && $sol->get("descargar_adjunto.php?id=$adP")->status === 403);
t('adjunto: la beca ve adjuntos de tickets que le corresponden y no los de otros', $C['beca_trabajo']->get("descargar_adjunto.php?id=$adB")->body === 'ADJ-EP-BECA' && $C['beca_trabajo']->get("descargar_adjunto.php?id=$adS")->status === 403);
t('adjunto: sin sesión no entrega nada', !$anon->get("descargar_adjunto.php?id=$adS")->contains('ADJ-EP-SOL'));
$r = $tec->get('descargar_adjunto.php');
t('adjunto: sin id → 404 "Adjunto no encontrado."', $r->status === 404 && $r->body === 'Adjunto no encontrado.' && $tec->get('descargar_adjunto.php?id=abc')->status === 404 && $tec->get('descargar_adjunto.php?id=999999999')->status === 404);
unlink($dirAd . $almS);
$r = $tec->get("descargar_adjunto.php?id=$adS");
t('adjunto: archivo borrado del disco → 404 "El archivo ya no está disponible."', $r->status === 404 && $r->body === 'El archivo ya no está disponible.');

// ── ticket_pdf.php / ticket_profesor_pdf.php
$esPdf = fn($r) => strncmp($r->body, '%PDF', 4) === 0;
$r = $sol->get("ticket_pdf.php?id=$tSol");
t('ticket_pdf: el dueño recibe un PDF con tipo y nombre Ticket_<id>.pdf', $esPdf($r) && $r->header('content-type') === 'application/pdf' && strpos($r->header('content-disposition'), "Ticket_$tSol.pdf") !== false, $r->header('content-disposition'));
t('ticket_pdf: técnico y admin ven cualquiera (incluido un reporte QR anónimo)', $esPdf($tec->get("ticket_pdf.php?id=$tOtro")) && $esPdf($adm->get("ticket_pdf.php?id=$tQr")));
$r = $C['otro']->get("ticket_pdf.php?id=$tSol");
t('ticket_pdf: otro solicitante → 403 "No tienes permiso para ver este ticket."', $r->status === 403 && $r->body === 'No tienes permiso para ver este ticket.');
t('ticket_pdf: un reporte QR anónimo no lo abre un solicitante', $sol->get("ticket_pdf.php?id=$tQr")->status === 403);
$r = $C['beca_trabajo']->get("ticket_pdf.php?id=$tBeca");
t('ticket_pdf: la beca → 403 "Los becas de trabajo no tienen acceso a los PDF de tickets."', $r->status === 403 && $r->body === 'Los becas de trabajo no tienen acceso a los PDF de tickets.');
t('ticket_pdf: sin sesión → "Acceso denegado"', $anon->get("ticket_pdf.php?id=$tSol")->body === 'Acceso denegado');
t('ticket_pdf: sin id/inválido → "ID de ticket no especificado"; inexistente → "Ticket no encontrado"', $adm->get('ticket_pdf.php')->body === 'ID de ticket no especificado' && $adm->get('ticket_pdf.php?id=abc')->body === 'ID de ticket no especificado' && $adm->get('ticket_pdf.php?id=999999999')->body === 'Ticket no encontrado');
$r = $C['profesor']->get("ticket_profesor_pdf.php?id=$tpProf");
t('ticket_profesor_pdf: el profesor dueño recibe un PDF Ticket_Profesor_<id>.pdf', $esPdf($r) && strpos($r->header('content-disposition'), "Ticket_Profesor_$tpProf.pdf") !== false, $r->header('content-disposition'));
t('ticket_profesor_pdf: requerimiento (préstamo de sala) también', $esPdf($C['profesor']->get("ticket_profesor_pdf.php?id=$tpReq")) && $esPdf($adm->get("ticket_profesor_pdf.php?id=$tpReq")) && $esPdf($tec->get("ticket_profesor_pdf.php?id=$tpProf")));
$r = $sol->get("ticket_profesor_pdf.php?id=$tpProf");
t('ticket_profesor_pdf: otro rol/usuario → 403 "No tienes permiso para ver este ticket."', $r->status === 403 && $r->body === 'No tienes permiso para ver este ticket.');
t('ticket_profesor_pdf: beca → 403, sin sesión → "Acceso denegado", sin id → mensaje, inexistente → "Ticket no encontrado"', $C['beca_trabajo']->get("ticket_profesor_pdf.php?id=$tpProf")->status === 403 && $anon->get("ticket_profesor_pdf.php?id=$tpProf")->body === 'Acceso denegado' && $adm->get('ticket_profesor_pdf.php')->body === 'ID de ticket no especificado' && $adm->get('ticket_profesor_pdf.php?id=999999999')->body === 'Ticket no encontrado');
$pdo->prepare("UPDATE tickets SET firmado=1, fecha_terminacion=NOW(), tecnico_id=?, descripcion=?, solucion='ZT-EP ñandú' WHERE id=?")->execute([$ue('tecnico'), 'ZT-EP descripción con tilde áéí ' . str_repeat('larga ', 200), $tSol]);
t('ticket_pdf: ticket firmado, con tildes y descripción larga (varias páginas) genera PDF', $esPdf($tec->get("ticket_pdf.php?id=$tSol")));
$pdo->prepare("INSERT INTO ticket_activos (tabla_ticket, id_ticket, id_equipo) VALUES ('tickets', ?, ?)")->execute([$tSol, $idEquipo]);
t('ticket_pdf: con equipos afectados genera PDF', $esPdf($tec->get("ticket_pdf.php?id=$tSol")));

// ── Correcciones
$j = $adm->get('buscar_global.php?q=' . urlencode('ZT-EP-DEP-SOL') . '&x=' . str_repeat('A', 3000));
t('P42 buscar_global: sigue respondiendo JSON con parámetros extra enormes', $j->json() !== null);
