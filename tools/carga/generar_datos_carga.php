<?php
/**
 * Generador de datos de CARGA para pruebas de rendimiento (README.md, «Rendimiento y capacidad»).
 *
 * Crea un escenario realista del Departamento de Sistemas e Informática:
 *   - 40 salas del departamento + 3 oficinas (Departamento, Redes, Mantenimiento)
 *   - 1 admin, 6 técnicos, 4 secretarias, 50 profesores, 40 becas trabajo
 *   - 30 puestos por sala (1.200) + 10 por oficina (30) = 1.230 equipos
 *   - componentes hasta la meta indicada (por defecto 2.000.000)
 *   - 3.000 tickets de todos los tipos (≈1.800 tickets + ≈1.200 tickets_profesor)
 *   - historial, eventos, SLA, comentarios, notificaciones, auditoría, bitácora, horarios, mantenimientos
 *
 * USO (solo consola y solo contra una base que termine en _test):
 *   php tools/carga/generar_datos_carga.php --db=tickets_carga_test --base          (estructura + 3.000 tickets)
 *   php tools/carga/generar_datos_carga.php --db=tickets_carga_test --componentes=500000   (sube el inventario HASTA esa cifra)
 *
 * Contraseña de todos los usuarios de carga: Carga2026!  (password_temporal = 0)
 * Correos: carga.<rol>.<n>@ufps.edu.co  — códigos: CG-<ROL>-<n>
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por consola.'); }

$opt = getopt('', ['db:', 'base', 'componentes:', 'multiplicar-tickets:', 'host:', 'user:', 'pass:']);
$db = $opt['db'] ?? 'tickets_carga_test';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "La base debe terminar en _test\n"); exit(2); }
$pdo = new PDO('mysql:host=' . ($opt['host'] ?? 'localhost') . ";dbname=$db;charset=utf8mb4", $opt['user'] ?? 'root', $opt['pass'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
mt_srand(20261004); // reproducible

$t0 = microtime(true);
function paso(string $m): void { global $t0; printf("[%7.1fs] %s\n", microtime(true) - $t0, $m); }
function q(PDO $pdo, string $sql): void { $pdo->exec($sql); }
function col(PDO $pdo, string $sql): array { return $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN); }
function pick(array $a) { return $a[mt_rand(0, count($a) - 1)]; }
/** Inserta filas en lotes de 1.000 con un INSERT multi-valores. */
function bulk(PDO $pdo, string $tabla, array $cols, array $filas): void
{
    foreach (array_chunk($filas, 1000) as $lote) {
        $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $sql = "INSERT INTO $tabla (" . implode(',', $cols) . ') VALUES ' . implode(',', array_fill(0, count($lote), $ph));
        $vals = [];
        foreach ($lote as $f) foreach ($f as $v) $vals[] = $v;
        $pdo->prepare($sql)->execute($vals);
    }
}
function fechaAzar(int $diasAtras): string { return date('Y-m-d H:i:s', time() - mt_rand(0, $diasAtras * 86400)); }


/** SLA, eventos, comentarios, asignaciones, activos afectados y encuestas de los tickets con id mayor al indicado. */
function satelitesTickets(PDO $pdo, int $desdeT, int $desdeTP): void
{
    $desde = ['tickets' => $desdeT, 'tickets_profesor' => $desdeTP];
    foreach (['tickets' => 'COALESCE(fecha_terminacion, NULL)', 'tickets_profesor' => 'fecha_solucion'] as $tb => $colSol) {
        q($pdo, "INSERT INTO ticket_sla (tabla_ticket, id_ticket, tipo, impacto, urgencia, prioridad, fecha_primera_respuesta, fecha_solucion, actualizado_en)
                 SELECT '$tb', id, 'INCIDENTE', 1 + (id % 3), 1 + ((id * 7) % 3), ELT(1 + (id % 4), 'CRITICA','ALTA','MEDIA','BAJA'),
                        IF(tecnico_id IS NULL, NULL, fecha_creacion + INTERVAL (id % 240) MINUTE), $colSol, NOW() FROM $tb WHERE id > " . $desde[$tb] . "");
        q($pdo, "INSERT INTO ticket_eventos (tabla_ticket, id_ticket, estado_anterior, estado_nuevo, fecha)
                 SELECT '$tb', id, NULL, 'En espera', fecha_creacion FROM $tb WHERE id > {$desde[$tb]}
                 UNION ALL SELECT '$tb', id, 'En espera', 'En proceso', fecha_creacion + INTERVAL 2 HOUR FROM $tb WHERE id > {$desde[$tb]} AND estado <> 'En espera'
                 UNION ALL SELECT '$tb', id, 'En proceso', estado, fecha_creacion + INTERVAL 1 DAY FROM $tb WHERE id > {$desde[$tb]} AND estado NOT IN ('En espera','En proceso')");
        q($pdo, "INSERT INTO ticket_comentarios (tabla_ticket, id_ticket, id_usuario, visibilidad, comentario, fecha_creacion)
                 SELECT '$tb', t.id, t.tecnico_id, IF(s.seq % 2, 'INTERNO', 'CONVERSACION'), CONCAT('Seguimiento #', s.seq, ' del caso ', t.id), t.fecha_creacion + INTERVAL s.seq HOUR
                 FROM $tb t JOIN seq_1_to_3 s WHERE t.id > {$desde[$tb]} AND t.tecnico_id IS NOT NULL");
        q($pdo, "INSERT INTO ticket_asignaciones (tabla_ticket, id_ticket, id_tecnico, fecha_asignado) SELECT '$tb', id, tecnico_id, fecha_creacion + INTERVAL 1 HOUR FROM $tb WHERE id > {$desde[$tb]} AND tecnico_id IS NOT NULL");
        q($pdo, "INSERT INTO encuestas_satisfaccion (tabla_ticket, id_ticket, calificacion, canal, fecha) SELECT '$tb', id, 3 + (id % 3), 'PANEL', fecha_creacion + INTERVAL 3 DAY FROM $tb WHERE id > {$desde[$tb]} AND estado='Cerrado' AND id % 3 = 0");
    }
    q($pdo, "INSERT INTO ticket_activos (tabla_ticket, id_ticket, id_equipo, tipo_afectacion) SELECT 'tickets', id, id_equipo, 'REVISION' FROM tickets WHERE id > {$desde['tickets']} AND id_equipo IS NOT NULL");
}

$DEP = 'Departamento de Sistemas e Informática';

// ───────────────────────────── BASE ─────────────────────────────
if (isset($opt['base'])) {
    q($pdo, 'SET FOREIGN_KEY_CHECKS=0');
    $hash = password_hash('Carga2026!', PASSWORD_DEFAULT);

    // Usuarios
    $roles = ['admin' => 1, 'tecnico' => 6, 'secretaria' => 4, 'profesor' => 50, 'beca_trabajo' => 40, 'vigilante' => 1, 'visualizacion' => 1];
    $nombres = ['Ana', 'Luis', 'María', 'Carlos', 'Diana', 'Jorge', 'Paola', 'Andrés', 'Laura', 'Sergio', 'Camila', 'Brayan', 'Juan', 'Valentina', 'Óscar', 'Natalia'];
    $apellidos = ['Rojas', 'Suárez', 'Peñaranda', 'Contreras', 'Jaimes', 'Ortega', 'Villamizar', 'Parada', 'Gélvez', 'Rincón', 'Carrillo', 'Duarte'];
    $filas = [];
    foreach ($roles as $rol => $n) {
        for ($i = 1; $i <= $n; $i++) {
            $tag = strtoupper(substr(str_replace('_', '', $rol), 0, 4));
            $filas[] = [sprintf('CG-%s-%02d', $tag, $i), sprintf('carga.%s.%02d@ufps.edu.co', $rol, $i), $hash, $rol,
                pick($nombres) . ' ' . pick($apellidos) . ' ' . pick($apellidos), $rol === 'beca_trabajo' ? 'beca' : $rol, $DEP,
                $rol === 'beca_trabajo' ? ($i <= 30 ? 'mantenimiento' : ($i % 2 ? 'secretaria' : 'redes')) : null,
                in_array($rol, ['secretaria', 'tecnico'], true) ? 1 : 0];
        }
    }
    bulk($pdo, 'users', ['codigo', 'correo', 'password', 'role', 'nombre_completo', 'cargo', 'dependencia', 'area_beca', 'permiso_ver_tickets_sistemas'], $filas);
    $U = [];
    foreach ($pdo->query("SELECT id, role FROM users WHERE correo LIKE 'carga.%'") as $r) $U[$r['role']][] = (int)$r['id'];
    paso('usuarios: ' . array_sum(array_map('count', $U)));

    // Salas: 40 del departamento (pisos 2-4 de Aula Sur y SC) + 3 oficinas
    $filas = [];
    for ($i = 1; $i <= 40; $i++) {
        $filas[] = [sprintf('SC%d%02d', intdiv($i - 1, 10) + 2, ($i - 1) % 10 + 1), sprintf('S%02d', $i), $DEP, bin2hex(random_bytes(16)), 0];
    }
    $filas[] = ['Oficina Departamento de Sistemas', 'ODS', $DEP, bin2hex(random_bytes(16)), 0];
    $filas[] = ['Oficina de Redes', 'RED', $DEP, bin2hex(random_bytes(16)), 0];
    $filas[] = ['Oficina de Mantenimiento SC403', 'MTO', $DEP, bin2hex(random_bytes(16)), 1];
    bulk($pdo, 'salas', ['nombre_sala', 'abreviatura', 'dependencia', 'codigo_qr_token', 'es_oficina_mantenimiento'], $filas);
    $salas = array_map('intval', col($pdo, "SELECT id_sala FROM salas ORDER BY id_sala"));
    $salasAula = array_slice($salas, 0, 40);
    $oficinaMto = (int)$pdo->query("SELECT id_sala FROM salas WHERE es_oficina_mantenimiento=1")->fetchColumn();
    paso('salas: ' . count($salas));

    // Equipos (30 por sala, 10 por oficina)
    $filas = [];
    foreach ($salas as $k => $s) {
        $n = $k < 40 ? 30 : 10;
        for ($p = 1; $p <= $n; $p++) $filas[] = [$s, sprintf('EQ-%02d-PC%02d', $k + 1, $p), "Puesto $p", $s, mt_rand(1, 100) > 4 ? 1 : 0];
    }
    bulk($pdo, 'equipos', ['id_sala', 'codigo', 'descripcion', 'id_sala_original', 'operativo'], $filas);
    paso('equipos: ' . count($filas));

    // Personal de la oficina de mantenimiento
    $filas = [];
    foreach ($U['tecnico'] as $id) $filas[] = [$oficinaMto, $id, 'tecnico'];
    foreach (array_slice($U['beca_trabajo'], 0, 30) as $id) $filas[] = [$oficinaMto, $id, 'beca'];
    bulk($pdo, 'oficina_staff', ['id_sala', 'user_id', 'rol'], $filas);
    $filas = [];
    foreach ($U['secretaria'] as $k => $id) foreach (array_slice($salasAula, $k * 10, 10) as $s) $filas[] = [$id, $s, $U['admin'][0]];
    bulk($pdo, 'secretaria_salas', ['id_usuario', 'id_sala', 'asignado_por'], $filas);

    // Horarios del periodo actual: clases (6-22h, lun-sáb) + turnos de becas
    $periodo = date('Y') . '-' . (date('n') <= 6 ? '1' : '2');
    $filas = [];
    foreach ($salasAula as $s) {
        for ($d = 1; $d <= 6; $d++) {
            foreach ([6, 8, 10, 14, 16, 18, 20] as $h) {
                if (mt_rand(1, 100) <= 65) $filas[] = [$s, pick($U['profesor']), 'CLASE', $d, $periodo, sprintf('%02d:00:00', $h), sprintf('%02d:00:00', $h + 2), 'Clase ' . pick(['Programación', 'Bases de datos', 'Redes', 'Sistemas operativos', 'Ingeniería de software']), $U['admin'][0]];
            }
        }
    }
    foreach ($U['beca_trabajo'] as $b) for ($d = 1; $d <= 5; $d++) if (mt_rand(0, 1)) $filas[] = [$oficinaMto, $b, 'TURNO_BECA', $d, $periodo, '08:00:00', '12:00:00', 'Turno', $U['admin'][0]];
    bulk($pdo, 'horarios', ['id_sala', 'id_usuario', 'tipo', 'dia_semana', 'periodo', 'hora_inicio', 'hora_fin', 'detalle', 'id_creador'], $filas);
    paso('horarios: ' . count($filas));

    // ─── Tickets (3.000) ───
    $equipos = $pdo->query("SELECT id_equipo, id_sala, codigo FROM equipos")->fetchAll();
    $estados = array_merge(array_fill(0, 66, 'Cerrado'), array_fill(0, 10, 'Solucionado'), array_fill(0, 9, 'En proceso'), array_fill(0, 8, 'En espera'),
        array_fill(0, 3, 'Listo pero sin validar'), array_fill(0, 3, 'Descartado'), ['Aprobado']);
    $cats = ['HARDWARE', 'HARDWARE', 'SOFTWARE', 'SOFTWARE', 'RED', 'IMPRESORA', 'PROYECTOR', 'INFRAESTRUCTURA', 'OTRO'];
    $desc = ['El equipo no enciende', 'Pantalla azul al iniciar Windows', 'No hay conexión a internet', 'Teclado con teclas pegadas', 'El videobeam no proyecta',
        'Falta instalar NetBeans', 'Mouse no responde', 'Monitor parpadea', 'Impresora atasca papel', 'Aire acondicionado gotea', 'Ruido extraño en la torre'];
    $filas = [];
    for ($i = 1; $i <= 1800; $i++) {
        $origen = pick(['QR_ANONIMO', 'QR_ANONIMO', 'QR_ANONIMO', 'PANEL_INTERNO', 'PANEL_INTERNO', 'BECA_DIRECTO', 'TECNICO_DIRECTO', 'TECNICO_DIRECTO']);
        $eq = pick($equipos);
        $estado = pick($estados);
        $f = fechaAzar(365);
        $tec = $estado === 'En espera' ? null : pick($U['tecnico']);
        $beca = in_array($estado, ['En espera'], true) || mt_rand(0, 2) === 0 ? null : pick($U['beca_trabajo']);
        $user = $origen === 'QR_ANONIMO' ? null : ($origen === 'PANEL_INTERNO' ? pick($U['secretaria']) : ($origen === 'BECA_DIRECTO' ? pick($U['beca_trabajo']) : pick($U['tecnico'])));
        $cerrado = in_array($estado, ['Cerrado', 'Solucionado', 'Aprobado', 'Descartado'], true);
        $filas[] = [$user, $tec, $beca, $eq['id_sala'], $eq['id_equipo'], $origen, mt_rand(1, 5) === 1 ? 'REQUERIMIENTO' : 'INCIDENTE', pick($cats),
            strtoupper(substr(md5("t$i"), 0, 10)), $origen === 'QR_ANONIMO' ? 'Persona QR ' . $i : null, $origen === 'QR_ANONIMO' ? "reporte$i@ufps.edu.co" : null,
            $DEP, 'Aula Sur', $eq['codigo'], pick($desc) . " (#$i)", 'Lenovo ThinkCentre', 'UFPS-' . mt_rand(10000, 99999),
            $cerrado ? 'Se revisó y se solucionó el problema reportado.' : null, $estado, $estado === 'Cerrado' ? 1 : 0, $f,
            $cerrado ? date('Y-m-d H:i:s', strtotime($f) + mt_rand(3600, 6 * 86400)) : null];
    }
    bulk($pdo, 'tickets', ['user_id', 'tecnico_id', 'beca_id', 'id_sala', 'id_equipo', 'origen', 'tipo_solicitud', 'categoria', 'codigo_seguimiento',
        'nombre_contacto', 'correo_contacto', 'nombre_dependencia', 'ubicacion', 'equipo', 'descripcion', 'marca_modelo', 'numero_inventario', 'solucion',
        'estado', 'firmado', 'fecha_creacion', 'fecha_terminacion'], $filas);
    $filas = [];
    for ($i = 1; $i <= 1200; $i++) {
        $tipo = mt_rand(1, 10) <= 6 ? 'danio' : 'requerimiento';
        $sub = $tipo === 'requerimiento' ? pick(['CAMBIO_SALA', 'INSTALACION_SOFTWARE', 'PRESTAMO_SALA', 'PRESTAMO_EQUIPO']) : null;
        $s = pick($salasAula);
        $estado = pick($estados);
        $f = fechaAzar(365);
        $cerrado = in_array($estado, ['Cerrado', 'Solucionado', 'Aprobado', 'Descartado'], true);
        $filas[] = [pick($U['profesor']), $tipo, mt_rand(0, 3) ? 'PANEL_PROFESOR' : 'QR_PROFESOR', pick($cats), $sub, $s, $f, $estado,
            $sub === 'INSTALACION_SOFTWARE' ? 'Python 3.12' : null, $tipo === 'requerimiento' ? "Sala $s" : null, $tipo === 'danio' ? "Sala $s" : null,
            $sub && str_starts_with($sub, 'PRESTAMO') ? date('Y-m-d', strtotime($f) + 86400 * 3) : null,
            $tipo === 'danio' ? pick($desc) . " (prof #$i)" : null, $cerrado ? 'Atendido por el equipo de mantenimiento.' : null,
            $estado === 'En espera' ? null : pick($U['tecnico']), mt_rand(0, 1) ? pick($U['beca_trabajo']) : null, $estado === 'Cerrado' ? 1 : 0,
            $cerrado ? date('Y-m-d H:i:s', strtotime($f) + mt_rand(3600, 5 * 86400)) : null];
    }
    bulk($pdo, 'tickets_profesor', ['profesor_id', 'tipo_ticket', 'origen', 'categoria', 'subtipo_requerimiento', 'id_sala', 'fecha_creacion', 'estado',
        'software', 'sala_requerimiento', 'sala_danio', 'fecha_devolucion_prevista', 'descripcion_problema', 'solucion', 'tecnico_id', 'beca_id', 'firmado',
        'fecha_solucion'], $filas);
    paso('tickets: 1800 + tickets_profesor: 1200');

    satelitesTickets($pdo, 0, 0);
    paso('SLA, eventos, comentarios, asignaciones, encuestas');

    // Mantenimientos programados (4 por sala en el año) + checklist por equipo
    $filas = [];
    foreach ($salasAula as $s) for ($k = 0; $k < 4; $k++) {
        $f = date('Y-m-d H:i:s', strtotime('-300 days') + $k * 90 * 86400 + mt_rand(0, 20) * 86400);
        $filas[] = [$s, pick(['MANTENIMIENTO_PREVENTIVO', 'MANTENIMIENTO_PREVENTIVO', 'INSTALACION_SOFTWARE']), 'Mantenimiento de sala', $f, pick($U['tecnico']), $U['admin'][0],
            strtotime($f) < time() ? 'REALIZADO' : 'PROGRAMADO'];
    }
    bulk($pdo, 'mantenimientos_programados', ['id_sala', 'tipo', 'descripcion', 'fecha_programada', 'id_responsable', 'id_creador', 'estado'], $filas);
    q($pdo, "INSERT INTO mantenimiento_equipos (id_programado, id_equipo, estado, id_usuario_ultimo, fecha_actualizacion)
             SELECT m.id_programado, e.id_equipo, IF(m.estado='REALIZADO','REALIZADO','PENDIENTE'), m.id_responsable, m.fecha_programada
             FROM mantenimientos_programados m JOIN equipos e ON e.id_sala = m.id_sala");
    paso('mantenimientos: ' . count($filas));

    // Notificaciones (≈30k), auditoría (≈60k)
    $todos = array_merge($U['tecnico'], $U['beca_trabajo'], $U['profesor'], $U['secretaria'], $U['admin']);
    $filas = [];
    for ($i = 0; $i < 30000; $i++) $filas[] = [pick($todos), pick(['TICKET_ESTADO', 'MANTENIMIENTO', 'REQUERIMIENTO']), 'El ticket #' . mt_rand(1, 1800) . ' cambió de estado', 'tickets.php', mt_rand(1, 100) <= 85 ? 1 : 0, fechaAzar(365)];
    bulk($pdo, 'notificaciones', ['id_usuario', 'tipo', 'mensaje', 'url_destino', 'leido', 'fecha_creacion'], $filas);
    $filas = [];
    for ($i = 0; $i < 60000; $i++) $filas[] = [pick($todos), pick(['tickets', 'tickets_profesor', 'componentes', 'horarios', 'users']), mt_rand(1, 3000), pick(['CREAR', 'EDITAR', 'EDITAR', 'EDITAR', 'BORRAR']), 'Cambio de prueba de carga', fechaAzar(365)];
    bulk($pdo, 'auditoria', ['id_usuario', 'tabla_afectada', 'id_registro', 'accion', 'detalle', 'fecha_hora'], $filas);
    paso('notificaciones 30k, auditoría 60k');

    // Bitácora de accesos (40k) con la cadena de hashes válida (misma fórmula que BitacoraAccesos::hashFila)
    $ant = str_repeat('0', 64);
    $filas = [];
    $ts = time() - 365 * 86400;
    for ($i = 0; $i < 40000; $i++) {
        $ts += mt_rand(300, 1200);
        $fecha = date('Y-m-d H:i:s', min($ts, time() - 60));
        $ev = mt_rand(1, 20) === 1 ? 'LOGIN_FALLIDO' : pick(['LOGIN_OK', 'LOGOUT']);
        $uid = pick($todos);
        $correo = "usuario$uid@ufps.edu.co";
        $ip = '10.0.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);
        $ag = 'Mozilla/5.0';
        $h = hash('sha256', implode("\x1F", [$ant, $fecha, $ev, (string)$uid, $correo, $ip, $ag, '']));
        $filas[] = [$fecha, $ev, $uid, $correo, $ip, $ag, null, $ant, $h];
        $ant = $h;
    }
    bulk($pdo, 'bitacora_accesos', ['fecha', 'evento', 'id_usuario', 'correo', 'ip', 'agente', 'detalle', 'hash_anterior', 'hash'], $filas);
    paso('bitácora 40k (cadena SHA-256 válida)');
    q($pdo, 'SET FOREIGN_KEY_CHECKS=1');
}

// ───────────────────────────── COMPONENTES ─────────────────────────────
if (isset($opt['componentes'])) {
    $meta = (int)$opt['componentes'];
    q($pdo, 'SET FOREIGN_KEY_CHECKS=0');
    q($pdo, 'SET unique_checks=0');
    $actual = (int)$pdo->query('SELECT COUNT(*) FROM componentes')->fetchColumn();
    $responsable = (int)$pdo->query("SELECT id FROM users WHERE role='tecnico' ORDER BY id LIMIT 1")->fetchColumn();
    if ($actual === 0) {
        // 1) Lo instalado: 5 piezas por equipo (torre, monitor, teclado, mouse, parlante)
        q($pdo, "INSERT INTO componentes (tipo, id_categoria, serial, codigo_inventario, marca, modelo, descripcion, estado, id_equipo_actual, fecha_registro, fecha_compra)
                 SELECT ELT(s.seq,'torre','monitor','teclado','mouse','parlante'), s.seq, CONCAT('SN-I-', e.id_equipo, '-', s.seq), CONCAT('UFPS-I', LPAD(e.id_equipo,5,'0'), s.seq),
                        ELT(1 + (e.id_equipo % 3),'Lenovo','HP','Dell'), CONCAT('Modelo ', e.id_equipo % 7), 'Instalado en puesto', IF(e.operativo, 'ACTIVO', 'EN_REVISION'), e.id_equipo,
                        NOW() - INTERVAL (e.id_equipo % 900) DAY, CURDATE() - INTERVAL (e.id_equipo % 1500) DAY
                 FROM equipos e JOIN seq_1_to_5 s");
        // 2) Infraestructura de cada sala (sillas, aires, iluminación, tomas, videobeam)
        q($pdo, "INSERT INTO componentes (tipo, serial, codigo_inventario, marca, descripcion, estado, id_sala_directa, fecha_registro)
                 SELECT ELT(1 + (s.seq % 10),'silla','silla','silla','silla','silla','aire_acondicionado','iluminacion','iluminacion','toma_electrica','videobeam'),
                        CONCAT('SN-S-', sa.id_sala, '-', s.seq), CONCAT('UFPS-S', LPAD(sa.id_sala,3,'0'), LPAD(s.seq,3,'0')), 'Genérico', 'Infraestructura de sala', 'ACTIVO', sa.id_sala, NOW()
                 FROM salas sa JOIN seq_1_to_45 s");
        $actual = (int)$pdo->query('SELECT COUNT(*) FROM componentes')->fetchColumn();
        paso("instalado + infraestructura: $actual");
    }
    // 3) Resto hasta la meta: bodega/historico de la universidad (sin equipo), con mezcla de estados
    $lote = 250000;
    while ($actual < $meta) {
        $n = min($lote, $meta - $actual);
        $desde = $actual + 1;
        $hasta = $actual + $n;
        q($pdo, "INSERT INTO componentes (tipo, id_categoria, serial, codigo_inventario, marca, modelo, descripcion, estado, fecha_registro, fecha_compra, valor_compra)
                 SELECT ELT(1 + (seq % 9),'torre','monitor','teclado','mouse','parlante','videobeam','portatil','todo_en_uno','otro'), 1 + (seq % 8),
                        CONCAT('SN', UPPER(SUBSTR(MD5(seq),1,10)), seq), CONCAT('UFPS-', LPAD(seq, 8, '0')),
                        ELT(1 + (seq % 6),'Lenovo','HP','Dell','Epson','Logitech','Genius'), CONCAT('M', seq % 120), 'Inventario general / bodega',
                        CASE WHEN seq % 200 = 0 THEN 'EN_REVISION' WHEN seq % 100 = 1 THEN 'DEFECTUOSO' WHEN seq % 5 = 0 THEN 'DE_BAJA' ELSE 'ACTIVO' END,
                        NOW() - INTERVAL (seq % 3650) DAY, CURDATE() - INTERVAL (seq % 3650) DAY, 50000 + (seq % 3000000)
                 FROM seq_{$desde}_to_{$hasta}");
        $actual = $hasta;
        paso("componentes: $actual");
    }
    // Historial: un REGISTRO por componente que aún no lo tenga + ASIGNACION/DESASIGNACION para lo instalado
    $maxHist = (int)$pdo->query('SELECT COALESCE(MAX(id_componente),0) FROM historial_componente')->fetchColumn();
    $maxComp = (int)$pdo->query('SELECT MAX(id_componente) FROM componentes')->fetchColumn();
    for ($d = $maxHist + 1; $d <= $maxComp; $d += 250000) {
        $h = min($d + 249999, $maxComp);
        q($pdo, "INSERT INTO historial_componente (id_componente, tipo_evento, estado_nuevo, id_equipo_nuevo, id_usuario_responsable, motivo, fecha_evento)
                 SELECT id_componente, 'REGISTRO', estado, id_equipo_actual, $responsable, 'Carga inicial', fecha_registro FROM componentes WHERE id_componente BETWEEN $d AND $h");
        paso("historial REGISTRO hasta $h");
    }
    if ($maxHist === 0) {
        q($pdo, "INSERT INTO historial_componente (id_componente, tipo_evento, id_equipo_anterior, estado_anterior, estado_nuevo, id_usuario_responsable, motivo, fecha_evento)
                 SELECT id_componente, 'DESASIGNACION', id_equipo_actual, 'ACTIVO', 'EN_REVISION', $responsable, 'Cambio de pieza', NOW() - INTERVAL (id_componente % 200) DAY
                 FROM componentes WHERE id_equipo_actual IS NOT NULL AND id_componente % 4 = 0");
        q($pdo, "INSERT INTO movimientos_activos (id_componente, id_equipo, id_sala_origen, id_sala_destino, motivo, id_solicito, id_aprobo, id_ejecuto, fecha_aprobado, fecha_ejecutado)
                 SELECT c.id_componente, c.id_equipo_actual, e.id_sala, (SELECT id_sala FROM salas WHERE es_oficina_mantenimiento=1 LIMIT 1), 'Traslado a oficina', $responsable, $responsable, $responsable, NOW(), NOW()
                 FROM componentes c JOIN equipos e ON e.id_equipo = c.id_equipo_actual WHERE c.id_componente % 10 = 0");
        paso('historial de movimientos');
    }
    q($pdo, 'SET unique_checks=1');
    q($pdo, 'SET FOREIGN_KEY_CHECKS=1');
    $pdo->query('ANALYZE TABLE componentes, historial_componente, tickets, tickets_profesor')->fetchAll();
}

// ───────────────────── MÁS AÑOS DE TICKETS (¿hasta dónde llega?) ─────────────────────
// --multiplicar-tickets=10 → copia los tickets actuales como si fueran 9 años anteriores (ya cerrados y firmados).
if (isset($opt['multiplicar-tickets'])) {
    $veces = max(2, (int)$opt['multiplicar-tickets']);
    q($pdo, 'SET FOREIGN_KEY_CHECKS=0');
    $maxT = (int)$pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn();
    $maxTP = (int)$pdo->query('SELECT MAX(id) FROM tickets_profesor')->fetchColumn();
    $colsT = 'user_id, tecnico_id, beca_id, id_sala, id_equipo, origen, tipo_solicitud, categoria, nombre_contacto, correo_contacto, nombre_dependencia, ubicacion, equipo, descripcion, marca_modelo, numero_inventario, solucion';
    $colsTP = 'profesor_id, tipo_ticket, origen, categoria, subtipo_requerimiento, id_sala, software, sala_requerimiento, sala_danio, descripcion_problema, solucion, tecnico_id, beca_id';
    for ($k = 1; $k < $veces; $k++) {
        q($pdo, "INSERT INTO tickets ($colsT, codigo_seguimiento, estado, firmado, fecha_creacion, fecha_terminacion)
                 SELECT $colsT, UPPER(LEFT(MD5(CONCAT(id, '-', $k, '-', UUID())), 12)), 'Cerrado', 1, fecha_creacion - INTERVAL $k YEAR, COALESCE(fecha_terminacion, fecha_creacion + INTERVAL 2 DAY) - INTERVAL $k YEAR
                 FROM tickets WHERE id <= $maxT");
        q($pdo, "INSERT INTO tickets_profesor ($colsTP, estado, firmado, fecha_creacion, fecha_solucion)
                 SELECT $colsTP, 'Cerrado', 1, fecha_creacion - INTERVAL $k YEAR, COALESCE(fecha_solucion, fecha_creacion + INTERVAL 2 DAY) - INTERVAL $k YEAR
                 FROM tickets_profesor WHERE id <= $maxTP");
    }
    satelitesTickets($pdo, $maxT, $maxTP);
    q($pdo, 'SET FOREIGN_KEY_CHECKS=1');
    $pdo->query('ANALYZE TABLE tickets, tickets_profesor, ticket_eventos, ticket_comentarios, ticket_sla')->fetchAll();
    paso('tickets: ' . $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn() . ' + tickets_profesor: ' . $pdo->query('SELECT COUNT(*) FROM tickets_profesor')->fetchColumn());
}
paso('listo');
