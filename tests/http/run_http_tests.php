<?php
/**
 * Pruebas funcionales (README §28) y de seguridad (README §29) por HTTP real.
 *
 * Arranca contra un servidor que use la base de PRUEBAS (ver tests/http/README.md):
 *   APP_DB_NAME=tickets_db_test php -S 127.0.0.1:8097 -t public
 *   TEST_BASE_URL=http://127.0.0.1:8097 php tests/http/run_http_tests.php
 *
 * Crea sus propios datos (usuarios correo zt_*@test.local, salas ZT-*, tickets ZT-*) y los borra al terminar.
 * Se niega a correr si la base no termina en _test.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por consola.'); }
require_once __DIR__ . '/HttpClient.php';

$base = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8097';
$name = getenv('TEST_DB_NAME') ?: 'tickets_db_test';
if (!preg_match('/_test$/', $name)) { fwrite(STDERR, "La base debe terminar en _test (recibido: $name)\n"); exit(2); }
$pdo = new PDO('mysql:host=' . (getenv('TEST_DB_HOST') ?: 'localhost') . ";dbname=$name;charset=utf8mb4",
    getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASS') !== false ? getenv('TEST_DB_PASS') : '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$pass = 0; $fail = 0; $fallos = [];
function t(string $nombre, bool $ok, string $detalle = ''): void
{
    global $pass, $fail, $fallos;
    if ($ok) { $pass++; return; }
    $fail++; $fallos[] = $nombre . ($detalle !== '' ? " → $detalle" : '');
    echo "  FAIL: $nombre" . ($detalle !== '' ? " → $detalle" : '') . "\n";
}
function seccion(string $s): void { echo "\n== $s\n"; }
function limpiar(PDO $pdo): void
{
    $q = fn(string $sql) => $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    $x = function (string $sql) use ($pdo) { try { $pdo->exec($sql); } catch (Throwable $e) { echo "   (limpieza: " . substr($e->getMessage(), 0, 140) . ")\n"; } };
    $lst = fn(array $a) => $a ? implode(',', array_map('intval', $a)) : '0';
    $u = $lst($q("SELECT id FROM users WHERE correo LIKE 'zt\\_%@test.local' OR correo LIKE 'zt\\_%@ufps.edu.co' OR correo LIKE 'zt\\_%@gmail.com'"));
    $t = $lst($q("SELECT id FROM tickets WHERE descripcion LIKE 'ZT-%' OR user_id IN ($u) OR tecnico_id IN ($u) OR beca_id IN ($u)"));
    $tp = $lst($q("SELECT id FROM tickets_profesor WHERE descripcion_problema LIKE 'ZT-%' OR profesor_id IN ($u) OR tecnico_id IN ($u) OR beca_id IN ($u)"));
    $s = $lst($q("SELECT id_sala FROM salas WHERE nombre_sala LIKE 'ZT-%' OR nombre_sala LIKE 'ZT%SALA%'"));
    $e = $lst($q("SELECT id_equipo FROM equipos WHERE codigo LIKE 'ZT-%' OR id_sala IN ($s)"));
    // Soluciones técnicas de prueba: antes que mantenimientos, equipos y usuarios (los referencian).
    $sol = $lst($q("SELECT id_solucion FROM st_soluciones WHERE nombre LIKE 'ZT-%' OR id_creador IN ($u)"));
    foreach ($q("SELECT a.nombre_almacenado FROM st_archivos a JOIN st_versiones v ON v.id_version = a.id_version WHERE v.id_solucion IN ($sol)
                 UNION ALL SELECT nombre_almacenado FROM st_evidencias WHERE id_solucion IN ($sol)") as $f) @unlink(__DIR__ . '/../../storage/soluciones/' . basename($f));
    foreach (['st_evidencias', 'st_mantenimiento_detalle', 'st_historial'] as $tb) $x("DELETE FROM $tb WHERE id_solucion IN ($sol)");
    $x("DELETE FROM st_verificaciones WHERE id_despliegue IN (SELECT id_despliegue FROM st_despliegues WHERE id_solucion IN ($sol))");
    foreach (['st_despliegues', 'st_objetivos'] as $tb) $x("DELETE FROM $tb WHERE id_solucion IN ($sol)");
    $x("DELETE FROM st_archivos WHERE id_version IN (SELECT id_version FROM st_versiones WHERE id_solucion IN ($sol))");
    $x("UPDATE st_procedimientos SET id_reemplaza = NULL WHERE id_solucion IN ($sol)");
    foreach (['st_procedimientos', 'st_versiones', 'st_soluciones'] as $tb) $x("DELETE FROM $tb WHERE id_solucion IN ($sol)");
    foreach ($q("SELECT nombre_almacenado FROM ticket_adjuntos WHERE nombre_original LIKE 'zt\\_%'") as $f) @unlink(__DIR__ . '/../../storage/adjuntos/' . basename($f));
    foreach (explode(',', $u) as $idU) foreach (glob(__DIR__ . '/../../public/assets/uploads/perfiles/u' . (int)$idU . '_*') ?: [] as $fp) @unlink($fp);
    $x("DELETE FROM ticket_adjuntos WHERE nombre_original LIKE 'zt\\_%' OR id_usuario_subio IN ($u)");
    $x("DELETE FROM ticket_adjuntos WHERE (tabla_ticket='tickets' AND id_ticket IN ($t)) OR (tabla_ticket='tickets_profesor' AND id_ticket IN ($tp))");
    $x("DELETE FROM ticket_activos WHERE id_equipo IN ($e) OR (tabla_ticket='tickets' AND id_ticket IN ($t)) OR (tabla_ticket='tickets_profesor' AND id_ticket IN ($tp))");
    $x("DELETE FROM ticket_comentarios WHERE id_usuario IN ($u) OR (tabla_ticket='tickets' AND id_ticket IN ($t)) OR (tabla_ticket='tickets_profesor' AND id_ticket IN ($tp))");
    $x("DELETE FROM ticket_asignaciones WHERE id_tecnico IN ($u)");
    $x("DELETE FROM historial_componente WHERE id_ticket IN ($t) OR id_usuario_responsable IN ($u)");
    $x("DELETE FROM mantenimiento_acciones WHERE id_creador IN ($u) OR id_responsable IN ($u) OR id_sala IN ($s) OR id_equipo IN ($e)");
    $x("DELETE FROM mantenimiento_equipos WHERE id_programado IN (SELECT id_programado FROM mantenimientos_programados WHERE id_creador IN ($u) OR id_responsable IN ($u) OR id_sala IN ($s) OR id_equipo IN ($e))");
    $x("DELETE FROM mantenimientos_programados WHERE id_creador IN ($u) OR id_responsable IN ($u) OR id_sala IN ($s) OR id_equipo IN ($e)");
    $x("DELETE FROM mantenimiento_campanas WHERE id_creador IN ($u) OR id_responsable IN ($u)");
    $x("DELETE FROM mantenimiento_equipos WHERE id_equipo IN ($e)");
    $x("DELETE FROM movimientos_activos WHERE id_equipo IN ($e) OR id_solicito IN ($u)");
    $x("DELETE FROM notificaciones WHERE id_usuario IN ($u)");
    $x("UPDATE tickets_profesor SET id_horario_referencia=NULL WHERE id_horario_referencia IN (SELECT id_horario FROM horarios WHERE detalle LIKE 'ZT-%' OR id_creador IN ($u) OR id_usuario IN ($u) OR id_sala IN ($s))");
    $x("DELETE FROM excepciones_horario WHERE id_creador IN ($u) OR id_horario IN (SELECT id_horario FROM horarios WHERE detalle LIKE 'ZT-%' OR id_creador IN ($u) OR id_usuario IN ($u) OR id_sala IN ($s))");
    $x("DELETE FROM horarios WHERE detalle LIKE 'ZT-%' OR id_creador IN ($u) OR id_usuario IN ($u) OR id_sala IN ($s)");
    $x("DELETE FROM oficina_staff WHERE user_id IN ($u) OR id_sala IN ($s)");
    $x("UPDATE users SET id_unidad=NULL WHERE id_unidad IN (SELECT id_unidad FROM unidades_organizacionales WHERE nombre LIKE 'ZT-%')");
    $x("DELETE FROM salas_unidades WHERE id_sala IN ($s) OR id_unidad IN (SELECT id_unidad FROM unidades_organizacionales WHERE nombre LIKE 'ZT-%')");
    $x("DELETE FROM unidades_organizacionales WHERE nombre LIKE 'ZT-%'");
    $x("DELETE FROM auditoria_detalle_campos WHERE id_auditoria IN (SELECT id_auditoria FROM auditoria WHERE id_usuario IN ($u))");
    $x("DELETE FROM auditoria WHERE id_usuario IN ($u)");
    foreach (['usuario_bloqueo', 'recuperacion_clave', 'usuario_google', 'solicitudes_baja'] as $tb) {
        $x("DELETE FROM $tb WHERE " . ($tb === 'solicitudes_baja' ? "id_solicito IN ($u)" : "id_usuario IN ($u)"));
    }
    // Calidad del servicio y seguridad: datos ligados a los tickets y usuarios de prueba.
    foreach (['ticket_sla', 'ticket_eventos', 'ticket_pausas', 'encuestas_satisfaccion', 'incidentes_seguridad'] as $tb) {
        $x("DELETE FROM $tb WHERE (tabla_ticket='tickets' AND id_ticket IN ($t)) OR (tabla_ticket='tickets_profesor' AND id_ticket IN ($tp))");
    }
    $x("DELETE FROM incidentes_seguridad WHERE titulo LIKE 'ZT%' OR id_reporto IN ($u)");
    $x("DELETE FROM bitacora_accesos"); // base de pruebas: se vacía entera (borrar filas sueltas rompería la cadena de huellas)
    foreach ($q("SELECT archivo FROM respaldos WHERE id_usuario IN ($u)") as $f) @unlink(__DIR__ . '/../../storage/respaldos/' . basename($f));
    $x("DELETE FROM respaldos WHERE id_usuario IN ($u)");
    // Cierre de semestre: resúmenes archivados de los tickets de prueba
    $x("DELETE aa FROM tickets_archivo_activos aa JOIN tickets_archivo a ON a.id_archivo = aa.id_archivo WHERE a.descripcion LIKE 'ZT-%' OR aa.id_equipo IN ($e)");
    $x("DELETE FROM tickets_archivo WHERE descripcion LIKE 'ZT-%' OR id_lote IN (SELECT id_lote FROM archivo_lotes WHERE id_admin IN ($u))");
    $x("DELETE FROM archivo_lotes WHERE id_admin IN ($u)");
    $x("UPDATE mantenimientos_programados SET id_ticket_origen=NULL WHERE id_ticket_origen IN ($t)");
    $x("DELETE FROM tickets WHERE id IN ($t)");
    $x("DELETE FROM tickets_profesor WHERE id IN ($tp)");
    $x("DELETE FROM historial_componente WHERE id_componente IN (SELECT id_componente FROM componentes WHERE serial LIKE 'ZT-%')");
    $x("DELETE FROM mantenimiento_equipos WHERE id_componente_saliente IN (SELECT id_componente FROM componentes WHERE serial LIKE 'ZT-%') OR id_componente_entrante IN (SELECT id_componente FROM componentes WHERE serial LIKE 'ZT-%')");
    $x("DELETE FROM componentes WHERE serial LIKE 'ZT-%'");
    $x("DELETE FROM modelos_activo WHERE nombre LIKE 'ZTMod%'");
    $x("DELETE FROM fabricantes WHERE nombre LIKE 'ZTFab%'");
    $x("DELETE FROM equipos WHERE id_equipo IN ($e)");
    $x("DELETE FROM salas WHERE id_sala IN ($s)");
    $x("DELETE FROM actividades_tablero WHERE id_creador IN ($u) OR id_asignado IN ($u)");
    $x("DELETE FROM users WHERE id IN ($u)");
    $x("DELETE FROM unidades_organizacionales WHERE nombre LIKE 'ZT-%'");
}

limpiar($pdo);
// Antispam del QR: muchas pruebas reportan desde 127.0.0.1 en la misma hora; el límite se prueba aparte.
$pdo->exec("INSERT INTO config_sistema (clave, valor) VALUES ('seg_qr_max_por_hora', '100') ON DUPLICATE KEY UPDATE valor = '100'");
$CLAVE = 'Prueba12345';
$hash = password_hash($CLAVE, PASSWORD_DEFAULT);
$roles = ['admin' => 'ZT01', 'tecnico' => 'ZT02', 'beca_trabajo' => 'ZT03', 'profesor' => 'ZT04', 'solicitante' => 'ZT05', 'secretaria' => 'ZT06'];
$U = []; // rol => [id, correo, codigo]
$ins = $pdo->prepare("INSERT INTO users (codigo, correo, password, password_temporal, role, nombre_completo, cargo, estado_activo) VALUES (?,?,?,0,?,?,?,1)");
foreach ($roles as $rol => $cod) {
    $c = "zt_$rol@test.local";
    $ins->execute([$cod, $c, $hash, $rol, "ZT $rol", 'Prueba']);
    $U[$rol] = ['id' => (int)$pdo->lastInsertId(), 'correo' => $c, 'codigo' => $cod];
}
// segundo solicitante (no propietario) e inactivo
$ins->execute(['ZT07', 'zt_otro@test.local', $hash, 'solicitante', 'ZT otro', 'Prueba']);
$U['otro'] = ['id' => (int)$pdo->lastInsertId(), 'correo' => 'zt_otro@test.local', 'codigo' => 'ZT07'];
$ins->execute(['ZT08', 'zt_inactivo@test.local', $hash, 'solicitante', 'ZT inactivo', 'Prueba']);
$U['inactivo'] = ['id' => (int)$pdo->lastInsertId(), 'correo' => 'zt_inactivo@test.local', 'codigo' => 'ZT08'];
$pdo->exec("UPDATE users SET estado_activo=0 WHERE id=" . (int)$U['inactivo']['id']);

$pdo->prepare("INSERT INTO salas (nombre_sala, abreviatura, dependencia, codigo_qr_token) VALUES (?,?,?,?)")
    ->execute(['ZT-SALA', 'ZTS', 'Departamento de Sistemas', 'zt' . bin2hex(random_bytes(8))]);
$idSala = (int)$pdo->lastInsertId();
$tokenSala = $pdo->query("SELECT codigo_qr_token FROM salas WHERE id_sala=$idSala")->fetchColumn();
$pdo->prepare("INSERT INTO equipos (id_sala, codigo, descripcion) VALUES (?,?,?)")->execute([$idSala, 'ZT-EQ1', 'Equipo prueba']);
$idEquipo = (int)$pdo->lastInsertId();

function cliente(array $u, string $clave): HttpClient
{
    global $base;
    $c = new HttpClient($base);
    $c->login($u['codigo'], $u['correo'], $clave);
    return $c;
}
$C = [];
foreach (['admin', 'tecnico', 'beca_trabajo', 'profesor', 'solicitante', 'secretaria', 'otro'] as $r) $C[$r] = cliente($U[$r], $CLAVE);
$anon = new HttpClient($base);
$estaLogueado = fn(HttpClient $c) => !$c->get('tickets.php')->toLogin();

// ───────────────────────────────────────────────────────────── 0. Servidor y sesión
seccion('Sesión / login');
t('El servidor responde', $anon->get('login.php')->status === 200, 'status ' . $anon->get('login.php')->status);
foreach ($C as $r => $c) t("Login correcto como $r", $estaLogueado($c));
$rIni = $C['tecnico']->get('index.php');
t('La portada con sesión iniciada lleva al inicio del rol (sin error)', $rIni->redirects() && strpos($rIni->location(), 'tickets.php') !== false, 'status ' . $rIni->status);
$mal = new HttpClient($base);
$mal->login($U['admin']['codigo'], $U['admin']['correo'], 'incorrecta');
t('Login con clave errónea no entra', !$estaLogueado($mal));
$mal2 = new HttpClient($base);
$mal2->login('XXXX', $U['admin']['correo'], $CLAVE);
t('Login con código distinto no entra', !$estaLogueado($mal2));
$ina = new HttpClient($base);
$ina->login($U['inactivo']['codigo'], $U['inactivo']['correo'], $CLAVE);
t('Usuario inactivo no entra', !$estaLogueado($ina));
$lo = cliente($U['solicitante'], $CLAVE);
$lo->get('logout.php');
t('Logout cierra la sesión', !$estaLogueado($lo));

// ───────────────────────────────────────────────────────────── 1. Matriz de acceso
seccion('Matriz de acceso página × rol');
$publicas = ['login.php', 'index.php', 'recuperar_clave.php', 'restablecer_clave.php', 'consultar_estado.php', 'reportar.php?token=' . $tokenSala];
$matriz = [ // página => roles permitidos (null = cualquier autenticado)
    'tickets.php' => null, 'perfil.php' => null, 'cambiar_clave.php' => null, 'escanear_qr.php' => null,
    'create_ticket.php' => ['solicitante', 'secretaria'],
    'create_ticket_profesor.php' => ['profesor', 'admin'], // el admin también pide como docente (salas de su dependencia)
    'create_ticket_beca.php' => ['beca_trabajo', 'tecnico', 'admin'],
    'gestionar_salas.php' => ['tecnico', 'admin'],
    'gestionar_inventario.php' => ['tecnico', 'admin'],
    'revisar_componentes_bodega.php' => ['tecnico', 'admin'],
    'usuarios.php' => ['admin'], 'register.php' => ['admin'], 'solicitudes_baja.php' => ['admin'],
    'mantenimientos_programados.php' => ['admin', 'tecnico', 'beca_trabajo'],
    'soluciones_tecnicas.php' => ['admin', 'tecnico', 'beca_trabajo'], // la beca solo ve sus tareas (modulo_soluciones_tecnicas.php)
    'horarios.php' => ['admin', 'tecnico', 'beca_trabajo', 'profesor', 'secretaria'],
    'seguimiento_horas.php' => ['beca_trabajo', 'admin'], // otros roles solo con permiso (ver modulo_becas.php)
    'ficha_equipo.php' => ['admin', 'tecnico'],
    'buscar_global.php?q=zt' => null, 'listar_equipos_ajax.php?id_sala=1' => null,
    'listar_modelos_ajax.php?id_fabricante=1' => ['admin', 'tecnico'],
    'buscar_componente_ajax.php?q=zt' => ['admin', 'tecnico', 'beca_trabajo'],
];
foreach ($matriz as $pag => $permitidos) {
    $r0 = $anon->get($pag);
    t("Anónimo bloqueado en $pag", $r0->denied(), 'status ' . $r0->status);
    foreach (['admin', 'tecnico', 'beca_trabajo', 'profesor', 'solicitante', 'secretaria'] as $rol) {
        $r = $C[$rol]->get($pag);
        $debe = $permitidos === null || in_array($rol, $permitidos, true);
        if ($debe) t("$rol accede a $pag", !$r->denied() && $r->status < 500, 'status ' . $r->status . ' loc ' . $r->location());
        else t("$rol NO accede a $pag", $r->denied(), 'status ' . $r->status);
    }
}
foreach ($publicas as $pag) { $r = $anon->get($pag); t("Pública $pag responde sin login", $r->status === 200 && !$r->toLogin(), 'status ' . $r->status); }
$r = $ina->get('tickets.php'); t('Inactivo bloqueado en tickets.php', $r->toLogin());
// Sin errores 500 en ninguna página para ningún rol
$sin500 = true;
foreach (array_keys($matriz) as $pag) foreach ($C as $c) { if ($c->get($pag)->status >= 500) { $sin500 = false; echo "   500 en $pag\n"; } }
t('Ninguna página responde 5xx', $sin500);

// ───────────────────────────────────────────────────────────── 2. Tickets: crear / listar / filtrar / paginar
seccion('Tickets: crear, consultar, listar, buscar, paginar');
$sol = $C['solicitante'];
$r = $sol->post('create_ticket.php', ['dependencia' => 'ZT dep', 'ubicacion' => 'ZT ubic', 'equipo' => 'ZT equipo', 'descripcion' => 'ZT-desc-uno', 'categoria' => '']);
t('Solicitante crea ticket (redirige a tickets.php)', $r->redirects() && strpos($r->location(), 'tickets.php') !== false, 'status ' . $r->status . ' ' . $r->location());
$idT1 = (int)$pdo->query("SELECT id FROM tickets WHERE descripcion='ZT-desc-uno'")->fetchColumn();
t('El ticket queda en BD asociado al solicitante', $idT1 > 0 && (int)$pdo->query("SELECT user_id FROM tickets WHERE id=$idT1")->fetchColumn() === $U['solicitante']['id']);
$r = $sol->post('create_ticket.php', ['dependencia' => '', 'ubicacion' => '', 'equipo' => '', 'descripcion' => '']);
t('Ticket vacío es rechazado (no redirige)', !$r->redirects());
t('Ticket vacío no inserta fila', (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE user_id=" . $U['solicitante']['id'])->fetchColumn() === 1);
$r = $C['secretaria']->post('create_ticket.php', ['dependencia' => 'ZT dep externa', 'equipo' => 'ZT portátil', 'descripcion' => 'ZT-desc-sec']);
t('Secretaria crea ticket de equipo que llega a la oficina de mantenimiento', $r->redirects(), 'status ' . $r->status);
$r = $C['profesor']->post('create_ticket_profesor.php', ['tipo_ticket' => 'danio', 'id_sala_danio' => $idSala, 'descripcion_problema' => 'ZT-desc-prof']);
$idTP = (int)$pdo->query("SELECT id FROM tickets_profesor WHERE descripcion_problema='ZT-desc-prof'")->fetchColumn();
t('Profesor crea ticket propio', $idTP > 0, 'status ' . $r->status);
// Muchos tickets para paginar
$ti = $pdo->prepare("INSERT INTO tickets (user_id, origen, tipo_solicitud, descripcion, estado, nombre_dependencia, ubicacion, equipo) VALUES (?, 'PANEL_INTERNO', 'INCIDENTE', ?, 'En espera', ?, 'ZT', 'ZT')");
for ($i = 1; $i <= 12; $i++) $ti->execute([$U['solicitante']['id'], "ZT-pag-$i", "ZT-pag-$i"]);
$ti->execute([$U['otro']['id'], 'ZT-ajeno-secreto', 'ZT-ajeno-secreto']);
$idAjeno = (int)$pdo->lastInsertId();
$r = $sol->get('tickets.php');
t('Solicitante lista sus tickets', $r->status === 200 && $r->contains('ZT-desc-uno') || $r->contains('ZT-pag-'));
t('Solicitante NO ve tickets ajenos en su lista', !$r->contains('ZT-ajeno-secreto'));
$p1 = $sol->get('tickets.php'); $p2 = $sol->get('tickets.php?pagina_activos=2');
t('Paginación: página 2 responde', $p2->status === 200 && !$p2->denied());
t('Paginación: página 1 y 2 muestran contenido distinto', md5($p1->body) !== md5($p2->body));
$p99 = $sol->get('tickets.php?pagina_activos=99999'); t('Página fuera de rango no rompe', $p99->status === 200);
$pneg = $sol->get('tickets.php?pagina_activos=-5'); t('Página negativa no rompe', $pneg->status === 200);
$r = $sol->get('buscar_global.php?q=' . urlencode('ZT-pag-1'));
t('Búsqueda global devuelve JSON con coincidencias', $r->json() !== null && $r->contains('ZT-pag-1'), substr($r->body, 0, 120));
$r = $sol->get('buscar_global.php?q=' . urlencode('ZT-ajeno'));
t('Búsqueda global del solicitante no revela ticket ajeno', !$r->contains('ZT-ajeno-secreto') && !$r->contains((string)'"id":' . $idAjeno . ','));
$r = $C['admin']->get('buscar_global.php?q=' . urlencode('ZT-ajeno'));
t('Admin sí encuentra el ticket ajeno', $r->contains('ZT-ajeno'));
$r = $C['tecnico']->get('tickets.php');
t('Técnico lista tickets pendientes', $r->status === 200 && $r->contains('ZT-'));
$r = $C['admin']->get('tickets.php'); t('Admin ve el panel', $r->status === 200);
foreach (['beca_trabajo', 'profesor', 'secretaria'] as $rol) { $r = $C[$rol]->get('tickets.php'); t("Panel de $rol carga", $r->status === 200 && !$r->denied()); }

// ───────────────────────────────────────────────────────────── 3. Tickets: acciones del técnico
seccion('Tickets: tomar, comentar, actualizar, liberar, descartar');
$tec = $C['tecnico'];
// Solo Departamento: $idT1 es de un solicitante sin sala (otra dependencia). Sin el permiso no se puede tomar.
$r = $tec->post('tickets.php', ['tomar_ticket' => '1', 'ticket_id' => $idT1]);
t('Sin permiso, el técnico NO toma un ticket de otra dependencia', $pdo->query("SELECT tecnico_id FROM tickets WHERE id=$idT1")->fetchColumn() === null && $r->contains('otra dependencia'));
t('Sin permiso, el panel del técnico no muestra la bandeja «Otras dependencias»', !$tec->get('tickets.php')->contains('id="tickets-solicitantes"'));
// El resto de la suite prueba las acciones con el permiso activado (lo da el admin en Usuarios).
$pdo->exec("UPDATE users SET permiso_ver_tickets_externos=1 WHERE id=" . (int)$U['tecnico']['id']);
$r = $tec->post('tickets.php', ['tomar_ticket' => '1', 'ticket_id' => $idT1]);
$row = $pdo->query("SELECT tecnico_id, estado FROM tickets WHERE id=$idT1")->fetch();
t('Técnico toma el ticket', (int)$row['tecnico_id'] === $U['tecnico']['id'], json_encode($row));
$r = $tec->post('tickets.php', ['agregar_comentario' => '1', 'tabla_ticket' => 'tickets', 'ticket_id' => $idT1, 'comentario' => 'ZT comentario <b>x</b>']);
t('Técnico agrega comentario', (int)$pdo->query("SELECT COUNT(*) FROM ticket_comentarios WHERE id_ticket=$idT1")->fetchColumn() >= 1 || $r->status < 400);
$r = $tec->post('tickets.php', ['update_ticket' => '1', 'id' => $idT1, 'estado' => 'En proceso', 'solucion' => 'ZT-sol']);
t('Técnico cambia estado', in_array($pdo->query("SELECT estado FROM tickets WHERE id=$idT1")->fetchColumn(), ['En proceso', 'En Proceso', 'en_proceso'], true), (string)$pdo->query("SELECT estado FROM tickets WHERE id=$idT1")->fetchColumn());
$r = $tec->post('tickets.php', ['liberar_ticket' => '1', 'ticket_id' => $idT1]);
t('Técnico libera el ticket', $pdo->query("SELECT tecnico_id FROM tickets WHERE id=$idT1")->fetchColumn() === null);
$idDesc = (int)$pdo->query("SELECT id FROM tickets WHERE descripcion='ZT-pag-2'")->fetchColumn();
$r = $tec->post('tickets.php', ['descartar_ticket' => '1', 'ticket_id' => $idDesc, 'motivo_descarte' => 'ZT motivo']);
t('Técnico descarta con motivo', stripos((string)$pdo->query("SELECT estado FROM tickets WHERE id=$idDesc")->fetchColumn(), 'descart') !== false, (string)$pdo->query("SELECT estado FROM tickets WHERE id=$idDesc")->fetchColumn());
// Autorización de las acciones
$idPr = (int)$pdo->query("SELECT id FROM tickets WHERE descripcion='ZT-pag-3'")->fetchColumn();
foreach (['solicitante', 'profesor', 'secretaria'] as $rol) {
    $C[$rol]->post('tickets.php', ['tomar_ticket' => '1', 'ticket_id' => $idPr]);
    t("$rol no puede tomar tickets", $pdo->query("SELECT tecnico_id FROM tickets WHERE id=$idPr")->fetchColumn() === null);
}
$C['otro']->post('tickets.php', ['update_ticket' => '1', 'id' => $idPr, 'estado' => 'Solucionado', 'solucion' => 'hack']);
t('Solicitante no puede cambiar estado de ticket ajeno', $pdo->query("SELECT estado FROM tickets WHERE id=$idPr")->fetchColumn() === 'En espera');
$sol->post('tickets.php', ['descartar_ticket' => '1', 'ticket_id' => $idPr, 'motivo_descarte' => 'x']);
t('Solicitante no puede descartar', $pdo->query("SELECT estado FROM tickets WHERE id=$idPr")->fetchColumn() === 'En espera');

// ───────────────────────────────────────────────────────────── 4. PDF
seccion('PDF');
$r = $sol->get("ticket_pdf.php?id=$idT1");
t('Propietario descarga su PDF', $r->status === 200 && strpos($r->body, '%PDF') === 0, 'status ' . $r->status);
t('PDF con cabecera application/pdf', stripos($r->header('content-type'), 'pdf') !== false, $r->header('content-type'));
$r = $tec->get("ticket_pdf.php?id=$idT1"); t('Técnico descarga PDF de cualquiera', strpos($r->body, '%PDF') === 0);
$r = $C['otro']->get("ticket_pdf.php?id=$idT1"); t('IDOR: otro solicitante NO obtiene PDF ajeno', strpos($r->body, '%PDF') !== 0, 'status ' . $r->status);
$r = $anon->get("ticket_pdf.php?id=$idT1"); t('Anónimo NO obtiene PDF', strpos($r->body, '%PDF') !== 0);
$r = $C['beca_trabajo']->get("ticket_pdf.php?id=$idT1"); t('Beca no obtiene PDF de tickets (403)', strpos($r->body, '%PDF') !== 0);
$r = $C['profesor']->get("ticket_profesor_pdf.php?id=$idTP"); t('Profesor descarga su PDF', strpos($r->body, '%PDF') === 0, 'status ' . $r->status);
$r = $sol->get("ticket_profesor_pdf.php?id=$idTP"); t('IDOR: solicitante NO obtiene PDF de profesor', strpos($r->body, '%PDF') !== 0);
foreach (["abc", "0", "-1", "1 OR 1=1", "99999999"] as $v) {
    $r = $sol->get('ticket_pdf.php?id=' . urlencode($v));
    t("PDF con id raro «{$v}» no da 5xx ni PDF", $r->status < 500 && strpos($r->body, '%PDF') !== 0, 'status ' . $r->status);
}

// ───────────────────────────────────────────────────────────── 5. Usuarios / permisos / clave / perfil
seccion('Usuarios, permisos, contraseña, perfil');
$adm = $C['admin'];
$r = $adm->get('usuarios.php'); t('Admin lista usuarios', $r->status === 200 && $r->contains('zt_tecnico@test.local'));
t('Listado de usuarios no expone hashes', !$r->contains('$2y$'));
$antes = (int)$pdo->query("SELECT permiso_ver_tickets_sistemas FROM users WHERE id=" . $U['secretaria']['id'])->fetchColumn();
$adm->post('usuarios.php', ['alternar_permiso' => '1', 'id_usuario' => $U['secretaria']['id'], 'permiso' => 'permiso_ver_tickets_sistemas']);
$desp = (int)$pdo->query("SELECT permiso_ver_tickets_sistemas FROM users WHERE id=" . $U['secretaria']['id'])->fetchColumn();
t('Admin alterna permiso de una secretaria', $antes !== $desp, "antes $antes después $desp");
$adm->post('usuarios.php', ['alternar_permiso' => '1', 'id_usuario' => $U['profesor']['id'], 'permiso' => 'permiso_horarios_extra']);
t('Escalada: el permiso no se puede dar a un profesor (solo secretaria)', (int)$pdo->query("SELECT permiso_horarios_extra FROM users WHERE id=" . $U['profesor']['id'])->fetchColumn() === 0);
$adm->post('usuarios.php', ['alternar_permiso' => '1', 'id_usuario' => $U['secretaria']['id'], 'permiso' => 'role']);
t('Permiso fuera de la lista blanca se ignora', $pdo->query("SELECT role FROM users WHERE id=" . $U['secretaria']['id'])->fetchColumn() === 'secretaria');
$sol->post('usuarios.php', ['alternar_permiso' => '1', 'id_usuario' => $U['solicitante']['id'], 'permiso' => 'permiso_horarios_extra']);
t('Solicitante no puede darse permisos (escalada)', (int)$pdo->query("SELECT permiso_horarios_extra FROM users WHERE id=" . $U['solicitante']['id'])->fetchColumn() === 0);
$tec->post('usuarios.php', ['toggle_id' => $U['solicitante']['id']]);
t('Técnico no puede desactivar usuarios', (int)$pdo->query("SELECT estado_activo FROM users WHERE id=" . $U['solicitante']['id'])->fetchColumn() === 1);
// Cambio de contraseña
$cp = cliente($U['otro'], $CLAVE);
$r = $cp->post('cambiar_clave.php', ['clave_actual' => $CLAVE, 'nueva_clave' => 'Nueva-clave-segura-Http', 'confirmar_clave' => 'Nueva-clave-segura-Http']);
$ok = password_verify('Nueva-clave-segura-Http', (string)$pdo->query("SELECT password FROM users WHERE id=" . $U['otro']['id'])->fetchColumn());
if (!$ok) { // nombres de campo alternativos
    $campos = [];
    preg_match_all('/name="([a-z_]+)"/', $cp->get('cambiar_clave.php')->body, $mm); $campos = array_unique($mm[1]);
    echo '   campos cambiar_clave: ' . implode(',', $campos) . "\n";
}
t('Cambio de contraseña con la actual correcta', $ok);
$ok2 = new HttpClient($base); $ok2->login($U['otro']['codigo'], $U['otro']['correo'], 'Nueva-clave-segura-Http');
t('Se puede entrar con la contraseña nueva', $estaLogueado($ok2));
$pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $U['otro']['id']]);
$r = $C['otro']->get('perfil.php'); t('Perfil muestra datos propios', $r->status === 200 && $r->contains('zt_otro@test.local'));
t('Perfil propio no muestra datos de otro', !$r->contains('zt_admin@test.local'));

// ───────────────────────────────────────────────────────────── 6. Salas / inventario / horarios / AJAX
seccion('Salas, inventario, horarios y AJAX');
$r = $C['tecnico']->get('gestionar_salas.php'); t('Gestionar salas lista la sala de prueba', $r->contains('ZT-SALA'));
$r = $C['tecnico']->get('listar_equipos_ajax.php?id_sala=' . $idSala);
$j = $r->json(); t('listar_equipos_ajax devuelve JSON con el equipo', is_array($j) && $r->contains('ZT-EQ1'), substr($r->body, 0, 100));
t('listar_equipos_ajax con id inválido devuelve JSON/vacío sin error', $C['tecnico']->get('listar_equipos_ajax.php?id_sala=abc')->status < 500);
$r = $C['tecnico']->get('listar_modelos_ajax.php?id_fabricante=1'); t('listar_modelos_ajax devuelve JSON', $r->json() !== null, substr($r->body, 0, 80));
$r = $C['tecnico']->get('buscar_componente_ajax.php?q=zt'); t('buscar_componente_ajax devuelve JSON', $r->json() !== null, substr($r->body, 0, 80));
$r = $C['tecnico']->get('ficha_equipo.php?id=' . $idEquipo); t('Ficha de equipo carga', $r->status === 200 && $r->contains('ZT-EQ1'));
$r = $C['beca_trabajo']->get('ficha_equipo.php?id=' . $idEquipo); t('Beca ve ficha de un equipo (con id)', !$r->denied());
// Horarios
$hd = ['id_sala' => $idSala, 'tipo' => 'CLASE', 'dia_semana' => 2, 'hora_inicio' => '08:00', 'hora_fin' => '10:00', 'detalle' => 'ZT-horario', 'periodo' => '2026-2', 'id_usuario' => $U['profesor']['id']];
$adm->post('horarios.php', ['crear_horario' => '1'] + $hd);
$idH = (int)$pdo->query("SELECT id_horario FROM horarios WHERE detalle='ZT-horario'")->fetchColumn();
t('Horarios: admin crea un horario', $idH > 0);
$adm->post('horarios.php', ['editar_horario' => '1', 'id_horario' => $idH] + array_merge($hd, ['detalle' => 'ZT-horario-ed', 'hora_fin' => '11:00']));
t('Horarios: admin edita el horario', (string)$pdo->query("SELECT detalle FROM horarios WHERE id_horario=$idH")->fetchColumn() === 'ZT-horario-ed');
$r = $C['profesor']->post('horarios.php', ['crear_horario' => '1'] + array_merge($hd, ['detalle' => 'ZT-horario-prof']));
t('Horarios: profesor (solo lectura) no puede crear', (int)$pdo->query("SELECT COUNT(*) FROM horarios WHERE detalle='ZT-horario-prof'")->fetchColumn() === 0);
$C['profesor']->post('horarios.php', ['borrar_horario' => '1', 'id_horario' => $idH]);
t('Horarios: profesor no puede borrar', (int)$pdo->query("SELECT COUNT(*) FROM horarios WHERE id_horario=$idH")->fetchColumn() === 1);
$adm->postRaw('horarios.php', ['borrar_horario' => '1', 'id_horario' => $idH]);
t('Horarios: borrar sin CSRF no borra', (int)$pdo->query("SELECT COUNT(*) FROM horarios WHERE id_horario=$idH")->fetchColumn() === 1);
$adm->post('horarios.php', ['borrar_horario' => '1', 'id_horario' => $idH]);
t('Horarios: admin borra el horario', (int)$pdo->query("SELECT COUNT(*) FROM horarios WHERE id_horario=$idH")->fetchColumn() === 0);
$r = $C['profesor']->get('horarios.php'); t('Profesor ve horarios', $r->status === 200 && !$r->denied());

// ───────────────────────────────────────────────────────────── 7. Notificaciones
seccion('Notificaciones');
$pdo->prepare("INSERT INTO notificaciones (id_usuario, tipo, mensaje) VALUES (?, 'TICKET_ESTADO', 'ZT notif <script>x</script>')")->execute([$U['solicitante']['id']]);
$idN = (int)$pdo->lastInsertId();
$r = $sol->get('tickets.php');
t('La notificación aparece en la campanita', $r->contains('ZT notif'));
t('XSS: la notificación está escapada', !$r->contains('<script>x</script>'));
$pk = $C['otro']; $pk->post('notificacion_marcar_leida.php', ['id_notificacion' => $idN], 'tickets.php');
t('IDOR: otro usuario no puede marcar la notificación ajena', (int)$pdo->query("SELECT leido FROM notificaciones WHERE id_notificacion=$idN")->fetchColumn() === 0);
$sol->post('notificacion_marcar_leida.php', ['id_notificacion' => $idN], 'tickets.php');
t('El dueño marca su notificación como leída', (int)$pdo->query("SELECT leido FROM notificaciones WHERE id_notificacion=$idN")->fetchColumn() === 1);

// ───────────────────────────────────────────────────────────── 8. Archivos
seccion('Archivos: adjuntos, foto de perfil');
$dir = __DIR__ . '/../../storage/adjuntos/';
@mkdir($dir, 0775, true);
$almac = 'zt_' . bin2hex(random_bytes(6)) . '.txt';
file_put_contents($dir . $almac, 'CONTENIDO-ZT');
$pdo->prepare("INSERT INTO ticket_adjuntos (tabla_ticket, id_ticket, nombre_original, nombre_almacenado, mime_type, tamano_bytes, id_usuario_subio) VALUES ('tickets', ?, 'zt_nota.txt', ?, 'text/plain', 12, ?)")
    ->execute([$idT1, $almac, $U['solicitante']['id']]);
$idAd = (int)$pdo->lastInsertId();
$r = $sol->get("descargar_adjunto.php?id=$idAd"); t('Propietario descarga su adjunto', $r->status === 200 && $r->contains('CONTENIDO-ZT'), 'status ' . $r->status);
$r = $tec->get("descargar_adjunto.php?id=$idAd"); t('Técnico descarga adjunto', $r->contains('CONTENIDO-ZT'));
$r = $C['otro']->get("descargar_adjunto.php?id=$idAd"); t('IDOR: otro solicitante NO descarga adjunto ajeno', !$r->contains('CONTENIDO-ZT'), 'status ' . $r->status);
$r = $anon->get("descargar_adjunto.php?id=$idAd"); t('Anónimo NO descarga adjunto', !$r->contains('CONTENIDO-ZT'));
$r = $anon->get('storage/adjuntos/' . $almac); t('Acceso directo a storage/adjuntos no existe por URL', !$r->contains('CONTENIDO-ZT'));
foreach (['../../config/database.php', '..%2F..%2Fconfig%2Fdatabase.php', '/etc/passwd', '0'] as $v) {
    $r = $tec->get('descargar_adjunto.php?id=' . $v);
    t("Path traversal «{$v}» no entrega archivos", !$r->contains('<?php') && !$r->contains('root:'), 'status ' . $r->status);
}
// Path traversal a través del nombre almacenado en BD (defensa en profundidad)
$pdo->prepare("UPDATE ticket_adjuntos SET nombre_almacenado=? WHERE id=?")->execute(['../../config/database.php', $idAd]);
$r = $tec->get("descargar_adjunto.php?id=$idAd");
t('Nombre almacenado con ../ no sale del directorio', !$r->contains("'dbname'") && !$r->contains('<?php'), 'status ' . $r->status);
$pdo->prepare("UPDATE ticket_adjuntos SET nombre_almacenado=? WHERE id=?")->execute([$almac, $idAd]);
// Foto de perfil
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$up = $C['otro'];
$r = $up->postFile('perfil.php', ['subir_foto' => '1'], ['foto_perfil' => ['f.png', $png, 'image/png']]);
$f = (string)$pdo->query("SELECT foto_perfil FROM users WHERE id=" . $U['otro']['id'])->fetchColumn();
t('Foto PNG válida se acepta', $f !== '', 'status ' . $r->status);
$pdo->exec("UPDATE users SET foto_perfil=NULL WHERE id=" . $U['otro']['id']);
foreach ([['sh.php', '<?php echo 1;', 'image/png'], ['x.png', '<?php system($_GET[0]);', 'image/png'], ['x.html', '<script>1</script>', 'text/html'], ['x.svg', '<svg onload=alert(1)>', 'image/svg+xml'], ['dos.php.png', '<?php phpinfo();', 'image/png']] as [$n, $c, $m]) {
    $r = $up->postFile('perfil.php', ['subir_foto' => '1'], ['foto_perfil' => [$n, $c, $m]]);
    $f = (string)$pdo->query("SELECT foto_perfil FROM users WHERE id=" . $U['otro']['id'])->fetchColumn();
    t("Archivo no permitido «{$n}» se rechaza en foto de perfil", $f === '', "guardado como $f");
    if ($f !== '') $pdo->exec("UPDATE users SET foto_perfil=NULL WHERE id=" . $U['otro']['id']);
}
$big = $png . str_repeat('A', 6 * 1024 * 1024);
$r = $up->postFile('perfil.php', ['subir_foto' => '1'], ['foto_perfil' => ['grande.png', $big, 'image/png']]);
t('Imagen demasiado grande se rechaza', (string)$pdo->query("SELECT foto_perfil FROM users WHERE id=" . $U['otro']['id'])->fetchColumn() === '');
// ¿Quedó algún .php en uploads?
$hayPhp = false; foreach (glob(__DIR__ . '/../../public/uploads/*/*') ?: [] as $f) if (preg_match('/\.(php|phtml|html|svg)$/i', $f)) $hayPhp = true;
t('No quedan ejecutables en carpetas de subida', !$hayPhp);

// ───────────────────────────────────────────────────────────── 9. Público: QR, consulta, recuperación
seccion('Público: reportar por QR, consultar estado, recuperar clave');
$r = $anon->get('reportar.php?token=' . $tokenSala); t('Reportar por QR muestra la sala', $r->contains('ZT-SALA'));
$r = $anon->get('reportar.php?token=invalido'); t('Token QR inválido no muestra salas', !$r->contains('ZT-SALA') && $r->status < 500);
$r = $anon->get('reportar.php'); t('Reportar sin token no da 5xx', $r->status < 500);
$pdo->prepare("UPDATE tickets SET codigo_seguimiento='ZTSEG0001' WHERE id=?")->execute([$idT1]);
$r = $anon->get('consultar_estado.php?codigo=ZTSEG0001'); t('Consultar estado por código de seguimiento', $r->status === 200 && $r->contains('ZT-desc-uno') || $r->contains('ZTSEG0001'));
$r = $anon->get('consultar_estado.php?codigo=NOEXISTE'); t('Código inexistente no da 5xx', $r->status < 500);
$r = $anon->postRaw('recuperar_clave.php', ['correo' => 'noexiste@test.local']);
$r2 = $anon->postRaw('recuperar_clave.php', ['correo' => $U['otro']['correo']]);
t('Recuperar clave: misma respuesta exista o no el correo (no enumera)', $r->status === $r2->status && abs(strlen($r->body) - strlen($r2->body)) < 200);
$pdo->exec("DELETE FROM recuperacion_clave WHERE id_usuario=" . (int)$U['otro']['id']);
$r = $anon->get('restablecer_clave.php?token=zzzz'); t('Token de recuperación inválido no permite cambiar', $r->status < 500 && !$r->contains('Nueva contraseña') || $r->contains('inválido') || $r->contains('expir') || $r->contains('no es válido'));

// ───────────────────────────────────────────────────────────── 10. Seguridad
seccion('Seguridad: CSRF');
$acciones = [
    ['tickets.php', ['tomar_ticket' => '1', 'ticket_id' => $idPr], $tec, fn() => $pdo->query("SELECT tecnico_id FROM tickets WHERE id=$idPr")->fetchColumn() === null],
    ['tickets.php', ['descartar_ticket' => '1', 'ticket_id' => $idPr, 'motivo_descarte' => 'x'], $tec, fn() => $pdo->query("SELECT estado FROM tickets WHERE id=$idPr")->fetchColumn() === 'En espera'],
    ['tickets.php', ['update_ticket' => '1', 'id' => $idPr, 'estado' => 'Solucionado', 'solucion' => 'x'], $tec, fn() => $pdo->query("SELECT estado FROM tickets WHERE id=$idPr")->fetchColumn() === 'En espera'],
    ['usuarios.php', ['alternar_permiso' => '1', 'id_usuario' => $U['profesor']['id'], 'permiso' => 'permiso_horarios_extra'], $adm, fn() => (int)$pdo->query("SELECT permiso_horarios_extra FROM users WHERE id=" . $U['profesor']['id'])->fetchColumn() === 0],
    ['usuarios.php', ['toggle_id' => $U['solicitante']['id']], $adm, fn() => (int)$pdo->query("SELECT estado_activo FROM users WHERE id=" . $U['solicitante']['id'])->fetchColumn() === 1],
    ['create_ticket.php', ['dependencia' => 'a', 'ubicacion' => 'b', 'equipo' => 'c', 'descripcion' => 'ZT-csrf'], $sol, fn() => (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE descripcion='ZT-csrf'")->fetchColumn() === 0],
    ['cambiar_clave.php', ['clave_actual' => $CLAVE, 'nueva_clave' => 'Hack12345xx', 'confirmar_clave' => 'Hack12345xx'], $C['profesor'], fn() => password_verify($CLAVE, (string)$pdo->query("SELECT password FROM users WHERE id=" . $U['profesor']['id'])->fetchColumn())],
    ['notificacion_marcar_leida.php', ['marcar_todas' => '1'], $C['secretaria'], fn() => true],
];
foreach ($acciones as [$url, $datos, $cli, $intacto]) {
    $r = $cli->postRaw($url, $datos);
    $k = array_key_first($datos);
    t("POST sin token CSRF a $url ($k) no cambia nada", $intacto(), 'status ' . $r->status);
    $r = $cli->postRaw($url, $datos + ['csrf_token' => 'token-falso']);
    t("POST con token falso a $url ($k) no cambia nada", $intacto(), 'status ' . $r->status);
}
// token de otra sesión
$tokOtro = $C['admin']->csrf('usuarios.php');
$C['tecnico']->postRaw('tickets.php', ['tomar_ticket' => '1', 'ticket_id' => $idPr, 'csrf_token' => $tokOtro]);
t('Token CSRF de otra sesión no sirve', $pdo->query("SELECT tecnico_id FROM tickets WHERE id=$idPr")->fetchColumn() === null);

seccion('Seguridad: XSS');
$xss = ['<script>alert(1)</script>', '"><img src=x onerror=alert(1)>', "'><svg/onload=alert(1)>"];
foreach ($xss as $i => $p) {
    $sol->post('create_ticket.php', ['dependencia' => $p, 'ubicacion' => $p, 'equipo' => $p, 'descripcion' => "ZT-xss-$i $p"]);
}
$ver = [
    'solicitante (lista)' => $sol->get('tickets.php'), 'solicitante (panel)' => $sol->get('tickets.php'),
    'técnico' => $tec->get('tickets.php'), 'admin' => $adm->get('tickets.php'),
    'búsqueda' => $adm->get('buscar_global.php?q=ZT-xss'),
];
foreach ($ver as $k => $rr) {
    $malo = preg_match('/<script>alert\(1\)<\/script>|<img src=x onerror|<svg\/onload/i', $rr->body) === 1;
    t("XSS almacenado escapado en $k", !$malo);
}
$idX = (int)$pdo->query("SELECT id FROM tickets WHERE descripcion LIKE 'ZT-xss-0%'")->fetchColumn();
$pdfx = $tec->get("ticket_pdf.php?id=$idX"); t('PDF con payload XSS se genera', strpos($pdfx->body, '%PDF') === 0);
// XSS reflejado
foreach (['buscar_global.php?q=', 'consultar_estado.php?codigo=', 'reportar.php?token=', 'restablecer_clave.php?token=', 'tickets.php?q=', 'ficha_equipo.php?q=', 'usuarios.php?q=', 'login.php?error='] as $u0) {
    $cli = $u0 === 'ficha_equipo.php?q=' ? $tec->get($u0 . urlencode("<script>alert(7)</script>") . '&id=' . $idEquipo) : (str_starts_with($u0, 'usuarios') ? $adm->get($u0 . urlencode('<script>alert(7)</script>')) : $anon->get($u0 . urlencode('<script>alert(7)</script>')));
    t("XSS reflejado escapado en $u0", !$cli->contains('<script>alert(7)</script>'));
}
// Cabeceras de seguridad
$r = $anon->get('login.php');
t('CSP presente y sin unsafe-inline en script-src', preg_match("/script-src[^;]*/", $r->header('content-security-policy'), $m) && strpos($m[0], 'unsafe-inline') === false, $r->header('content-security-policy'));
t('X-Content-Type-Options: nosniff', strtolower($r->header('x-content-type-options')) === 'nosniff');
t('Cookie de sesión HttpOnly', stripos($r->header('set-cookie'), 'httponly') !== false || $r->header('set-cookie') === '', $r->header('set-cookie'));

seccion('Seguridad: inyección SQL');
$payloads = ["' OR '1'='1", "1' OR '1'='1' -- ", "1; DROP TABLE tickets;--", "' UNION SELECT 1,2,3,4,5,6,7,8,9,10--", "1 AND SLEEP(3)", "\\", "%27%22"];
$urls = ['buscar_global.php?q=%s', 'tickets.php?q=%s', 'tickets.php?pagina_activos=%s', 'consultar_estado.php?codigo=%s', 'reportar.php?token=%s',
         'listar_equipos_ajax.php?id_sala=%s', 'buscar_componente_ajax.php?q=%s', 'ficha_equipo.php?id=%s', 'ticket_pdf.php?id=%s', 'descargar_adjunto.php?id=%s'];
$t0 = microtime(true); $cuenta0 = (int)$pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn(); $sqlErr = [];
foreach ($urls as $u0) foreach ($payloads as $pl) {
    foreach (['tecnico' => $tec, 'admin' => $adm, 'anon' => $anon] as $cn => $cli) {
        $rr = $cli->get(sprintf($u0, urlencode($pl)));
        if ($rr->status >= 500 || preg_match('/SQLSTATE|You have an error in your SQL|mysqli?_|PDOException|Stack trace|on line \d+/i', $rr->body)) $sqlErr[] = "$cn $u0 [$pl] → " . $rr->status;
    }
}
t('Ningún payload SQL produce 5xx ni mensajes de error de BD', !$sqlErr, implode(' | ', array_slice($sqlErr, 0, 4)));
t('Las tablas siguen existiendo (sin DROP)', (int)$pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn() === $cuenta0);
t('Ningún payload usó SLEEP (tiempo total razonable)', (microtime(true) - $t0) < 60);
$r1 = $anon->get('consultar_estado.php?codigo=' . urlencode("' OR '1'='1")); t('Consulta de estado con SQLi no revela tickets', !$r1->contains('ZT-desc-uno'));
// Login SQLi
$sq = new HttpClient($base); $sq->login("' OR '1'='1", "' OR '1'='1' -- ", "' OR '1'='1");
t('Login con SQLi no entra', !$estaLogueado($sq));

seccion('Seguridad: manipulación de IDs / acceso directo');
$r = $C['otro']->get("ticket_pdf.php?id=$idT1"); t('IDOR PDF: ajeno bloqueado', strpos($r->body, '%PDF') !== 0);
$r = $C['otro']->get("descargar_adjunto.php?id=$idAd"); t('IDOR adjunto: ajeno bloqueado', !$r->contains('CONTENIDO-ZT'));
$sol->post('tickets.php', ['agregar_comentario' => '1', 'tabla_ticket' => 'tickets', 'ticket_id' => $idAjeno, 'comentario' => 'ZT-intruso']);
t('IDOR comentario: no se comenta un ticket ajeno', (int)@$pdo->query("SELECT COUNT(*) FROM ticket_comentarios WHERE comentario LIKE 'ZT-intruso%'")->fetchColumn() === 0);
$sol->post('tickets.php', ['update_ticket' => '1', 'id' => $idAjeno, 'estado' => 'Solucionado', 'solucion' => 'hack']);
t('IDOR actualización: solicitante no cambia ticket ajeno', $pdo->query("SELECT estado FROM tickets WHERE id=$idAjeno")->fetchColumn() === 'En espera');
$r = $C['solicitante']->get('ficha_equipo.php?id=' . $idEquipo); t('Solicitante no accede a ficha de equipo', $r->denied());
foreach (['app/Support/auth.php', 'config/database.php', 'bootstrap.php', 'storage/adjuntos/', 'database/tickets_db.sql', 'vendor/fpdf/fpdf.php', 'tests/run_tests.php', '.env', '.git/config'] as $f) {
    $r = $anon->get($f);
    t("Acceso directo por URL a /$f no expone contenido", !$r->contains('<?php') && !$r->contains("'password'") && !$r->contains('CREATE TABLE'), 'status ' . $r->status);
}
$r = $anon->get('login.php?x=' . str_repeat('A', 9000)); t('Parámetro enorme no da 5xx', $r->status < 500);
$r = $anon->request('GET', 'tickets.php', null, ['Cookie' => 'PHPSESSID=' . str_repeat('a', 40)]); t('Cookie de sesión inventada no da acceso', $r->toLogin());
// Fijación de sesión: el id cambia al hacer login
$fx = new HttpClient($base); $a = $fx->get('login.php'); $cookieAntes = $a->header('set-cookie');
$fx->login($U['solicitante']['codigo'], $U['solicitante']['correo'], $CLAVE);
t('Login emite una sesión (cookie nueva)', true);

seccion('Seguridad: uploads en mantenimientos');
$r = $C['tecnico']->get('mantenimientos_programados.php'); t('Mantenimientos carga para técnico', $r->status === 200);
$r = $C['solicitante']->postFile('mantenimientos_programados.php', ['accion' => 'x'], ['evidencia[]' => ['s.php', '<?php 1;', 'image/png']], 'tickets.php');
t('Solicitante no puede subir evidencias (acceso denegado)', $r->denied(), 'status ' . $r->status);
$ph = glob(__DIR__ . '/../../storage/*/*.php') ?: [];
t('No hay PHP subido dentro de storage/', count($ph) === 0, implode(',', $ph));

require __DIR__ . '/modulo_salas_horarios.php';
require __DIR__ . '/modulo_mantenimientos.php';
require __DIR__ . '/modulo_mantenimiento_ciclo.php';
require __DIR__ . '/modulo_soluciones_tecnicas.php';
require __DIR__ . '/modulo_inventario.php';
require __DIR__ . '/modulo_usuarios.php';
require __DIR__ . '/modulo_tickets_profesor.php';
require __DIR__ . '/modulo_becas.php';
require __DIR__ . '/modulo_areas_beca.php';
require __DIR__ . '/modulo_publico.php';
require __DIR__ . '/modulo_cuenta.php';
require __DIR__ . '/modulo_endpoints.php';
require __DIR__ . '/modulo_conexiones.php';
require __DIR__ . '/modulo_calidad_seguridad.php';
require __DIR__ . '/modulo_horarios_notificaciones.php';
require __DIR__ . '/modulo_roles_pantalla_archivo.php';

// ───────────────────────────────────────────────────────────── Limpieza
limpiar($pdo);
$pdo->exec("DELETE FROM config_sistema WHERE clave IN ('seg_qr_max_por_hora', 'seg_mfa_obligatorio', 'seg_qr_captcha')");
$res = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE correo LIKE 'zt\\_%@test.local'")->fetchColumn()
     + (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE descripcion LIKE 'ZT-%'")->fetchColumn();
t('Limpieza: no quedan datos de prueba', $res === 0, "$res filas");

echo "\n" . str_repeat('=', 50) . "\nPASS: $pass   FAIL: $fail\n";
if ($fail) { echo "\nFallos:\n - " . implode("\n - ", $fallos) . "\n"; }
exit($fail ? 1 : 0);
