<?php
require_once __DIR__ . '/css_helper.php';
/**
 * Pruebas de regresión del módulo Tickets (pasos 2 y 3 de la refactorización).
 *
 * USO (solo consola):
 *   1. Crea una base de PRUEBAS aparte e impórtale el esquema.
 *      database/tickets_db.sql ya no borra nada (CREATE ... IF NOT EXISTS),
 *      pero crea/usa `tickets_db`: para NO tocar la base real, cambia el
 *      nombre al vuelo (Linux/Mac/Git Bash):
 *        mysql -u root -e "CREATE DATABASE tickets_db_test CHARACTER SET utf8mb4"
 *        sed 's/`tickets_db`/`tickets_db_test`/g' database/tickets_db.sql | mysql -u root tickets_db_test
 *      (En Windows/XAMPP sin sed: abre una copia del .sql y reemplaza las
 *      apariciones de `tickets_db` por `tickets_db_test` antes de importar.)
 *      Luego aplica las migraciones: APP_DB_NAME=tickets_db_test php tools/migrar.php
 *   2. Ejecuta:
 *        php tests/run_tests.php            (usa root@localhost sin clave)
 *      o con variables de entorno:
 *        TEST_DB_HOST=localhost TEST_DB_NAME=tickets_db_test \
 *        TEST_DB_USER=root TEST_DB_PASS=  php tests/run_tests.php
 *
 * SEGURIDAD: se niega a correr si el nombre de la base no termina en
 * "_test" (para no tocar datos reales). Todo lo que inserta lo revierte
 * con ROLLBACK al final.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo por línea de comandos.');
}
date_default_timezone_set('America/Bogota'); // misma zona que bootstrap.php: «hoy» no cambia de día antes que MySQL

$host = getenv('TEST_DB_HOST') ?: 'localhost';
$name = getenv('TEST_DB_NAME') ?: 'tickets_db_test';
$user = getenv('TEST_DB_USER') ?: 'root';
$pass = getenv('TEST_DB_PASS') !== false ? getenv('TEST_DB_PASS') : '';

if (!preg_match('/_test$/', $name)) {
    fwrite(STDERR, "Por seguridad la base de pruebas debe terminar en _test (recibido: $name)\n");
    exit(2);
}

$pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

require_once __DIR__ . '/../app/Support/autoload.php';

$ok = 0; $fail = 0;
function check(string $desc, bool $cond): void {
    global $ok, $fail;
    if ($cond) { $ok++; echo "  PASS  $desc\n"; }
    else       { $fail++; echo "  FAIL  $desc\n"; }
}
function fila(PDO $pdo, string $tabla, int $id): array {
    $s = $pdo->prepare("SELECT tecnico_id, estado FROM `$tabla` WHERE id=?");
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
}
function nAsig(PDO $pdo, string $tabla, int $id, bool $abiertas = false): int {
    $sql = "SELECT COUNT(*) FROM ticket_asignaciones WHERE tabla_ticket=? AND id_ticket=?"
         . ($abiertas ? " AND fecha_liberado IS NULL" : "");
    $s = $pdo->prepare($sql); $s->execute([$tabla, $id]);
    return (int)$s->fetchColumn();
}

$pdo->beginTransaction();
try {
    // ---------- Fixtures (se revierten al final) ----------
    $h = password_hash('x', PASSWORD_DEFAULT);
    $insU = $pdo->prepare("INSERT INTO users (codigo,correo,password,password_temporal,role,nombre_completo,cargo,dependencia,estado_activo)
                           VALUES (?,?,?,0,?,?,?,?,1)");
    $insU->execute(['TT1','tt1@test.local',$h,'tecnico','Tec 1','Tecnico','Sistemas']);
    $tec1 = (int)$pdo->lastInsertId();
    $insU->execute(['TT2','tt2@test.local',$h,'tecnico','Tec 2','Tecnico','Sistemas']);
    $tec2 = (int)$pdo->lastInsertId();
    $insU->execute(['BB1','bb1@test.local',$h,'beca_trabajo','Beca 1','Beca','Sistemas']);
    $beca1 = (int)$pdo->lastInsertId();
    $insU->execute(['BB2','bb2@test.local',$h,'beca_trabajo','Beca 2','Beca','Sistemas']);
    $beca2 = (int)$pdo->lastInsertId();
    $insU->execute(['PP1','pp1@test.local',$h,'profesor','Prof','Docente','Sistemas']);
    $prof = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO salas (nombre_sala,abreviatura,dependencia,codigo_qr_token) VALUES
                ('Sala TEST SIS','TSI','Departamento de Sistemas e Informatica','tk_test_sis'),
                ('Sala TEST DER','TDE','Facultad de Derecho','tk_test_der')");
    $salaSis = (int)$pdo->query("SELECT id_sala FROM salas WHERE codigo_qr_token='tk_test_sis'")->fetchColumn();
    $salaDer = (int)$pdo->query("SELECT id_sala FROM salas WHERE codigo_qr_token='tk_test_der'")->fetchColumn();

    $insT = $pdo->prepare("INSERT INTO tickets (origen,id_sala,tecnico_id,estado,user_id,firmado) VALUES (?,?,?,?,?,?)");
    $mk = function (string $estado, ?int $tec = null, int $sala = 0, ?int $uid = null, int $firmado = 0) use ($pdo, $insT, $salaSis): int {
        $insT->execute(['QR_ANONIMO', $sala ?: $salaSis, $tec, $estado, $uid, $firmado]);
        return (int)$pdo->lastInsertId();
    };
    $insP = $pdo->prepare("INSERT INTO tickets_profesor (profesor_id,origen,id_sala,id_sala_actual,tecnico_id,estado) VALUES (?,?,?,?,?,?)");
    $mkP = function (string $estado, ?int $tec = null, int $sala = 0, ?int $salaAct = null) use ($pdo, $insP, $prof, $salaSis): int {
        $insP->execute([$prof, 'QR_PROFESOR', $sala ?: $salaSis, $salaAct, $tec, $estado]);
        return (int)$pdo->lastInsertId();
    };
    // Foto de evidencia simulada (solo la fila; la regla de cierre cuenta filas con categoria = EVIDENCIA).
    $insEv = $pdo->prepare("INSERT INTO ticket_adjuntos (tabla_ticket,id_ticket,nombre_original,nombre_almacenado,mime_type,tamano_bytes,id_usuario_subio,categoria)
                            VALUES (?,?,'foto.jpg',?,'image/jpeg',100,?,'EVIDENCIA')");
    $evidencia = function (string $tabla, int $id, int $idUsuario) use ($insEv): void {
        $insEv->execute([$tabla, $id, bin2hex(random_bytes(16)) . '.jpg', $idUsuario]);
    };

    // ==================== TicketAsignacionService::tomar ====================
    echo "\n[TicketAsignacionService::tomar / liberar]\n";
    $a = $mk('En espera');
    check('tomar ticket libre En espera devuelve true', TicketAsignacionService::tomar($pdo, 'tickets', $a, $tec1) === true);
    $f = fila($pdo, 'tickets', $a);
    check('  queda En proceso y asignado al técnico', $f['estado'] === 'En proceso' && (int)$f['tecnico_id'] === $tec1);
    check('  registra 1 asignación abierta', nAsig($pdo, 'tickets', $a, true) === 1);
    check('otro técnico NO puede tomar uno ya asignado', TicketAsignacionService::tomar($pdo, 'tickets', $a, $tec2) === false);
    check('  el dueño sigue siendo el primero', (int)fila($pdo, 'tickets', $a)['tecnico_id'] === $tec1);

    $d = $mk('Descartado');
    check('NO se puede "tomar" un ticket Descartado (antes lo reabría)', TicketAsignacionService::tomar($pdo, 'tickets', $d, $tec1) === false && fila($pdo, 'tickets', $d)['estado'] === 'Descartado');
    $c = $mk('Cerrado', null, 0, null, 1);
    check('NO se puede "tomar" un ticket Cerrado', TicketAsignacionService::tomar($pdo, 'tickets', $c, $tec1) === false && fila($pdo, 'tickets', $c)['estado'] === 'Cerrado');

    check('otro técnico NO puede liberar el ticket ajeno', TicketAsignacionService::liberar($pdo, 'tickets', $a, $tec2) === false && (int)fila($pdo, 'tickets', $a)['tecnico_id'] === $tec1);
    check('el dueño libera su ticket', TicketAsignacionService::liberar($pdo, 'tickets', $a, $tec1) === true);
    $f = fila($pdo, 'tickets', $a);
    check('  vuelve a En espera sin técnico', $f['estado'] === 'En espera' && $f['tecnico_id'] === null);
    check('  la asignación queda cerrada (0 abiertas, 1 en historial)', nAsig($pdo, 'tickets', $a, true) === 0 && nAsig($pdo, 'tickets', $a) === 1);
    check('se puede volver a tomar tras liberar (2 asignaciones en historial)', TicketAsignacionService::tomar($pdo, 'tickets', $a, $tec2) === true && nAsig($pdo, 'tickets', $a) === 2);

    $s = $mk('Solucionado', $tec1);
    check('liberar un Solucionado (aún sin firmar) funciona como antes', TicketAsignacionService::liberar($pdo, 'tickets', $s, $tec1) === true && fila($pdo, 'tickets', $s)['estado'] === 'En espera');
    $cf = $mk('Cerrado', $tec1, 0, null, 1);
    check('NO se libera un ticket Cerrado/firmado', TicketAsignacionService::liberar($pdo, 'tickets', $cf, $tec1) === false && fila($pdo, 'tickets', $cf)['estado'] === 'Cerrado');

    $audit = $pdo->prepare("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='tickets' AND id_registro=?");
    $audit->execute([$a]);
    check('la transición al tomar queda en auditoría', (int)$audit->fetchColumn() >= 1);

    // ---- tickets_profesor ----
    $p = $mkP('En espera');
    check('tickets_profesor: tomar', TicketAsignacionService::tomar($pdo, 'tickets_profesor', $p, $tec1) === true && fila($pdo, 'tickets_profesor', $p)['estado'] === 'En proceso');
    check('tickets_profesor: otro técnico no lo toma', TicketAsignacionService::tomar($pdo, 'tickets_profesor', $p, $tec2) === false);
    check('tickets_profesor: liberar', TicketAsignacionService::liberar($pdo, 'tickets_profesor', $p, $tec1) === true && fila($pdo, 'tickets_profesor', $p)['estado'] === 'En espera');
    $pd = $mkP('Descartado');
    check('tickets_profesor: no se toma uno Descartado', TicketAsignacionService::tomar($pdo, 'tickets_profesor', $pd, $tec1) === false);

    // ==================== Visibilidad de la beca ====================
    echo "\n[Regla de visibilidad de beca_trabajo]\n";
    $q1 = $mk('En espera', null, $salaSis);            // sala Sistemas, libre
    $q2 = $mk('En espera', null, $salaDer);            // sala externa, libre
    $q3 = $mk('En proceso', $beca1, $salaDer);         // externa, asignado a beca1
    $q4 = $mk('En espera', null, $salaDer, $beca1);    // externa, creado por beca1
    $p1 = $mkP('En espera', null, $salaSis);
    $p2 = $mkP('En espera', null, $salaDer);
    $p3 = $mkP('En proceso', $beca1, $salaDer);
    $p4 = $mkP('En espera', null, $salaDer, $salaSis); // cambio de sala: destino externa, actual Sistemas
    $casos = [
        ['tickets', $q1, true,  true,  'QR sala Sistemas libre'],
        ['tickets', $q2, false, false, 'QR sala externa libre'],
        ['tickets', $q3, true,  false, 'QR externa asignado a beca1'],
        ['tickets', $q4, true,  false, 'ticket externo creado por beca1'],
        ['tickets_profesor', $p1, true,  true,  'profesor sala Sistemas'],
        ['tickets_profesor', $p2, false, false, 'profesor sala externa libre'],
        ['tickets_profesor', $p3, true,  false, 'profesor externa asignado a beca1'],
        ['tickets_profesor', $p4, true,  true,  'profesor cambio de sala (actual = Sistemas)'],
    ];
    foreach ($casos as [$tabla, $id, $ve1, $ve2, $desc]) {
        check("beca1 " . ($ve1 ? 've' : 'NO ve') . " $desc", TicketConsultas::becaPuedeVerTicket($pdo, $beca1, $tabla, $id) === $ve1);
        check("beca2 " . ($ve2 ? 've' : 'NO ve') . " $desc", TicketConsultas::becaPuedeVerTicket($pdo, $beca2, $tabla, $id) === $ve2);
    }
    check('tabla inválida no se acepta', TicketConsultas::becaPuedeVerTicket($pdo, $beca1, 'users', 1) === false);

    // ==================== asignar / quitar beca ====================
    echo "\n[TicketAsignacionService::asignarBeca / quitarBeca]\n";
    require_once __DIR__ . '/../app/Domain/Tickets/TicketPermissions.php';
    $fb = function (PDO $pdo, string $tabla, int $id): array {
        $s = $pdo->prepare("SELECT tecnico_id, beca_id, estado FROM `$tabla` WHERE id=?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $inactiva = null;
    $insU->execute(['BB3','bb3@test.local',$h,'beca_trabajo','Beca 3 inactiva','Beca','Sistemas']);
    $inactiva = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE users SET estado_activo=0 WHERE id=?")->execute([$inactiva]);

    // ticket libre en sala EXTERNA: asignar a beca lo toma el técnico y deja la beca como ejecutora
    $a1 = $mk('En espera', null, $salaDer);
    $r = TicketAsignacionService::asignarBeca($pdo, 'tickets', $a1, $tec1, $beca1);
    $f = $fb($pdo, 'tickets', $a1);
    check('asignar beca a ticket libre: ok', $r['ok'] === true);
    check('  el técnico queda como responsable (tecnico_id) y la beca en beca_id', (int)$f['tecnico_id'] === $tec1 && (int)$f['beca_id'] === $beca1 && $f['estado'] === 'En proceso');
    check('  la beca ahora ve el ticket externo (regla de visibilidad)', TicketConsultas::becaPuedeVerTicket($pdo, $beca1, 'tickets', $a1) === true);
    check('  la otra beca NO lo ve', TicketConsultas::becaPuedeVerTicket($pdo, $beca2, 'tickets', $a1) === false);
    check('  la beca es ejecutora (becaEsEjecutora)', TicketConsultas::becaEsEjecutora($f, $beca1) === true && TicketConsultas::becaEsEjecutora($f, $beca2) === false);
    check('  historial: asignación del técnico y de la beca', nAsig($pdo, 'tickets', $a1, true) === 2);

    $r = TicketAsignacionService::asignarBeca($pdo, 'tickets', $a1, $tec2, $beca2);
    check('otro técnico NO puede asignar beca a un ticket ajeno', $r['ok'] === false && (int)$fb($pdo, 'tickets', $a1)['beca_id'] === $beca1);
    $r = TicketAsignacionService::asignarBeca($pdo, 'tickets', $a1, $tec1, $inactiva);
    check('no se asigna una beca inactiva', $r['ok'] === false);
    $r = TicketAsignacionService::asignarBeca($pdo, 'tickets', $a1, $tec1, $tec2);
    check('no se asigna a alguien que no es beca_trabajo', $r['ok'] === false);

    $r = TicketAsignacionService::asignarBeca($pdo, 'tickets', $a1, $tec1, $beca2);
    check('reasignar a otra beca funciona y avisa a la anterior', $r['ok'] === true && $r['id_beca_anterior'] === $beca1 && (int)$fb($pdo, 'tickets', $a1)['beca_id'] === $beca2);
    check('  la beca anterior ya no lo ve (queda solo la nueva)', TicketConsultas::becaPuedeVerTicket($pdo, $beca1, 'tickets', $a1) === false && TicketConsultas::becaPuedeVerTicket($pdo, $beca2, 'tickets', $a1) === true);

    check('otro técnico NO puede quitar la beca', TicketAsignacionService::quitarBeca($pdo, 'tickets', $a1, $tec2)['ok'] === false);
    $r = TicketAsignacionService::quitarBeca($pdo, 'tickets', $a1, $tec1);
    check('el técnico responsable quita la beca', $r['ok'] === true && $r['id_beca'] === $beca2 && $fb($pdo, 'tickets', $a1)['beca_id'] === null);
    check('  el ticket sigue con el técnico y En proceso', (int)$fb($pdo, 'tickets', $a1)['tecnico_id'] === $tec1 && $fb($pdo, 'tickets', $a1)['estado'] === 'En proceso');
    check('quitar cuando no hay beca devuelve false', TicketAsignacionService::quitarBeca($pdo, 'tickets', $a1, $tec1)['ok'] === false);

    // estados que no admiten reasignación
    $sol = $mk('Solucionado', $tec1, $salaDer);
    check('no se asigna beca a un ticket Solucionado', TicketAsignacionService::asignarBeca($pdo, 'tickets', $sol, $tec1, $beca1)['ok'] === false);
    $cer = $mk('Cerrado', $tec1, $salaDer, null, 1);
    check('no se asigna beca a un ticket Cerrado/firmado', TicketAsignacionService::asignarBeca($pdo, 'tickets', $cer, $tec1, $beca1)['ok'] === false);
    $des = $mk('Descartado', null, $salaDer);
    check('no se asigna beca a un ticket Descartado', TicketAsignacionService::asignarBeca($pdo, 'tickets', $des, $tec1, $beca1)['ok'] === false);

    // tickets_profesor
    $pa = $mkP('En espera', null, $salaDer);
    check('tickets_profesor: asignar beca a ticket libre', TicketAsignacionService::asignarBeca($pdo, 'tickets_profesor', $pa, $tec1, $beca1)['ok'] === true
        && (int)$fb($pdo, 'tickets_profesor', $pa)['beca_id'] === $beca1 && (int)$fb($pdo, 'tickets_profesor', $pa)['tecnico_id'] === $tec1);
    check('tickets_profesor: la beca lo ve aunque sea sala externa', TicketConsultas::becaPuedeVerTicket($pdo, $beca1, 'tickets_profesor', $pa) === true);
    check('tickets_profesor: el técnico quita la beca', TicketAsignacionService::quitarBeca($pdo, 'tickets_profesor', $pa, $tec1)['ok'] === true);
    try { TicketAsignacionService::asignarBeca($pdo, 'users', 1, $tec1, $beca1); check('tabla inválida lanza excepción', false); }
    catch (InvalidArgumentException $e) { check('tabla inválida lanza excepción', true); }

    // ==================== Permisos por rol ====================
    echo "\n[TicketPermissions]\n";
    check('técnico puede ASSIGN, APPROVE, REJECT y DISCARD', TicketPermissions::tienePermiso('tecnico', TicketPermissions::ASSIGN)
        && TicketPermissions::tienePermiso('tecnico', TicketPermissions::APPROVE)
        && TicketPermissions::tienePermiso('tecnico', TicketPermissions::REJECT)
        && TicketPermissions::tienePermiso('tecnico', TicketPermissions::DISCARD));
    check('beca_trabajo NO puede ASSIGN ni APPROVE', !TicketPermissions::tienePermiso('beca_trabajo', TicketPermissions::ASSIGN)
        && !TicketPermissions::tienePermiso('beca_trabajo', TicketPermissions::APPROVE));
    check('profesor/solicitante NO pueden ASSIGN', !TicketPermissions::tienePermiso('profesor', TicketPermissions::ASSIGN)
        && !TicketPermissions::tienePermiso('solicitante', TicketPermissions::ASSIGN));
    check('rol desconocido no tiene permisos', !TicketPermissions::tienePermiso('hacker', TicketPermissions::VIEW));

    // ==================== descartar / actualizar / aprobar / rechazar ====================
    echo "\n[TicketSeguimientoService::descartar]\n";
    $g = function (string $tabla, int $id, string $col) use ($pdo) {
        $s = $pdo->prepare("SELECT `$col` FROM `$tabla` WHERE id=?"); $s->execute([$id]); return $s->fetchColumn();
    };
    $x = $mk('En proceso', $tec1);
    check('descartar ticket normal con motivo', TicketSeguimientoService::descartar($pdo, 'tickets', $x, $tec1, '  duplicado ') === true
        && fila($pdo, 'tickets', $x)['estado'] === 'Descartado' && $g('tickets', $x, 'solucion') === 'duplicado');
    $x = $mk('En espera');
    TicketSeguimientoService::descartar($pdo, 'tickets', $x, $tec1, '');
    check('  sin motivo guarda el texto por defecto', $g('tickets', $x, 'solucion') === 'Descartado sin motivo especificado');
    $x = $mk('Cerrado', $tec1, 0, null, 1);
    check('NO descarta un Cerrado', TicketSeguimientoService::descartar($pdo, 'tickets', $x, $tec1, 'x') === false && fila($pdo, 'tickets', $x)['estado'] === 'Cerrado');
    $x = $mk('Descartado');
    check('NO vuelve a descartar un Descartado', TicketSeguimientoService::descartar($pdo, 'tickets', $x, $tec1, 'x') === false);
    $x = $mk('En espera');
    check('  deja fila de auditoría', (int)$pdo->query("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='tickets' AND id_registro=$x")->fetchColumn() === 0
        && TicketSeguimientoService::descartar($pdo, 'tickets', $x, $tec1, 'a') && (int)$pdo->query("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='tickets' AND id_registro=$x")->fetchColumn() === 1);
    $p = $mkP('En espera'); $pdo->exec("UPDATE tickets_profesor SET tipo_ticket='danio', descripcion_problema='roto' WHERE id=$p");
    check('profesor daño: descarta y anexa motivo', TicketSeguimientoService::descartar($pdo, 'tickets_profesor', $p, $tec1, 'no aplica') === true
        && fila($pdo, 'tickets_profesor', $p)['estado'] === 'Descartado' && $g('tickets_profesor', $p, 'descripcion_problema') === 'roto [Descartado: no aplica]');
    $p = $mkP('En espera'); $pdo->exec("UPDATE tickets_profesor SET tipo_ticket='requerimiento' WHERE id=$p");
    check('profesor requerimiento: NO se descarta por esta vía', TicketSeguimientoService::descartar($pdo, 'tickets_profesor', $p, $tec1, 'x') === false && fila($pdo, 'tickets_profesor', $p)['estado'] === 'En espera');
    try { TicketSeguimientoService::descartar($pdo, 'users', 1, $tec1); check('descartar con tabla inválida lanza excepción', false); }
    catch (InvalidArgumentException $e) { check('descartar con tabla inválida lanza excepción', true); }

    echo "\n[actualizar estado]\n";
    $x = $mk('En proceso', $tec1, 0, $prof);
    $r = TicketEstadoService::actualizarTicketTecnico($pdo, $x, $tec1, ['estado' => 'Aprobado', 'solucion' => 'x']);
    check('técnico NO puede poner Aprobado por update_ticket', $r['ok'] === false && fila($pdo, 'tickets', $x)['estado'] === 'En proceso');
    $r = TicketEstadoService::actualizarTicketTecnico($pdo, $x, $tec1, ['estado' => 'Cerrado']);
    check('técnico NO puede poner Cerrado por update_ticket', $r['ok'] === false && fila($pdo, 'tickets', $x)['estado'] === 'En proceso');
    $r = TicketEstadoService::actualizarTicketTecnico($pdo, $x, $tec2, ['estado' => 'Solucionado']);
    check('otro técnico NO puede actualizar', $r['ok'] === false && fila($pdo, 'tickets', $x)['estado'] === 'En proceso');
    $r = TicketEstadoService::actualizarTicketTecnico($pdo, $x, $tec1, ['estado' => 'En espera', 'solucion' => 'pend']);
    check('estado intermedio se guarda y NO firma', $r['ok'] === true && fila($pdo, 'tickets', $x)['estado'] === 'En espera' && (int)$g('tickets', $x, 'firmado') === 0);
    // Reporte QR: Solucionado exige explicación (mín. 15 caracteres) + al menos una foto de evidencia.
    $r = TicketEstadoService::actualizarTicketTecnico($pdo, $x, $tec1, ['estado' => 'Solucionado', 'solucion' => 'Se cambió el cable de red del equipo']);
    check('QR: Solucionado SIN foto se rechaza', $r['ok'] === false && fila($pdo, 'tickets', $x)['estado'] === 'En espera');
    $evidencia('tickets', $x, $tec1);
    $r = TicketEstadoService::actualizarTicketTecnico($pdo, $x, $tec1, ['estado' => 'Solucionado', 'solucion' => 'listo']);
    check('QR: Solucionado con explicación corta se rechaza', $r['ok'] === false && fila($pdo, 'tickets', $x)['estado'] === 'En espera');
    $r = TicketEstadoService::actualizarTicketTecnico($pdo, $x, $tec1, ['estado' => 'Solucionado', 'solucion' => 'Se cambió el cable de red del equipo']);
    check('Solucionado firma, devuelve el dueño', $r['ok'] === true && $r['id_dueno'] === $prof && (int)$g('tickets', $x, 'firmado') === 1 && $g('tickets', $x, 'solucion') === 'Se cambió el cable de red del equipo');
    $r = TicketEstadoService::actualizarTicketTecnico($pdo, $x, $tec1, ['estado' => 'En proceso']);
    check('ticket firmado ya no se puede editar', $r['ok'] === false && fila($pdo, 'tickets', $x)['estado'] === 'Solucionado');
    $y = $mk('En proceso', $tec1);
    $r = TicketEstadoService::actualizarTicketTecnico($pdo, $y, $tec1, ['estado' => 'Listo pero sin validar', 'solucion' => 't']);
    check('Listo pero sin validar se guarda sin firmar', $r['ok'] === true && fila($pdo, 'tickets', $y)['estado'] === 'Listo pero sin validar' && (int)$g('tickets', $y, 'firmado') === 0);
    $pdo->exec("INSERT INTO equipos (id_sala,codigo) VALUES ($salaSis,'T6-EQ-SIS'),($salaDer,'T6-EQ-DER')");
    $eqSis = (int)$pdo->query("SELECT id_equipo FROM equipos WHERE codigo='T6-EQ-SIS'")->fetchColumn();
    $eqDer = (int)$pdo->query("SELECT id_equipo FROM equipos WHERE codigo='T6-EQ-DER'")->fetchColumn();
    $z = $mk('En proceso', $tec1, $salaSis);
    TicketEstadoService::actualizarTicketTecnico($pdo, $z, $tec1, ['estado' => 'En proceso', 'modo_resolucion' => 'sala_general', 'id_equipo_afectado' => $eqDer]);
    check('equipo de OTRA sala no se liga al ticket', $g('tickets', $z, 'id_equipo') === null);
    TicketEstadoService::actualizarTicketTecnico($pdo, $z, $tec1, ['estado' => 'En proceso', 'modo_resolucion' => 'sala_general', 'id_equipo_afectado' => $eqSis]);
    check('equipo de la misma sala sí se liga', (int)$g('tickets', $z, 'id_equipo') === $eqSis);
    $z2 = $mk('En proceso', $tec1, $salaSis);
    TicketEstadoService::actualizarTicketTecnico($pdo, $z2, $tec1, ['estado' => 'Solucionado', 'modo_resolucion' => 'sala_general', 'tipo_resolucion_general' => 'traslado_oficina']);
    check('traslado_oficina fuerza Listo pero sin validar', fila($pdo, 'tickets', $z2)['estado'] === 'Listo pero sin validar');

    $p = $mkP('En proceso', $tec1);
    check('profesor: estado inválido (Aprobado) rechazado', TicketEstadoService::actualizarEstadoProfesorTecnico($pdo, $p, $tec1, 'Aprobado') === false && fila($pdo, 'tickets_profesor', $p)['estado'] === 'En proceso');
    check('profesor: otro técnico rechazado', TicketEstadoService::actualizarEstadoProfesorTecnico($pdo, $p, $tec2, 'Solucionado') === false);
    check('profesor: Solucionado firma', TicketEstadoService::actualizarEstadoProfesorTecnico($pdo, $p, $tec1, 'Solucionado') === true && (int)$g('tickets_profesor', $p, 'firmado') === 1);
    check('profesor: firmado ya no se edita', TicketEstadoService::actualizarEstadoProfesorTecnico($pdo, $p, $tec1, 'En proceso') === false);

    $b = $mk('En proceso', $tec1);
    TicketAsignacionService::asignarBeca($pdo, 'tickets', $b, $tec1, $beca1);
    check('beca ejecutora NO puede poner Aprobado', TicketEstadoService::actualizarEstadoEjecutor($pdo, 'tickets', $b, $beca1, 'Aprobado', 'x') === false);
    check('beca ejecutora NO puede poner Cerrado', TicketEstadoService::actualizarEstadoEjecutor($pdo, 'tickets', $b, $beca1, 'Cerrado', 'x') === false);
    check('beca que no es ejecutora rechazada', TicketEstadoService::actualizarEstadoEjecutor($pdo, 'tickets', $b, $beca2, 'Solucionado', 'x') === false);
    check('QR: origen distinto rechazado', TicketEstadoService::actualizarEstadoEjecutor($pdo, 'tickets', $b, $beca1, 'En proceso', 'x', 'OTRO_ORIGEN') === false);
    check('beca: Solucionado SIN foto se rechaza (QR)', TicketEstadoService::actualizarEstadoEjecutor($pdo, 'tickets', $b, $beca1, 'Solucionado', 'Se reinició el switch de la sala', 'QR_ANONIMO') === false);
    $evidencia('tickets', $b, $beca1);
    check('beca ejecutora pone Solucionado (con origen QR)', TicketEstadoService::actualizarEstadoEjecutor($pdo, 'tickets', $b, $beca1, 'Solucionado', 'Se reinició el switch de la sala', 'QR_ANONIMO') === true
        && fila($pdo, 'tickets', $b)['estado'] === 'Solucionado' && $g('tickets', $b, 'solucion') === 'Se reinició el switch de la sala');
    // Profesor: daño e instalación de software exigen evidencia; préstamo no.
    $pd = $mkP('En proceso', $tec1); $pdo->exec("UPDATE tickets_profesor SET tipo_ticket='danio' WHERE id=$pd");
    check('profesor daño: Solucionado sin foto se rechaza', TicketEstadoService::actualizarEstadoProfesorTecnico($pdo, $pd, $tec1, 'Solucionado', 'Se cambió el HDMI del videobeam') === false);
    $evidencia('tickets_profesor', $pd, $tec1);
    check('profesor daño: con foto y explicación queda Solucionado y guarda la solución', TicketEstadoService::actualizarEstadoProfesorTecnico($pdo, $pd, $tec1, 'Solucionado', 'Se cambió el HDMI del videobeam') === true
        && $g('tickets_profesor', $pd, 'solucion') === 'Se cambió el HDMI del videobeam' && $g('tickets_profesor', $pd, 'fecha_solucion') !== null);
    $ps = $mkP('En proceso', $tec1); $pdo->exec("UPDATE tickets_profesor SET tipo_ticket='requerimiento', subtipo_requerimiento='INSTALACION_SOFTWARE' WHERE id=$ps");
    check('instalación de software exige evidencia', TicketEvidencia::exige('tickets_profesor', TicketRepository::crudo($pdo, 'tickets_profesor', $ps)) === true
        && TicketEstadoService::actualizarEstadoProfesorTecnico($pdo, $ps, $tec1, 'Solucionado', 'Instalado en los 20 equipos') === false);
    $pp = $mkP('En proceso', $tec1); $pdo->exec("UPDATE tickets_profesor SET tipo_ticket='requerimiento', subtipo_requerimiento='PRESTAMO_EQUIPO' WHERE id=$pp");
    check('préstamo NO exige evidencia', TicketEstadoService::actualizarEstadoProfesorTecnico($pdo, $pp, $tec1, 'Solucionado') === true);

    echo "\n[Conversación con quien reportó]\n";
    $pc = $mkP('En espera');
    $pdo->exec("UPDATE tickets_profesor SET tipo_ticket='danio', fecha_creacion = NOW() WHERE id=$pc");
    $rc = TicketConversacion::enviarPersonal($pdo, 'tickets_profesor', $pc, $tec1, 'hola');
    check('personal sin tomar el ticket NO escribe', $rc['ok'] === false);
    $rc = TicketConversacion::enviarSolicitante($pdo, 'tickets_profesor', $pc, $prof, 'urgente');
    check('profesor NO reitera antes del tiempo de respuesta', $rc['ok'] === false && strpos($rc['mensaje'], 'Podrás reiterarla') !== false);
    $pdo->exec("UPDATE tickets_profesor SET fecha_creacion = '2020-01-06 08:00:00' WHERE id=$pc");
    $rc = TicketConversacion::enviarSolicitante($pdo, 'tickets_profesor', $pc, $prof, 'sigue dañado');
    $msgs = TicketConversacion::deTicket($pdo, 'tickets_profesor', $pc);
    check('profesor reitera cuando vence el tiempo (queda marcada)', $rc['ok'] === true && count($msgs) === 1 && (int)$msgs[0]['es_reiteracion'] === 1);
    $rc = TicketConversacion::enviarSolicitante($pdo, 'tickets_profesor', $pc, $prof, 'otra vez');
    check('segunda reiteración inmediata bloqueada', $rc['ok'] === false);
    TicketAsignacionService::tomar($pdo, 'tickets_profesor', $pc, $tec1);
    $rc = TicketConversacion::enviarPersonal($pdo, 'tickets_profesor', $pc, $tec1, 'voy para allá');
    $rc2 = TicketConversacion::enviarSolicitante($pdo, 'tickets_profesor', $pc, $prof, 'gracias');
    check('tras la respuesta del técnico el profesor responde libre', $rc['ok'] === true && $rc2['ok'] === true
        && (int)TicketConversacion::deTicket($pdo, 'tickets_profesor', $pc)[2]['es_reiteracion'] === 0);
    $rc = TicketConversacion::enviarSolicitante($pdo, 'tickets_profesor', $pc, $tec2, 'intruso');
    check('otro usuario NO escribe como solicitante', $rc['ok'] === false);
    $internas = TicketSeguimiento::comentariosDeTickets($pdo, 'tickets_profesor', [$pc]);
    check('la conversación NO aparece en las notas internas', empty($internas[$pc]));
    check('la conversación sale en el PDF', count(TicketPdfService::paraTicketProfesor($pdo, $pc, 'tecnico', $tec1)['extra']['conversacion']) === 3);

    $pdo->exec("UPDATE tickets SET firmado=1 WHERE id=$b");
    check('beca no edita un ticket firmado', TicketEstadoService::actualizarEstadoEjecutor($pdo, 'tickets', $b, $beca1, 'En proceso') === false);

    echo "\n[aprobar / rechazar tickets normales]\n";
    $n = $mk('Solucionado', $tec1, 0, $prof, 1);
    check('aprobar: otro técnico NO puede', TicketAprobacionService::aprobarTicketNormal($pdo, $n, $tec2)['ok'] === false && fila($pdo, 'tickets', $n)['estado'] === 'Solucionado');
    $r = TicketAprobacionService::aprobarTicketNormal($pdo, $n, $tec1);
    check('aprobar: el responsable aprueba y se devuelve el dueño', $r['ok'] === true && $r['id_dueno'] === $prof && fila($pdo, 'tickets', $n)['estado'] === 'Aprobado');
    check('aprobar: no se aprueba dos veces', TicketAprobacionService::aprobarTicketNormal($pdo, $n, $tec1)['ok'] === false);
    $n = $mk('En proceso', $tec1);
    check('aprobar: solo desde Solucionado', TicketAprobacionService::aprobarTicketNormal($pdo, $n, $tec1)['ok'] === false);
    $n = $mk('Solucionado', $beca1, 0, null, 1);
    check('aprobar: ticket que tomó una beca lo aprueba cualquier técnico', TicketAprobacionService::aprobarTicketNormal($pdo, $n, $tec2)['ok'] === true);
    $pdo->exec("INSERT INTO componentes (tipo,serial,codigo_inventario) VALUES ('mouse','T6-SER-1','T6-INV-1')");
    $comp = (int)$pdo->lastInsertId();
    $n = $mk('Solucionado', $tec1, 0, null, 1);
    $pdo->exec("UPDATE tickets SET id_componente_saliente=$comp WHERE id=$n");
    check('aprobar: con pieza propuesta NO pasa por la vía simple (va por inventario)', TicketAprobacionService::aprobarTicketNormal($pdo, $n, $tec1)['ok'] === false && fila($pdo, 'tickets', $n)['estado'] === 'Solucionado');
    $n = $mk('En proceso', $tec1, 0, $prof); TicketAsignacionService::asignarBeca($pdo, 'tickets', $n, $tec1, $beca2);
    $pdo->exec("UPDATE tickets SET estado='Solucionado', firmado=1 WHERE id=$n");
    check('rechazar: otro técnico NO puede', TicketAprobacionService::rechazarTicketNormal($pdo, $n, $tec2)['ok'] === false);
    $r = TicketAprobacionService::rechazarTicketNormal($pdo, $n, $tec1);
    check('rechazar: vuelve a En proceso, desfirma y devuelve la beca', $r['ok'] === true && $r['id_beca'] === $beca2
        && fila($pdo, 'tickets', $n)['estado'] === 'En proceso' && (int)$g('tickets', $n, 'firmado') === 0);
    check('rechazar: solo desde Solucionado', TicketAprobacionService::rechazarTicketNormal($pdo, $n, $tec1)['ok'] === false);

    echo "\n[aprobar / rechazar tickets de profesor]\n";
    $p = $mkP('Solucionado', $tec1); $pdo->exec("UPDATE tickets_profesor SET tipo_ticket='requerimiento', firmado=1 WHERE id=$p");
    $r = TicketAprobacionService::aprobarTicketProfesor($pdo, $p, $tec2);
    check('aprobar profesor: cualquier técnico (decisión de negocio)', $r['ok'] === true && $r['id_profesor'] === $prof && $r['id_tecnico'] === $tec1
        && fila($pdo, 'tickets_profesor', $p)['estado'] === 'Aprobado');
    check('aprobar profesor: no se repite', TicketAprobacionService::aprobarTicketProfesor($pdo, $p, $tec1)['ok'] === false);
    $p = $mkP('Solucionado', $tec1); $pdo->exec("UPDATE tickets_profesor SET tipo_ticket='requerimiento', subtipo_requerimiento='PRESTAMO_SALA', devuelto=0 WHERE id=$p");
    $r = TicketAprobacionService::aprobarTicketProfesor($pdo, $p, $tec1);
    check('aprobar profesor: préstamo sin devolver bloqueado con motivo', $r['ok'] === false && is_string($r['motivo']) && fila($pdo, 'tickets_profesor', $p)['estado'] === 'Solucionado');
    $pdo->exec("UPDATE tickets_profesor SET devuelto=1 WHERE id=$p");
    check('aprobar profesor: préstamo devuelto sí se aprueba', TicketAprobacionService::aprobarTicketProfesor($pdo, $p, $tec1)['ok'] === true);
    check('aprobar profesor: id inexistente', TicketAprobacionService::aprobarTicketProfesor($pdo, 2147480000, $tec1)['ok'] === false);
    $p = $mkP('Solucionado', $tec1); $pdo->exec("UPDATE tickets_profesor SET firmado=1, beca_id=$beca1 WHERE id=$p");
    $r = TicketAprobacionService::rechazarTicketProfesor($pdo, $p, $tec1);
    check('rechazar profesor: En proceso, desfirma, ejecutor = la beca', $r['ok'] === true && $r['id_ejecutor'] === $beca1
        && fila($pdo, 'tickets_profesor', $p)['estado'] === 'En proceso' && (int)$g('tickets_profesor', $p, 'firmado') === 0);
    $p = $mkP('Solucionado', $tec2); $pdo->exec("UPDATE tickets_profesor SET firmado=1 WHERE id=$p");
    check('rechazar profesor: sin beca el ejecutor es el técnico', TicketAprobacionService::rechazarTicketProfesor($pdo, $p, $tec1)['id_ejecutor'] === $tec2);
    check('rechazar profesor: solo desde Solucionado', TicketAprobacionService::rechazarTicketProfesor($pdo, $p, $tec1)['ok'] === false);

    echo "\n[tecnicoPuedeDecidir (inventario)]\n";
    $i = $mk('Listo pero sin validar', $tec1);
    check('el técnico responsable puede decidir', TicketAprobacionService::tecnicoPuedeDecidir($pdo, $i, $tec1) === true);
    check('OTRO técnico NO puede decidir', TicketAprobacionService::tecnicoPuedeDecidir($pdo, $i, $tec2) === false);
    $i = $mk('Listo pero sin validar', $beca1);
    check('ticket tomado por una beca: cualquier técnico puede decidir', TicketAprobacionService::tecnicoPuedeDecidir($pdo, $i, $tec2) === true);
    $i = $mk('Listo pero sin validar', null);
    check('ticket sin responsable: no se decide (nadie lo tiene)', TicketAprobacionService::tecnicoPuedeDecidir($pdo, $i, $tec1) === false);
    check('ticket inexistente: false', TicketAprobacionService::tecnicoPuedeDecidir($pdo, 2147480000, $tec1) === false);

    // ==================== inventario desde tickets ====================
    echo "\n[InventarioService (inventario)]\n";
    require_once __DIR__ . '/../app/Support/inventario.php'; // envoltorios globales (compatibilidad)
    $n7 = 0;
    $mkEq = function (int $sala) use ($pdo, &$n7): int {
        $n7++; $pdo->exec("INSERT INTO equipos (id_sala,codigo) VALUES ($sala,'T7-EQ-$n7')"); return (int)$pdo->lastInsertId();
    };
    $mkComp = function (string $tipo, ?int $eq = null, string $estado = 'ACTIVO') use ($pdo, &$n7): int {
        $n7++; $e = $eq ? (string)$eq : 'NULL';
        $pdo->exec("INSERT INTO componentes (tipo,serial,codigo_inventario,estado,id_equipo_actual) VALUES ('$tipo','T7-S-$n7','T7-I-$n7','$estado',$e)");
        return (int)$pdo->lastInsertId();
    };
    $cnt = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();
    $compRow = function (int $id) use ($pdo): array { return $pdo->query("SELECT estado,id_equipo_actual FROM componentes WHERE id_componente=$id")->fetch(PDO::FETCH_ASSOC); };
    $opEq = fn(int $eq) => (int)$pdo->query("SELECT operativo FROM equipos WHERE id_equipo=$eq")->fetchColumn();

    // --- cambio de pieza (aprobar) ---
    $eq = $mkEq($salaSis); $sal = $mkComp('torre', $eq); $ent = $mkComp('torre', null, 'ACTIVO');
    $t = $mk('Solucionado', $tec1, 0, $prof, 1);
    $pdo->exec("UPDATE tickets SET id_equipo=$eq, id_componente_saliente=$sal, id_componente_entrante=$ent, solucion='torre dañada' WHERE id=$t");
    $r = TicketTrasladoService::aprobarCambioPieza($pdo, $t, $tec1);
    check('cambio de pieza: ok y ticket Aprobado', $r['ok'] === true && fila($pdo, 'tickets', $t)['estado'] === 'Aprobado');
    check('  saliente EN_REVISION y sin equipo', $compRow($sal) === ['estado' => 'EN_REVISION', 'id_equipo_actual' => null]);
    check('  entrante ACTIVO instalado en el equipo', (int)$compRow($ent)['id_equipo_actual'] === $eq && $compRow($ent)['estado'] === 'ACTIVO');
    check('  equipo operativo (volvió una torre)', $opEq($eq) === 1);
    check('  2 filas de historial y 2 movimientos', $cnt("SELECT COUNT(*) FROM historial_componente WHERE id_ticket=$t") === 2
        && $cnt("SELECT COUNT(*) FROM movimientos_activos WHERE tabla_ticket_relacionado='tickets' AND id_ticket_relacionado=$t") === 2);
    check('  historial guarda el motivo (solución del ticket)', $cnt("SELECT COUNT(*) FROM historial_componente WHERE id_ticket=$t AND motivo='torre dañada'") === 2);
    $r2 = TicketTrasladoService::aprobarCambioPieza($pdo, $t, $tec1);
    check('  aprobar de nuevo NO repite el movimiento', $r2['ok'] === false && $cnt("SELECT COUNT(*) FROM historial_componente WHERE id_ticket=$t") === 2);
    $eq2 = $mkEq($salaSis); $sal2 = $mkComp('torre', $eq2);
    $t2 = $mk('Solucionado', $tec1, 0, null, 1);
    $pdo->exec("UPDATE tickets SET id_equipo=$eq2, id_componente_saliente=$sal2 WHERE id=$t2");
    TicketTrasladoService::aprobarCambioPieza($pdo, $t2, $tec1);
    check('  si sale la torre, el equipo queda NO operativo', $opEq($eq2) === 0);
    $t3 = $mk('Solucionado', $tec1);
    check('  ticket sin piezas propuestas: no hace nada', TicketTrasladoService::aprobarCambioPieza($pdo, $t3, $tec1)['ok'] === false && fila($pdo, 'tickets', $t3)['estado'] === 'Solucionado');
    $t4 = $mk('En proceso', $tec1); $pdo->exec("UPDATE tickets SET id_componente_saliente=$sal WHERE id=$t4");
    check('  solo desde Solucionado', TicketTrasladoService::aprobarCambioPieza($pdo, $t4, $tec1)['ok'] === false);

    // --- cambio de pieza desde la CHECKLIST de mantenimiento (ejecutarAprobacionInventarioEquipo) ---
    // Debe comportarse igual que el de tickets respecto a la bandera `operativo`.
    $eqC1 = $mkEq($salaSis); $salC1 = $mkComp('torre', $eqC1); $entC1 = $mkComp('torre');
    InventarioService::aprobarCambioPiezaMantenimiento($pdo, ['id_componente_saliente' => $salC1, 'id_componente_entrante' => $entC1, 'id_equipo' => $eqC1], $tec1, 0);
    check('checklist: cambiar una torre por otra deja el equipo OPERATIVO', $opEq($eqC1) === 1
        && (int)$compRow($entC1)['id_equipo_actual'] === $eqC1 && $compRow($salC1) === ['estado' => 'EN_REVISION', 'id_equipo_actual' => null]);
    $eqC2 = $mkEq($salaSis); $salC2 = $mkComp('torre', $eqC2);
    InventarioService::aprobarCambioPiezaMantenimiento($pdo, ['id_componente_saliente' => $salC2, 'id_componente_entrante' => null, 'id_equipo' => $eqC2], $tec1, 0);
    check('checklist: si sale la torre y no entra otra, el equipo queda NO operativo', $opEq($eqC2) === 0);
    $eqC3 = $mkEq($salaSis); $salC3 = $mkComp('torre', $eqC3); $entC3 = $mkComp('monitor');
    InventarioService::aprobarCambioPiezaMantenimiento($pdo, ['id_componente_saliente' => $salC3, 'id_componente_entrante' => $entC3, 'id_equipo' => $eqC3], $tec1, 0);
    check('checklist: sale la torre y entra un monitor → NO operativo (el monitor no es pieza principal)', $opEq($eqC3) === 0);

    // --- traslado a la oficina de mantenimiento (bodega) desde la checklist ---
    $pdo->exec("INSERT INTO mantenimientos_programados (id_sala,tipo,descripcion,fecha_programada,id_creador,id_responsable,estado) VALUES ($salaSis,'MANTENIMIENTO_PREVENTIVO','T7 traslado',NOW(),$tec1,$tec1,'EN_PROCESO')");
    $mpT = (int)$pdo->lastInsertId();
    $eqT = $mkEq($salaSis); $torreT = $mkComp('torre', $eqT); $otraT = $mkComp('torre', $mkEq($salaSis));
    $pdo->exec("INSERT INTO mantenimiento_equipos (id_programado,id_equipo,estado,id_usuario_asignado) VALUES ($mpT,$eqT,'TOMADO',$beca1)");
    $meT = (int)$pdo->lastInsertId();
    $estMe = fn() => (string)$pdo->query("SELECT estado FROM mantenimiento_equipos WHERE id_mant_equipo=$meT")->fetchColumn();
    check('traslado: sin motivo se rechaza', MantenimientoService::proponerTraslado($pdo, $beca1, 'beca_trabajo', $meT, $mpT, '', $torreT)['error'] !== '');
    check('traslado: pieza de OTRO equipo se rechaza', MantenimientoService::proponerTraslado($pdo, $beca1, 'beca_trabajo', $meT, $mpT, 'no enciende', $otraT)['error'] !== '' && $estMe() === 'TOMADO');
    check('traslado: otra beca (no la tomó) no puede', MantenimientoService::proponerTraslado($pdo, $beca2, 'beca_trabajo', $meT, $mpT, 'no enciende', $torreT)['error'] !== '');
    $rT = MantenimientoService::proponerTraslado($pdo, $beca1, 'beca_trabajo', $meT, $mpT, 'La torre no enciende, fuente quemada', $torreT);
    check('traslado: quien lo tomó lo propone → TRASLADO_PENDIENTE', $rT['error'] === '' && $estMe() === 'TRASLADO_PENDIENTE' && $compRow($torreT)['id_equipo_actual'] !== null);
    check('  cuenta como pendiente: el mantenimiento no se puede cerrar', MantenimientoRepository::contarChecklistPendiente($pdo, $mpT) === 1);
    check('  «aprobar pieza» NO lo aplica (es un traslado)', MantenimientoService::aprobarPieza($pdo, $tec1, $meT, $mpT)['exito'] === '' && $compRow($torreT)['id_equipo_actual'] !== null);
    check('  rechazar → vuelve a TOMADO sin pieza', MantenimientoService::rechazarTraslado($pdo, $tec1, $meT, $mpT, 'se arregla en sitio')['exito'] !== ''
        && $estMe() === 'TOMADO' && $pdo->query("SELECT id_componente_saliente FROM mantenimiento_equipos WHERE id_mant_equipo=$meT")->fetchColumn() === null);
    MantenimientoService::proponerTraslado($pdo, $beca1, 'beca_trabajo', $meT, $mpT, 'La torre no enciende, fuente quemada', $torreT);
    $aT = MantenimientoService::aprobarTraslado($pdo, $tec1, $meT, $mpT);
    check('  aprobar → pieza a bodega EN_REVISION, equipo NO operativo y fila EXCLUIDO_TRASLADO', $aT['exito'] !== ''
        && $compRow($torreT) === ['estado' => 'EN_REVISION', 'id_equipo_actual' => null] && $opEq($eqT) === 0 && $estMe() === 'EXCLUIDO_TRASLADO'
        && MantenimientoRepository::contarChecklistPendiente($pdo, $mpT) === 0);
    check('  queda el movimiento sala → bodega con quién aprobó', $cnt("SELECT COUNT(*) FROM movimientos_activos WHERE id_componente=$torreT AND id_sala_destino IS NULL AND id_aprobo=$tec1") === 1);
    check('  aprobar dos veces no repite nada', MantenimientoService::aprobarTraslado($pdo, $tec1, $meT, $mpT)['error'] !== '');

    // --- Solo Departamento de Sistemas: permiso para ver otras dependencias ---
    check('permiso «otras dependencias» solo aplica a técnicos', array_key_exists(UserPermissions::VER_TICKETS_EXTERNOS, UserPermissions::aplicablesA('tecnico'))
        && !array_key_exists(UserPermissions::VER_TICKETS_EXTERNOS, UserPermissions::aplicablesA('beca_trabajo')));
    check('  técnico sin permiso NO ve otras dependencias; el admin sí', TicketConsultas::puedeVerExternos($pdo, $tec1, 'tecnico') === false && TicketConsultas::puedeVerExternos($pdo, 0, 'admin') === true);
    $pdo->exec("UPDATE users SET permiso_ver_tickets_externos=1 WHERE id=$tec1");
    check('  con el permiso que activa el admin, sí', TicketConsultas::puedeVerExternos($pdo, $tec1, 'tecnico') === true);
    $pdo->exec("UPDATE users SET permiso_ver_tickets_externos=0 WHERE id=$tec1");
    $tSis = $mk('En espera'); $tExt = $mk('En espera', null, $salaDer);
    check('  esTicketDeSistemas distingue la sala del Departamento', TicketConsultas::esTicketDeSistemas($pdo, 'tickets', $tSis) === true && TicketConsultas::esTicketDeSistemas($pdo, 'tickets', $tExt) === false);

    // --- UNA sola verdad: ticket y checklist usan InventarioService::aplicarCambioPieza ---
    $srcInv = (string)file_get_contents(__DIR__ . '/../app/Support/inventario.php');
    check('checklist delega en el núcleo único (sin SQL de inventario propio)', strpos((string)file_get_contents(__DIR__ . '/../app/Domain/Mantenimientos/MantenimientoService.php'), 'InventarioService::aprobarCambioPiezaMantenimiento') !== false
        && !preg_match('/UPDATE\s+(componentes|equipos)/', $srcInv));
    check('checklist deja movimientos con quién aprobó (como los tickets)',
        $cnt("SELECT COUNT(*) FROM movimientos_activos WHERE id_componente IN ($salC1,$entC1) AND id_aprobo=$tec1 AND motivo IN ('CAMBIO_PIEZA_SALIENTE','CAMBIO_PIEZA_ENTRANTE')") === 2);
    // Regla: solo un técnico aprueba (aunque se llame al servicio directamente)
    $eqC4 = $mkEq($salaSis); $salC4 = $mkComp('torre', $eqC4); $entC4 = $mkComp('torre');
    $rechazoBeca = null;
    try { InventarioService::aprobarCambioPiezaMantenimiento($pdo, ['id_componente_saliente' => $salC4, 'id_componente_entrante' => $entC4, 'id_equipo' => $eqC4], $beca1, 0); }
    catch (CambioPiezaNoAplicable $e) { $rechazoBeca = $e->getMessage(); }
    check('una beca NO puede aprobar un cambio de pieza (no se toca el inventario)', $rechazoBeca !== null
        && (int)$compRow($salC4)['id_equipo_actual'] === $eqC4 && $compRow($entC4)['id_equipo_actual'] === null);
    // Regla: no se aplica dos veces
    $repetido = null;
    try { InventarioService::aprobarCambioPiezaMantenimiento($pdo, ['id_componente_saliente' => $salC1, 'id_componente_entrante' => $entC1, 'id_equipo' => $eqC1], $tec1, 0); }
    catch (CambioPiezaNoAplicable $e) { $repetido = $e->getMessage(); }
    check('aprobar dos veces el mismo cambio se rechaza y no duplica historial', $repetido !== null
        && $cnt("SELECT COUNT(*) FROM historial_componente WHERE id_componente=$entC1 AND tipo_evento='ASIGNACION'") === 1);

    // --- dentro de una transacción ajena y con fallo: no deja nada a medias ---
    $eq3 = $mkEq($salaSis); $sal3 = $mkComp('mouse', $eq3);
    $t5 = $mk('Solucionado', $tec1, 0, null, 1);
    $pdo->exec("UPDATE tickets SET id_equipo=$eq3, id_componente_saliente=$sal3 WHERE id=$t5");
    $pdo->exec("SAVEPOINT t7");
    TicketTrasladoService::aprobarCambioPieza($pdo, $t5, $tec1);
    $pdo->exec("ROLLBACK TO SAVEPOINT t7");
    check('  funciona dentro de una transacción ajena y se puede revertir', fila($pdo, 'tickets', $t5)['estado'] === 'Solucionado' && $compRow($sal3)['estado'] === 'ACTIVO');

    // --- rechazar propuesta ---
    $t6 = $mk('Solucionado', $tec1, 0, null, 1); $pdo->exec("UPDATE tickets SET id_componente_saliente=$sal3 WHERE id=$t6");
    check('rechazar propuesta: En proceso, sin firma y sin piezas', TicketTrasladoService::rechazarCambioPieza($pdo, $t6, $tec1) === true
        && fila($pdo, 'tickets', $t6)['estado'] === 'En proceso' && (int)$g('tickets', $t6, 'firmado') === 0 && $g('tickets', $t6, 'id_componente_saliente') === null);
    check('  solo desde Solucionado', TicketTrasladoService::rechazarCambioPieza($pdo, $t6, $tec1) === false);

    // --- cambio de posición ---
    $eqP = $mkEq($salaSis);
    $p1 = $mk('Solucionado', $tec1, $salaSis, $prof, 1); $pdo->exec("UPDATE tickets SET id_equipo=$eqP, id_sala_destino=$salaDer WHERE id=$p1");
    $r = TicketTrasladoService::aprobarCambioPosicion($pdo, $p1, $tec2);
    check('posición: otro técnico NO aplica (queda sin tocar)', $r['aplica'] === false && fila($pdo, 'tickets', $p1)['estado'] === 'Solucionado' && (int)$pdo->query("SELECT id_sala FROM equipos WHERE id_equipo=$eqP")->fetchColumn() === $salaSis);
    $r = TicketTrasladoService::aprobarCambioPosicion($pdo, $p1, $tec1);
    check('posición: el responsable mueve el equipo y aprueba', $r['aplica'] && $r['ok'] && $r['id_dueno'] === $prof
        && (int)$pdo->query("SELECT id_sala FROM equipos WHERE id_equipo=$eqP")->fetchColumn() === $salaDer && fila($pdo, 'tickets', $p1)['estado'] === 'Aprobado');
    check('  queda movimiento CAMBIO_DE_POSICION', $cnt("SELECT COUNT(*) FROM movimientos_activos WHERE tabla_ticket_relacionado='tickets' AND id_ticket_relacionado=$p1 AND motivo='CAMBIO_DE_POSICION'") === 1);
    check('  repetir no vuelve a mover (ya no aplica)', TicketTrasladoService::aprobarCambioPosicion($pdo, $p1, $tec1)['aplica'] === false);
    $eqQ = $mkEq($salaDer); // el ticket cree que está en SIS, pero ya lo movieron
    $p2 = $mk('Solucionado', $tec1, $salaSis, null, 1); $pdo->exec("UPDATE tickets SET id_equipo=$eqQ, id_sala_destino=$salaSis WHERE id=$p2");
    $r = TicketTrasladoService::aprobarCambioPosicion($pdo, $p2, $tec1);
    check('posición: equipo ya no está en la sala de origen -> ok=false, nada cambia', $r['aplica'] && !$r['ok'] && fila($pdo, 'tickets', $p2)['estado'] === 'Solucionado'
        && (int)$pdo->query("SELECT id_sala FROM equipos WHERE id_equipo=$eqQ")->fetchColumn() === $salaDer);
    $eqR = $mkEq($salaSis);
    $p3 = $mk('Solucionado', $beca1, $salaSis, null, 1); $pdo->exec("UPDATE tickets SET id_equipo=$eqR, id_sala_destino=$salaDer WHERE id=$p3");
    check('posición: ticket tomado por una beca lo aprueba cualquier técnico', TicketTrasladoService::aprobarCambioPosicion($pdo, $p3, $tec2)['ok'] === true);
    $p4 = $mk('Solucionado', $tec1);
    check('posición: ticket normal -> aplica=false (sigue el camino simple)', TicketTrasladoService::aprobarCambioPosicion($pdo, $p4, $tec1)['aplica'] === false);
    check('inventario: un solo camino (InventarioService), sin envoltorios globales', !function_exists('ejecutarCambioPosicion') && !function_exists('ejecutarAprobacionInventario') && !function_exists('registrarHistorialComponente') && method_exists('InventarioService', 'cambiarPosicion') && method_exists('InventarioService', 'registrarHistorial'));

    // --- traslado a bodega (3 pasos) ---
    $eqT = $mkEq($salaSis); $salT = $mkComp('torre', $eqT);
    $tr = $mk('Listo pero sin validar', $tec1, 0, $prof); $pdo->exec("UPDATE tickets SET id_equipo=$eqT, id_componente_saliente=$salT WHERE id=$tr");
    check('traslado: cerrar sin traslado ni reubicación falla', TicketTrasladoService::aprobarReubicacionFinal($pdo, $tr, $tec1)['ok'] === false);
    check('traslado: reubicado antes de aprobar traslado falla', TicketTrasladoService::marcarReubicado($pdo, $tr, $tec1) === false);
    check('traslado: paso 1 ok', TicketTrasladoService::aprobarTraslado($pdo, $tr, $tec1)['ok'] === true && (int)$g('tickets', $tr, 'traslado_aprobado') === 1);
    check('  mueve el componente a EN_REVISION y el equipo queda NO operativo', $compRow($salT)['estado'] === 'EN_REVISION' && $opEq($eqT) === 0);
    $h0 = $cnt("SELECT COUNT(*) FROM historial_componente WHERE id_componente=$salT");
    check('  repetir paso 1 no vuelve a mover inventario', TicketTrasladoService::aprobarTraslado($pdo, $tr, $tec1)['ok'] === false && $cnt("SELECT COUNT(*) FROM historial_componente WHERE id_componente=$salT") === $h0);
    check('traslado: paso 2 (reubicado)', TicketTrasladoService::marcarReubicado($pdo, $tr, $tec1) === true);
    $r = TicketTrasladoService::aprobarReubicacionFinal($pdo, $tr, $tec1);
    check('traslado: paso 3 cierra, firma y devuelve el dueño', $r['ok'] && $r['id_dueno'] === $prof && fila($pdo, 'tickets', $tr)['estado'] === 'Cerrado' && (int)$g('tickets', $tr, 'firmado') === 1);
    $tr2 = $mk('Listo pero sin validar', $tec1);
    check('traslado sin componente: se aprueba sin mover nada', TicketTrasladoService::aprobarTraslado($pdo, $tr2, $tec1)['ok'] === true);
    $tr3 = $mk('En proceso', $tec1);
    check('traslado: solo desde "Listo pero sin validar"', TicketTrasladoService::aprobarTraslado($pdo, $tr3, $tec1)['ok'] === false);

    // --- auditoría compartida ---
    check('auditoría: los servicios de tickets escriben vía AuditoriaService', $cnt("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='tickets' AND id_registro=$tr AND detalle LIKE 'estado=Cerrado%'") === 1);
    check('app/Support/{auditoria,notificaciones,inventario} cargan sus funciones y los shims de public/ ya no existen', (function () {
        require_once __DIR__ . '/../app/Domain/Audit/AuditoriaService.php'; require_once __DIR__ . '/../app/Domain/Notifications/NotificationService.php'; require_once __DIR__ . '/../app/Support/lib_inventario.php';
        return method_exists('AuditoriaService', 'registrar') && method_exists('NotificationService', 'crear') && method_exists('NotificationService', 'notificarPorCorreo') && function_exists('buscarComponentes')
            && !file_exists(__DIR__ . '/../public/auditoria.php') && !file_exists(__DIR__ . '/../public/notificaciones.php') && !file_exists(__DIR__ . '/../public/inventario.php') && !file_exists(__DIR__ . '/../public/lib_inventario.php');
    })());

    echo "\n[admin aprueba/rechaza tickets de profesor]\n";
    $adm = $tec1; // el id solo se usa para auditoría
    $ap = $mkP('Solucionado', $tec1); $pdo->exec("UPDATE tickets_profesor SET tipo_ticket='danio' WHERE id=$ap");
    $r = TicketAprobacionService::aprobarProfesorAdmin($pdo, $ap, $adm);
    check('admin NO aprueba tickets de daño (bloqueo danio)', $r['ok'] === false && $r['bloqueo'] === 'danio' && fila($pdo, 'tickets_profesor', $ap)['estado'] === 'Solucionado');
    check('admin NO rechaza tickets de daño', TicketAprobacionService::rechazarProfesorAdmin($pdo, $ap, $adm)['bloqueo'] === 'danio');
    $ap = $mkP('Solucionado', $tec1); $pdo->exec("UPDATE tickets_profesor SET tipo_ticket='requerimiento', subtipo_requerimiento='PRESTAMO_EQUIPO', devuelto=0 WHERE id=$ap");
    check('admin: préstamo sin devolver bloqueado', TicketAprobacionService::aprobarProfesorAdmin($pdo, $ap, $adm)['bloqueo'] === 'prestamo');
    $ap = $mkP('Solucionado', $tec1); $pdo->exec("UPDATE tickets_profesor SET tipo_ticket='requerimiento' WHERE id=$ap");
    $r = TicketAprobacionService::aprobarProfesorAdmin($pdo, $ap, $adm);
    check('admin aprueba requerimiento en Solucionado', $r['ok'] && $r['id_profesor'] === $prof && fila($pdo, 'tickets_profesor', $ap)['estado'] === 'Aprobado');
    $a0 = $cnt("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='tickets_profesor' AND id_registro=$ap");
    check('  repetir no audita ni avisa (antes avisaba aunque no cambiara)', TicketAprobacionService::aprobarProfesorAdmin($pdo, $ap, $adm)['ok'] === false && $cnt("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='tickets_profesor' AND id_registro=$ap") === $a0);
    $ap = $mkP('En proceso', $tec1); $pdo->exec("UPDATE tickets_profesor SET tipo_ticket='requerimiento' WHERE id=$ap");
    check('admin: ticket que no está en Solucionado no cambia', TicketAprobacionService::aprobarProfesorAdmin($pdo, $ap, $adm)['ok'] === false && fila($pdo, 'tickets_profesor', $ap)['estado'] === 'En proceso');
    $ap = $mkP('Solucionado', $tec1); $pdo->exec("UPDATE tickets_profesor SET tipo_ticket='requerimiento', firmado=1 WHERE id=$ap");
    $r = TicketAprobacionService::rechazarProfesorAdmin($pdo, $ap, $adm);
    check('admin rechaza requerimiento: Rechazado y desfirmado', $r['ok'] && fila($pdo, 'tickets_profesor', $ap)['estado'] === 'Rechazado' && (int)$g('tickets_profesor', $ap, 'firmado') === 0);
    check('admin: id inexistente', TicketAprobacionService::aprobarProfesorAdmin($pdo, 2147480000, $adm)['ok'] === false);

    // ==================== cierre de Tickets, secretaria, paginación ====================
    require_once __DIR__ . '/../app/Domain/Users/UserPermissions.php';
    echo "\n[crear ticket (solicitante / secretaria)]\n";
    $insU->execute(['SS1','ss1x@test.local',$h,'secretaria','Secre','Secretaria','Sistemas']);
    $secre = (int)$pdo->lastInsertId();
    $insU->execute(['SS2','ss2x@test.local',$h,'solicitante','Solic','Solicitante','Derecho']);
    $solic = (int)$pdo->lastInsertId();
    $eqS = $mkEq($salaSis); $eqD = $mkEq($salaDer);
    $r = TicketCreacionService::crearTicketPanel($pdo, $solic, 'solicitante', ['dependencia' => 'Derecho', 'ubicacion' => 'Of 1', 'equipo' => 'PC1', 'descripcion' => 'no prende', 'categoria' => 'HARDWARE']);
    check('solicitante crea ticket (como siempre)', $r['ok'] && $g('tickets', $r['id'], 'nombre_dependencia') === 'Derecho' && $g('tickets', $r['id'], 'categoria') === 'HARDWARE' && $g('tickets', $r['id'], 'id_sala') === null);
    $r = TicketCreacionService::crearTicketPanel($pdo, $solic, 'solicitante', ['dependencia' => 'D', 'ubicacion' => 'U', 'equipo' => 'E', 'descripcion' => 'x', 'categoria' => 'INVENTADA']);
    check('  categoría fuera de la lista queda sin clasificar', $r['ok'] && $g('tickets', $r['id'], 'categoria') === null);
    check('  campos vacíos rechazados', TicketCreacionService::crearTicketPanel($pdo, $solic, 'solicitante', ['dependencia' => '', 'ubicacion' => 'U', 'equipo' => 'E', 'descripcion' => 'x'])['ok'] === false);
    $r = TicketCreacionService::crearTicketPanel($pdo, $secre, 'secretaria', ['dependencia' => 'Biblioteca', 'equipo' => 'Torre', 'marca_modelo' => 'HP', 'numero_inventario' => 'INV1', 'radicado_datarsoft' => 'r-123', 'descripcion' => 'monitor roto', 'id_sala' => $salaSis]);
    check('secretaria crea ticket de un equipo que llega a la oficina de mantenimiento (sin sala, con radicado DatArSoft)', $r['ok'] && $g('tickets', $r['id'], 'id_sala') === null && $g('tickets', $r['id'], 'radicado_datarsoft') === 'R-123'
        && $g('tickets', $r['id'], 'nombre_dependencia') === 'Biblioteca' && (int)$g('tickets', $r['id'], 'user_id') === $secre);
    check('  queda auditado', $cnt("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='tickets' AND id_registro={$r['id']} AND accion='CREAR'") === 1);
    $idSec = $r['id'];
    check('secretaria sin dependencia ni equipo: rechazado', TicketCreacionService::crearTicketPanel($pdo, $secre, 'secretaria', ['descripcion' => 'x'])['ok'] === false);
    check('secretaria: radicado inválido rechazado', TicketCreacionService::crearTicketPanel($pdo, $secre, 'secretaria', ['dependencia' => 'D', 'equipo' => 'E', 'radicado_datarsoft' => '<x>', 'descripcion' => 'x'])['ok'] === false);
    check('otros roles no crean por este formulario', TicketCreacionService::crearTicketPanel($pdo, $tec1, 'tecnico', ['descripcion' => 'x'])['ok'] === false);

    echo "\n[tickets de Sistemas y permisos de secretaria]\n";
    $rowsS = TicketConsultas::obtenerTicketsSistemas($pdo, null, 1, 1000)['datos'];
    $idsS = array_column($rowsS, 'id');
    check('el ticket de la secretaria (equipo que llega a la oficina, sin sala) cuenta como del Departamento', in_array($idSec, array_map('intval', $idsS), true));
    $extD = TicketCreacionService::crearTicketPanel($pdo, $solic, 'solicitante', ['dependencia' => 'D', 'ubicacion' => 'U', 'equipo' => 'E', 'descripcion' => 'x']);
    check('  el de un solicitante (texto libre, sin sala) NO', !in_array($extD['id'], array_map('intval', $idsS), true));
    $orden = array_map('intval', array_column($rowsS, 'id'));
    $f = array_column($rowsS, 'fecha_creacion'); $ord = $f; rsort($ord);
    check('  vienen de más reciente a más antiguo', $f === $ord);
    check('permiso por defecto: desactivado', UserPermissions::tiene($pdo, $secre, UserPermissions::VER_TICKETS_SISTEMAS) === false);
    $r = UserPermissions::alternar($pdo, $tec1, $secre, UserPermissions::VER_TICKETS_SISTEMAS);
    check('el admin lo activa con un clic', $r['ok'] && $r['valor'] === 1 && UserPermissions::tiene($pdo, $secre, UserPermissions::VER_TICKETS_SISTEMAS) === true);
    check('  otro clic lo desactiva', UserPermissions::alternar($pdo, $tec1, $secre, UserPermissions::VER_TICKETS_SISTEMAS)['valor'] === 0);
    check('  el otro permiso (horarios) no se toca', UserPermissions::tiene($pdo, $secre, UserPermissions::HORARIOS) === false);
    check('  no sirve con usuarios que no son secretaria', UserPermissions::alternar($pdo, $tec1, $tec2, UserPermissions::HORARIOS)['ok'] === false);
    check('  no acepta columnas arbitrarias', UserPermissions::alternar($pdo, $tec1, $secre, 'role')['ok'] === false);
    check('  deja auditoría', $cnt("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='users' AND id_registro=$secre") === 2);
    check('permisos de una secretaria en bloque (los 3 que aplican a su rol)', UserPermissions::de($pdo, [$secre])[$secre] === [UserPermissions::HORARIOS => 0, UserPermissions::VER_TICKETS_SISTEMAS => 0, UserPermissions::VER_HORAS_BECAS => 0]);

    echo "\n[Seguimiento de horas: solo becas, admin ve todas, permiso habilitable a otros roles]\n";
    check('horas: «ver horas de becas» aplica a técnico, secretaria, profesor, solicitante y beca, nunca a admin',
        (UserPermissions::aplicablesA('tecnico')[UserPermissions::VER_HORAS_BECAS] ?? null) === 'Ver horas de todas las becas'
        && isset(UserPermissions::aplicablesA('profesor')[UserPermissions::VER_HORAS_BECAS]) && isset(UserPermissions::aplicablesA('solicitante')[UserPermissions::VER_HORAS_BECAS])
        && isset(UserPermissions::aplicablesA('beca_trabajo')[UserPermissions::VER_HORAS_BECAS]) && UserPermissions::aplicablesA('admin') === []);
    $acTec = SeguimientoHorasService::acceso($pdo, $tec2, 'tecnico');
    check('horas: técnico sin permiso no entra y no tiene horas propias', $acTec === ['propio' => false, 'todas' => false, 'entra' => false]);
    check('horas: beca sin permiso entra solo a las suyas', SeguimientoHorasService::acceso($pdo, $beca1, 'beca_trabajo') === ['propio' => true, 'todas' => false, 'entra' => true]);
    check('horas: admin ve todas y no tiene horas propias', SeguimientoHorasService::acceso($pdo, $adm, 'admin') === ['propio' => false, 'todas' => true, 'entra' => true]);
    check('horas: el admin activa el permiso a un técnico con un clic', UserPermissions::alternar($pdo, $adm, $tec2, UserPermissions::VER_HORAS_BECAS)['valor'] === 1
        && SeguimientoHorasService::acceso($pdo, $tec2, 'tecnico') === ['propio' => false, 'todas' => true, 'entra' => true]);
    check('horas: también a un profesor y a una secretaria', UserPermissions::alternar($pdo, $adm, $prof, UserPermissions::VER_HORAS_BECAS)['ok'] && UserPermissions::alternar($pdo, $adm, $secre, UserPermissions::VER_HORAS_BECAS)['ok']
        && SeguimientoHorasService::acceso($pdo, $prof, 'profesor')['todas'] && SeguimientoHorasService::acceso($pdo, $secre, 'secretaria')['todas']);
    $insU->execute(['AH9','ah9@test.local',$h,'admin','Admin Horas','Admin','Sistemas']);
    $admReal = (int)$pdo->lastInsertId();
    check('horas: no se le puede dar a un admin (ya ve todas)', UserPermissions::alternar($pdo, $adm, $admReal, UserPermissions::VER_HORAS_BECAS)['ok'] === false);
    $cons = SeguimientoHorasService::consultaMensual($pdo, $tec2, SeguimientoHorasService::acceso($pdo, $tec2, 'tecnico'), null, SeguimientoHorasService::periodo('2025', '3'));
    $idsBecas = array_map('intval', array_column($cons['becas'], 'id'));
    check('horas: el técnico con permiso ve el listado de becas (solo becas) y ninguna beca consultada por defecto',
        in_array($beca1, $idsBecas, true) && in_array($beca2, $idsBecas, true) && !in_array($tec2, $idsBecas, true) && $cons['becaConsultada'] === null);
    $cons = SeguimientoHorasService::consultaMensual($pdo, $tec2, SeguimientoHorasService::acceso($pdo, $tec2, 'tecnico'), $tec1, SeguimientoHorasService::periodo('2025', '3'));
    check('horas: ?ver_id de alguien que no es beca no muestra nada (los técnicos no tienen horas)', $cons['becaConsultada'] === null);
    $cons = SeguimientoHorasService::consultaMensual($pdo, $beca1, SeguimientoHorasService::acceso($pdo, $beca1, 'beca_trabajo'), $beca2, SeguimientoHorasService::periodo('2025', '3'));
    check('horas: la beca sin permiso ignora ?ver_id y ve solo las suyas, sin listado', $cons['becas'] === [] && (int)$cons['becaConsultada']['id'] === $beca1 && $cons['esPropia']);
    UserPermissions::alternar($pdo, $adm, $beca1, UserPermissions::VER_HORAS_BECAS);
    $cons = SeguimientoHorasService::consultaMensual($pdo, $beca1, SeguimientoHorasService::acceso($pdo, $beca1, 'beca_trabajo'), $beca2, SeguimientoHorasService::periodo('2025', '3'));
    check('horas: la beca con permiso ve el listado y puede abrir otra beca', $cons['becas'] !== [] && (int)$cons['becaConsultada']['id'] === $beca2 && !$cons['esPropia']);
    $pdfB = SeguimientoHorasService::becasParaPdf($pdo, $beca2, SeguimientoHorasService::acceso($pdo, $beca2, 'beca_trabajo'), true, 'BB1');
    check('horas (PDF): la beca sin permiso solo descarga el suyo (?codigo y ?todas se ignoran)', $pdfB['error'] === null && count($pdfB['becas']) === 1 && $pdfB['becas'][0]['codigo'] === 'BB2');
    $pdfT = SeguimientoHorasService::becasParaPdf($pdo, $tec2, SeguimientoHorasService::acceso($pdo, $tec2, 'tecnico'), false, 'TT1');
    check('horas (PDF): con permiso, el código de un técnico no sirve (solo becas)', $pdfT['error'] === 'No existe ninguna beca trabajo con ese código.');
    check('horas (PDF): admin sin ?codigo ni ?todas recibe un aviso', SeguimientoHorasService::becasParaPdf($pdo, $adm, SeguimientoHorasService::acceso($pdo, $adm, 'admin'), false, '')['error'] === 'Falta el código de la beca a consultar.');
    check('horas (PDF): con ?todas sale cada beca con código', count(SeguimientoHorasService::becasParaPdf($pdo, $adm, SeguimientoHorasService::acceso($pdo, $adm, 'admin'), true, '')['becas']) >= 2);

    echo "\n[devolución, equipos afectados, sugerir mantenimiento]\n";
    $pd = $mkP('En proceso', $tec1); $pdo->exec("UPDATE tickets_profesor SET tipo_ticket='requerimiento', subtipo_requerimiento='PRESTAMO_EQUIPO' WHERE id=$pd");
    check('devolución: otro técnico NO', TicketAprobacionService::confirmarDevolucion($pdo, $pd, $tec2)['ok'] === false);
    $r = TicketAprobacionService::confirmarDevolucion($pdo, $pd, $tec1);
    check('devolución: el responsable confirma y se avisa al profesor', $r['ok'] && $r['id_profesor'] === $prof && (int)$g('tickets_profesor', $pd, 'devuelto') === 1);
    check('  confirmar de nuevo no hace nada', TicketAprobacionService::confirmarDevolucion($pdo, $pd, $tec1)['ok'] === false);
    $pn = $mkP('En proceso', $tec1); $pdo->exec("UPDATE tickets_profesor SET tipo_ticket='danio' WHERE id=$pn");
    check('  un ticket que no es préstamo no aplica', TicketAprobacionService::confirmarDevolucion($pdo, $pn, $tec1)['ok'] === false);

    $qr = $mk('En proceso', $tec1, $salaSis); $pdo->exec("UPDATE tickets SET origen='QR_ANONIMO' WHERE id=$qr");
    check('equipos: otro técnico NO', TicketSeguimientoService::agregarEquiposAfectados($pdo, 'tickets', $qr, $tec2, [$eqS]) === false);
    check('equipos: equipo de OTRA sala se ignora', TicketSeguimientoService::agregarEquiposAfectados($pdo, 'tickets', $qr, $tec1, [$eqD]) === false && $cnt("SELECT COUNT(*) FROM ticket_activos WHERE tabla_ticket='tickets' AND id_ticket=$qr") === 0);
    check('equipos: el responsable agrega un equipo de la sala', TicketSeguimientoService::agregarEquiposAfectados($pdo, 'tickets', $qr, $tec1, [$eqS, $eqD]) === true && $cnt("SELECT COUNT(*) FROM ticket_activos WHERE tabla_ticket='tickets' AND id_ticket=$qr") === 1);
    check('  si ya hay equipos no se pisan', TicketSeguimientoService::agregarEquiposAfectados($pdo, 'tickets', $qr, $tec1, [$eqS]) === false);
    $qb = $mk('En proceso', $tec1, $salaSis); $pdo->exec("UPDATE tickets SET origen='QR_ANONIMO', beca_id=$beca1 WHERE id=$qb");
    check('equipos: beca NO ejecutora rechazada', TicketSeguimientoService::agregarEquiposAfectados($pdo, 'tickets', $qb, $beca2, [$eqS], true) === false);
    check('equipos: beca ejecutora (asignada) sí', TicketSeguimientoService::agregarEquiposAfectados($pdo, 'tickets', $qb, $beca1, [$eqS], true) === true);
    check('sugerir mantenimiento: beca ejecutora sí, otra no', TicketSeguimientoService::sugerirMantenimientoProfesor($pdo, $pn, $beca1) === false
        && ($pdo->exec("UPDATE tickets_profesor SET beca_id=$beca1 WHERE id=$pn") !== false) && TicketSeguimientoService::sugerirMantenimientoProfesor($pdo, $pn, $beca1) === true && TicketSeguimientoService::sugerirMantenimientoProfesor($pdo, $pn, $beca2) === false);

    echo "\n[paginación de 5 en 5]\n";
    check('tamaño único de página = 5', ticketsPorPagina() === 5);
    $lista = range(1, 12);
    $p1 = paginarArreglo($lista, 1); $p3 = paginarArreglo($lista, 3); $p9 = paginarArreglo($lista, 9);
    check('12 elementos -> 3 páginas; la 1ª trae 1..5, la 3ª 11..12', $p1['total_paginas'] === 3 && $p1['datos'] === [1,2,3,4,5] && $p3['datos'] === [11,12]);
    check('  página fuera de rango se ajusta a la última', $p9['pagina'] === 3);
    check('  vacío -> 1 página sin datos', paginarArreglo([], 1) === ['datos' => [], 'total' => 0, 'total_paginas' => 1, 'pagina' => 1]);
    check('botones: pocas páginas -> todas (1..5)', paginasVisibles(1, 5) === [1,2,3,4,5]);
    check('botones: muchas páginas -> 1 … vecindad … última', paginasVisibles(10, 20) === [1, null, 9, 10, 11, null, 20]);
    check('botones: al inicio y al final', paginasVisibles(1, 20) === [1,2,3,4,null,20] && paginasVisibles(20, 20) === [1,null,17,18,19,20]);
    $_GET = ['pagina_prof' => '2'];
    $html = renderControlesPaginacion(2, 4, [], 'pagina_sistemas', 'tickets-sistemas');
    check('controles: muestra 1 2 3 4, resalta la actual, conserva otros bloques y el ancla',
        substr_count($html, 'href=') === 5 && strpos($html, 'pag-actual') !== false && strpos($html, 'pagina_prof=2') !== false && strpos($html, '#tickets-sistemas') !== false);
    check('controles: una sola página no pinta nada', renderControlesPaginacion(1, 1) === '');
    $_GET = [];
    for ($i = 0; $i < 7; $i++) { $mk('En espera', null, 0, $solic); }
    $rr = TicketConsultas::obtenerTicketsPorUsuario($pdo, $solic, 'tickets', ['En espera'], 1);
    check('obtenerTicketsPorUsuario: 5 por defecto, más nuevos primero', count($rr['datos']) === 5 && $rr['total_paginas'] >= 2
        && array_column($rr['datos'], 'fecha_creacion') === (function ($a) { rsort($a); return $a; })(array_column($rr['datos'], 'fecha_creacion')));

    // ==================== admin_tickets sin SQL inline ====================
    echo "\n[UserAccount y AdminTicketsQueries]\n";
    require_once __DIR__ . '/../app/Domain/Users/UserAccount.php';
    require_once __DIR__ . '/../app/Domain/Tickets/AdminTicketsQueries.php';
    $claveOk = 'Clave-Original-1';
    $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($claveOk, PASSWORD_DEFAULT), $solic]);
    $r = UserAccount::cambiarPropiaClave($pdo, $solic, 'incorrecta', 'NuevaClave123');
    check('cambio de clave: la actual incorrecta se rechaza y no cambia nada', !$r['ok'] && password_verify($claveOk, (string)$pdo->query("SELECT password FROM users WHERE id=$solic")->fetchColumn()));
    $r = UserAccount::cambiarPropiaClave($pdo, $solic, '', 'NuevaClave123');
    check('  actual vacía se rechaza', !$r['ok']);
    $r = UserAccount::cambiarPropiaClave($pdo, $solic, $claveOk, 'corta');
    check('  nueva de menos de 12 caracteres se rechaza', !$r['ok'] && strpos($r['error'], '12') !== false);
    $r = UserAccount::cambiarPropiaClave($pdo, $solic, $claveOk, 'NuevaClave123');
    check('  cambio válido: guarda hash nuevo y audita', $r['ok'] && password_verify('NuevaClave123', (string)$pdo->query("SELECT password FROM users WHERE id=$solic")->fetchColumn())
        && (int)$pdo->query("SELECT COUNT(*) FROM auditoria WHERE id_usuario=$solic AND detalle='cambio de contraseña propia'")->fetchColumn() >= 1);
    $porIdS = AdminTicketsQueries::ticketsSolicitantePorIds($pdo, array_column(AdminTicketsQueries::ticketsSolicitanteLigeros($pdo), 'id'));
    $ts = array_map(fn($f) => $porIdS[(int)$f['id']], AdminTicketsQueries::ticketsSolicitanteLigeros($pdo));
    check('ticketsSolicitante (ligeros + filas por id): trae QR y de solicitantes/secretarias, con sala_dependencia y más nuevos primero',
        count($ts) > 0 && array_key_exists('sala_dependencia', $ts[0]) && array_key_exists('mant_total_equipos', $ts[0])
        && array_column($ts, 'fecha_creacion') === (function ($a) { rsort($a); return $a; })(array_column($ts, 'fecha_creacion')));
    $porIdP = AdminTicketsQueries::ticketsProfesorPorIds($pdo, array_column(AdminTicketsQueries::ticketsProfesorLigeros($pdo), 'id'));
    $tp = array_values($porIdP);
    check('ticketsProfesor: columnas de dependencia de sala presentes', $tp === [] || (array_key_exists('dependencia_sala', $tp[0]) && array_key_exists('profesor_nombre', $tp[0])));
    $e = AdminTicketsQueries::tiempoPromedioTickets($pdo);
    check('estadísticas: tiempos, rankings y becas devuelven la forma esperada',
        array_key_exists('promedio_horas', $e) && array_key_exists('total_resueltos', AdminTicketsQueries::tiempoPromedioTicketsProfesor($pdo))
        && is_array(AdminTicketsQueries::tiempoPorTecnico($pdo)) && count(AdminTicketsQueries::rankingSalas($pdo)) <= 10
        && count(AdminTicketsQueries::rankingModelosTexto($pdo)) <= 10 && is_array(AdminTicketsQueries::rankingModelosComponentes($pdo))
        && is_array(AdminTicketsQueries::becasUsuarios($pdo)));

    // ==================== vistas parciales de admin_tickets ====================
    echo "\n[admin_tickets.php con vistas parciales y sin SQL]\n";
    $pg = file_get_contents(__DIR__ . '/../app/Presentation/Controllers/Tickets/AdminPanel.php');
    check('admin_tickets.php: sin $pdo-> ni <table> (las vistas están en app/Presentation/Views)', strpos($pg, '$pdo->') === false && strpos($pg, '<table') === false);
    $okVistas = true;
    foreach (['seccion_departamento','seccion_externos','seccion_estadisticas','seccion_tendencias','seccion_becas','seccion_cambiar_pass','seccion_mis_solicitudes','bandeja','scripts_graficos'] as $v) {
        $f = __DIR__ . "/../app/Presentation/Views/admin_tickets/$v.php";
        $okVistas = $okVistas && is_file($f) && strpos((string)file_get_contents($f), '$pdo') === false;
    }
    check('  las 9 vistas existen y ninguna toca $pdo', $okVistas);

    // ==================== Pasos 11 y 12: legacy/ y esqueleto común ====================
    echo "\n[Pasos 11-12: legacy/ y esqueleto de página]\n";
    check('instalación limpia: no existe legacy/ ni los huérfanos viejos en public/',
        !is_dir(__DIR__ . '/../legacy')
        && !is_file(__DIR__ . '/../public/panel_tecnico.php') && !is_file(__DIR__ . '/../public/panel_administrador.php') && !is_file(__DIR__ . '/../public/widget_fila_ticket.php'));
    $refs = 0;
    foreach (glob(__DIR__ . '/../public/*.php') as $f) { if (preg_match('/(include|require)[^;]*(panel_tecnico|panel_administrador|widget_fila_ticket)\.php|Location: *panel_|return .panel_|crear_ticket_profesor/', (string)file_get_contents($f))) { $refs++; } }
    check('  ninguna página de public/ los incluye ni redirige a ellos', $refs === 0);
    $lt = (string)file_get_contents(__DIR__ . '/../app/Presentation/Controllers/Tickets/TecnicoPanel.php');
    check('el panel técnico usa el esqueleto común (head/body_start/end) y no repite <!DOCTYPE>/<body>',
        strpos($lt, 'app_page_head.php') !== false && strpos($lt, 'app_page_body_start.php') !== false && strpos($lt, 'app_page_end.php') !== false
        && strpos($lt, '<!DOCTYPE') === false && strpos($lt, '<body>') === false);

    // ==================== los 5 paneles usan el esqueleto común ====================
    echo "\n[esqueleto común en los 5 paneles]\n";
    $todos = true;
    foreach (['Tecnico','Admin','Solicitante','Beca','Profesor'] as $pn) {
        $c = (string)file_get_contents(__DIR__ . "/../app/Presentation/Controllers/Tickets/{$pn}Panel.php");
        $todos = $todos && strpos($c, 'app_page_head.php') !== false && strpos($c, 'app_page_body_start.php') !== false
            && (strpos($c, 'app_page_end.php') !== false || strpos($c, 'app_page_main_end.php') !== false)
            && strpos($c, '<!DOCTYPE') === false && strpos($c, '<main class="app-main">') === false;
    }
    check('los 5 paneles (ahora en Controllers/Tickets/) no repiten DOCTYPE/<main>', $todos);

    // ==================== más páginas sobre el esqueleto común ====================
    echo "\n[13 páginas más sobre el esqueleto común]\n";
    $pg14 = ['create_ticket','create_ticket_beca','create_ticket_profesor','escanear_qr','gestionar_inventario','gestionar_salas','horarios','mantenimientos_programados','perfil','revisar_componentes_bodega','seguimiento_horas','solicitudes_baja','usuarios'];
    $ok14 = true;
    foreach ($pg14 as $pn) {
        // horarios, gestionar_salas, mantenimientos inventario usuarios y crear ticket de profesor tienen el esqueleto en su vista
        $vistas32 = ['horarios' => 'horarios/pagina.php', 'gestionar_salas' => 'gestionar_salas/pagina.php', 'mantenimientos_programados' => 'mantenimientos/pagina.php', 'gestionar_inventario' => 'inventario/gestionar.php', 'usuarios' => 'usuarios/pagina.php', 'create_ticket_profesor' => 'tickets/crear_profesor.php', 'create_ticket_beca' => 'becas/crear_ticket.php', 'revisar_componentes_bodega' => 'becas/revisar_bodega.php', 'seguimiento_horas' => 'horas/seguimiento.php', 'solicitudes_baja' => 'becas/solicitudes_baja.php', 'create_ticket' => 'tickets/crear.php', 'perfil' => 'cuenta/perfil.php', 'escanear_qr' => 'tickets/escanear_qr.php'];
        $c = (string)file_get_contents(__DIR__ . '/../' . (isset($vistas32[$pn]) ? 'app/Presentation/Views/' . $vistas32[$pn] : "public/$pn.php"));
        $ok14 = $ok14 && strpos($c, 'app_page_head.php') !== false && strpos($c, 'app_page_body_start.php') !== false && strpos($c, '<!DOCTYPE') === false && strpos($c, '<main class="app-main">') === false;
    }
    check('las 13 páginas no repiten DOCTYPE/<main> y usan app_page_head/body_start', $ok14);

    // Inventario separado en Controller / Service / Repository / Views
    $finas34 = true; $sinSql34 = true;
    foreach (['gestionar_inventario', 'ficha_equipo', 'ficha_componente'] as $pn) { $finas34 = $finas34 && substr_count((string)file_get_contents(__DIR__ . "/../public/$pn.php"), "\n") < 20 && strpos((string)file_get_contents(__DIR__ . "/../public/$pn.php"), '$pdo->') === false; }
    foreach (glob(__DIR__ . '/../app/Presentation/Views/inventario/*.php') as $vf) { $sinSql34 = $sinSql34 && strpos((string)file_get_contents($vf), '$pdo') === false; }
    // Usuarios separado en Controller / Service / Repository / Views
    $fin35 = true;
    foreach (['usuarios', 'register'] as $pn) { $src35 = (string)file_get_contents(__DIR__ . "/../public/$pn.php"); $fin35 = $fin35 && substr_count($src35, "\n") < 20 && strpos($src35, '$pdo->') === false; }
    foreach (glob(__DIR__ . '/../app/Presentation/Views/usuarios/*.php') as $vf) { $fin35 = $fin35 && strpos((string)file_get_contents($vf), '$pdo') === false; }
    // creación de tickets de profesor separada (TicketProfesorCreacionService / TicketRepository / Controller / View)
    $src36 = (string)file_get_contents(__DIR__ . '/../public/create_ticket_profesor.php');
    $vista36 = (string)file_get_contents(__DIR__ . '/../app/Presentation/Views/tickets/crear_profesor.php');
    check('Crear ticket de profesor: página solo punto de entrada, vista sin SQL y servicio con crearTicketProfesor',
        substr_count($src36, "\n") < 20 && strpos($src36, '$pdo->') === false && strpos($vista36, '$pdo') === false
        && method_exists('TicketProfesorCreacionService', 'crearTicketProfesor') && method_exists('TicketRepository', 'insertarProfesor'));
    check('Usuarios: usuarios.php y register.php son solo punto de entrada y las vistas no tienen SQL/PDO', $fin35);
    check('Inventario: las 3 páginas son solo punto de entrada y las vistas no tienen SQL/PDO', $finas34 && $sinSql34);

    // ==================== cierre (helpers troceados, auditoría/notificaciones en clases, tokens de marca) ====================
    echo "\n[cierre]\n";
    $raiz37 = __DIR__ . '/..';
    $clases38 = ['TicketConsultas' => 'Tickets', 'TicketActivos' => 'Tickets', 'TicketSeguimiento' => 'Tickets', 'TicketAdjuntos' => 'Tickets', 'ComponenteBajas' => 'Inventory'];
    $okClases38 = true; foreach ($clases38 as $cn => $dir) { $okClases38 = $okClases38 && is_file("$raiz37/app/Domain/$dir/$cn.php") && class_exists($cn); }
    check('tickets_helpers.php: retirado; sus 5 clases están en Domain y se cargan por autocarga', !is_file("$raiz37/app/Support/tickets_helpers.php") && !is_dir("$raiz37/app/Support/tickets") && $okClases38);
    $cv37 = (string)file_get_contents("$raiz37/app/Support/validacion.php");
    $enPiezas37 = ''; foreach ($clases38 as $cn => $dir) $enPiezas37 .= file_get_contents("$raiz37/app/Domain/$dir/$cn.php");
    check('correoValido() vive en validacion.php y no quedó duplicada en las clases de tickets', strpos($cv37, 'function correoValido') !== false && strpos($enPiezas37, 'function correoValido') === false && correoValido('x@ufps.edu.co') && !correoValido('x@otro.com'));
    check('adjuntos: TicketAdjuntos::carpetaAdjuntos() sigue apuntando a storage/adjuntos del proyecto', realpath(TicketAdjuntos::carpetaAdjuntos()) === realpath("$raiz37/storage/adjuntos") || (strpos(TicketAdjuntos::carpetaAdjuntos(), '/app/Support/tickets/../../../storage/adjuntos') !== false && is_dir(dirname(TicketAdjuntos::carpetaAdjuntos()))));
    check('auditoría y notificaciones solo existen como clases en Domain (sin fachadas en Support)',
        method_exists('AuditoriaService', 'registrar') && method_exists('AuditoriaRepository', 'insertar')
        && method_exists('NotificationService', 'crear') && method_exists('NotificationRepository', 'insertar')
        && !file_exists("$raiz37/app/Support/auditoria.php") && !file_exists("$raiz37/app/Support/notificaciones.php"));
    // auditoría: encabezado + solo los campos que realmente cambiaron
    AuditoriaService::registrar($pdo, $tec1, 'users', 987001, 'EDITAR', 'paso37', ['estado' => ['A', 'B'], 'marca' => ['X', 'X']]);
    $h37 = AuditoriaService::historialObjeto($pdo, 'users', 987001);
    check('auditoría: AuditoriaService::registrar + historialObjeto (omite campos sin cambio)', count($h37) === 1 && $h37[0]['accion'] === 'EDITAR' && count($h37[0]['cambios']) === 1 && $h37[0]['cambios'][0]['campo'] === 'estado');
    check('auditoría: historial de un registro sin movimientos es []', AuditoriaService::historialObjeto($pdo, 'users', 987002) === []);
    // notificaciones
    NotificationService::crear($pdo, $tec1, 'TIPO_INEXISTENTE', 'zt37 msg', 'x.php');
    $nid37 = (int)$pdo->query("SELECT id_notificacion FROM notificaciones WHERE id_usuario=$tec1 AND mensaje='zt37 msg'")->fetchColumn();
    check('notificaciones: tipo desconocido se guarda como SISTEMA', $nid37 > 0 && $pdo->query("SELECT tipo FROM notificaciones WHERE id_notificacion=$nid37")->fetchColumn() === 'SISTEMA');
    $n0 = NotificationService::contarNoLeidas($pdo, $tec1);
    check('notificaciones: marcarLeida ajena falla (IDOR) y propia funciona', NotificationService::marcarLeida($pdo, $nid37, $prof) === false && NotificationService::marcarLeida($pdo, $nid37, $tec1) === true && NotificationService::contarNoLeidas($pdo, $tec1) === $n0 - 1 && NotificationService::marcarLeida($pdo, $nid37, $tec1) === false);
    $nAdm37 = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
    $a0 = (int)$pdo->query("SELECT COUNT(*) FROM notificaciones WHERE mensaje='zt37 admins'")->fetchColumn();
    NotificationService::notificarAdmins($pdo, 'SISTEMA', 'zt37 admins', null);
    check('notificaciones: notificarAdmins crea una por admin', (int)$pdo->query("SELECT COUNT(*) FROM notificaciones WHERE mensaje='zt37 admins'")->fetchColumn() - $a0 === $nAdm37);
    // solo cuentas activas reciben notificaciones por rol
    $pdo->prepare("INSERT INTO users (codigo,correo,password,password_temporal,role,nombre_completo,cargo,dependencia,estado_activo) VALUES ('TT97','tt97@test.local',?,0,'admin','Admin inactivo','Tecnico','Sistemas',0)")->execute([$h]);
    $idInact38 = (int)$pdo->lastInsertId();
    NotificationService::notificarAdmins($pdo, 'SISTEMA', 'zt38 solo activos', null);
    check('notificarAdmins/notificarPorRol no notifican a cuentas deshabilitadas',
        (int)$pdo->query("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=$idInact38")->fetchColumn() === 0
        && (int)$pdo->query("SELECT COUNT(*) FROM notificaciones WHERE mensaje='zt38 solo activos'")->fetchColumn() === (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND estado_activo=1")->fetchColumn());
    check('notificaciones: obtenerNoLeidas respeta el límite', count(NotificationService::ultimas($pdo, $tec1, 1)) <= 1);
    // marca / tokens CSS
    $tk37 = (string)file_get_contents("$raiz37/public/assets/tokens.css");
    // Refactorización: el valor literal vive en --color-principal (panel de personalización) y --brand-500 deriva de él.
    check('tokens.css: --color-principal es la única definición literal del rojo de marca y --brand-500 deriva de él', preg_match_all('/#BC0017/i', $tk37) === 1 && preg_match('/--color-principal:\s*#BC0017/i', $tk37) === 1 && preg_match('/--brand-500:\s*var\(--color-principal\)/', $tk37) === 1 && strpos($tk37, '188,0,23') === false);
    check('tokens.css: primary/dark/darker/hover/soft y focus-ring derivan de --brand-500 con color-mix()',
        preg_match('/--color-primary:\s*var\(--brand-500\)/', $tk37) === 1 && substr_count($tk37, 'var(--brand-500)') >= 7 && substr_count($tk37, 'color-mix(') >= 6);
    $css37 = cssCompleto($raiz37);
    check('estilo.css (y partes): sin rgba() ni colores hex literales (todo sale de tokens.css)', !preg_match('/rgba?\(|:[^;{}]*#[0-9a-fA-F]{3,8}\b/', preg_replace('~/\*.*?\*/~s', '', $css37)));
    require_once "$raiz37/app/Support/marca.php";
    // periodo/eventos de horarios fuera de inventario.php
    require_once "$raiz37/app/Domain/Horarios/HorarioEventos.php";
    $inv38 = (string)file_get_contents("$raiz37/app/Support/inventario.php");
    check('HorarioPeriodo (siguiente/anterior/valido) y HorarioEventos existen; inventario.php ya no define esas funciones',
        class_exists('HorarioPeriodo') && class_exists('HorarioEventos')
        && HorarioPeriodo::siguiente('2026-1') === '2026-2' && HorarioPeriodo::siguiente('2026-2') === '2027-1'
        && HorarioPeriodo::anterior('2026-1') === '2025-2' && HorarioPeriodo::anterior('2026-2') === '2026-1'
        && HorarioPeriodo::valido('2026-2') && !HorarioPeriodo::valido('2026-3') && preg_match('/^\d{4}-[12]$/', HorarioPeriodo::actual()) === 1
        && !preg_match('/function (periodoActualUFPS|periodoSiguiente|periodoAnterior|periodoValido|buscarChoquesHorario|obtenerEventosSemana|crearExcepcionPorCambioSalaAprobado)\b/', $inv38));
    check('HorarioEventos::choques ignora fechas inválidas y salas sin eventos', HorarioEventos::choques($pdo, 987654, 'no-es-fecha', '10:00') === [] && HorarioEventos::choques($pdo, 987654, '2026-10-01', '10:00') === []);
    check('marcaRgb() lee --color-principal de tokens.css', marcaRgb() === [188, 0, 23]);
    check('marcaRgb() no depende del valor por defecto: el regex encuentra --color-principal', (bool)preg_match('/--(?:color-principal|brand-500):\s*#([0-9a-fA-F]{6})\b/', (string)file_get_contents("$raiz37/public/assets/tokens.css")));

    // ==================== h(), chips y JS compartidos ====================
    echo "\n[duplicados de bajo riesgo]\n";
    require_once __DIR__ . '/../app/Support/ui.php';
    check('h() escapa y tolera null', h('<a href="x">&</a>') === '&lt;a href=&quot;x&quot;&gt;&amp;&lt;/a&gt;' && h(null) === '');
    check('claseChipEstadoTicket conserva las reglas de las copias',
        claseChipEstadoTicket('En espera') === 'espera' && claseChipEstadoTicket('En proceso') === 'proceso' && claseChipEstadoTicket('Aprobado') === 'solucionado'
        && claseChipEstadoTicket('Rechazado') === 'solucionado' && claseChipEstadoTicket('Rechazado', true) === 'danio' && claseChipEstadoTicket(null) === 'solucionado');
    check('claseChipHistorialTicket (ficha de equipo)', claseChipHistorialTicket('Cerrado') === 'solucionado' && claseChipHistorialTicket('Listo pero sin validar') === 'proceso'
        && claseChipHistorialTicket('Descartado') === 'danio' && claseChipHistorialTicket('En espera') === 'espera');
    $copiasH = 0;
    foreach (glob(__DIR__ . '/../public/*.php') as $f) { if (preg_match('/function\s+h\s*\(/', (string)file_get_contents($f))) { $copiasH++; } }
    check('ninguna página de public/ define su propia h()', $copiasH === 0);
    check('public/app_sidebar.php (copia sin uso) ya no está en public/', !is_file(__DIR__ . '/../public/app_sidebar.php'));
    check('toggleFirmados vive solo en assets/tickets.js', strpos((string)file_get_contents(__DIR__ . '/../app/Presentation/Controllers/Tickets/TecnicoPanel.php'), 'function toggleFirmados') === false
        && strpos((string)file_get_contents(__DIR__ . '/../app/Presentation/Controllers/Tickets/BecaPanel.php'), 'function toggleFirmados') === false
        && strpos((string)file_get_contents(__DIR__ . '/../public/assets/tickets.js'), 'function toggleFirmados') !== false);

    // ==================== Repositories + Views ====================
    echo "\n[repositorios de paneles y vistas]\n";
    require_once __DIR__ . '/../app/Domain/Tickets/TicketPanelRepository.php';
    require_once __DIR__ . '/../app/Domain/Tickets/BecaPanelRepository.php';
    // Bandeja unificada (TicketBandeja): normales + profesor juntos, pendientes / firmados, 5 por página.
    $bS = TicketBandeja::cargar($pdo, true, 1, 1);
    check('bandeja: pendientes y firmados con máximo 5 por página', count($bS['pendientes']['datos']) <= 5 && count($bS['firmados']['datos']) <= 5 && isset($bS['pendientes']['total'], $bS['firmados']['total_paginas']));
    $todosS = array_merge(TicketPanelRepository::todosNormalesLigeros($pdo, true), TicketPanelRepository::todosProfesorLigeros($pdo, true));
    check('bandeja: el total suma normales + profesor del grupo', $bS['pendientes']['total'] + $bS['firmados']['total'] === count($todosS));
    $okOrden = true; $dS = $bS['pendientes']['datos'];
    for ($i = 1; $i < count($dS); $i++) { if ($dS[$i-1]['fecha_creacion'] < $dS[$i]['fecha_creacion']) { $okOrden = false; } }
    check('bandeja: más recientes primero (mezclando ambas tablas)', $okOrden && count(array_unique(array_column($dS, '_tipo'))) >= 1);
    check('bandeja: ningún firmado/cerrado queda en pendientes y ningún pendiente en firmados',
        !array_filter($bS['pendientes']['datos'], fn($f) => TicketBandeja::estaFirmado($f)) && !array_filter($bS['firmados']['datos'], fn($f) => !TicketBandeja::estaFirmado($f)));
    check('bandeja: «Solucionado» firmado sigue PENDIENTE (falta aprobar); Aprobado/Descartado van a firmados',
        TicketBandeja::estaFirmado(['estado' => 'Solucionado', 'firmado' => 1]) === false && TicketBandeja::estaFirmado(['estado' => 'Aprobado', 'firmado' => 1]) === true
        && TicketBandeja::estaFirmado(['estado' => 'Descartado', 'firmado' => 0]) === true && TicketBandeja::estaFirmado(['estado' => 'En proceso', 'firmado' => 0]) === false);
    $idsS = array_column(TicketPanelRepository::todosNormalesLigeros($pdo, true), 'id'); $idsX = array_column(TicketPanelRepository::todosNormalesLigeros($pdo, false), 'id');
    check('bandeja: Departamento y otras dependencias son disjuntos', count(array_intersect($idsS, $idsX)) === 0);
    check('bandeja: rango tipo correo («1–5 de N»)', TicketBandeja::rango(['datos' => [1, 2, 3, 4, 5], 'total' => 12, 'pagina' => 1]) === '1–5 de 12'
        && TicketBandeja::rango(['datos' => [1, 2], 'total' => 12, 'pagina' => 3]) === '11–12 de 12');
    check('salasTodas/becasParaAsignar/becasConCodigo devuelven arreglos', is_array(TicketPanelRepository::salasTodas($pdo))
        && is_array(TicketPanelRepository::becasParaAsignar($pdo)) && is_array(TicketPanelRepository::becasConCodigo($pdo)));
    $rdB = BecaPanelRepository::reportesDanio($pdo, $beca1);
    check('BecaPanelRepository: listas y auxiliares (reportes de daño = solo errores de sala de profesor + QR anónimo)', is_array($rdB)
        && !array_filter($rdB, fn($r) => $r['_tipo'] === 'prof' && $r['tipo_ticket'] !== 'danio') && !array_filter($rdB, fn($r) => $r['_tipo'] === 'qr' && $r['origen'] !== 'QR_ANONIMO')
        && BecaPanelRepository::esDanioProfesor($pdo, 2147483000) === false
        && is_array(BecaPanelRepository::ticketsCreadosPorBeca($pdo, $beca1, 1, 5)['datos'])
        && is_array(BecaPanelRepository::componentesEnBodega($pdo))
        && is_array(BecaPanelRepository::idsTecnicos($pdo)));
    $mAct = BecaPanelRepository::mantenimientosActivos($pdo, $beca1);
    check('checklistPorMantenimiento tolera vacío y lista', BecaPanelRepository::checklistPorMantenimiento($pdo, []) === [] && is_array(BecaPanelRepository::checklistPorMantenimiento($pdo, $mAct)));
    check('esReporteQrAnonimo: id inexistente = false', BecaPanelRepository::esReporteQrAnonimo($pdo, 2147483000) === false);
    check('datosUsuario devuelve nombre_completo del usuario', (BecaPanelRepository::datosUsuario($pdo, $beca1)['nombre_completo'] ?? null) !== null);
    foreach (['Tecnico' => 'list_tickets', 'Beca' => 'my_tickets_becatrabajo', 'Solicitante' => 'my_tickets', 'Profesor' => 'mis_tickets_profesor'] as $pn => $pg) {
        check("panel $pn ($pg.php) sin \$pdo-> (SQL fuera de la página)", strpos((string)file_get_contents(__DIR__ . "/../app/Presentation/Controllers/Tickets/{$pn}Panel.php"), '$pdo->') === false);
    }
    $vistasOk = true;
    foreach (glob(__DIR__ . '/../app/Presentation/Views/list_tickets/*.php') as $v) { if (preg_match('/\$pdo\b|SELECT\s|INSERT\s|UPDATE\s/', (string)file_get_contents($v))) { $vistasOk = false; } }
    check('vistas list_tickets existen y no tocan $pdo ni SQL', $vistasOk && count(glob(__DIR__ . '/../app/Presentation/Views/list_tickets/*.php')) === 3); // seccion_sistemas + seccion_solicitantes → seccion_grupo
    // --- vista de beca, legacy y JS de catálogo ---
    $vb = glob(__DIR__ . '/../app/Presentation/Views/my_tickets_becatrabajo/*.php');
    $vbOk = count($vb) === 4; foreach ($vb as $v) { if (preg_match('/\$pdo\b|SELECT\s|INSERT\s|UPDATE\s/', (string)file_get_contents($v))) { $vbOk = false; } }
    check('vistas de my_tickets_becatrabajo: 4 parciales sin $pdo ni SQL', $vbOk);
    check('revisar_componentes.php (sin referencias) ya no está en public/', !is_file(__DIR__ . '/../public/revisar_componentes.php'));
    $jsCat = (string)@file_get_contents(__DIR__ . '/../public/assets/catalogo_marca_modelo.js');
    check('assets/catalogo_marca_modelo.js define cargarModelosCatalogo y alternarModeloNuevo', strpos($jsCat, 'window.cargarModelosCatalogo') !== false && strpos($jsCat, 'window.alternarModeloNuevo') !== false && strpos($jsCat, 'listar_modelos_ajax.php') !== false);
    // ==================== Soluciones técnicas: permisos, estados y archivos privados ====================
    echo "\n[Soluciones técnicas: permisos, estados y archivos privados]\n";
    check('soluciones: gestiona el técnico, autoriza el admin, la beca no ve el catálogo',
        SolucionService::permisos('tecnico') === ['gestionar' => true, 'autorizar' => false, 'verTodo' => true]
        && SolucionService::permisos('admin') === ['gestionar' => false, 'autorizar' => true, 'verTodo' => true]
        && !array_filter(SolucionService::permisos('beca_trabajo')) && !array_filter(SolucionService::permisos('profesor')));
    check('soluciones: aplicada con una versión distinta de la vigente = desactualizada; sin estado = pendiente',
        SolucionDespliegueService::estadoVisible('VERIFICADO', 3, 4) === 'DESACTUALIZADO' && SolucionDespliegueService::estadoVisible('IMPLEMENTADO', 4, 4) === 'IMPLEMENTADO'
        && SolucionDespliegueService::estadoVisible('CON_INCIDENCIA', 3, 4) === 'CON_INCIDENCIA' && SolucionDespliegueService::estadoVisible(null, null, 4) === 'PENDIENTE'
        && SolucionDespliegueService::estadoVisible('VERIFICADO', 3, null) === 'DESACTUALIZADO');
    $extST = array_keys(SolucionArchivos::EXTENSIONES);
    check('soluciones: ninguna extensión que el servidor web pueda ejecutar o el navegador interpretar (php, phtml, phar, html, svg, js, htaccess)',
        !array_intersect($extST, ['php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'html', 'htm', 'xhtml', 'svg', 'js', 'mjs', 'htaccess', 'shtml', 'cgi', 'pl', 'asp', 'aspx', 'jsp']));
    check('soluciones: el nombre original se limpia (sin rutas, comillas ni saltos de línea)',
        SolucionArchivos::nombreLimpio("..\\..\\config/da\"ta\r\nbase.ps1") === 'da_ta__base.ps1' && SolucionArchivos::nombreLimpio('') === 'archivo');
    check('soluciones: la ruta en disco no sale de storage/soluciones (path traversal) y lo que no existe es null',
        SolucionArchivos::ruta('../../config/database.php') === null && SolucionArchivos::ruta('../../bootstrap.php') === null
        && SolucionArchivos::ruta('../adjuntos/.htaccess') === SolucionArchivos::carpeta() . '/.htaccess'); // se queda en su carpeta, nunca en adjuntos/
    check('soluciones: storage/soluciones está bloqueada por URL y fuera de git', strpos((string)@file_get_contents(__DIR__ . '/../storage/soluciones/.htaccess'), 'Require all denied') !== false
        && strpos((string)file_get_contents(__DIR__ . '/../.gitignore'), 'storage/soluciones/*') !== false);
    check('soluciones: el respaldo incluye los archivos de las soluciones técnicas', strpos((string)file_get_contents(__DIR__ . '/../app/Domain/Seguridad/RespaldoService.php'), "'storage/soluciones'") !== false);
    $resST = SolucionService::resolverDescarga($pdo, 'archivo', 0, 'tecnico', 1);
    check('soluciones: descarga sin id o de tipo desconocido = 404, sin ruta', !$resST['ok'] && $resST['status'] === 404 && $resST['ruta'] === null
        && SolucionService::resolverDescarga($pdo, 'otro', 1, 'admin', 1)['status'] === 404);
    $sinPermisoST = SolucionService::crear($pdo, 0, ['nombre' => 'ZT-x', 'id_tipo' => 1, 'descripcion' => 'x', 'objetivo' => 'x']);
    check('soluciones: el servicio revisa el rol en la base (un usuario inexistente no crea nada)', $sinPermisoST['error'] !== '' && $sinPermisoST['id'] === 0);

    // ==================== base de datos única y limpieza ====================
    echo "\n[base de datos única y archivos sin uso]\n";
    $sqlUnico = (string)@file_get_contents(__DIR__ . '/../database/tickets_db.sql');
    check('database/tickets_db.sql existe, tiene 68 tablas (incluye oficina_staff, conexiones_externas, glpi_cola, sala_distribucion, actividades_tablero, SLA, seguridad, el módulo de mantenimiento y las 11 st_* de soluciones técnicas) y las 3 migraciones (columnas)', substr_count($sqlUnico, 'CREATE TABLE IF NOT EXISTS') === 68
        && strpos($sqlUnico, 'permiso_horarios_extra') !== false && strpos($sqlUnico, 'permiso_ver_tickets_sistemas') !== false && strpos($sqlUnico, '`beca_id`') !== false);
    check('database/tickets_db.sql: la tabla users ya no tiene cédula (y la elimina en bases existentes)', strpos($sqlUnico, '`cedula` varchar') === false && strpos($sqlUnico, 'DROP COLUMN `cedula`') !== false);
    check('database/tickets_db.sql es no destructivo (sin DROP ni TRUNCATE ni DELETE)', !preg_match('/\b(DROP\s+(DATABASE|TABLE)|TRUNCATE|DELETE\s+FROM)\b/i', $sqlUnico));
    check('no quedan .sql sueltos en database/ (solo el único + seeds)', count(glob(__DIR__ . '/../database/*.sql')) === 1 && !is_dir(__DIR__ . '/../database/migrations'));
    check('instalación limpia: sin migraciones viejas sueltas ni docs de FPDF', !is_dir(__DIR__ . '/../database/migrations')
        && !is_file(__DIR__ . '/../public/assets/sonidos/notificacion.mp3') && !is_file(__DIR__ . '/../vendor/fpdf/install.txt') && is_file(__DIR__ . '/../vendor/fpdf/fpdf.php'));
    check('create_ticket_profesor.php (activo) sigue en public/', is_file(__DIR__ . '/../public/create_ticket_profesor.php'));
    // ==================== compatibilidad, PDO y configuración ====================
    echo "\n[rutas por rol, PDO y configuración centralizados]\n";
    require_once __DIR__ . '/../app/Support/auth.php';
    require_once __DIR__ . '/../app/Support/rutas.php';
    require_once __DIR__ . '/../app/Support/config.php';
    require_once __DIR__ . '/../app/Infrastructure/Database/Database.php';
    check('rutaInicioPorRol cubre los 6 roles (todos van al panel único tickets.php)', rutaInicioPorRol('secretaria') === 'tickets.php'
        && rutaInicioPorRol('tecnico') === 'tickets.php' && rutaInicioPorRol('admin') === 'tickets.php' && rutaInicioPorRol('solicitante') === 'tickets.php'
        && rutaInicioPorRol('profesor') === 'tickets.php' && rutaInicioPorRol('beca_trabajo') === 'tickets.php');
    $okMenu = true;
    foreach (['profesor','beca_trabajo','tecnico','admin','secretaria','solicitante'] as $rolM) {
        $primero = menuPorRol($rolM)[0] ?? [];
        $hrefM = $primero['tipo'] === 'grupo' ? $primero['hijos'][0]['href'] : ($primero['href'] ?? '');
        if ($hrefM !== rutaInicioPorRol($rolM)) { $okMenu = false; }
    }
    check('la página de inicio de cada rol = primer elemento de su menú (lista de tickets)', $okMenu);
    check('rutaInicioPorRol: rol desconocido o vacío = null', rutaInicioPorRol('hacker') === null && rutaInicioPorRol('') === null);
    $menuIncoherente = [];
    foreach (Rol::TODOS as $rolM) {
        foreach (menuPorRol($rolM, ['veHorariosSalas' => true, 'veHorasBecas' => true]) as $itM) {
            foreach ($itM['tipo'] === 'grupo' ? $itM['hijos'] : ($itM['tipo'] === 'link' ? [$itM] : []) as $lnk) {
                if (!puedeAbrirPagina($rolM, explode('?', $lnk['href'])[0])) $menuIncoherente[] = "$rolM:{$lnk['href']}";
            }
        }
    }
    check('mapa único de rutas: ningún rol ve en el menú una página que no puede abrir' . ($menuIncoherente ? ' (' . implode(', ', $menuIncoherente) . ')' : ''), !$menuIncoherente);
    $sinGuard = [];
    foreach (PAGINAS_POR_ROL as $pagM => $rolesM) {
        $pubM = (string)@file_get_contents(__DIR__ . '/../public/' . $pagM);
        if (!preg_match('~Controllers/([A-Za-z/]+\.php)~', $pubM, $cm)) { $sinGuard[] = $pagM; continue; }
        $ctrlM = (string)file_get_contents(__DIR__ . '/../app/Presentation/Controllers/' . $cm[1]);
        if (strpos($ctrlM, "requirePagina('$pagM')") === false && strpos($ctrlM, "PAGINAS_POR_ROL['$pagM']") === false && $pagM !== 'actividades.php') $sinGuard[] = $pagM;
    }
    check('mapa único de rutas: cada página del mapa se protege con requirePagina()' . ($sinGuard ? ' (' . implode(', ', $sinGuard) . ')' : ''), !$sinGuard);
    check('index.php y login.php usan rutaInicioPorRol (sin tabla de roles copiada)', strpos((string)file_get_contents(__DIR__ . '/../app/Presentation/Controllers/Publico/InicioPage.php'), 'rutaInicioPorRol') !== false
        && strpos((string)file_get_contents(__DIR__ . '/../app/Support/auth.php'), 'rutaInicioPorRol((string)$user') !== false
        && strpos((string)file_get_contents(__DIR__ . '/../app/Presentation/Controllers/Publico/InicioPage.php'), "=='tecnico'") === false);
    check('configCargar: archivo inexistente o nombre con ruta = null', configCargar('no_existe_xyz') === null && configCargar('../app/Support/auth') === null);
    check('configCargar lee config/database.php (arreglo con host)', is_array(configCargar('database')) && isset(configCargar('database')['host']));
    check('Database::transaccion devuelve el valor y no deja transacción abierta', Database::transaccion(fn($c) => 42) === 42 && !Database::conexion()->inTransaction());
    $relanza = false; try { Database::transaccion(function ($c) { throw new RuntimeException('x'); }); } catch (RuntimeException $e) { $relanza = true; }
    check('Database::transaccion deshace y relanza ante excepción', $relanza && !Database::conexion()->inTransaction());
    $a = Database::asistencia();
    check('Database::asistencia: sin config/postgres.php (o sin servidor) devuelve null sin romper', $a === null || $a instanceof PDO);
    $pgSrc = (string)file_get_contents(__DIR__ . '/../app/Infrastructure/Database/PostgresConnection.php');
    check('PostgresConnection.php ya no trae host/usuario/clave escritos', strpos($pgSrc, 'clave_pg') === false && strpos($pgSrc, 'new PDO') === false && strpos($pgSrc, 'Database::asistencia()') !== false);
    $pdoFuera = [];
    foreach (array_merge(glob(__DIR__ . '/../app/*/*.php'), glob(__DIR__ . '/../app/*/*/*.php'), glob(__DIR__ . '/../public/*.php'), glob(__DIR__ . '/../app/Presentation/Views/layout/*.php')) as $f) {
        if (preg_match('/new\s+PDO\s*\(/', (string)file_get_contents($f)) && basename($f) !== 'Database.php') { $pdoFuera[] = basename($f); }
    }
    check('PDO se crea solo en app/Infrastructure/Database/Database.php', $pdoFuera === []);
    $gi = (string)@file_get_contents(__DIR__ . '/../.gitignore');
    check('.gitignore excluye config/database.php, postgres.php y smtp.php', strpos($gi, 'config/database.php') !== false && strpos($gi, 'config/postgres.php') !== false && strpos($gi, 'config/smtp.php') !== false);
    check('existe config/postgres.example.php', is_file(__DIR__ . '/../config/postgres.example.php'));
    $cred = [];
    foreach (array_merge(glob(__DIR__ . '/../app/*/*.php'), glob(__DIR__ . '/../app/*/*/*.php'), glob(__DIR__ . '/../public/*.php')) as $f) {
        if (preg_match('/(pgsql|mysql):host=[^$\s"\']|[\'"](usuario_pg|clave_pg)[\'"]/', (string)file_get_contents($f))) { $cred[] = basename($f); }
    }
    check('ninguna página/servicio escribe DSN con host fijo ni credenciales', array_diff($cred, ['Database.php', 'run_tests.php']) === []);
    // ==================== panel único de tickets ====================
    echo "\n[panel único tickets.php]\n";
    require_once __DIR__ . '/../app/Presentation/Controllers/Tickets/PanelRouter.php';
    check('panelTicketsDeRol cubre los 6 roles y rechaza desconocidos', panelTicketsDeRol('tecnico') === 'Tecnico' && panelTicketsDeRol('admin') === 'Admin' && panelTicketsDeRol('beca_trabajo') === 'Beca'
        && panelTicketsDeRol('profesor') === 'Profesor' && panelTicketsDeRol('solicitante') === 'Solicitante' && panelTicketsDeRol('secretaria') === 'Solicitante' && panelTicketsDeRol('x') === null);
    $panelesValidos = ['Tecnico', 'Admin', 'Beca', 'Profesor', 'Solicitante'];
    $okPan = true; foreach ($panelesValidos as $pn) { if (!is_file(__DIR__ . "/../app/Presentation/Controllers/Tickets/{$pn}Panel.php")) { $okPan = false; } }
    check('existe un script de panel por cada panel válido', $okPan);
    $okShim = true; foreach (['list_tickets', 'admin_tickets', 'my_tickets', 'my_tickets_becatrabajo', 'mis_tickets_profesor'] as $old) {
        if (is_file(__DIR__ . "/../public/$old.php")) { $okShim = false; }
    }
    check('instalación limpia: las 5 URLs antiguas ya no existen (todo entra por tickets.php)', $okShim);
    $entradasMal = [];
    foreach (glob(__DIR__ . '/../public/*.php') as $fe) {
        $ce = (string)file_get_contents($fe);
        if (substr_count($ce, "\n") > 8 || strpos($ce, '$') !== false || strpos($ce, '<') !== strpos($ce, '<?php') || substr_count($ce, "require __DIR__ . '/../app/Presentation/Controllers/") !== 1) $entradasMal[] = basename($fe);
    }
    check('public/ solo tiene puntos de entrada: ≤ 8 líneas, sin variables ni HTML, un controlador cada uno (' . implode(',', $entradasMal) . ')', $entradasMal === [] && !is_dir(__DIR__ . '/../public/includes'));
    $okSql = true; foreach ($panelesValidos as $pn) { if (strpos((string)file_get_contents(__DIR__ . "/../app/Presentation/Controllers/Tickets/{$pn}Panel.php"), '$pdo->') !== false) { $okSql = false; } }
    check('los paneles de tickets no ejecutan SQL directo ($pdo->) — solo repositorios/servicios', $okSql);
    $tk = (string)file_get_contents(__DIR__ . '/../app/Presentation/Controllers/Tickets/PanelPage.php'); // public/tickets.php solo lo carga
    check('tickets.php exige sesión (requireAuth) y no concede acceso a roles desconocidos', strpos($tk, 'requireAuth();') !== false && strpos($tk, "header('Location: login.php')") !== false);
    $sbx = (string)file_get_contents(__DIR__ . '/../app/Support/rutas.php');
    check('el menú de los 6 roles apunta a tickets.php (sin enlaces a las URLs antiguas)', !preg_match("/enlace\('(list_tickets|admin_tickets|my_tickets|my_tickets_becatrabajo|mis_tickets_profesor)\.php'/", $sbx) && substr_count($sbx, "enlace('tickets.php'") === 6);
    check('app_header usa rutaInicioPorRol (sin tabla de roles propia)', strpos((string)file_get_contents(__DIR__ . '/../app/Presentation/Views/layout/app_header.php'), 'rutaInicioPorRol') !== false);
    // ==================== autenticación centralizada (§21) ====================
    echo "\n[guards de sesión centralizados]\n";
    $manual = [];
    foreach (glob(__DIR__ . '/../public/*.php') as $f) {
        $c = (string)file_get_contents($f);
        if (preg_match('/if\s*\(\s*!\s*isset\(\s*\$_SESSION\[\'user_id\'\]\s*\)/', $c)) { $manual[] = basename($f); }
    }
    check('ninguna página de public/ valida sesión a mano con !isset($_SESSION[\'user_id\'])', $manual === []);
    check('un solo guard de rol: requireRole (sin inventarioRequiereRol ni h_inv/h_notif)', !function_exists('inventarioRequiereRol') && !function_exists('h_inv') && !function_exists('h_notif'));
    $au = (string)file_get_contents(__DIR__ . '/../app/Support/auth.php');
    check('auth.php define requireAuth/Role/Permission/Json/OrDie', strpos($au, 'function requireAuthJson') !== false && strpos($au, 'function requireAuthOrDie') !== false
        && strpos($au, 'function requireRole') !== false && strpos($au, 'function requirePermission') !== false && strpos($au, 'function requireAuth()') !== false);
    $okEp = true; foreach (['buscar_componente_ajax' => 'requireAuthJson', 'buscar_global' => 'requireAuthJson', 'listar_equipos_ajax' => 'requireAuthJson', 'listar_modelos_ajax' => 'requireAuthJson',
        'notificacion_marcar_leida' => 'requireAuthJson', 'seguimiento_horas_pdf' => 'requireAuthOrDie', 'ticket_pdf' => 'requireAuthOrDie', 'ticket_profesor_pdf' => 'requireAuthOrDie', 'descargar_adjunto' => 'requireAuth'] as $ep => $fn) {
        // pasos 39 y 42: el guard vive en el controlador (la página pública solo lo carga)
        $ctrlEp = ['seguimiento_horas_pdf' => 'Horas/SeguimientoHorasPdfPage', 'buscar_global' => 'Ajax/BuscarGlobalPage', 'buscar_componente_ajax' => 'Ajax/BuscarComponentePage',
            'listar_equipos_ajax' => 'Ajax/ListarEquiposPage', 'listar_modelos_ajax' => 'Ajax/ListarModelosPage', 'notificacion_marcar_leida' => 'Ajax/NotificacionMarcarLeidaPage',
            'descargar_adjunto' => 'Archivos/DescargarAdjuntoPage', 'ticket_pdf' => 'Pdf/TicketPdfPage', 'ticket_profesor_pdf' => 'Pdf/TicketProfesorPdfPage'][$ep];
        $fuenteEp = (string)file_get_contents(__DIR__ . "/../app/Presentation/Controllers/$ctrlEp.php");
        check("public/$ep.php delega en su controlador", strpos((string)file_get_contents(__DIR__ . "/../public/$ep.php"), "Controllers/$ctrlEp.php") !== false);
        if (strpos($fuenteEp, "$fn(") === false) { $okEp = false; }
    }
    check('los 9 endpoints AJAX/PDF/descarga usan los guards centrales', $okEp);

    // ==================== Becas (create_ticket_beca, solicitudes_baja, revisar_componentes_bodega) + Seguimiento de horas ====================
    echo "\n[módulo Becas separado en Controller / Service / Repository / Views / PDF]\n";
    $r39 = __DIR__ . '/..';
    $fin39 = true;
    foreach (['create_ticket_beca' => 'Becas', 'seguimiento_horas' => 'Horas', 'seguimiento_horas_pdf' => 'Horas', 'solicitudes_baja' => 'Becas', 'revisar_componentes_bodega' => 'Becas'] as $pn => $carpeta39) {
        $src39 = (string)file_get_contents("$r39/public/$pn.php");
        $fin39 = $fin39 && substr_count($src39, "\n") < 20 && strpos($src39, '$pdo->') === false && strpos($src39, "Controllers/$carpeta39/") !== false;
    }
    check('Becas/Horas: las 5 páginas de public/ son solo punto de entrada (sin SQL)', $fin39);
    check('instalación limpia: no quedan seguimiento_beca*.php ni referencias al formato institucional', !is_file("$r39/public/seguimiento_beca.php") && !is_file("$r39/public/seguimiento_beca_pdf.php")
        && strpos(implode('', array_map('file_get_contents', array_merge(glob("$r39/app/*/*/*.php"), glob("$r39/app/*/*/*/*.php"), glob("$r39/public/*.php"), glob("$r39/app/Presentation/Views/layout/*.php")))), 'FO-BU') === false);
    $sinSql39 = true;
    foreach (glob("$r39/app/Presentation/Views/becas/*.php") as $vf) { $sinSql39 = $sinSql39 && strpos((string)file_get_contents($vf), '$pdo') === false; }
    foreach (glob("$r39/app/Presentation/Views/horas/*.php") as $vf) { $sinSql39 = $sinSql39 && strpos((string)file_get_contents($vf), '$pdo') === false; }
    check('Becas/Horas: las vistas no contienen SQL ni $pdo', $sinSql39 && count(glob("$r39/app/Presentation/Views/becas/*.php")) === 3 && count(glob("$r39/app/Presentation/Views/horas/*.php")) === 1);
    $sinSqlCtl39 = true;
    foreach (array_merge(glob("$r39/app/Presentation/Controllers/Becas/*.php"), glob("$r39/app/Presentation/Controllers/Horas/*.php")) as $cf) { $sinSqlCtl39 = $sinSqlCtl39 && !preg_match('/\$pdo->(query|prepare|exec)\(/', (string)file_get_contents($cf)); }
    check('Becas/Horas: los controladores no ejecutan SQL directamente', $sinSqlCtl39 && count(glob("$r39/app/Presentation/Controllers/Becas/*.php")) === 3 && count(glob("$r39/app/Presentation/Controllers/Horas/*.php")) === 2);
    require_once "$r39/app/Domain/Tickets/TicketBecaService.php";
    require_once "$r39/app/Domain/Inventory/BodegaService.php";
    check('Becas: existen los servicios y repositorios', method_exists('TicketBecaService', 'crear') && method_exists('TicketBecaService', 'resolverTicketProfesorOrigen')
        && method_exists('BodegaService', 'revisar') && method_exists('BodegaService', 'solicitarBaja') && method_exists('BodegaService', 'resolverSolicitud')
        && method_exists('TicketBecaRepository', 'crearCambioPieza') && method_exists('BodegaRepository', 'solicitudesResueltas'));
    $pdfSrc39 = (string)file_get_contents("$r39/app/Infrastructure/Pdf/ReporteHorasPdf.php");
    check('Horas: la clase del PDF vive en Infrastructure/Pdf y el logo apunta a public/logo.png', strpos($pdfSrc39, 'class ReporteHorasPdf extends FPDF') !== false && strpos($pdfSrc39, "/public/logo.png") !== false && is_file("$r39/public/logo.png"));
    check('Becas: ninguna función global nueva en las páginas (las reglas están en clases)', !preg_match('/^function /m', (string)file_get_contents("$r39/public/create_ticket_beca.php")));
    $p39 = SeguimientoHorasService::periodo('2025', '3');
    check('SeguimientoHorasService::periodo calcula inicio y fin del mes', $p39['inicio'] === '2025-03-01' && $p39['fin'] === '2025-03-31');
    $p39 = SeguimientoHorasService::periodo('abc', '99');
    check('SeguimientoHorasService::periodo con valores inválidos usa el mes actual', $p39['mes'] === (int)date('n') && $p39['anio'] === (int)date('Y'));
    check('SeguimientoHorasService::nombreArchivoPdf limpia el nombre', SeguimientoHorasService::nombreArchivoPdf([['nombre_completo' => 'Ana Perez/2']], 3, 2025) === 'Horas_Ana_Perez_2_3_2025.pdf'
        && SeguimientoHorasService::nombreArchivoPdf([[], []], 3, 2025) === 'Horas_Becas_Trabajo_3_2025.pdf');


    // ==================== Público/QR y solicitante (reportar, consultar_estado, create_ticket) ====================
    echo "\n[reportar, consultar_estado y create_ticket separados]\n";
    $r40 = __DIR__ . '/..';
    $fin40 = true;
    foreach (['reportar', 'consultar_estado', 'create_ticket'] as $pn) {
        $src40 = (string)file_get_contents("$r40/public/$pn.php");
        $fin40 = $fin40 && substr_count($src40, "\n") < 20 && strpos($src40, '$pdo->') === false && !preg_match('/^function /m', $src40);
    }
    check('las 3 páginas de public/ son solo punto de entrada (sin SQL ni funciones)', $fin40);
    $sinSql40 = true;
    foreach (array_merge(glob("$r40/app/Presentation/Views/publico/*.php"), ["$r40/app/Presentation/Views/tickets/crear.php"]) as $vf) { $sinSql40 = $sinSql40 && strpos((string)file_get_contents($vf), '$pdo') === false && strpos((string)file_get_contents($vf), '<script') === false; }
    check('las vistas públicas y la de crear ticket no contienen SQL ni <script>', $sinSql40 && count(glob("$r40/app/Presentation/Views/publico/*.php")) === 4); // reportar, consultar_estado, inicio (portada) y aviso de privacidad
    $ctl40 = true;
    foreach (array_merge(glob("$r40/app/Presentation/Controllers/Publico/*.php"), ["$r40/app/Presentation/Controllers/Tickets/CrearTicketPage.php"]) as $cf) { $ctl40 = $ctl40 && !preg_match('/\$pdo->(query|prepare|exec)\(/', (string)file_get_contents($cf)); }
    check('los controladores no ejecutan SQL directamente', $ctl40);
    check('reportar exige CSRF cuando hay sesión de profesor y la vista lleva csrfField()', strpos((string)file_get_contents("$r40/app/Presentation/Controllers/Publico/ReportarPage.php"), 'if ($profesorLogueado) csrfCheck();') !== false
        && strpos((string)file_get_contents("$r40/app/Presentation/Views/publico/reportar.php"), 'csrfField()') !== false);
    require_once "$r40/app/Domain/Tickets/ReporteQrService.php";
    check('ReporteQrService expone reportar/consultar y los mensajes públicos de estado', method_exists('ReporteQrService', 'reportar') && method_exists('ReporteQrService', 'consultar')
        && ReporteQrService::descripcionEstadoPublico('Cerrado') === 'Resuelto y cerrado.' && ReporteQrService::descripcionEstadoPublico('Otro') === 'Otro' && ReporteQrService::MAX_DESCRIPCION === 5000);
    $ses40 = ['consulta_estado_intentos' => 8, 'consulta_estado_ultimo' => time()];
    $limit40 = ReporteQrService::consultar($pdo, 'ABC', $ses40);
    check('ReporteQrService::consultar bloquea tras 8 intentos en un minuto (sin tocar la BD)', $limit40['error'] === 'Demasiados intentos. Espera un minuto antes de volver a intentar.' && $limit40['codigo'] === '' && $limit40['ticket'] === null);
    $ses40 = [];
    check('ReporteQrService::consultar sin código no cuenta intento ni consulta', ReporteQrService::consultar($pdo, '', $ses40)['error'] === '' && $ses40 === []);
    check('TicketRepository::nombreOficinaMantenimiento existe (formulario de la secretaría)', method_exists('TicketRepository', 'nombreOficinaMantenimiento'));


    // ==================== Cuenta y acceso (login, perfil, cambiar_clave, recuperar/restablecer, google_callback, logout) ====================
    echo "\n[cuenta y acceso separados en Controller / Service / Repository / Views]\n";
    $r41 = __DIR__ . '/..';
    $fin41 = true;
    foreach (['login', 'logout', 'perfil', 'cambiar_clave', 'recuperar_clave', 'restablecer_clave', 'google_callback'] as $pn) {
        $src41 = (string)file_get_contents("$r41/public/$pn.php");
        $fin41 = $fin41 && substr_count($src41, "\n") < 20 && strpos($src41, '$pdo->') === false && !preg_match('/^function /m', $src41);
    }
    check('las 7 páginas de cuenta/acceso son solo punto de entrada (sin SQL ni funciones)', $fin41);
    $vistas41 = glob("$r41/app/Presentation/Views/cuenta/*.php");
    $sinSql41 = count($vistas41) === 7; // + verificación en dos pasos y notificaciones
    foreach ($vistas41 as $vf) { $sinSql41 = $sinSql41 && strpos((string)file_get_contents($vf), '$pdo') === false && strpos((string)file_get_contents($vf), '<script') === false; }
    check('las 7 vistas (incl. verificación en dos pasos y notificaciones) no contienen SQL ni <script>', $sinSql41);
    $ctl41 = true;
    foreach (glob("$r41/app/Presentation/Controllers/Cuenta/*.php") as $cf) { $ctl41 = $ctl41 && !preg_match('/\$pdo->(query|prepare|exec)\(/', (string)file_get_contents($cf)); }
    check('los 10 controladores de cuenta (incl. GoogleLogin, Logout, Verificación y Notificaciones) no ejecutan SQL directamente', $ctl41 && count(glob("$r41/app/Presentation/Controllers/Cuenta/*.php")) === 10);
    require_once "$r41/app/Domain/Users/LoginService.php";
    require_once "$r41/app/Domain/Users/RecuperacionClaveService.php";
    require_once "$r41/app/Domain/Users/PerfilService.php";
    require_once "$r41/app/Domain/Users/UserAccount.php";
    check('existen LoginService, RecuperacionClaveService, PerfilService y UserAccount::cambiarClaveDesdeSesion', method_exists('LoginService', 'intentar') && method_exists('RecuperacionClaveService', 'solicitar')
        && method_exists('PerfilService', 'subirFoto') && method_exists('PerfilService', 'quitarFoto') && method_exists('UserAccount', 'cambiarClaveDesdeSesion')
        && method_exists('UserRepository', 'porCorreo') && method_exists('UserRepository', 'marcarOffline'));
    check('cambiar_clave redirige con rutaInicioPorRol (sin tabla de roles propia)', strpos((string)file_get_contents("$r41/app/Presentation/Controllers/Cuenta/CambiarClavePage.php"), 'rutaInicioPorRol(') !== false
        && strpos((string)file_get_contents("$r41/app/Presentation/Controllers/Cuenta/CambiarClavePage.php"), 'admin_tickets.php') === false);
    check('los mensajes de bloqueo salen de una sola función (LoginService::mensajeBloqueada)', substr_count((string)file_get_contents("$r41/app/Domain/Users/LoginService.php"), 'Cuenta bloqueada por') === 1);
    // Cambio de clave: validaciones en orden y sin tocar la BD cuando fallan
    $uCta = $pdo->prepare("INSERT INTO users (codigo, correo, password, password_temporal, role, nombre_completo, cargo, estado_activo) VALUES ('ZTU41', 'zt_u41@test.local', ?, 1, 'solicitante', 'ZT u41', 'Prueba', 1)");
    $uCta->execute([password_hash('Actual12345', PASSWORD_DEFAULT)]);
    $id41 = (int)$pdo->lastInsertId();
    check('cambiarClaveDesdeSesion: actual incorrecta/vacía', UserAccount::cambiarClaveDesdeSesion($pdo, $id41, 'mala', 'Nueva-clave-segura-41', 'Nueva-clave-segura-41')['error'] === 'La contraseña actual no es correcta.' && UserAccount::cambiarClaveDesdeSesion($pdo, $id41, '', 'Nueva-clave-segura-41', 'Nueva-clave-segura-41')['ok'] === false);
    check('cambiarClaveDesdeSesion: clave corta y confirmación distinta', UserAccount::cambiarClaveDesdeSesion($pdo, $id41, 'Actual12345', 'corta', 'corta')['error'] === PoliticaClave::validar('corta')
        && UserAccount::cambiarClaveDesdeSesion($pdo, $id41, 'Actual12345', 'Nueva-clave-segura-41', 'Otra-clave-segura-41')['error'] === 'Las contraseñas no coinciden.' && (int)$pdo->query("SELECT password_temporal FROM users WHERE id=$id41")->fetchColumn() === 1);
    $ok41 = UserAccount::cambiarClaveDesdeSesion($pdo, $id41, 'Actual12345', 'Nueva-clave-segura-41', 'Nueva-clave-segura-41');
    check('cambiarClaveDesdeSesion: guarda, quita password_temporal y audita', $ok41['ok'] === true && (int)$pdo->query("SELECT password_temporal FROM users WHERE id=$id41")->fetchColumn() === 0
        && password_verify('Nueva-clave-segura-41', (string)$pdo->query("SELECT password FROM users WHERE id=$id41")->fetchColumn()) && (int)$pdo->query("SELECT COUNT(*) FROM auditoria WHERE id_usuario=$id41 AND tabla_afectada='users'")->fetchColumn() === 1);
    $lg41 = LoginService::intentar($pdo, 'ZTU41', 'zt_u41@test.local', 'Nueva-clave-segura-41', '127.0.0.1');
    check('LoginService::intentar: par código+correo+clave correcto → ok; código ajeno → error con contador', $lg41['ok'] === true && (int)$lg41['usuario']['id'] === $id41
        && LoginService::intentar($pdo, 'OTRO', 'zt_u41@test.local', 'Nueva-clave-segura-41', null)['ok'] === false);
    $pdo->exec("DELETE FROM usuario_bloqueo WHERE id_usuario=$id41"); $pdo->exec("DELETE FROM auditoria_detalle_campos WHERE id_auditoria IN (SELECT id_auditoria FROM auditoria WHERE id_usuario=$id41)"); $pdo->exec("DELETE FROM auditoria WHERE id_usuario=$id41"); $pdo->exec("DELETE FROM users WHERE id=$id41");
    check('RecuperacionClaveService::solicitar rechaza correos con formato inválido sin tocar la BD', RecuperacionClaveService::solicitar($pdo, 'no-es-correo', null) === ['error' => 'Escribe un correo válido.', 'enviado' => false]);

    // ==================== AJAX, descargas y PDF ====================
    echo "\n[AJAX, descargas y PDF separados en Controller / Service / Repository / Infrastructure\\Pdf]\n";
    $r42 = __DIR__ . '/..';
    $fin42 = true;
    foreach (['buscar_global', 'buscar_componente_ajax', 'listar_equipos_ajax', 'listar_modelos_ajax', 'notificacion_marcar_leida', 'descargar_adjunto', 'ticket_pdf', 'ticket_profesor_pdf'] as $pn) {
        $src42 = (string)file_get_contents("$r42/public/$pn.php");
        $fin42 = $fin42 && substr_count($src42, "\n") < 15 && strpos($src42, '$pdo->') === false && !preg_match('/^(function|class) /m', $src42);
    }
    check('las 8 páginas AJAX/descarga/PDF son solo punto de entrada (sin SQL, clases ni funciones)', $fin42);
    $ctl42 = '';
    foreach (glob("$r42/app/Presentation/Controllers/{Ajax,Archivos,Pdf}/*.php", GLOB_BRACE) as $f) $ctl42 .= file_get_contents($f);
    check('los controllers AJAX/descarga/PDF no contienen SQL ni PDO directo', !preg_match('/->prepare\(|->query\(|SELECT\s/i', $ctl42));
    $tp42 = (string)file_get_contents("$r42/app/Infrastructure/Pdf/TicketPdf.php");
    check('un solo encabezado/pie para los PDF de ticket y logo por ruta absoluta', substr_count($tp42, 'function Header') === 1 && substr_count($tp42, 'function Footer') === 1 && strpos($tp42, "Image('logo.png'") === false);
    $dup42 = [];
    foreach (['ticket_pdf', 'ticket_profesor_pdf'] as $pn) if (strpos((string)file_get_contents("$r42/public/$pn.php"), 'extends FPDF') !== false) $dup42[] = $pn;
    check('las páginas de PDF ya no definen su propia clase FPDF', $dup42 === []);
    require_once "$r42/app/Domain/Tickets/TicketPdfService.php";
    require_once "$r42/app/Domain/Search/BusquedaGlobalService.php";
    check('textoEquipos arma «codigo (descripcion)» separado por comas', TicketPdfService::textoEquipos([['codigo' => 'A1', 'descripcion' => 'PC'], ['codigo' => 'A2', 'descripcion' => '']]) === 'A1 (PC), A2');
    $_SESSION['role'] = 'beca_trabajo';
    $pb42 = TicketPdfService::paraTicket($pdo, '1', 'beca_trabajo', 1);
    check('beca_trabajo no accede a PDF de ticket (403)', $pb42['status'] === 403 && $pb42['ticket'] === null);
    check('PDF sin id o con id inválido → «ID de ticket no especificado»', TicketPdfService::paraTicket($pdo, null, 'admin', 1)['error'] === 'ID de ticket no especificado' && TicketPdfService::paraTicketProfesor($pdo, 'abc', 'admin', 1)['error'] === 'ID de ticket no especificado');
    check('búsqueda global con menos de 2 caracteres devuelve vacío', BusquedaGlobalService::buscar($pdo, 'admin', 1, 'a') === BusquedaGlobalService::vacio());
    require_once "$r42/app/Domain/Tickets/AdjuntoService.php";
    check('adjunto inexistente o id nulo → 404', AdjuntoService::resolver($pdo, null, 'admin', 1)['status'] === 404 && AdjuntoService::resolver($pdo, 999999999, 'admin', 1)['status'] === 404);

    // ==================== parciales y shell sin PDO ====================
    echo "\n[las vistas y parciales reciben datos preparados (sin \$pdo)]\n";
    $r43 = __DIR__ . '/..';
    $conPdo43 = [];
    $it43 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$r43/app/Presentation/Views"));
    foreach ($it43 as $f43) if ($f43->isFile() && substr($f43, -4) === '.php' && strpos((string)file_get_contents((string)$f43), '$pdo') !== false) $conPdo43[] = basename((string)$f43);
    foreach (glob("$r43/app/Presentation/Views/layout/app_{header,page_head,page_body_start,sidebar,page_end,page_main_end}.php", GLOB_BRACE) as $f43) if (strpos((string)file_get_contents($f43), '$pdo') !== false) $conPdo43[] = basename($f43);
    check('ninguna vista, parcial ni include del shell usa $pdo (' . implode(',', $conPdo43) . ')', $conPdo43 === []);
    // todo controlador/página que dibuja el shell debe precargar $shell
    $sinShell43 = [];
    foreach (array_merge(glob("$r43/app/Presentation/Controllers/*/*.php"), [$r43 . '/app/Presentation/Controllers/Tickets/EscanearQrPage.php']) as $f43) {
        $c43 = (string)file_get_contents($f43);
        $usaShell = strpos($c43, 'app_page_body_start.php') !== false || strpos($c43, 'app_header.php') !== false;
        if (preg_match_all("~Views/([a-z_/]+)\\.php~", $c43, $mv)) foreach ($mv[1] as $v43) {
            $vs = (string)file_get_contents("$r43/app/Presentation/Views/$v43.php");
            if (strpos($vs, 'app_page_body_start.php') !== false || strpos($vs, 'app_header.php') !== false) $usaShell = true;
        }
        if ($usaShell && strpos($c43, 'ShellData::cargar($pdo)') === false) $sinShell43[] = basename($f43);
    }
    check('todo controlador que dibuja el shell precarga ShellData (' . implode(',', $sinShell43) . ')', $sinShell43 === []);
    require_once "$r43/app/Domain/Tickets/TicketSeguimiento.php";
    check('comentariosDeTickets sin ids devuelve vacío', TicketSeguimiento::comentariosDeTickets($pdo, 'tickets', []) === []);
    $porT43 = TicketSeguimiento::comentariosDeTickets($pdo, 'tickets', [-1, -2]);
    check('comentariosDeTickets con ids sin comentarios devuelve vacío', $porT43 === []);

    // ==================== Servicios de tickets por caso de uso (sin fachada) ====================
    echo "\n[Servicios de tickets por caso de uso, sin fachada intermedia]\n";
    $r44 = __DIR__ . '/..';
    $casos44 = ['TicketAsignacionService', 'TicketSeguimientoService', 'TicketEstadoService', 'TicketAprobacionService', 'TicketTrasladoService', 'TicketCreacionService', 'TicketProfesorCreacionService'];
    check('sin fachada TicketService ni TicketServiceSoporte: se llama directo al servicio', !file_exists("$r44/app/Domain/Tickets/TicketService.php") && !file_exists("$r44/app/Domain/Tickets/TicketServiceSoporte.php")
        && !preg_grep('/\bTicketService(Soporte)?::/', array_map('file_get_contents', glob("$r44/app/{Domain,Presentation}/*/*.php", GLOB_BRACE))));
    $big44 = []; foreach ($casos44 as $cn) if (substr_count((string)file_get_contents("$r44/app/Domain/Tickets/$cn.php"), "\n") > 400) $big44[] = $cn;
    check('ningún servicio de caso de uso supera 400 líneas (' . implode(',', $big44) . ')', $big44 === []);

    // ==================== diseño único ====================
    echo "\n[layout público único, utilidades en vez de style=\"\", CSS partido, marca en un solo lugar]\n";
    $r45 = __DIR__ . '/..';
    // 1) sin style="" de diseño (solo display:none/inline —los alterna JS— y grid-* de la grilla de horarios)
    $malos45 = [];
    $itp = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$r45/app")); $archivos45 = [];
    foreach ($itp as $f45) if ($f45->isFile() && substr((string)$f45, -4) === '.php') $archivos45[] = (string)$f45;
    foreach (glob("$r45/public/*.php") as $f45) $archivos45[] = $f45;
    foreach (glob("$r45/app/Presentation/Views/layout/*.php") as $f45) $archivos45[] = $f45;
    foreach ($archivos45 as $f45) {
        if (substr($f45, -19) === 'Support/errores.php') continue; // página de error autónoma: sin hoja de estilos
        if (preg_match_all('/\sstyle="([^"]*)"/', (string)file_get_contents($f45), $mm)) foreach ($mm[1] as $v45) {
            foreach (array_filter(array_map('trim', explode(';', preg_replace('/<\?.*?\?>/s', '', $v45)))) as $d45) {
                if (!preg_match('/^(display\s*:\s*(none|inline)?$|grid-(row|column|template-rows)\s*:)/', $d45) && !preg_match('/^display\s*:\s*(none|inline)$/', $d45)) $malos45[] = basename($f45) . ': ' . $d45;
            }
        }
    }
    check('ningún style="" con colores/márgenes/tamaños/tipografía (usar clases de utilidad) ' . implode(' | ', array_slice($malos45, 0, 5)), $malos45 === []);
    // 2) estilo.css solo importa y las partes están en orden
    $est45 = (string)file_get_contents("$r45/public/assets/estilo.css");
    preg_match_all('~@import url\("(css/[^"]+)"\);~', $est45, $imp45);
    $partes45 = array_map('basename', glob("$r45/public/assets/css/*.css"));
    check('estilo.css solo importa (sin reglas) y todas las partes de css/ están importadas en orden', trim(preg_replace(['~/\*.*?\*/~s', '~@import url\("[^"]+"\);~'], '', $est45)) === '' && array_map('basename', $imp45[1]) === $partes45);
    // 3) un componente = una definición (selector de nivel superior repetido)
    $css45 = preg_replace('~/\*.*?\*/~s', '', cssCompleto($r45)); $prof = 0; $selAct = ''; $buf = ''; $conteo45 = [];
    foreach (str_split($css45) as $ch) {
        if ($ch === '{') { if ($prof === 0) { $selAct = trim($buf); if ($selAct !== '' && $selAct[0] !== '@') $conteo45[$selAct] = ($conteo45[$selAct] ?? 0) + 1; } $prof++; $buf = ''; continue; }
        if ($ch === '}') { $prof--; $buf = ''; continue; }
        $buf .= $ch;
    }
    $dup45 = array_keys(array_filter($conteo45, fn($n) => $n > 1));
    check('ningún selector de nivel superior se define dos veces (' . implode(', ', $dup45) . ')', $dup45 === []);
    // 4) prueba de aceptación de marca: el valor de marca vive solo en tokens.css (y su espejo para PDF en marca.php)
    $fugas45 = [];
    $itm = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r45, FilesystemIterator::SKIP_DOTS));
    foreach ($itm as $f45) {
        $ruta45 = (string)$f45; $rel45 = substr($ruta45, strlen($r45) + 1);
        if (!$f45->isFile() || !preg_match('~\.(php|css|js|html|svg)$~', $ruta45) || preg_match('~^(storage|legacy|vendor|tests|database)/~', $rel45)) continue;
        if (in_array($rel45, ['public/assets/tokens.css', 'public/assets/app.css', 'app/Support/marca.php'], true)) continue; // app.css se genera de tokens.css
        if (preg_match('/#bc0017|188\s*,\s*0\s*,\s*23/i', (string)file_get_contents($ruta45))) $fugas45[] = $rel45;
    }
    check('el color de marca (#BC0017 / 188,0,23) no se repite fuera de tokens.css y marca.php (' . implode(',', $fugas45) . ')', $fugas45 === []);
    $tok45 = (string)file_get_contents("$r45/public/assets/tokens.css");
    preg_match_all('/--brand-500\s*:/', $tok45, $b45);
    $derivados45 = preg_match_all('/--color-primary[\w-]*\s*:\s*(var\(--brand-500\)|color-mix\([^;]*var\(--brand-500\)[^;]*\))\s*;/', $tok45);
    check('--brand-500 se define una vez y los tonos de marca derivan de él (cambiarlo recolorea todo)', count($b45[0]) === 1 && $derivados45 >= 6);
    // 5) layout público único: las vistas públicas no declaran su propio <head>
    $propios45 = [];
    foreach (glob("$r45/app/Presentation/Views/*/*.php") as $f45) {
        if (basename(dirname($f45)) === 'layout') continue; // el esqueleto común ES el único <head>
        $c45 = (string)file_get_contents($f45);
        if (strpos($c45, '<!DOCTYPE') !== false || strpos($c45, 'rel="stylesheet" href="assets/estilo.css"') !== false) $propios45[] = basename(dirname($f45)) . '/' . basename($f45);
    }
    check('ninguna vista declara su propio <head>/hoja de estilos (todas usan app_page_head.php) (' . implode(',', $propios45) . ')', $propios45 === []);

    // ==================== autoload y transacciones centralizadas ====================
    echo "\n[autoload por classmap y Database::transaccion en todos los servicios]\n";
    $r46 = __DIR__ . '/..';
    $mapa46 = require "$r46/app/classmap.php";
    $esc46 = autoloadEscanear($r46);
    ksort($mapa46); ksort($esc46);
    check('app/classmap.php está al día (php tools/generar_classmap.php) — ' . count($esc46) . ' clases', array_keys($mapa46) === array_keys($esc46));
    $conReq46 = [];
    $it46 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$r46/app", FilesystemIterator::SKIP_DOTS));
    foreach ($it46 as $f46) {
        if (substr($f46->getPathname(), -4) !== '.php') continue;
        $c46 = (string)file_get_contents($f46->getPathname());
        if (preg_match_all('~^\s*require(?:_once)?\s+[^;]*?([\w\/\.]+)\.php[\'"]\s*;~m', $c46, $mm46)) {
            foreach ($mm46[1] as $destino46) {
                if (isset($mapa46[basename($destino46)]) && basename($destino46) !== 'Database' ) { $conReq46[] = basename($f46->getPathname()) . '→' . basename($destino46); }
            }
        }
    }
    check('ningún archivo de app/ hace require de un archivo de clase (lo carga el autoload) (' . implode(',', array_slice($conReq46, 0, 5)) . ')', $conReq46 === []);
    check('bootstrap.php y session_boot.php registran el autoload', strpos(file_get_contents("$r46/bootstrap.php"), 'Support/autoload.php') !== false && strpos(file_get_contents("$r46/app/Support/session_boot.php"), 'autoload.php') !== false);
    check('una clase no cargada se resuelve por autoload', class_exists('TicketWorkflow') && class_exists('TransaccionRevertida'));

    $manual46 = [];
    foreach ($it46 as $f46) {
        $n46 = $f46->getPathname();
        if (substr($n46, -4) !== '.php' || substr($n46, -strlen('Database.php')) === 'Database.php') continue;
        if (preg_match('~->(beginTransaction|rollBack|commit)\(\)~', (string)file_get_contents($n46))) $manual46[] = basename($n46);
    }
    check('ningún Service/Repository/helper maneja transacciones a mano; todo va por Database::transaccion (' . implode(',', $manual46) . ')', $manual46 === []);

    // Semántica de Database::transaccion: conexión propia con tabla temporal (la de las pruebas ya está en una transacción).
    $tx46 = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tx46->exec('CREATE TEMPORARY TABLE tx46 (v INT) ENGINE=InnoDB');
    $cuenta46 = fn() => (int)$tx46->query('SELECT COUNT(*) FROM tx46')->fetchColumn();
    $r = Database::transaccion(function (PDO $c) { $c->exec('INSERT INTO tx46 VALUES (1)'); return 'ok'; }, $tx46);
    check('transaccion confirma y devuelve el valor de la función', $r === 'ok' && $cuenta46() === 1 && !$tx46->inTransaction());
    try { Database::transaccion(function (PDO $c) { $c->exec('INSERT INTO tx46 VALUES (2)'); throw new RuntimeException('x'); }, $tx46); $lanzo46 = false; } catch (RuntimeException $e) { $lanzo46 = true; }
    check('transaccion deshace y relanza si la función falla', $lanzo46 && $cuenta46() === 1 && !$tx46->inTransaction());
    $r = Database::transaccion(function (PDO $c) { $c->exec('INSERT INTO tx46 VALUES (3)'); throw new TransaccionRevertida(['abandonado' => true]); }, $tx46);
    check('TransaccionRevertida deshace sin error y devuelve el valor indicado', $r === ['abandonado' => true] && $cuenta46() === 1);
    $tx46->beginTransaction();
    $tx46->exec('INSERT INTO tx46 VALUES (4)');
    try { Database::transaccion(function (PDO $c) { $c->exec('INSERT INTO tx46 VALUES (5)'); throw new RuntimeException('y'); }, $tx46, 'sp_prueba'); } catch (RuntimeException $e) {}
    $r = Database::transaccion(function (PDO $c) { $c->exec('INSERT INTO tx46 VALUES (6)'); throw new TransaccionRevertida('nada'); }, $tx46, 'sp_prueba2');
    Database::transaccion(function (PDO $c) { $c->exec('INSERT INTO tx46 VALUES (7)'); }, $tx46, 'sp_prueba3');
    check('con transacción externa y savepoint solo se deshace la operación interna', $tx46->inTransaction() && $r === 'nada' && $cuenta46() === 3);
    $tx46->rollBack();
    check('al revertir la externa se pierde todo lo anidado', $cuenta46() === 1);
    $tx46->beginTransaction();
    try { Database::transaccion(function (PDO $c) { $c->exec('INSERT INTO tx46 VALUES (8)'); throw new RuntimeException('z'); }, $tx46); } catch (RuntimeException $e) {}
    check('sin savepoint se une a la externa y la decide quien la abrió', $tx46->inTransaction() && $cuenta46() === 2);
    $tx46->rollBack();
    $tx46->beginTransaction();
    try { Database::transaccion(fn() => 1, $tx46, 'bad name;'); $malo46 = false; } catch (InvalidArgumentException $e) { $malo46 = true; }
    $tx46->rollBack();
    check('nombre de savepoint inválido se rechaza (no se concatena al SQL)', $malo46);

    // Manejo de errores centralizado: un único registro, sin detalles al navegador.
    $err46 = (string)file_get_contents("$r46/app/Support/errores.php");
    check('errores.php centraliza manejador de excepciones, errores fatales y respuesta 500 genérica', strpos($err46, 'set_exception_handler') !== false && strpos($err46, 'register_shutdown_function') !== false && strpos($err46, 'http_response_code(500)') !== false);

    // ==================== análisis estático y CI ====================
    echo "\n[PHPStan y CI (php -l + pruebas + lints)]\n";
    $r47 = __DIR__ . '/..';
    $neon47 = (string)@file_get_contents("$r47/phpstan.neon");
    preg_match('/^\s*level:\s*(\d+)/m', $neon47, $nivel47);
    check('phpstan.neon analiza app/, public/ y bootstrap.php con nivel >= 3', isset($nivel47[1]) && (int)$nivel47[1] >= 3 && strpos($neon47, '- app') !== false && strpos($neon47, '- public') !== false && strpos($neon47, 'bootstrap.php') !== false);
    check('"variable indefinida" solo se tolera en Presentation/ y public/ (Domain/Infrastructure/Support siguen estrictos)', preg_match_all('~app/(\w+)/\*~', $neon47, $perm47) && $perm47[1] === ['Presentation'] && strpos($neon47, 'app/Domain') === false && strpos($neon47, 'app/Support') === false);
    $ci47 = (string)@file_get_contents("$r47/tools/ci.php");
    check('tools/ci.php define las etapas lint, classmap, phpstan, unit, lints y http', !array_diff(['lint', 'classmap', 'phpstan', 'unit', 'lints', 'http'], array_map(fn($m) => $m, preg_match_all("~^    '(\w+)' => \[~m", $ci47, $e47) ? $e47[1] : [])));
    $yml47 = (string)@file_get_contents("$r47/.github/workflows/ci.yml");
    check('el workflow de CI corre php tools/ci.php (php -l, classmap, PHPStan, unit, lints) y las pruebas HTTP con MySQL', strpos($yml47, 'php tools/ci.php') !== false && strpos($yml47, 'php tools/ci.php http') !== false && strpos($yml47, 'mysql') !== false && strpos($yml47, 'tickets_db_test') !== false);
    check('el CI no usa credenciales reales (config/database.php sale de database.example.php y está en .gitignore)', strpos($yml47, 'database.example.php') !== false && strpos(file_get_contents("$r47/.gitignore"), 'config/database.php') !== false && strpos(file_get_contents("$r47/.gitignore"), 'tools/phpstan.phar') !== false);

    // ==================== lint de código muerto ====================
    echo "\n[código muerto]\n";
    require_once __DIR__ . '/lint_codigo_muerto.php';
    $lint = lintCodigoMuerto(dirname(__DIR__));
    check('lint: ninguna función PHP sin llamadores (salvo API de dominio listada)', $lint['funciones'] === []);
    check('lint: ninguna clase CSS sin uso en estilo.css', $lint['css'] === []);
    check('lint: ninguna función JS sin uso', $lint['js'] === []);
    check('lint: ningún archivo de public/app/config/database sin referencias', $lint['archivos'] === []);
    check('instalación limpia: código muerto eliminado (sin legacy/ ni usuarios_prueba.xlsx con claves en texto plano)', !is_dir(__DIR__ . '/../legacy') && !is_file(__DIR__ . '/../database/seeds/usuarios_prueba.xlsx'));
    check('las funciones retiradas ya no existen en el código vivo', !function_exists('nombreTecnico') && !function_exists('activosDeTicket') && !function_exists('adjuntosDeTicket') && !function_exists('historialMovimientosActivo'));

    // ==================== autenticación centralizada ====================
    echo "\n[autenticación centralizada]\n";
    $au = (string)file_get_contents(__DIR__ . '/../app/Support/auth.php');
    check('auth.php define exigirClaveDefinitiva', strpos($au, 'function exigirClaveDefinitiva') !== false);
    check('los 3 guards de sesión aplican la regla de clave temporal', substr_count($au, 'exigirClaveDefinitiva(') >= 4);
    check('sin session_guard.php: la regla de clave temporal la aplican los guards de auth.php', !file_exists(__DIR__ . '/../app/Support/session_guard.php') && !preg_grep('/session_guard/', array_map('file_get_contents', glob(__DIR__ . '/../public/*.php'))));
    $manualPt = [];
    foreach (glob(__DIR__ . '/../public/*.php') as $f) {
        if (in_array(basename($f), ['cambiar_clave.php', 'login.php', 'index.php'], true)) continue;
        if (strpos((string)file_get_contents($f), "SESSION['password_temporal']") !== false) $manualPt[] = basename($f);
    }
    check('ninguna página de public/ (salvo login/cambiar_clave) comprueba $_SESSION[password_temporal] a mano', $manualPt === []);
    $_SESSION = ['user_id' => 7, 'role' => 'tecnico'];
    check('rolActual lee la sesión', rolActual() === 'tecnico');
    $_SESSION = [];

    // ==================== CSRF centralizado ====================
    echo "\n[CSRF centralizado]\n";
    $cs = (string)file_get_contents(__DIR__ . '/../app/Support/csrf.php');
    $au = (string)file_get_contents(__DIR__ . '/../app/Support/auth.php');
    check('csrf.php: csrfVerificar y csrfExigirEnPost existen; sin inyección de campos por expresión regular', function_exists('csrfVerificar') && function_exists('csrfExigirEnPost') && !function_exists('csrfInyectarEnFormularios'));
    check('csrf.php acepta la cabecera X-CSRF-Token (AJAX)', strpos($cs, 'HTTP_X_CSRF_TOKEN') !== false);
    check('los 3 guards (requireAuth/Json/OrDie) exigen CSRF en POST', substr_count($au, 'csrfExigirEnPost(') === 3 && strpos($au, 'csrfExigirEnPost(true)') !== false);
    $sinCampo = [];
    foreach (array_merge(glob(__DIR__ . '/../app/Presentation/Views/*/*.php'), glob(__DIR__ . '/../app/Presentation/Controllers/*/*.php')) as $f) {
        $c = (string)file_get_contents($f);
        if (basename($f) === 'login.php') continue;
        if (preg_match_all('#<form\b[^>]*method="post"[^>]*>(.*?)</form>#is', $c, $m)) {
            foreach ($m[1] as $cuerpo) { if (strpos($cuerpo, 'csrfField') === false && strpos($cuerpo, 'csrf_token') === false) $sinCampo[] = basename($f); }
        }
    }
    check('todo formulario POST privado lleva csrfField() explícito', $sinCampo === []);
    check('las páginas privadas no repiten csrfCheck() (el guard ya valida el token)', !preg_grep('/csrfCheck\(\)/', array_map(fn($f) => (string)file_get_contents($f), glob(__DIR__ . '/../app/Presentation/Controllers/{Tickets,Inventario,Salas,Horarios,Mantenimientos,Usuarios,Becas,Admin,Calidad,Seguridad}/*.php', GLOB_BRACE))));
    $_SERVER['REQUEST_METHOD'] = 'GET';
    csrfExigirEnPost(); // un GET nunca se bloquea
    check('csrfExigirEnPost no bloquea peticiones GET', true);
    $_SESSION = []; $t1 = csrfToken(); $t2 = csrfToken();
    check('csrfToken es estable dentro de la sesión (64 hex)', $t1 === $t2 && strlen($t1) === 64);
    check('csrfField contiene el token', strpos(csrfField(), $t1) !== false);
    $_SESSION = [];
    $sinGuard = [];
    foreach (glob(__DIR__ . '/../public/*.php') as $f) {
        $c = (string)file_get_contents($f);
        if (preg_match('/\$_SERVER\[.REQUEST_METHOD.\]\s*={2,3}\s*.POST.|isset\(\$_POST\[/', $c)
            && !preg_match('/requireAuth|requireRole|requirePermission/', $c)
            && !in_array(basename($f), ['login.php', 'reportar.php', 'consultar_estado.php', 'index.php', 'recuperar_clave.php', 'restablecer_clave.php'], true) && $f[strlen(__DIR__ . '/../public/')] !== '_') {
            $sinGuard[] = basename($f);
        }
    }
    check('toda página con POST usa un guard (o es pública exenta: login, reportar, consultar_estado)', $sinGuard === []);

    // ==================== validación de entrada y seguridad ====================
    echo "\n[validación de entrada y seguridad]\n";
    require_once __DIR__ . '/../app/Support/validacion.php';
    check('validarId: acepta enteros positivos', validarId('15') === 15 && validarId(' 7 ') === 7 && validarId(3) === 3);
    check('validarId: rechaza 0, negativos, texto, decimales, arreglos y desbordes', validarId('0') === null && validarId('-1') === null && validarId('1abc') === null
        && validarId('1.5') === null && validarId(['1']) === null && validarId('99999999999999999999') === null && validarId('') === null && validarId(null) === null);
    check('validarEntero: dentro de rango / fuera de rango usa el defecto', validarEntero('5', 1, 12, 9) === 5 && validarEntero('13', 1, 12, 9) === 9 && validarEntero('abc', 1, 12, 9) === 9 && validarEntero(null, 1, 12, 9) === 9);
    check('validarEnum: solo valores exactos', validarEnum('A', ['A', 'B']) === 'A' && validarEnum('a', ['A', 'B']) === null && validarEnum(['A'], ['A']) === null);
    check('validarFecha: fechas reales Y-m-d', validarFecha('2026-02-28') === '2026-02-28' && validarFecha('2026-02-30') === null && validarFecha('2026-13-45') === null && validarFecha('26-1-1') === null && validarFecha(['x']) === null);
    check('validarHora: HH:MM y HH:MM:SS', validarHora('08:30') === '08:30' && validarHora('23:59:59') === '23:59:59' && validarHora('24:00') === null && validarHora('8:30') === null && validarHora('abc') === null);
    check('validarTexto: recorta, limita longitud, rechaza nulos y arreglos', validarTexto('  hola ', 10) === 'hola' && validarTexto(str_repeat('a', 11), 10) === null
        && validarTexto("a\0b", 10) === null && validarTexto(['x'], 10) === null && validarTexto('', 10, 1) === null && validarTexto('ñandú', 5) === 'ñandú');
    $_GET = ['q' => ['1'], 'ok' => 'x', 'ids_equipo' => ['1', ['2'], '3']]; $_POST = ['a' => ['b' => 'c']]; $_COOKIE = [];
    entradaNormalizar();
    check('entradaNormalizar: arreglo inesperado pasa a cadena vacía', $_GET['q'] === '' && $_POST['a'] === '' && $_GET['ok'] === 'x');
    check('entradaNormalizar: ids_equipo conserva solo escalares', $_GET['ids_equipo'] === ['1', '3']);
    $_GET = []; $_POST = [];
    check('jsJson escapa </script> y comillas', strpos(jsJson(['x' => '</script><b>"\'']), '<') === false && json_decode(jsJson('</script>"\''), true) === '</script>"\'');
    $sb = (string)file_get_contents(__DIR__ . '/../app/Support/session_boot.php');
    check('session_boot aplica errores, normalización y cabeceras de seguridad', strpos($sb, 'erroresConfigurar();') !== false && strpos($sb, 'entradaNormalizar();') !== false && strpos($sb, 'X-Frame-Options') !== false);
    check('errores.php: display_errors apagado salvo APP_DEBUG', strpos((string)file_get_contents(__DIR__ . '/../app/Support/errores.php'), "display_errors', '0'") !== false);
    $json_en_script = [];
    foreach (array_merge(glob(__DIR__ . '/../public/*.php'), glob(__DIR__ . '/../app/Presentation/Views/*/*.php')) as $f) {
        if (preg_match('/<\?=\s*json_encode\(/', (string)file_get_contents($f))) { $json_en_script[] = basename($f); }
    }
    check('ninguna vista imprime json_encode() sin escapar (usar jsJson)', $json_en_script === []);
    $ctk = TicketCreacionService::crearTicketPanel($pdo, 1, 'solicitante', ['descripcion' => str_repeat('a', 5001), 'dependencia' => 'x', 'ubicacion' => 'y', 'equipo' => 'z']);
    check('crear ticket: descripción demasiado larga se rechaza con mensaje', $ctk['ok'] === false && strpos((string)$ctk['error'], '5000') !== false);
    $ctk = TicketCreacionService::crearTicketPanel($pdo, 1, 'solicitante', ['descripcion' => 'ok', 'dependencia' => str_repeat('d', 101), 'ubicacion' => 'y', 'equipo' => 'z']);
    check('crear ticket: dependencia > 100 caracteres se rechaza', $ctk['ok'] === false && strpos((string)$ctk['error'], '100') !== false);
    $da = (string)file_get_contents(__DIR__ . '/../app/Presentation/Controllers/Archivos/DescargarAdjuntoPage.php')
        . (string)file_get_contents(__DIR__ . '/../app/Domain/Tickets/AdjuntoService.php');
    check('descargar_adjunto: id validado, basename en ruta y nosniff', strpos($da, 'validarId(') !== false && strpos($da, "basename((string)\$adjunto['nombre_almacenado'])") !== false && strpos($da, 'nosniff') !== false);

    // ==================== bloqueo de login, recuperación, Google, CSP ====================
    echo "\n[login seguro]\n";
    require_once __DIR__ . '/../app/Domain/Users/LoginThrottle.php';
    require_once __DIR__ . '/../app/Domain/Users/PasswordReset.php';
    require_once __DIR__ . '/../app/Domain/Users/GoogleLogin.php';
    require_once __DIR__ . '/../app/Infrastructure/Auth/GoogleOAuth.php';
    $pdo->prepare("INSERT INTO users (codigo,correo,password,password_temporal,role,nombre_completo,cargo,dependencia,estado_activo) VALUES (?,?,?,1,?,?,?,?,1)")
        ->execute(['LG26', 'login26@ufps.edu.co', $h, 'solicitante', 'Usuario Login', 'Solicitante', 'Sistemas']);
    $uid = (int)$pdo->lastInsertId();
    $correoU = 'login26@ufps.edu.co';
    $pdo->prepare("DELETE FROM usuario_bloqueo WHERE id_usuario=?")->execute([$uid]);

    $r1 = LoginThrottle::registrarFallo($pdo, $uid, '10.0.0.1');
    check('throttle: 1.er fallo → quedan 2 intentos, sin bloqueo', $r1['intentos'] === 1 && $r1['restantes'] === 2 && $r1['bloqueado'] === false);
    $r2 = LoginThrottle::registrarFallo($pdo, $uid, '10.0.0.1');
    check('throttle: 2.º fallo → queda 1 intento', $r2['intentos'] === 2 && $r2['restantes'] === 1 && $r2['bloqueado'] === false);
    LoginThrottle::registrarExito($pdo, $uid);
    check('throttle: un login correcto reinicia el contador', LoginThrottle::estado($pdo, $uid)['intentos'] === 0);
    LoginThrottle::registrarFallo($pdo, $uid); LoginThrottle::registrarFallo($pdo, $uid);
    $r3 = LoginThrottle::registrarFallo($pdo, $uid, '10.0.0.1');
    check('throttle: el 3.er fallo bloquea la cuenta', $r3['bloqueado'] === true && $r3['restantes'] === 0 && LoginThrottle::estado($pdo, $uid)['bloqueado'] === true);
    LoginThrottle::registrarExito($pdo, $uid);
    check('throttle: registrarExito no desbloquea una cuenta bloqueada', LoginThrottle::estado($pdo, $uid)['bloqueado'] === true);
    check('throttle: el bloqueo queda en la auditoría', (int)$pdo->query("SELECT COUNT(*) FROM auditoria WHERE accion='EDITAR' AND detalle LIKE 'bloqueo de login%' AND id_registro=$uid")->fetchColumn() >= 1);
    check('throttle: desbloquear devuelve true y limpia', LoginThrottle::desbloquear($pdo, $uid, 1) === true && LoginThrottle::estado($pdo, $uid) === ['intentos' => 0, 'bloqueado' => false]);

    // --- recuperación de clave ---
    $pdo->prepare("DELETE FROM recuperacion_clave WHERE id_usuario=?")->execute([$uid]);
    $enviados = [];
    $capt = function (array $u, string $t) use (&$enviados) { $enviados[] = [$u['correo'], $t]; };
    check('reset: correo inexistente no envía nada', PasswordReset::solicitar($pdo, 'nadie@ufps.edu.co', null, $capt) === false && $enviados === []);
    check('reset: correo de otro dominio se ignora', PasswordReset::solicitar($pdo, 'x@otro.com', null, $capt) === false && $enviados === []);
    LoginThrottle::registrarFallo($pdo, $uid); LoginThrottle::registrarFallo($pdo, $uid); LoginThrottle::registrarFallo($pdo, $uid);
    check('reset: usuario activo genera token y se entrega al envío', PasswordReset::solicitar($pdo, $correoU, '10.0.0.1', $capt) === true && count($enviados) === 1 && preg_match('/^[0-9a-f]{64}$/', $enviados[0][1]) === 1);
    $tok = $enviados[0][1];
    check('reset: en BD solo se guarda el hash, nunca el token', (int)$pdo->query("SELECT COUNT(*) FROM recuperacion_clave WHERE token_hash='" . $tok . "'")->fetchColumn() === 0
        && (int)$pdo->query("SELECT COUNT(*) FROM recuperacion_clave WHERE token_hash='" . PasswordReset::hashToken($tok) . "'")->fetchColumn() === 1);
    check('reset: token válido identifica al usuario; basura no', PasswordReset::validar($pdo, $tok) === $uid && PasswordReset::validar($pdo, 'abc') === null && PasswordReset::validar($pdo, str_repeat('0', 64)) === null);
    $m = PasswordReset::restablecer($pdo, $tok, 'corta', 'corta');
    check('reset: clave menor de 8 caracteres se rechaza (el token sigue sirviendo)', $m['ok'] === false && PasswordReset::validar($pdo, $tok) === $uid);
    $m = PasswordReset::restablecer($pdo, $tok, 'ClaveNueva123', 'otraDistinta1');
    check('reset: confirmación distinta se rechaza', $m['ok'] === false);
    $m = PasswordReset::restablecer($pdo, $tok, 'ClaveNueva123', 'ClaveNueva123');
    check('reset: restablecer OK cambia la clave, quita temporal y DESBLOQUEA', $m['ok'] === true
        && password_verify('ClaveNueva123', (string)$pdo->query("SELECT password FROM users WHERE id=$uid")->fetchColumn())
        && LoginThrottle::estado($pdo, $uid)['bloqueado'] === false && (int)$pdo->query("SELECT password_temporal FROM users WHERE id=$uid")->fetchColumn() === 0);
    check('reset: el token es de un solo uso', PasswordReset::validar($pdo, $tok) === null && PasswordReset::restablecer($pdo, $tok, 'OtraClave1234', 'OtraClave1234')['ok'] === false);
    $enviados = [];
    PasswordReset::solicitar($pdo, $correoU, null, $capt);
    $tokA = $enviados[0][1];
    PasswordReset::solicitar($pdo, $correoU, null, $capt);
    check('reset: un enlace nuevo invalida el anterior', PasswordReset::validar($pdo, $tokA) === null && PasswordReset::validar($pdo, $enviados[1][1]) === $uid);
    $pdo->prepare("UPDATE recuperacion_clave SET expira_en = NOW() - INTERVAL 1 MINUTE WHERE token_hash=?")->execute([PasswordReset::hashToken($enviados[1][1])]);
    check('reset: un token vencido no sirve', PasswordReset::validar($pdo, $enviados[1][1]) === null);
    $pdo->prepare("DELETE FROM recuperacion_clave WHERE id_usuario=?")->execute([$uid]);
    $s3 = [PasswordReset::solicitar($pdo, $correoU, null, $capt), PasswordReset::solicitar($pdo, $correoU, null, $capt), PasswordReset::solicitar($pdo, $correoU, null, $capt), PasswordReset::solicitar($pdo, $correoU, null, $capt)];
    check('reset: máximo 3 solicitudes por hora (la 4.ª se ignora)', $s3 === [true, true, true, false]);
    $pdo->prepare("UPDATE users SET estado_activo=0 WHERE id=?")->execute([$uid]);
    $pdo->prepare("DELETE FROM recuperacion_clave WHERE id_usuario=?")->execute([$uid]);
    $n = count($enviados);
    check('reset: usuario desactivado no recibe enlace', PasswordReset::solicitar($pdo, $correoU, null, $capt) === false && count($enviados) === $n);

    // --- Google (infraestructura lista, inactiva) ---
    check('google: sin config/google.php (o enabled=false) está deshabilitado', GoogleOAuth::habilitado() === false);
    $cfgG = ['client_id' => 'cid', 'client_secret' => 'sec', 'redirect_uri' => 'https://x.test/google_callback.php', 'dominios_permitidos' => []];
    $sol = GoogleOAuth::nuevaSolicitud();
    $url = GoogleOAuth::urlAutorizacion($cfgG, $sol['state'], $sol['verificador']);
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    check('google: la URL lleva state, PKCE S256 y scope openid email', strpos($url, 'https://accounts.google.com/') === 0 && $q['state'] === $sol['state']
        && $q['code_challenge_method'] === 'S256' && $q['code_challenge'] === rtrim(strtr(base64_encode(hash('sha256', $sol['verificador'], true)), '+/', '-_'), '=') && strpos($q['scope'], 'openid') !== false);
    $llamadas = [];
    GoogleOAuth::$http = function (string $metodo, string $url, array $datos, array $cab) use (&$llamadas) {
        $llamadas[] = [$metodo, $url, $datos];
        if ($metodo === 'POST') return ['estado' => 200, 'cuerpo' => json_encode(['access_token' => 'AT'])];
        return ['estado' => 200, 'cuerpo' => json_encode(['sub' => '123', 'email' => 'Sol@UFPS.edu.co', 'email_verified' => true, 'hd' => 'ufps.edu.co', 'name' => 'Sol'])];
    };
    $perfil = GoogleOAuth::perfilDesdeCodigo($cfgG, 'CODE', $sol['verificador']);
    check('google: canje de código → perfil normalizado (correo en minúsculas)', $perfil !== null && $perfil['sub'] === '123' && $perfil['email'] === 'sol@ufps.edu.co' && $perfil['email_verified'] === true
        && $llamadas[0][2]['code_verifier'] === $sol['verificador'] && $llamadas[0][2]['grant_type'] === 'authorization_code');
    check('google: dominio no permitido se rechaza', GoogleOAuth::perfilDesdeCodigo(array_merge($cfgG, ['dominios_permitidos' => ['otra.edu']]), 'C', 'v') === null
        && GoogleOAuth::perfilDesdeCodigo(array_merge($cfgG, ['dominios_permitidos' => ['ufps.edu.co']]), 'C', 'v') !== null);
    GoogleOAuth::$http = fn() => ['estado' => 400, 'cuerpo' => '{"error":"invalid_grant"}'];
    check('google: código rechazado por Google → null', GoogleOAuth::perfilDesdeCodigo($cfgG, 'MAL', 'v') === null);
    GoogleOAuth::$http = null;

    $pdo->prepare("UPDATE users SET estado_activo=1 WHERE id=?")->execute([$uid]);
    $pdo->prepare("DELETE FROM usuario_google WHERE id_usuario=?")->execute([$uid]);
    $pf = ['sub' => 'sub-test-1', 'email' => $correoU, 'email_verified' => true, 'hd' => null, 'name' => 'x'];
    check('google: correo no verificado → rechazado', GoogleLogin::resolver($pdo, ['email_verified' => false] + $pf)['ok'] === false);
    check('google: correo que no existe en users → rechazado (no crea usuarios)', GoogleLogin::resolver($pdo, ['email' => 'nuevo@ufps.edu.co'] + $pf)['ok'] === false
        && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE correo='nuevo@ufps.edu.co'")->fetchColumn() === 0);
    $g = GoogleLogin::resolver($pdo, $pf);
    check('google: primer ingreso vincula la cuenta por correo', $g['ok'] === true && (int)$g['usuario']['id'] === $uid
        && (int)$pdo->query("SELECT COUNT(*) FROM usuario_google WHERE id_usuario=$uid AND google_sub='sub-test-1'")->fetchColumn() === 1);
    check('google: ingresos siguientes entran por el sub aunque cambie el correo', GoogleLogin::resolver($pdo, ['email' => 'otro@gmail.com'] + $pf)['ok'] === true);
    check('google: otro sub con el mismo correo no puede secuestrar la cuenta', GoogleLogin::resolver($pdo, ['sub' => 'sub-atacante'] + $pf)['ok'] === false);
    $pdo->prepare("UPDATE users SET estado_activo=0 WHERE id=?")->execute([$uid]);
    check('google: usuario desactivado no entra', GoogleLogin::resolver($pdo, $pf)['ok'] === false);
    $pdo->prepare("UPDATE users SET estado_activo=1 WHERE id=?")->execute([$uid]);
    LoginThrottle::registrarFallo($pdo, $uid); LoginThrottle::registrarFallo($pdo, $uid); LoginThrottle::registrarFallo($pdo, $uid);
    check('google: usuario bloqueado por intentos no entra', GoogleLogin::resolver($pdo, $pf)['ok'] === false);
    LoginThrottle::desbloquear($pdo, $uid);

    // --- estructura ---
    $sb = (string)file_get_contents(__DIR__ . '/../app/Support/session_boot.php');
    check('CSP: política con script-src, object-src none, frame-ancestors y modo report-only/off', strpos($sb, 'Content-Security-Policy') !== false && strpos($sb, "object-src 'none'") !== false
        && strpos($sb, "frame-ancestors 'self'") !== false && strpos($sb, 'APP_CSP') !== false);
    $au = (string)file_get_contents(__DIR__ . '/../app/Support/auth.php');
    check('auth: los 3 guards cortan la sesión de cuentas desactivadas', substr_count($au, 'exigirCuentaActiva(') >= 4 && strpos($au, 'function iniciarSesionDeUsuario') !== false && strpos($au, 'Database::') !== false); // Database se autocarga
    $frag = ['_asignar_beca', '_fila_ticket_admin_profesor', '_fila_ticket_admin_solicitante', '_fila_ticket_normal', '_fila_ticket_profesor', 'buscador_componente_widget', 'buscador_global_widget', 'notificaciones_widget'];
    $okFrag = true; foreach ($frag as $f) { if (is_file(__DIR__ . "/../public/$f.php") || !is_file(__DIR__ . "/../app/Presentation/Views/partials/$f.php")) $okFrag = false; }
    check('fragmentos movidos de public/ a app/Presentation/Views/partials/', $okFrag && defined('PARCIALES_DIR') === (defined('PARCIALES_DIR')));
    $lg = (string)file_get_contents(__DIR__ . '/../app/Domain/Users/LoginService.php') . (string)file_get_contents(__DIR__ . '/../app/Presentation/Controllers/Cuenta/LoginPage.php') . (string)file_get_contents(__DIR__ . '/../app/Presentation/Views/cuenta/login.php'); // login repartido en Service/Controller/Vista
    check('login usa LoginThrottle, iniciarSesionDeUsuario y enlaza a recuperar_clave', strpos($lg, 'LoginThrottle::registrarFallo') !== false && strpos($lg, 'iniciarSesionDeUsuario(') !== false && strpos($lg, 'recuperar_clave.php') !== false);
    check('gitignore protege config/google.php y config/app.php', strpos((string)file_get_contents(__DIR__ . '/../.gitignore'), 'config/google.php') !== false && strpos((string)file_get_contents(__DIR__ . '/../.gitignore'), 'config/app.php') !== false);
    check('urlBase: sin config deduce un host válido', preg_match('#^https?://[A-Za-z0-9.-]+(:[0-9]+)?#', urlBase()) === 1);

    // ==================== CSS centralizado ====================
    echo "\n[CSS centralizado]\n";
    $tok = (string)file_get_contents(__DIR__ . '/../public/assets/tokens.css');
    $okTok = true;
    foreach (['--color-primary', '--color-secondary', '--color-danger', '--color-success', '--radius-sm', '--radius-md', '--spacing-sm', '--spacing-md'] as $t) { if (!preg_match('/' . preg_quote($t, '/') . '\s*:/', $tok)) $okTok = false; }
    check('tokens.css define las variables globales pedidas', $okTok);
    // Refactorización: panel de personalización en español al inicio de :root (un cambio aquí recolorea toda la app).
    $okPanel = true;
    foreach (['--color-principal', '--color-secundario', '--color-fondo', '--color-superficie', '--color-texto', '--color-borde', '--radio-borde', '--fuente-principal'] as $t) { if (!preg_match('/' . preg_quote($t, '/') . '\s*:\s*[^;]+;/', $tok)) $okPanel = false; }
    $posPanel = strpos($tok, 'PANEL DE PERSONALIZACIÓN'); $posInternos = strpos($tok, '--brand-500:');
    check('tokens.css: panel de personalización en español antes de los tokens internos', $okPanel && $posPanel !== false && $posInternos !== false && $posPanel < $posInternos);
    check('tokens.css: los tokens internos leen del panel (fondo, superficie, texto, radio, fuente)',
        preg_match('/--color-bg:\s*var\(--color-fondo\)/', $tok) && preg_match('/--color-white:\s*var\(--color-superficie\)/', $tok)
        && preg_match('/--ink:\s*var\(--color-texto\)/', $tok) && preg_match('/--radius-md:\s*var\(--radio-borde\)/', $tok) && preg_match('/--font-base:\s*var\(--fuente-principal\)/', $tok));
    $cssPartes = ''; foreach (glob(__DIR__ . '/../public/assets/css/*.css') as $cf) $cssPartes .= file_get_contents($cf);
    check('CSS: el texto sobre el color de marca usa --color-on-primary (no --color-white, que es la superficie)', !preg_match('/(?<![-\w])color:\s*var\(--color-white\)/', $cssPartes));
    // Refactorización: tablas duplicadas → parciales; todo dato de la base escapado.
    $tablaSol = (string)file_get_contents(__DIR__ . '/../app/Presentation/Views/tickets/_tabla_solicitante.php');
    check('Mis tickets (solicitante): técnico y fechas se escapan con h()', strpos($tablaSol, "h(\$t['tecnico_nombre']") !== false && strpos($tablaSol, "<?= \$t['tecnico_nombre']") === false && strpos($tablaSol, "<?= \$t['fecha_creacion'] ?>") === false);
    check('Paneles solicitante/profesor sin tablas duplicadas (usan parciales)', substr_count((string)file_get_contents(__DIR__ . '/../app/Presentation/Controllers/Tickets/SolicitantePanel.php'), '_tabla_solicitante.php') === 2 && substr_count((string)file_get_contents(__DIR__ . '/../app/Presentation/Controllers/Tickets/ProfesorPanel.php'), '_tabla_profesor.php') === 2);
    check('Database: prepared statements nativos (EMULATE_PREPARES = false)', strpos((string)file_get_contents(__DIR__ . '/../app/Infrastructure/Database/Database.php'), 'PDO::ATTR_EMULATE_PREPARES => false') !== false);
    $est = cssCompleto(__DIR__ . '/..');
    check('estilo.css importa tokens.css y no tiene :root propio', strpos((string)file_get_contents(__DIR__ . '/../public/assets/estilo.css'), '@import url("tokens.css")') !== false && strpos($est, ':root') === false);
    $sinTok = preg_replace('~/\*.*?\*/~s', '', $est);
    check('estilo.css sin colores hex literales (todo vía tokens)', !preg_match('/#[0-9a-fA-F]{3,8}\b/', $sinTok));
    check('estilo.css sin rgba de la marca (188,0,23) fuera de tokens', strpos($sinTok, '188,0,23') === false);
    check('landing.css archivado: index.php usa estilo.css (componentes y tokens), no una hoja propia', !is_file(__DIR__ . '/../public/assets/landing.css') && strpos((string)file_get_contents(__DIR__ . '/../app/Presentation/Views/publico/inicio.php'), 'landing') === false);
    $hexPhp = []; $styleBlk = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../public', FilesystemIterator::SKIP_DOTS));
    $it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../app', FilesystemIterator::SKIP_DOTS));
    foreach ([$it, $it2] as $iter) foreach ($iter as $f) {
        $path = $f->getPathname();
        if (substr($path, -4) !== '.php' || strpos($path, '/vendor/') !== false) continue;
        $c = (string)file_get_contents($path);
        if (basename($path) !== 'app_page_head.php' && preg_match('/<style\b/i', $c)) $styleBlk[] = basename($path);
        if (preg_match('/style="[^"]*#[0-9a-fA-F]{3,6}\b/', $c)) $hexPhp[] = basename($path);
    }
    check('sin bloques <style> en PHP ni hex en style="" (' . implode(',', array_merge($styleBlk, $hexPhp)) . ')', !$styleBlk && !$hexPhp);
    $gr = (string)file_get_contents(__DIR__ . '/../public/assets/graficos_admin.js');
    check('gráficos Chart.js leen colores de variables CSS', strpos($gr, "getComputedStyle") !== false && !preg_match("/'#[0-9a-fA-F]{6}'/", $gr));
    check('sin var(--x, fallback) sobre variables no definidas', !preg_match('/var\(--(muted|panel|danger|surface)\b/', $est));

    // ==================== JavaScript ====================
    echo "\n[JavaScript]\n";
    $pub = __DIR__ . '/../public';
    $rd = function (string $rel) { return (string)file_get_contents(__DIR__ . '/../' . $rel); };
    $outJs = []; exec('php ' . escapeshellarg(__DIR__ . '/lint_js.php') . ' 2>&1', $outJs, $rcJs);
    check('lint_js: sintaxis de assets e inline, endpoints/parámetros AJAX y handlers seguros', $rcJs === 0);
    $appJs = $rd('public/assets/app.js');
    $okLib = true; foreach (['UFPS.esc =', 'UFPS.fetchJson =', 'UFPS.listarEquipos =', 'UFPS.llenarSelectEquipos =', 'UFPS.qrDescargar =', 'UFPS.qrImprimir =', 'UFPS.qrImprimirLote ='] as $fn) if (strpos($appJs, $fn) === false) $okLib = false;
    check('app.js expone la biblioteca compartida UFPS', $okLib);
    check('app_page_head.php carga assets/app.js', strpos($rd('app/Presentation/Views/layout/app_page_head.php'), 'assets/app.js') !== false);
    $dupe = [];
    foreach (array_merge(glob("$pub/*.php"), glob(__DIR__ . '/../app/Presentation/*/*/*.php'), glob(__DIR__ . '/../app/Presentation/Views/*/*.php')) as $f) {
        if (strpos((string)file_get_contents($f), "listar_equipos_ajax.php?id_sala=") !== false) $dupe[] = basename($f);
    }
    check('listar_equipos_ajax.php solo se consume desde app.js (sin copias: ' . implode(',', $dupe) . ')', !$dupe && strpos($appJs, 'listar_equipos_ajax.php?id_sala=') !== false);
    $wid = $rd('app/Presentation/Views/partials/buscador_global_widget.php') . $rd('app/Presentation/Views/partials/notificaciones_widget.php') . $rd('app/Presentation/Views/partials/buscador_componente_widget.php') . $rd('app/Presentation/Views/list_tickets/scripts_head.php');
    check('widgets y scripts_head sin <script> en línea (van en assets/*.js)', !preg_match('~<script(?![^>]*\bsrc=)~i', $wid));
    check('app_header.php carga shell.js y ya no trae script en línea', strpos($rd('app/Presentation/Views/layout/app_header.php'), 'assets/shell.js') !== false && !preg_match('~<script(?![^>]*\bsrc=)~i', $rd('app/Presentation/Views/layout/app_header.php')));
    check('notificaciones: el token CSRF viaja en data-csrf (no incrustado en JS)', strpos($rd('app/Presentation/Views/partials/notificaciones_widget.php'), 'data-csrf=') !== false && strpos($rd('public/assets/shell.js'), "getAttribute('data-csrf')") !== false);
    $cw = $rd('public/assets/componentes_widget.js');
    check('componentes_widget.js escapa los datos antes de innerHTML (XSS DOM)', substr_count($cw, 'UFPS.esc') >= 2 && strpos($cw, 'crearItemComponente') !== false);
    check('QR: ficha_equipo/ficha_componente/gestionar_salas usan data-qr-accion y UFPS.qrCrear (sin copias)', strpos($rd('app/Presentation/Views/inventario/equipo.php'), 'data-qr-accion') !== false && strpos($rd('app/Presentation/Views/inventario/componente.php'), 'data-qr-accion') !== false && strpos($rd('app/Presentation/Views/gestionar_salas/listado.php'), 'data-qr-accion') !== false
        && strpos($rd('app/Presentation/Views/inventario/equipo.php') . $rd('app/Presentation/Views/inventario/componente.php') . $rd('app/Presentation/Views/gestionar_salas/listado.php'), 'ventana.document.write') === false);
    check('horarios: confirmación de permisos por data-confirm (sin inyectar nombre en JS)', strpos($rd('app/Presentation/Views/horarios/acordeones.php'), 'data-confirm=') !== false && strpos($rd('app/Presentation/Views/horarios/acordeones.php'), 'addslashes($s[\'nombre_completo\'])') === false);

    // ==================== scripts en línea (Mantenimientos, Inventario/QR, Transversal) ====================
    echo "\n[sin JS en línea en 3 módulos]\n";
    $rd2 = function (string $rel) { return (string)file_get_contents(__DIR__ . '/../' . $rel); };
    $modulos = ['public/mantenimientos_programados.php', 'app/Presentation/Views/mantenimientos/pagina.php', 'app/Presentation/Views/mantenimientos/programar_form.php', 'app/Presentation/Views/mantenimientos/listado.php', 'app/Presentation/Views/mantenimientos/fila.php', 'app/Presentation/Views/mantenimientos/checklist.php', 'app/Presentation/Views/tickets/escanear_qr.php', 'app/Presentation/Views/inventario/equipo.php', 'app/Presentation/Views/inventario/equipos_lista.php', 'app/Presentation/Views/inventario/componente.php', 'app/Presentation/Views/inventario/gestionar.php',
        'app/Presentation/Views/layout/app_header.php', 'app/Presentation/Views/layout/app_sidebar.php', 'app/Presentation/Views/layout/app_page_head.php',
        'app/Presentation/Views/partials/buscador_global_widget.php', 'app/Presentation/Views/partials/notificaciones_widget.php', 'app/Presentation/Views/partials/buscador_componente_widget.php'];
    $conScript = []; $conHandler = [];
    foreach ($modulos as $m) {
        $c = preg_replace('~<\?php.*?\?>~s', '', $rd2($m));
        if (preg_match('~<script(?![^>]*\bsrc=)(?![^>]*application/json)~i', $c)) $conScript[] = basename($m);
        if (preg_match('~\son(?:click|change|input|submit|keyup|load)="~i', $c)) $conHandler[] = basename($m);
    }
    check('módulos Mantenimientos/Inventario-QR/Transversal sin <script> en línea (' . implode(',', $conScript) . ')', !$conScript);
    check('esos módulos sin atributos onclick/onchange/oninput/onsubmit (' . implode(',', $conHandler) . ')', !$conHandler);
    $nuevos = true; foreach (['mantenimientos.js', 'escanear_qr.js'] as $f) if (!is_file(__DIR__ . "/../public/assets/$f")) $nuevos = false;
    check('assets/mantenimientos.js y escanear_qr.js existen y las páginas los cargan', $nuevos && strpos($rd2('app/Presentation/Views/mantenimientos/pagina.php'), 'assets/mantenimientos.js') !== false && strpos($rd2('app/Presentation/Views/tickets/escanear_qr.php'), 'assets/escanear_qr.js') !== false);
    check('app.js: QR declarativos (data-qr-texto) y carga de equipos por data-equipos-destino', strpos($rd2('public/assets/app.js'), 'data-qr-texto') !== false && strpos($rd2('public/assets/app.js'), 'data-equipos-destino') !== false);
    check('shell.js y componentes_widget.js usan eventos delegados (data-shell / data-comp-*)', strpos($rd2('public/assets/shell.js'), 'data-shell') !== false && strpos($rd2('public/assets/componentes_widget.js'), 'data-comp-buscar') !== false);

    // ==================== sin JS en línea en todo el proyecto + CSP sin 'unsafe-inline' en scripts ====================
    echo "\n[sin JS en línea y CSP estricta para scripts]\n";
    $rd3 = function (string $rel) { return (string)file_get_contents(__DIR__ . '/../' . $rel); };
    $pend = []; $hand = [];
    $itx = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/..', FilesystemIterator::SKIP_DOTS));
    foreach ($itx as $f) {
        $path = $f->getPathname();
        if (substr($path, -4) !== '.php' || preg_match('~/(vendor|legacy|tests|database|storage|config)/~', $path)) continue;
        $c = (string)file_get_contents($path);
        $c = preg_replace('~<\?php.*?\?>~s', '', $c); // quita bloques PHP (comentarios/código)
        if (strpos($path, '/public/') !== false || strpos($path, '/Presentation/') !== false) {
            if (preg_match('~<script(?![^>]*\bsrc=)(?![^>]*application/json)~i', $c)) $pend[] = basename($path);
            if (preg_match('~\son(?:click|change|input|submit|keyup|load|error|focus|blur)="|href="javascript:~i', $c)) $hand[] = basename($path);
        }
    }
    check('ningún PHP de public/ ni Presentation/ tiene <script> en línea (' . implode(',', $pend) . ')', !$pend);
    check('ningún PHP tiene atributos on*="" ni href="javascript:" (' . implode(',', $hand) . ')', !$hand);
    $sb2 = $rd3('app/Support/session_boot.php');
    preg_match("~script-src ([^;\"]*);~", $sb2, $mcsp);
    check("CSP: script-src sin 'unsafe-inline' (" . ($mcsp[1] ?? '?') . ')', isset($mcsp[1]) && strpos($mcsp[1], 'unsafe-inline') === false && strpos($mcsp[1], "'self'") !== false);
    check('CSP: style-src conserva unsafe-inline (atributos style="" aún existen)', strpos($sb2, "style-src 'self' 'unsafe-inline'") !== false);
    $acc = true; foreach (['horarios.js', 'paneles.js', 'graficos_admin.js', 'crear_ticket_beca.js', 'profesor_nuevo_ticket.js'] as $j) if (!is_file(__DIR__ . "/../public/assets/$j")) $acc = false;
    check('assets de las pantallas presentes', $acc);
    check('app.js: acciones declarativas data-click / data-change / data-confirm-click', strpos($rd3('public/assets/app.js'), 'UFPS.accion =') !== false && strpos($rd3('public/assets/app.js'), 'data-confirm-click') !== false);
    check('scripts_graficos.php solo emite JSON (sin JS)', strpos($rd3('app/Presentation/Views/admin_tickets/scripts_graficos.php'), 'application/json') !== false && strpos($rd3('app/Presentation/Views/admin_tickets/scripts_graficos.php'), 'new Chart') === false);


    // ==================== Conexiones externas y GLPI (BD + API simuladas) ====================
    echo "\n[Conexiones: cifrado, guardar/probar y GLPI simulado (BD de lectura + API de escritura + cola)]\n";
    putenv('GLPI_SIN_ENVIO_AUTOMATICO=1'); // en pruebas la cola se procesa a mano
    putenv('CONEXIONES_CLAVE_ARCHIVO=' . sys_get_temp_dir() . '/zt_clave_conexiones.key');
    $cif = ConexionesExternas::cifrar('sistemas');
    check('cifrado: la clave guardada no contiene el texto y se recupera igual', strpos($cif, 'sistemas') === false && strpos($cif, 'enc:v1:') === 0 && ConexionesExternas::descifrar($cif) === 'sistemas' && ConexionesExternas::cifrar('sistemas') !== $cif);
    $admC = (int)$pdo->query("SELECT id FROM users WHERE role='tecnico' ORDER BY id DESC LIMIT 1")->fetchColumn();
    ConexionesExternas::guardar($pdo, ConexionesExternas::ASISTENCIA_PG, ['host' => '3.93.129.8', 'port' => '5432', 'dbname' => 'bd_sistemas_becario', 'user' => 'dptosistemas', 'password' => 'sistemas', 'extra' => 'x'], $admC);
    $crudo = (string)$pdo->query("SELECT datos FROM conexiones_externas WHERE clave='asistencia_pg'")->fetchColumn();
    check('conexiones: en la base la contraseña va cifrada y no se guardan campos fuera de la lista', strpos((string)(json_decode($crudo, true)['password'] ?? ''), 'enc:v1:') === 0 && strpos($crudo, '"sistemas"') === false && strpos($crudo, 'extra') === false && strpos($crudo, 'bd_sistemas_becario') !== false);
    check('conexiones: obtener() devuelve la contraseña descifrada', (ConexionesExternas::obtener($pdo, 'asistencia_pg')['password'] ?? '') === 'sistemas');
    $resu = ConexionesExternas::resumen($pdo, 'asistencia_pg');
    check('conexiones: el resumen para la vista NO trae la contraseña, solo que está guardada', !isset($resu['datos']['password']) && $resu['secretosGuardados']['password'] === true && strpos(json_encode($resu), '"sistemas"') === false && strpos(json_encode($resu), 'enc:v1:') === false);
    ConexionesExternas::guardar($pdo, 'asistencia_pg', ['host' => '127.0.0.1', 'port' => '1', 'dbname' => 'bd_sistemas_becario', 'user' => 'dptosistemas', 'password' => ''], $admC);
    check('conexiones: guardar con la contraseña vacía conserva la anterior', ConexionesExternas::obtener($pdo, 'asistencia_pg')['password'] === 'sistemas' && ConexionesExternas::obtener($pdo, 'asistencia_pg')['host'] === '127.0.0.1');
    check('conexiones: validación de host, puerto y URL', ConexionesService::guardar($pdo, 'glpi_db', ['host' => 'http://x y', 'port' => '3306'], $admC)['ok'] === false
        && ConexionesService::guardar($pdo, 'glpi_db', ['host' => '10.0.0.5', 'port' => '99999'], $admC)['ok'] === false
        && ConexionesService::guardar($pdo, 'glpi_api', ['url' => 'ftp://glpi'], $admC)['ok'] === false && ConexionesService::guardar($pdo, 'otra', [], $admC)['ok'] === false);
    $prPg = ConexionesService::probar($pdo, 'asistencia_pg');
    check('conexiones: probar Postgres inaccesible devuelve un error claro y queda registrado (' . $prPg['mensaje'] . ')', $prPg['ok'] === false && (int)$pdo->query("SELECT prueba_ok FROM conexiones_externas WHERE clave='asistencia_pg'")->fetchColumn() === 0);

    // GLPI simulado: base de datos (lectura)
    $gName = 'glpi_zt_test';
    $root = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); // conexión aparte: el DDL no toca la transacción de las pruebas
    $root->exec("DROP DATABASE IF EXISTS `$gName`"); $root->exec("CREATE DATABASE `$gName`"); $root->exec("USE `$gName`");
    $root->exec((string)file_get_contents(__DIR__ . '/glpi/esquema_glpi_falso.sql'));
    ConexionesService::guardar($pdo, 'glpi_db', ['host' => $host === 'localhost' ? '127.0.0.1' : $host, 'port' => '3306', 'dbname' => $gName, 'user' => $user, 'password' => $pass], $admC);
    $prG = ConexionesService::probar($pdo, 'glpi_db');
    check('GLPI BD: probar conexión cuenta los activos (' . $prG['mensaje'] . ')', $prG['ok'] && strpos($prG['mensaje'], '5 activos') !== false);
    $g = Database::abrirMysql(ConexionesExternas::obtener($pdo, 'glpi_db'));
    $act = GlpiDb::activos($g);
    check('GLPI BD: lee computadores, monitores y periféricos; omite borrados, plantillas y tablas que no existen', count($act) === 5 && !in_array('Borrado', array_column($act, 'nombre'), true) && !in_array('Plantilla', array_column($act, 'nombre'), true));
    check('GLPI BD: trae ubicación, fabricante, modelo y estado', ($pc = array_values(array_filter($act, fn($a) => $a['id'] === 11))[0] ?? null) !== null && $pc['ubicacion'] === 'Aula Sur > SA401' && $pc['fabricante'] === 'Dell' && $pc['modelo'] === 'OptiPlex 7010' && $pc['estado'] === 'Dañado');
    check('GLPI BD: filtro por ubicación padre incluye sububicaciones', count(GlpiDb::activos($g, 1)) === 5 && count(GlpiDb::activos($g, 3)) === 2);
    check('GLPI BD: buscar por serial', GlpiDb::buscarPorSerial($g, 'ZTG-SER-MON1') === ['itemtype' => 'Monitor', 'id' => 20] && GlpiDb::buscarPorSerial($g, 'NO-EXISTE') === null);

    // Datos locales: sala vinculable, equipo, componentes
    $pdo->prepare("INSERT INTO salas (nombre_sala, abreviatura, codigo_qr_token) VALUES ('ZT-GLPI-S1','ZG1',?), ('ZT-GLPI-S2','ZG2',?)")->execute(['zg' . bin2hex(random_bytes(6)), 'zg' . bin2hex(random_bytes(6))]);
    $sG1 = (int)$pdo->query("SELECT id_sala FROM salas WHERE nombre_sala='ZT-GLPI-S1'")->fetchColumn();
    $sG2 = (int)$pdo->query("SELECT id_sala FROM salas WHERE nombre_sala='ZT-GLPI-S2'")->fetchColumn();
    $pdo->prepare("INSERT INTO equipos (id_sala, codigo, descripcion) VALUES (?, 'ZT-GLPI-EQ1', 'x'), (?, 'ZT-GLPI-EQ2', 'x')")->execute([$sG1, $sG2]);
    $eqG1 = (int)$pdo->query("SELECT id_equipo FROM equipos WHERE codigo='ZT-GLPI-EQ1'")->fetchColumn();
    $eqG2 = (int)$pdo->query("SELECT id_equipo FROM equipos WHERE codigo='ZT-GLPI-EQ2'")->fetchColumn();
    $colaN = fn(string $w = '1=1') => (int)$pdo->query("SELECT COUNT(*) FROM glpi_cola WHERE $w")->fetchColumn();
    $antes = $colaN();
    $rNuevo = ComponenteService::crear($pdo, $tec1, ['tipo' => 'monitor', 'serial' => 'ZTG-NUEVO-1', 'codigo_inventario' => 'ZTG-INV-N1', 'id_equipo_inicial' => $eqG1]);
    $rExiste = ComponenteService::crear($pdo, $tec1, ['tipo' => 'torre', 'serial' => 'ZTG-SER-PC01', 'codigo_inventario' => 'ZTG-INV-PC01', 'id_equipo_inicial' => $eqG1]);
    $cN = (int)$pdo->query("SELECT id_componente FROM componentes WHERE serial='ZTG-NUEVO-1'")->fetchColumn();
    $cE = (int)$pdo->query("SELECT id_componente FROM componentes WHERE serial='ZTG-SER-PC01'")->fetchColumn();
    check('cola: dar de alta un componente TI lo encola para crearlo en GLPI', $rNuevo['error'] === '' && $colaN("tipo='CREAR_COMPONENTE' AND id_componente=$cN AND estado='PENDIENTE'") === 1 && $colaN() === $antes + 2);
    ComponenteService::crearEnSala($pdo, $tec1, ['id_sala' => $sG1, 'tipo_infra' => 'silla', 'serial_infra' => 'ZTG-SILLA', 'codigo_inventario_infra' => 'ZTG-SILLA']);
    check('cola: los de infraestructura (sillas, aire…) no se envían a GLPI', $colaN("id_componente IN (SELECT id_componente FROM componentes WHERE serial='ZTG-SILLA')") === 0);
    $sinApi = GlpiSyncService::procesar($pdo, false, 50);
    check('cola: sin API configurada no se envía nada y queda pendiente', $sinApi['procesados'] === 0 && strpos($sinApi['mensaje'], 'no está configurada') !== false && $colaN("id_componente=$cN AND estado='PENDIENTE'") === 1);

    // Movimientos: componente suelto (técnico) y equipo completo aprobado
    InventarioService::registrarHistorial($pdo, $cN, 'DESASIGNACION', $tec1, $eqG1, null, 'ACTIVO', 'EN_REVISION', null, 'a bodega');
    check('cola: un técnico saca un componente a bodega → cambio de ubicación encolado (destino bodega)', $colaN("tipo='CAMBIO_UBICACION' AND id_componente=$cN AND id_sala_destino IS NULL") === 1);
    InventarioService::registrarHistorial($pdo, $cN, 'ASIGNACION', $beca1, null, $eqG2, 'EN_REVISION', 'ACTIVO', null, 'lo movió una beca');
    check('cola: si lo mueve una beca (sin aprobación del técnico) NO se envía a GLPI', $colaN("tipo='CAMBIO_UBICACION' AND id_componente=$cN") === 1);
    TicketActivos::registrarMovimientoActivo($pdo, $eqG1, null, $sG1, $sG2, 'CAMBIO_DE_POSICION', null, null, null, $tec1, $tec1);
    check('cola: traslado aprobado de un equipo completo → se encola cada componente TI suyo', $colaN("tipo='CAMBIO_UBICACION' AND id_componente=$cE AND id_sala_destino=$sG2") === 1);
    TicketActivos::registrarMovimientoActivo($pdo, $eqG1, null, $sG2, $sG1, 'SOLICITUD', null, null, $tec1, null, null);
    check('cola: un traslado aún sin aprobar no se encola', $colaN("tipo='CAMBIO_UBICACION' AND id_componente=$cE") === 1);

    // API simulada (escritura)
    $logApi = sys_get_temp_dir() . '/zt_glpi_api.log'; @unlink($logApi);
    $puertoApi = 8000 + random_int(500, 900);
    $srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$puertoApi", __DIR__ . '/glpi/api_falsa.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipesSrv, null, array_merge(getenv(), ['GLPI_FALSA_LOG' => $logApi]));
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $puertoApi); $i++) usleep(100000);
    $urlApi = "http://127.0.0.1:$puertoApi/apirest.php";
    ConexionesService::guardar($pdo, 'glpi_api', ['url' => $urlApi, 'app_token' => 'APPZT', 'user_token' => 'MALO', 'ubicacion_bodega' => ''], $admC);
    $prA = ConexionesService::probar($pdo, 'glpi_api');
    check('GLPI API: con token de usuario malo, la prueba falla con el mensaje de GLPI (' . $prA['mensaje'] . ')', $prA['ok'] === false && strpos($prA['mensaje'], '401') !== false);
    ConexionesService::guardar($pdo, 'glpi_api', ['url' => $urlApi, 'user_token' => 'USRZT', 'ubicacion_bodega' => '9'], $admC);
    $prA = ConexionesService::probar($pdo, 'glpi_api');
    check('GLPI API: probar conexión inicia sesión (' . $prA['mensaje'] . ')', $prA['ok'] && strpos($prA['mensaje'], 'glpi-zt') !== false);
    check('GLPI API: el App-Token vacío al guardar conservó el anterior', ConexionesExternas::obtener($pdo, 'glpi_api')['app_token'] === 'APPZT');

    $r1 = GlpiSyncService::procesar($pdo, false, 50, new GlpiApi($urlApi, 'APPZT', 'USRZT'), $g);
    $llamadas = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents($logApi))));
    $posts = array_values(array_filter($llamadas, fn($l) => $l['metodo'] === 'POST'));
    $cNfila = $pdo->query("SELECT glpi_itemtype, glpi_id FROM componentes WHERE id_componente=$cN")->fetch(PDO::FETCH_ASSOC);
    check('envío: el componente nuevo se crea en GLPI (POST /Monitor con serial, n.º de inventario y ubicación) y queda vinculado',
        count($posts) === 1 && $posts[0]['ruta'] === '/Monitor' && $posts[0]['cuerpo']['input']['serial'] === 'ZTG-NUEVO-1' && $posts[0]['cuerpo']['input']['otherserial'] === 'ZTG-INV-N1'
        && !isset($posts[0]['cuerpo']['input']['locations_id']) && $cNfila['glpi_itemtype'] === 'Monitor' && (int)$cNfila['glpi_id'] > 500);
    check('envío: el que ya existía en GLPI (mismo serial) NO se duplica: se vincula al Computer #10', $pdo->query("SELECT CONCAT(glpi_itemtype,'#',glpi_id) FROM componentes WHERE id_componente=$cE")->fetchColumn() === 'Computer#10'
        && $colaN("tipo='CREAR_COMPONENTE' AND id_componente=$cE AND estado='ENVIADO' AND ultimo_error LIKE 'Ya existía%'") === 1);
    $puts = array_values(array_filter($llamadas, fn($l) => $l['metodo'] === 'PUT'));
    check('envío: el retiro a bodega mueve el activo en GLPI a la ubicación de bodega configurada (9)', count($puts) === 1 && $puts[0]['cuerpo']['input']['locations_id'] === 9);
    $err = (string)$pdo->query("SELECT ultimo_error FROM glpi_cola WHERE tipo='CAMBIO_UBICACION' AND id_componente=$cE")->fetchColumn();
    check('envío: si la sala destino no está vinculada a GLPI queda en ERROR con explicación (' . $err . ')', $colaN("tipo='CAMBIO_UBICACION' AND id_componente=$cE AND estado='ERROR'") === 1 && strpos($err, 'ZT-GLPI-S2') !== false);
    check('envío: resumen del proceso (incluye movimientos viejos de componentes que nunca se dieron de alta → error «No existe en GLPI»)', $r1['enviados'] >= 3 && $r1['errores'] >= 1
        && $colaN("estado='ERROR' AND ultimo_error LIKE 'No existe en GLPI%'") >= 1);
    GlpiColaRepository::vincularSala($pdo, $sG2, 3);
    $r2 = GlpiSyncService::procesar($pdo, false, 50, new GlpiApi($urlApi, 'APPZT', 'USRZT'), $g);
    check('reintento: el envío automático NO reintenta errores (solo pendientes)', $r2['procesados'] === 0);
    $r3 = GlpiSyncService::procesar($pdo, true, 50, new GlpiApi($urlApi, 'APPZT', 'USRZT'), $g);
    $ultimoPut = array_values(array_filter(array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)file_get_contents($logApi)))), fn($l) => $l['metodo'] === 'PUT'));
    $putPc10 = array_values(array_filter($ultimoPut, fn($l) => $l['ruta'] === '/Computer/10'));
    check('reintento: tras vincular la sala, el admin reintenta y se mueve el Computer #10 a la ubicación 3', $r3['enviados'] >= 1 && count($putPc10) === 1 && $putPc10[0]['cuerpo']['input']['locations_id'] === 3
        && $colaN("tipo='CAMBIO_UBICACION' AND id_componente=$cE AND estado='ENVIADO' AND intentos=2") === 1);
    ComponenteService::crear($pdo, $tec1, ['tipo' => 'mouse', 'serial' => 'ZTG-FALLA', 'codigo_inventario' => 'ZTG-INV-F']);
    $r4 = GlpiSyncService::procesar($pdo, false, 50, new GlpiApi($urlApi, 'APPZT', 'USRZT'), $g);
    check('envío: si GLPI rechaza el alta, queda en ERROR con el mensaje de GLPI', $r4['errores'] === 1 && strpos((string)$pdo->query("SELECT ultimo_error FROM glpi_cola WHERE id_componente=(SELECT id_componente FROM componentes WHERE serial='ZTG-FALLA')")->fetchColumn(), 'ERROR_GLPI_ADD') !== false);
    $r5 = GlpiSyncService::procesar($pdo, true, 50, new GlpiApi("http://127.0.0.1:1/apirest.php", 'APPZT', 'USRZT', 2), $g);
    check('envío: si GLPI no responde, queda en ERROR «GLPI no responde» (no rompe nada)', $r5['errores'] >= 1 && $colaN("ultimo_error LIKE 'GLPI no responde%'") >= 1);
    proc_terminate($srv); proc_close($srv);

    // Inventario agrupado + vincular por serial
    $inv = GlpiSyncService::inventario($pdo, $g, null, null);
    check('inventario: agrupa por tipo y por ubicación, y cuenta los que también están en el sistema',
        $inv['conectado'] && $inv['total'] === 5 && $inv['porTipo'] === ['Computer' => 3, 'Monitor' => 1, 'Peripheral' => 1] && $inv['porUbicacion']['Aula Sur > SA401']['total'] === 3 && $inv['enSistema'] === 1 && $inv['detalle'] === []);
    $inv2 = GlpiSyncService::inventario($pdo, $g, 3, 'Computer');
    check('inventario: detalle filtrado por ubicación y tipo', count($inv2['detalle']) === 1 && $inv2['detalle'][0]['nombre'] === 'SA402-PC01');
    check('inventario: sin conexión a GLPI no falla', GlpiSyncService::inventario($pdo, null, null, null)['conectado'] === false);
    $pdo->exec("INSERT INTO componentes (tipo, serial, codigo_inventario, estado) VALUES ('mouse','ZTG-SER-MOU1','ZTG-INV-MOU1','ACTIVO')");
    check('vincular por serial: enlaza el mouse local con el Peripheral #30 de GLPI', GlpiSyncService::vincularPorSerial($pdo, $g) >= 1
        && $pdo->query("SELECT CONCAT(glpi_itemtype,'#',glpi_id) FROM componentes WHERE serial='ZTG-SER-MOU1'")->fetchColumn() === 'Peripheral#30');
    // Distribución física del salón (filas → mesas → puestos, número y punto de red)
    $pl = DistribucionSala::plantilla(2, 2, 3, 1, 'atras');
    check('distribución: la plantilla arma filas de mesas con pasillo y puesto docente', count($pl['filas']) === 2 && count($pl['filas'][0]['mesas']) === 2 && count($pl['filas'][0]['mesas'][0]['puestos']) === 3
        && $pl['filas'][0]['mesas'][0]['pasillo'] === true && $pl['filas'][0]['mesas'][1]['pasillo'] === false && $pl['docente']['ubicacion'] === 'atras');
    $numF = array_map(fn($x) => $x, DistribucionSala::recorrido($pl, 'zigzag'));
    check('distribución: recorrido en zigzag invierte las filas pares', $numF[0] === [0, 0, 0] && $numF[6] === [1, 1, 2] && count($numF) === 12);
    $plN = DistribucionSala::numerar($pl, 'filas', 1, 'PC');
    check('distribución: numerar por filas (PC1…PC12)', $plN['filas'][0]['mesas'][1]['puestos'][0]['numero'] === 'PC4' && $plN['filas'][1]['mesas'][1]['puestos'][2]['numero'] === 'PC12');
    $plA = DistribucionSala::autoAsignar($plN, [11, 12, 13]);
    check('distribución: ubicar equipos sin puesto en orden de recorrido', $plA['filas'][0]['mesas'][0]['puestos'][0]['id_equipo'] === 11 && $plA['filas'][0]['mesas'][0]['puestos'][2]['id_equipo'] === 13 && DistribucionSala::idsUbicados($plA) === [11, 12, 13]);
    $plA['filas'][1]['mesas'][0]['puestos'][0]['id_equipo'] = 11; // repetido
    $plA['filas'][1]['mesas'][0]['puestos'][1]['numero'] = 'pc1';  // número repetido (sin distinguir mayúsculas)
    $plA['filas'][1]['mesas'][0]['puestos'][2]['id_equipo'] = 999; // de otra sala
    $nv = DistribucionSala::normalizar($plA, [11, 12, 13], [11 => 'SA1-PC01']);
    check('distribución: rechaza equipo en dos puestos, número repetido y equipo de otra sala', count($nv['errores']) === 3 && strpos(implode(' ', $nv['errores']), 'SA1-PC01 está en dos puestos') !== false);
    $irregular = ['docente' => ['ubicacion' => 'adelante'], 'filas' => [['mesas' => [['puestos' => [[], [], [], []], 'pasillo' => true], ['puestos' => [[], [], []]], ['puestos' => []]]]]];
    $nIrr = DistribucionSala::normalizar($irregular, [])['estructura'];
    check('distribución: mesas de distinto tamaño (4 y 3), sin mesas vacías ni pasillo tras la última', count($nIrr['filas'][0]['mesas']) === 2 && count($nIrr['filas'][0]['mesas'][1]['puestos']) === 3 && $nIrr['filas'][0]['mesas'][1]['pasillo'] === false);
    $ini = DistribucionSala::inicial([5, 6, 7, 8, 9], 4);
    check('distribución inicial: dos lados con pasillo, numerada en orden de código y docente «D»', count(DistribucionSala::recorrido($ini)) >= 5 && $ini['filas'][0]['mesas'][0]['puestos'][0]['id_equipo'] === 5 && $ini['filas'][0]['mesas'][0]['puestos'][0]['numero'] === '1' && $ini['docente']['id_equipo'] === 4 && $ini['docente']['numero'] === 'D');
    check('distribución: puesto de un equipo (número, fila, mesa, punto de red)', DistribucionSala::puestoDeEquipo($plA, 12)['numero'] === 'PC2' && DistribucionSala::puestoDeEquipo($plA, 12)['fila'] === 1 && DistribucionSala::puestoDeEquipo($plA, 77) === null);
    // planoSala() con distribución: puestos reales, vacíos, docente al fondo y sin ubicar
    $eqsP = [['id_equipo' => 1, 'codigo' => 'SA9-PC01'], ['id_equipo' => 2, 'codigo' => 'SA9-PC02'], ['id_equipo' => 3, 'codigo' => 'SA9-PC03'], ['id_equipo' => 4, 'codigo' => 'SA9-DOC', 'descripcion' => 'Equipo docente'], ['id_equipo' => 5, 'codigo' => 'SA9-VB']];
    $dP = ['docente' => ['ubicacion' => 'atras', 'lado' => 'izq', 'id_equipo' => 4, 'numero' => 'D', 'red' => 'R-0'],
           'filas' => [['mesas' => [['pasillo' => true, 'puestos' => [['id_equipo' => 2, 'numero' => '1', 'red' => 'R-1'], ['id_equipo' => null, 'numero' => '2', 'red' => '']]], ['pasillo' => false, 'puestos' => [['id_equipo' => 1, 'numero' => '3', 'red' => '']]]]]]];
    $pS = planoSala($eqsP, 'codigo', 'descripcion', $dP);
    check('planoSala con distribución: cada equipo en su puesto con número y punto de red', $pS['filas'][0]['izq'][0]['puestos'][0]['codigo'] === 'SA9-PC02' && $pS['filas'][0]['izq'][0]['puestos'][0]['_red'] === 'R-1'
        && !empty($pS['filas'][0]['izq'][0]['puestos'][1]['_vacio']) && $pS['filas'][0]['der'][0]['puestos'][0]['_num'] === '3');
    check('planoSala con distribución: docente al fondo, videobeam al frente y el PC sin puesto aparte', $pS['fondo'][0]['codigo'] === 'SA9-DOC' && array_column($pS['frente'], 'codigo') === ['SA9-VB']
        && array_column($pS['sinUbicar'], 'codigo') === ['SA9-PC03'] && count($pS['todos']) === 5);
    check('planoSala sin distribución: reparto automático de siempre', planoSala($eqsP)['filas'] === null && count(planoSala($eqsP)['izquierda']) === 2);
    $choque = planoSala([['id_equipo' => 1, 'codigo' => 'PC01'], ['id_equipo' => 2, 'codigo' => 'SA9-PC01'], ['id_equipo' => 3, 'codigo' => 'SA9-PC02']])['todos'];
    check('plano: el código corto no se usa si choca con otro equipo de la sala', array_column($choque, '_cod') === ['PC01', 'SA9-PC01', 'PC02']);
    check('codigoEnSala: quita el prefijo de la sala solo cuando lo hay', codigoEnSala('SA402-PC07') === 'PC07' && codigoEnSala('PC-04') === 'PC-04' && codigoEnSala('PC01') === 'PC01');
    // Guardar y leer (servicio + repositorio)
    $idSalaD = (int)$pdo->query("SELECT id_sala FROM salas WHERE nombre_sala='ZT-GLPI-S1'")->fetchColumn();
    $eqsD = DistribucionSalaService::equiposDeSala($pdo, $idSalaD);
    $estD = DistribucionSala::autoAsignar(DistribucionSala::numerar(DistribucionSala::plantilla(1, 2, 2, 1)), array_column($eqsD, 'id_equipo'));
    $gD = DistribucionSalaService::guardar($pdo, $idSalaD, json_encode($estD), $admC);
    check('distribución: se guarda validada y se lee igual (' . $gD['exito'] . $gD['error'] . ')', $gD['error'] === '' && DistribucionSalaRepository::deSala($pdo, $idSalaD)['filas'][0]['mesas'][0]['puestos'][0]['numero'] === '1');
    $malD = $estD; $malD['filas'][0]['mesas'][1]['puestos'][0]['id_equipo'] = $estD['filas'][0]['mesas'][0]['puestos'][0]['id_equipo'] ?? 1;
    check('distribución: con errores no se guarda y explica por qué', DistribucionSalaService::guardar($pdo, $idSalaD, json_encode($malD), $admC)['error'] !== '' && DistribucionSalaService::guardar($pdo, $idSalaD, 'no-json', $admC)['error'] !== '');
    DistribucionSalaService::restablecer($pdo, $idSalaD, $admC);
    check('distribución: restablecer vuelve al reparto automático', DistribucionSalaRepository::deSala($pdo, $idSalaD) === null && DistribucionSalaService::pantalla($pdo, $idSalaD)['esNueva'] === true);
    // Ubicaciones de GLPI para salas nuevas: agrupadas por padre y detección automática por nombre/abreviatura
    $ubG = GlpiUbicaciones::listar($g);
    $grG = GlpiUbicaciones::agrupar($ubG);
    check('ubicaciones GLPI: se agrupan por la ubicación padre (Aula Sur → SA401, SA402)', isset($grG['Aula Sur']) && array_column($grG['Aula Sur'], 'nombre') === ['SA401', 'SA402'] && GlpiUbicaciones::listar(null) === []);
    check('ubicaciones GLPI: detecta por nombre exacto, por abreviatura y sin importar mayúsculas/espacios', GlpiUbicaciones::detectar($ubG, 'sa 402') === 3 && GlpiUbicaciones::detectar($ubG, 'Sala nueva', 'SA401') === 2
        && GlpiUbicaciones::detectar($ubG, 'Oficina de Mantenimiento') === 9);
    check('ubicaciones GLPI: no adivina si no hay coincidencia o si es ambigua', GlpiUbicaciones::detectar($ubG, 'Auditorio') === null && GlpiUbicaciones::detectar($ubG, 'SA4') === null);
    // SalaService lee la conexión de GLPI con Database::conexion(): que use la misma transacción de las pruebas
    // (si no, no ve la configuración de GLPI guardada arriba, que aún no tiene COMMIT).
    $instanciaDb = new ReflectionProperty(Database::class, 'instancia');
    $instanciaOriginal = $instanciaDb->getValue();
    $instanciaDb->setValue(null, $pdo);
    Database::reiniciarExternas();
    $resSalaG = SalaService::crear($pdo, 'SA402', 'ZSA402', null);
    $idSalaNuevaG = (int)$pdo->query("SELECT id_sala FROM salas WHERE nombre_sala='SA402'")->fetchColumn();
    check('salas: al crear una sala nueva se vincula sola con su ubicación de GLPI (' . $resSalaG['exito'] . ')', $resSalaG['error'] === '' && strpos($resSalaG['exito'], 'Aula Sur > SA402') !== false
        && (int)$pdo->query("SELECT glpi_location_id FROM salas WHERE id_sala=$idSalaNuevaG")->fetchColumn() === 3);
    $pdo->exec("UPDATE salas SET glpi_location_id=NULL WHERE id_sala=$idSalaNuevaG");
    $resVincG = SalaService::vincularSugeridasGlpi($pdo, $admC);
    check('salas: «Vincular salas con GLPI» enlaza en bloque las que coinciden', $resVincG['error'] === '' && (int)$pdo->query("SELECT glpi_location_id FROM salas WHERE id_sala=$idSalaNuevaG")->fetchColumn() === 3);
    $instanciaDb->setValue(null, $instanciaOriginal);
    Database::reiniciarExternas();
    $root->exec("DROP DATABASE IF EXISTS `$gName`");
    putenv('GLPI_SIN_ENVIO_AUTOMATICO');

    // ==================== SLA, indicadores, encuesta y seguridad ====================
    echo "\n[Calidad del servicio (SLA) y seguridad de la información]\n";
    // TOTP (RFC 6238, vector de prueba SHA-1 con la clave «12345678901234567890»)
    $secRfc = Totp::base32('12345678901234567890');
    check('TOTP: Base32 del vector del RFC y vuelta atrás', $secRfc === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ' && Totp::base32Decodificar($secRfc) === '12345678901234567890');
    check('TOTP: códigos del RFC 6238 (T=59 → 287082, T=1111111109 → 081804)', Totp::codigo($secRfc, 1) === '287082' && Totp::codigo($secRfc, intdiv(1111111109, 30)) === '081804');
    check('TOTP: acepta ±1 intervalo y rechaza otro código', Totp::verificar($secRfc, '287082', 59 + 30) === 1 && Totp::verificar($secRfc, '000000', 59) === null && Totp::verificar($secRfc, '28708', 59) === null);
    check('TOTP: el enlace otpauth lleva emisor, secreto y 6 dígitos', strpos(Totp::uri('ABC', 'a@b.co'), 'otpauth://totp/Soporte%20UFPS:a%40b.co?secret=ABC&issuer=Soporte%20UFPS&digits=6') === 0);
    // Festivos de Colombia y calendario hábil
    $fest26 = CalendarioHabil::festivosColombia(2026);
    check('festivos 2026: 18 días, con Reyes trasladado al lunes 12/01, Jueves Santo 02/04, Ascensión 18/05, Corpus 08/06 y Sagrado Corazón 15/06',
        count($fest26) === 18 && isset($fest26['2026-01-12'], $fest26['2026-04-02'], $fest26['2026-04-03'], $fest26['2026-05-18'], $fest26['2026-06-08'], $fest26['2026-06-15'], $fest26['2026-12-08']) && !isset($fest26['2026-01-06']));
    $tz = new DateTimeZone('America/Bogota');
    $d = static fn(string $s): DateTimeImmutable => new DateTimeImmutable($s, $tz);
    $calT = new CalendarioHabil([1 => ['08:00', '12:00'], 2 => ['08:00', '12:00'], 3 => ['08:00', '12:00'], 4 => ['08:00', '12:00'], 5 => ['08:00', '12:00']], ['2026-10-12']);
    check('calendario: solo cuenta el horario de atención (viernes 11:00 → lunes 09:00 = 2 h)', $calT->segundosEntre($d('2026-10-02 11:00'), $d('2026-10-05 09:00')) === 7200);
    check('calendario: el festivo no cuenta (viernes 09/10 11:00 → martes 13/10 09:00 = 2 h)', $calT->segundosEntre($d('2026-10-09 11:00'), $d('2026-10-13 09:00')) === 7200);
    check('calendario: sumar 2 h hábiles al viernes 11:00 da el lunes 09:00, y saltando el festivo da el martes', $calT->sumar($d('2026-10-02 11:00'), 7200)->format('Y-m-d H:i') === '2026-10-05 09:00'
        && $calT->sumar($d('2026-10-09 11:00'), 7200)->format('Y-m-d H:i') === '2026-10-13 09:00');
    check('calendario: horario inválido (cierre antes de abrir) se ignora y cuenta tiempo corrido', (new CalendarioHabil([1 => ['18:00', '08:00']]))->segundosEntre($d('2026-10-05 08:00'), $d('2026-10-05 09:00')) === 3600);
    // Prioridad
    check('prioridad: matriz impacto × urgencia (1×1 crítica, 2×2 media, 3×3 baja, 1×2 alta)', SlaService::prioridadDe(1, 1) === 'CRITICA' && SlaService::prioridadDe(2, 2) === 'MEDIA' && SlaService::prioridadDe(3, 3) === 'BAJA' && SlaService::prioridadDe(1, 2) === 'ALTA');
    check('prioridad automática: seguridad crítica, daño de salón alta, requerimiento de solicitante baja',
        SlaService::prioridadDe(...SlaService::impactoUrgenciaAutomaticos('tickets', 'INCIDENTE', 'SEGURIDAD', 'PANEL_INTERNO')) === 'CRITICA'
        && SlaService::prioridadDe(...SlaService::impactoUrgenciaAutomaticos('tickets', 'INCIDENTE', null, 'QR_ANONIMO')) === 'ALTA'
        && SlaService::prioridadDe(...SlaService::impactoUrgenciaAutomaticos('tickets', 'REQUERIMIENTO', null, 'PANEL_INTERNO')) === 'BAJA');
    // Medición de un ticket (cálculo puro)
    $polT = ['INCIDENTE' => ['MEDIA' => ['respuesta' => 1.0, 'solucion' => 4.0]]];
    $tBase = ['tabla' => 'tickets', 'id' => 1, 'estado' => 'Cerrado', 'fecha_creacion' => '2026-10-05 08:00:00', 'tipo' => 'INCIDENTE', 'prioridad' => 'MEDIA', 'fecha_terminacion' => null, 'fecha_firmado' => null];
    $evT = [['estado_anterior' => null, 'estado_nuevo' => 'En espera', 'fecha' => '2026-10-05 08:00:00'], ['estado_anterior' => 'En espera', 'estado_nuevo' => 'En proceso', 'fecha' => '2026-10-05 08:30:00'],
            ['estado_anterior' => 'En proceso', 'estado_nuevo' => 'Solucionado', 'fecha' => '2026-10-05 10:00:00'], ['estado_anterior' => 'Solucionado', 'estado_nuevo' => 'Cerrado', 'fecha' => '2026-10-05 10:30:00']];
    $m1 = SlaService::medirUno($tBase, $evT, [], [], [], null, $polT, $calT, $d('2026-10-06 08:00'));
    check('SLA: primera respuesta 30 min, solución 2 h, cumple ambos y sin reaperturas', $m1['seg_respuesta'] === 1800 && $m1['seg_solucion'] === 7200 && $m1['cumple_respuesta'] === true && $m1['cumple_solucion'] === true && $m1['reaperturas'] === 0 && $m1['semaforo'] === 'cumplido');
    $evR = array_merge(array_slice($evT, 0, 3), [['estado_anterior' => 'Solucionado', 'estado_nuevo' => 'En proceso', 'fecha' => '2026-10-05 11:00:00'], ['estado_anterior' => 'En proceso', 'estado_nuevo' => 'Cerrado', 'fecha' => '2026-10-06 09:30:00']]);
    $m2 = SlaService::medirUno($tBase, $evR, [], [], [], null, $polT, $calT, $d('2026-10-07 08:00'));
    check('SLA: una reapertura se cuenta y la solución final (5,5 h) incumple las 4 h', $m2['reaperturas'] === 1 && $m2['seg_solucion'] === 19800 && $m2['cumple_solucion'] === false && $m2['semaforo'] === 'incumplido');
    $pausaT = [['inicio' => '2026-10-05 11:00:00', 'fin' => '2026-10-06 09:00:00', 'motivo' => 'x']];
    $m3 = SlaService::medirUno($tBase, $evR, [], [], $pausaT, null, $polT, $calT, $d('2026-10-07 08:00'));
    check('SLA: el tiempo en pausa (2 h hábiles) no cuenta y el ticket pasa a cumplir', $m3['seg_pausa'] === 7200 && $m3['seg_solucion'] === 12600 && $m3['cumple_solucion'] === true);
    $tAb = ['estado' => 'En proceso'] + $tBase;
    $evAb = array_slice($evT, 0, 2);
    check('semáforo: abierto a tiempo / por vencer (≥75 %) / vencido / en pausa',
        SlaService::medirUno($tAb, $evAb, [], [], [], null, $polT, $calT, $d('2026-10-05 09:00'))['semaforo'] === 'a_tiempo'
        && SlaService::medirUno($tAb, $evAb, [], [], [], null, $polT, $calT, $d('2026-10-05 11:30'))['semaforo'] === 'por_vencer'
        && SlaService::medirUno($tAb, $evAb, [], [], [], null, $polT, $calT, $d('2026-10-06 09:00'))['semaforo'] === 'vencido'
        && SlaService::medirUno($tAb, $evAb, [], [], [['inicio' => '2026-10-05 09:00:00', 'fin' => null, 'motivo' => 'x']], null, $polT, $calT, $d('2026-10-06 09:00'))['semaforo'] === 'pausa');
    check('SLA: sin cambio de estado, la primera asignación cuenta como respuesta', SlaService::medirUno($tAb, [], [['id_tecnico' => 5, 'fecha_asignado' => '2026-10-05 08:20:00']], [], [], null, $polT, $calT, $d('2026-10-05 09:00'))['seg_respuesta'] === 1200);

    // Disparadores, fila de servicio y medición con la base
    $uF = $pdo->prepare("INSERT INTO users (codigo, correo, password, role, nombre_completo, cargo, estado_activo) VALUES (?, ?, ?, ?, ?, 'Prueba', 1)");
    $uF->execute(['ZTF1', 'zt_fase_sol@test.local', password_hash('x', PASSWORD_DEFAULT), 'solicitante', 'ZT Solicitante Fase']);
    $solF = (int)$pdo->lastInsertId();
    $uF->execute(['ZTF2', 'zt_fase_tec@test.local', password_hash('x', PASSWORD_DEFAULT), 'tecnico', 'ZT Tecnico Fase']);
    $tecF = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO tickets (user_id, nombre_dependencia, ubicacion, equipo, descripcion, categoria) VALUES (?, 'ZT', 'ZT', 'PC', 'ZT-fase ticket', 'RED')")->execute([$solF]);
    $tkF = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE tickets SET estado = 'En proceso', tecnico_id = ? WHERE id = ?")->execute([$tecF, $tkF]);
    $evF = $pdo->query("SELECT estado_anterior, estado_nuevo FROM ticket_eventos WHERE tabla_ticket = 'tickets' AND id_ticket = $tkF ORDER BY id_evento")->fetchAll(PDO::FETCH_ASSOC);
    check('disparadores: el alta y cada cambio de estado quedan en ticket_eventos', count($evF) === 2 && $evF[0]['estado_nuevo'] === 'En espera' && $evF[1]['estado_anterior'] === 'En espera' && $evF[1]['estado_nuevo'] === 'En proceso');
    $pdo->prepare("UPDATE tickets SET descripcion = 'ZT-fase ticket editado' WHERE id = ?")->execute([$tkF]);
    check('disparadores: un cambio que no toca el estado no agrega evento', (int)$pdo->query("SELECT COUNT(*) FROM ticket_eventos WHERE tabla_ticket = 'tickets' AND id_ticket = $tkF")->fetchColumn() === 2);
    $medF = SlaService::medir($pdo, ['tabla' => 'tickets', 'id' => $tkF]);
    check('SLA con la base: crea la fila de servicio (red = prioridad alta), mide y nombra al responsable', count($medF) === 1 && $medF[0]['prioridad_sla'] === 'ALTA' && $medF[0]['responsable'] === 'ZT Tecnico Fase' && $medF[0]['primera_respuesta'] !== null
        && (int)$pdo->query("SELECT COUNT(*) FROM ticket_sla WHERE tabla_ticket = 'tickets' AND id_ticket = $tkF")->fetchColumn() === 1);
    $ajF = SlaService::ajustarPrioridad($pdo, 'tickets', $tkF, 1, 1, $tecF);
    check('SLA: el admin ajusta la prioridad (queda manual) y se rechazan valores inválidos', $ajF['ok'] && SlaService::medir($pdo, ['tabla' => 'tickets', 'id' => $tkF])[0]['prioridad_sla'] === 'CRITICA'
        && SlaService::ajustarPrioridad($pdo, 'tickets', $tkF, 9, 1, $tecF)['ok'] === false && SlaService::ajustarPrioridad($pdo, 'otra', $tkF, 1, 1, $tecF)['ok'] === false);
    $p1 = SlaService::alternarPausa($pdo, 'tickets', $tkF, '', $tecF);
    $p2 = SlaService::alternarPausa($pdo, 'tickets', $tkF, 'ZT el usuario no está', $tecF);
    $enPausaF = SlaService::medir($pdo, ['tabla' => 'tickets', 'id' => $tkF])[0]['semaforo'];
    $p3 = SlaService::alternarPausa($pdo, 'tickets', $tkF, '', $tecF);
    check('SLA: pausar exige motivo, deja el semáforo «en pausa» y se reanuda', !$p1['ok'] && $p2['ok'] && $enPausaF === 'pausa' && $p3['ok'] && SlaRepository::pausaAbierta($pdo, 'tickets', $tkF) === null);
    $polMal = SlaService::guardarPoliticas($pdo, ['INCIDENTE' => ['CRITICA' => ['respuesta' => 5, 'solucion' => 1]]], $tecF);
    check('SLA: no se guardan tiempos incompletos ni una respuesta mayor que la solución', $polMal['ok'] === false);
    $polOk = []; foreach (SlaService::TIPOS as $tp => $_) foreach (SlaService::PRIORIDADES as $pr => $_n) $polOk[$tp][$pr] = ['respuesta' => '1,5', 'solucion' => '10'];
    check('SLA: guarda la tabla completa de tiempos (acepta coma decimal)', SlaService::guardarPoliticas($pdo, $polOk, $tecF)['ok'] && SlaRepository::politicas($pdo)['REQUERIMIENTO']['BAJA']['respuesta'] === 1.5);
    $hor = SlaService::guardarHorario($pdo, ['dia' => [1 => ['activo' => 1, 'inicio' => '07:00', 'fin' => '17:00']], 'umbral' => 80, 'meta' => 95], $tecF);
    ConfigSistema::reiniciar();
    check('SLA: guarda el horario de atención y los umbrales; rechaza un cierre antes de la apertura',
        $hor['ok'] && CalendarioHabil::semanaDeConfig(ConfigSistema::texto($pdo, 'horario_semana'))[1] === ['07:00', '17:00'] && ConfigSistema::entero($pdo, 'sla_umbral_aviso') === 80
        && SlaService::guardarHorario($pdo, ['dia' => [2 => ['activo' => 1, 'inicio' => '17:00', 'fin' => '07:00']], 'umbral' => 80, 'meta' => 95], $tecF)['ok'] === false);
    $cfF = SlaService::cargarFestivosColombia($pdo, 2027, $tecF);
    check('festivos: carga los de Colombia de un año y se pueden quitar', $cfF['ok'] && count(SlaRepository::festivos($pdo, 2027)) === 18 && SlaService::quitarFestivo($pdo, '2027-01-01', $tecF)['ok'] && count(SlaRepository::festivos($pdo, 2027)) === 17);
    $indF = IndicadoresService::calcular($pdo, IndicadoresService::filtros(['desde' => date('Y-m-d'), 'hasta' => date('Y-m-d')]));
    check('indicadores: cuentan los tickets del periodo, las prioridades y arman el resumen', $indF['kpi']['recibidos'] >= 1 && isset($indF['prioridades']['CRITICA']) && count(IndicadoresService::resumen($indF)) === 13);
    $csvF = IndicadoresService::csv($indF);
    check('indicadores: el CSV para Excel lleva BOM UTF-8, punto y coma y el detalle por ticket', strpos($csvF, "\xEF\xBB\xBF") === 0 && strpos($csvF, '"Cumplimiento SLA de solución";') !== false && strpos($csvF, 'T-' . $tkF . ';') !== false);
    check('indicadores: filtros con fechas inválidas vuelven al mes actual y se ordenan', IndicadoresService::filtros(['desde' => 'x'])['desde'] === date('Y-m-01') && IndicadoresService::filtros(['desde' => '2026-05-10', 'hasta' => '2026-05-01'])['desde'] === '2026-05-01');
    $pdfF = IndicadoresPdf::generar($indF, 'ZT')->Output('S');
    check('indicadores: genera el PDF', strpos($pdfF, '%PDF') === 0);
    // Encuesta de satisfacción
    check('encuesta: no se califica un ticket abierto ni uno ajeno', EncuestaService::responderDesdePanel($pdo, $solF, 'tickets', $tkF, 5, '')['ok'] === false
        && EncuestaService::responderDesdePanel($pdo, $tecF, 'tickets', $tkF, 5, '')['mensaje'] === 'Ese ticket no es tuyo.');
    $pdo->prepare("UPDATE tickets SET estado = 'Cerrado' WHERE id = ?")->execute([$tkF]);
    $enc1 = EncuestaService::responderDesdePanel($pdo, $solF, 'tickets', $tkF, 4, 'ZT bien');
    check('encuesta: el dueño califica una vez (1 a 5) y no puede repetir', $enc1['ok'] && EncuestaService::responderDesdePanel($pdo, $solF, 'tickets', $tkF, 5, '')['ok'] === false
        && EncuestaService::respuesta($pdo, 'tickets', $tkF)['calificacion'] == 4 && EncuestaService::responderDesdePanel($pdo, $solF, 'tickets', $tkF, 9, '')['ok'] === false);
    $medCerr = SlaService::medir($pdo, ['tabla' => 'tickets', 'id' => $tkF])[0];
    check('SLA: al cerrarse se registra la solución y la encuesta queda ligada al ticket', $medCerr['resuelto'] && $medCerr['solucion'] !== null && (int)$medCerr['encuesta']['calificacion'] === 4);
    // Política de contraseñas
    check('contraseñas: mínimo 12, sin comunes ni variaciones, sin el nombre/correo, sin repeticiones',
        PoliticaClave::validar('corta') !== null && PoliticaClave::validar('Password1234') !== null && PoliticaClave::validar('Ufps2026****') !== null
        && PoliticaClave::validar('contraseña123') !== null && PoliticaClave::validar('aaaaaaaaaaaaaa') !== null
        && PoliticaClave::validar('jimenezMiClave99', ['nombre' => 'Pedro Jimenez']) !== null && PoliticaClave::validar('zt_fase_sol-clave', ['correo' => 'zt_fase_sol@test.local']) !== null
        && PoliticaClave::validar('cafe con pan a las 7') === null && PoliticaClave::validar('Torre-Norte-Bodega-14') === null);
    // Verificación en dos pasos
    $secF = Totp::generarSecreto();
    check('2 pasos: aplica solo a admin y técnico; inscripción con un código válido entrega 8 códigos de recuperación',
        MfaService::aplica('tecnico') && !MfaService::aplica('profesor') && MfaService::confirmarInscripcion($pdo, $tecF, $secF, '000000') === null
        && count((array)($codF = MfaService::confirmarInscripcion($pdo, $tecF, $secF, Totp::codigo($secF, intdiv(time(), 30))))) === 8 && MfaService::estado($pdo, $tecF)['activo']);
    check('2 pasos: el mismo código de la app no sirve dos veces (repetición)', MfaService::verificar($pdo, $tecF, Totp::codigo($secF, intdiv(time(), 30))) === null);
    check('2 pasos: un código de recuperación sirve una sola vez', MfaService::verificar($pdo, $tecF, strtolower($codF[0])) === 'recuperacion' && MfaService::verificar($pdo, $tecF, $codF[0]) === null && MfaService::estado($pdo, $tecF)['restantes'] === 7);
    check('2 pasos: con la cuenta activa el ingreso pide el código; sin activar y obligatorio, la inscripción', MfaService::pasoPendiente($pdo, ['id' => $tecF, 'role' => 'tecnico']) === 'verificar'
        && MfaService::pasoPendiente($pdo, ['id' => $solF, 'role' => 'solicitante']) === '');
    check('2 pasos: el secreto se guarda cifrado', strpos((string)$pdo->query("SELECT mfa_secreto FROM users WHERE id = $tecF")->fetchColumn(), 'enc:v1:') === 0);
    check('2 pasos: el admin la reinicia', MfaService::reiniciar($pdo, $tecF, $tecF)['ok'] && !MfaService::estado($pdo, $tecF)['activo']);
    ConfigSistema::guardar($pdo, 'seg_mfa_obligatorio', '1', null);
    check('2 pasos: obligatoria → la cuenta sin activar debe inscribirse al entrar', MfaService::pasoPendiente($pdo, ['id' => $tecF, 'role' => 'tecnico']) === 'inscribir');
    ConfigSistema::guardar($pdo, 'seg_mfa_obligatorio', '0', null);
    // Bitácora de accesos encadenada
    BitacoraAccesos::registrar($pdo, 'LOGIN_FALLIDO', $solF, 'zt_fase_sol@test.local', 'ZT prueba 1');
    BitacoraAccesos::registrar($pdo, 'LOGIN_OK', $solF, 'zt_fase_sol@test.local', 'ZT prueba 2');
    $vb1 = BitacoraAccesos::verificar($pdo);
    $idUlt = (int)$pdo->query("SELECT MAX(id_bitacora) FROM bitacora_accesos")->fetchColumn();
    $pdo->exec("UPDATE bitacora_accesos SET detalle = 'alterado' WHERE id_bitacora = " . ($idUlt - 1));
    $vb2 = BitacoraAccesos::verificar($pdo);
    $pdo->exec("UPDATE bitacora_accesos SET detalle = 'ZT prueba 1' WHERE id_bitacora = " . ($idUlt - 1));
    check('bitácora: la cadena de huellas es íntegra y detecta un registro alterado', $vb1['ok'] && !$vb2['ok'] && $vb2['falla'] === $idUlt - 1 && BitacoraAccesos::verificar($pdo)['ok']);
    $lstB = BitacoraAccesos::listar($pdo, BitacoraAccesos::filtros(['q' => 'zt_fase_sol', 'evento' => 'LOGIN_FALLIDO']));
    check('bitácora: filtra por texto y evento, y exporta CSV', $lstB['total'] === 1 && strpos(BitacoraAccesos::csv($pdo, BitacoraAccesos::filtros(['q' => 'zt_fase_sol'])), 'zt_fase_sol@test.local') !== false);
    check('bitácora: el inicio de sesión fallido queda registrado por LoginService', LoginService::intentar($pdo, 'ZTF1', 'zt_fase_sol@test.local', 'mala', '127.0.0.1')['ok'] === false
        && (int)$pdo->query("SELECT COUNT(*) FROM bitacora_accesos WHERE evento = 'LOGIN_FALLIDO' AND id_usuario = $solF AND detalle = 'contraseña incorrecta'")->fetchColumn() === 1);
    // Antispam del QR
    $sesQ = [];
    $preg = AntiSpamQr::nuevoReto($sesQ);
    preg_match('/(\d+) \+ (\d+)/', $preg, $mq);
    check('QR: la pregunta de verificación se responde una sola vez', AntiSpamQr::retoCorrecto($sesQ, (string)((int)$mq[1] + (int)$mq[2])) && !AntiSpamQr::retoCorrecto($sesQ, (string)((int)$mq[1] + (int)$mq[2])));
    AntiSpamQr::nuevoReto($sesQ);
    check('QR: el campo trampa y la falta de aceptación de privacidad bloquean el envío', AntiSpamQr::revisar($pdo, ['sitio_web' => 'x', 'acepto_privacidad' => '1'], $sesQ, '10.9.9.9', 'ZT') !== null
        && strpos((string)AntiSpamQr::revisar($pdo, [], $sesQ, '10.9.9.9', 'ZT'), 'aviso de privacidad') !== false);
    $pdo->prepare("INSERT INTO tickets (descripcion, origen, ip_origen) VALUES ('ZT-qr spam', 'QR_ANONIMO', '10.9.9.8')")->execute();
    ConfigSistema::guardar($pdo, 'seg_qr_max_por_hora', '1', null);
    check('QR: el límite por IP y por hora frena el siguiente reporte', strpos((string)AntiSpamQr::revisar($pdo, ['acepto_privacidad' => '1'], $sesQ, '10.9.9.8', 'ZT'), 'muchos reportes') !== false);
    ConfigSistema::guardar($pdo, 'seg_qr_max_por_hora', '5', null);
    // Retención de IP, configuración e incidentes
    $pdo->exec("UPDATE tickets SET fecha_creacion = NOW() - INTERVAL 400 DAY WHERE descripcion = 'ZT-qr spam'");
    check('retención: la IP de los reportes anónimos antiguos se borra', SeguridadService::aplicarRetencionIp($pdo) >= 1 && $pdo->query("SELECT ip_origen FROM tickets WHERE descripcion = 'ZT-qr spam'")->fetchColumn() === null);
    $cfgBase = ['seg_retencion_bitacora_dias' => '365', 'seg_retencion_ip_dias' => '180', 'seg_qr_max_por_hora' => '5', 'seg_respaldos_conservar' => '10', 'priv_responsable' => 'UFPS', 'priv_correo' => ''];
    check('seguridad: HTTPS/HSTS solo se activan entrando por HTTPS y los rangos se validan',
        SeguridadService::guardarConfiguracion($pdo, $cfgBase + ['seg_forzar_https' => '1'], $tecF, false)['ok'] === false
        && SeguridadService::guardarConfiguracion($pdo, ['seg_retencion_bitacora_dias' => '5'] + $cfgBase, $tecF, true)['ok'] === false
        && SeguridadService::guardarConfiguracion($pdo, $cfgBase + ['seg_forzar_https' => '1', 'seg_hsts' => '1'], $tecF, true)['ok'] === true
        && ConfigSistema::activo($pdo, 'seg_hsts'));
    SeguridadService::guardarConfiguracion($pdo, $cfgBase, $tecF, true);
    check('seguridad: el diagnóstico revisa HTTPS, usuario de BD, 2 pasos, respaldos y bitácora', count(array_intersect(array_column(SeguridadService::diagnostico($pdo), 'control'),
        ['Conexión cifrada (HTTPS)', 'Usuario de la base de datos', 'Verificación en dos pasos', 'Respaldos', 'Bitácora de accesos'])) === 5);
    $pdo->prepare("INSERT INTO tickets (user_id, descripcion, categoria) VALUES (?, 'ZT-fase alguien entró a mi correo', 'SEGURIDAD')")->execute([$solF]);
    $tkSeg = (int)$pdo->lastInsertId();
    IncidenteService::desdeTickets($pdo);
    $incF = array_values(array_filter(IncidenteService::listar($pdo), static fn($i) => (int)$i['id_ticket'] === $tkSeg));
    check('incidentes: un ticket «Incidente de seguridad» abre su registro y es prioridad crítica', count($incF) === 1 && SlaService::medir($pdo, ['tabla' => 'tickets', 'id' => $tkSeg])[0]['prioridad_sla'] === 'CRITICA');
    $idInc = (int)$incF[0]['id_incidente'];
    $baseInc = ['titulo' => 'ZT incidente', 'tipo' => 'PHISHING', 'severidad' => 'ALTA'];
    check('incidentes: cerrar exige contención, causa y lección; con ellas queda cerrado con fecha',
        IncidenteService::actualizar($pdo, $idInc, $baseInc + ['estado' => 'CERRADO'], $tecF)['ok'] === false
        && IncidenteService::actualizar($pdo, $idInc, $baseInc + ['estado' => 'CERRADO', 'contencion' => 'c', 'causa' => 'k', 'leccion' => 'l', 'afecta_confidencialidad' => '1'], $tecF)['ok'] === true
        && IncidenteService::listar($pdo, 'CERRADO') && $pdo->query("SELECT fecha_cierre FROM incidentes_seguridad WHERE id_incidente = $idInc")->fetchColumn() !== null);
    check('incidentes: el admin registra uno a mano', IncidenteService::registrar($pdo, $baseInc, $tecF)['ok'] && IncidenteService::registrar($pdo, ['titulo' => ''] + $baseInc, $tecF)['ok'] === false);
    // Respaldos cifrados
    putenv('RESPALDOS_CARPETA=' . sys_get_temp_dir() . '/zt_respaldos_' . getmypid());
    $rsp = RespaldoService::generar($pdo, 'frase-de-prueba-ZT-2026', $tecF);
    $rspCorto = RespaldoService::generar($pdo, 'corta', $tecF);
    $filaR = RespaldoService::ultimo($pdo);
    $contenido = (string)file_get_contents(RespaldoService::ruta($filaR));
    check('respaldos: se genera cifrado (sin texto SQL legible) y se rechaza una frase corta', $rsp['ok'] && !$rspCorto['ok'] && strpos($contenido, 'UFPSBK1') === 0 && strpos($contenido, 'CREATE TABLE') === false && strpos($contenido, 'zt_fase_sol') === false);
    check('respaldos: con otra frase no se descifra', RespaldoService::verificar($pdo, (int)$filaR['id_respaldo'], 'otra-frase-cualquiera', $tecF)['ok'] === false);
    $vr = RespaldoService::verificar($pdo, (int)$filaR['id_respaldo'], 'frase-de-prueba-ZT-2026', $tecF);
    check('respaldos: con la frase correcta se descifra y la restauración de prueba coincide (' . $vr['mensaje'] . ')', $vr['ok'] === true);
    check('respaldos: eliminar borra archivo y registro', RespaldoService::eliminar($pdo, (int)$filaR['id_respaldo'], $tecF)['ok'] && !is_file(RespaldoService::ruta($filaR)));
    putenv('RESPALDOS_CARPETA');

    // ==================== Notificaciones: destino seguro por rol · Horarios: vista según rol ====================
    echo "\n[Notificaciones por rol y horarios por rol]\n";
    check('avisos: una página antigua se corrige (panel_becario → mantenimientos, pdf_ticket → ticket_pdf)', NotificationService::destino('panel_becario.php', 'beca_trabajo') === 'mantenimientos_programados.php'
        && NotificationService::destino('pdf_ticket.php?id=5', 'solicitante') === 'ticket_pdf.php?id=5');
    check('avisos: página que no existe, vacía o externa → inicio del rol', NotificationService::destino('no_existe.php', 'admin') === 'tickets.php' && NotificationService::destino(null, 'profesor') === 'tickets.php'
        && NotificationService::destino('https://malo.com/x', 'admin') === 'tickets.php' && NotificationService::destino('//malo.com', 'admin') === 'tickets.php');
    check('avisos: una página de otro rol no da error (técnico con aviso de sla.php → su inicio); el admin sí la abre', NotificationService::destino('sla.php', 'tecnico') === 'tickets.php'
        && NotificationService::destino('seguridad.php?vista=incidentes', 'admin') === 'seguridad.php?vista=incidentes' && NotificationService::destino('actividades.php#act-3', 'secretaria') === 'actividades.php#act-3');
    check('avisos: «hace cuánto» legible', NotificationService::haceCuanto(date('Y-m-d H:i:s', time() - 30)) === 'hace un momento' && NotificationService::haceCuanto(date('Y-m-d H:i:s', time() - 600)) === 'hace 10 min');
    check('horarios: cada rol ve su vista (salas, profesor, beca de mantenimiento, beca de secretaría/redes, sin acceso)',
        HorarioCalendario::modoVista('admin', true, null) === 'salas' && HorarioCalendario::modoVista('profesor', false, null) === 'profesor'
        && HorarioCalendario::modoVista('beca_trabajo', false, null) === 'beca_mantenimiento' && HorarioCalendario::modoVista('beca_trabajo', false, 'secretaria') === 'beca_personal'
        && HorarioCalendario::modoVista('beca_trabajo', false, 'redes') === 'beca_personal' && HorarioCalendario::modoVista('secretaria', false, null) === 'sin_acceso');
    check('horarios: la secretaria ve todas las salas solo con el permiso', HorarioService::permisos($pdo, 'secretaria', 0)['puedeVerTodo'] === false && HorarioService::permisos($pdo, 'tecnico', 0)['puedeVerTodo'] === true);

} catch (Throwable $e) {
    $fail++;
    echo "  ERROR: " . $e->getMessage() . " (" . basename($e->getFile()) . ":" . $e->getLine() . ")\n";
}

$pdo->rollBack();
echo "\nResultado: $ok PASS, $fail FAIL (datos de prueba revertidos)\n";
exit($fail === 0 ? 0 : 1);
