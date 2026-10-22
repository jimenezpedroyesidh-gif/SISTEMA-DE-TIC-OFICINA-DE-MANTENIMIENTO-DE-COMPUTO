<?php
/**
 * Pruebas HTTP del módulo Inventario: gestionar_inventario.php, ficha_componente.php y ficha_equipo.php (se
 * incluye desde run_http_tests.php, que aporta $pdo, $U, $C, $adm, $tec, $CLAVE, cliente(), t() y seccion()).
 */
seccion('Inventario: gestionar_inventario, ficha_componente, ficha_equipo');

$ci = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); })();
$fi = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(); })();
$beca = $C['beca_trabajo'];
$uidI = fn(string $r) => (int)$U[$r]['id'];

// Datos: sala con dependencia propia, 2 equipos, piezas en distintos estados
$dep = 'ZT-DEP-INV';
$pdo->prepare("INSERT INTO salas (nombre_sala, abreviatura, dependencia, codigo_qr_token) VALUES ('ZT-SALA-INV', 'ZTI', ?, ?)")->execute([$dep, 'zt' . bin2hex(random_bytes(8))]);
$sInv = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO salas (nombre_sala, abreviatura, dependencia, codigo_qr_token) VALUES ('ZT-SALA-INV2', 'ZI2', NULL, ?)")->execute(['zt' . bin2hex(random_bytes(8))]);
$sInv2 = (int)$pdo->lastInsertId();
$mkE = function (int $sala, string $cod) use ($pdo) { $pdo->prepare("INSERT INTO equipos (id_sala, codigo, descripcion) VALUES (?,?,?)")->execute([$sala, $cod, 'ZT']); return (int)$pdo->lastInsertId(); };
$eqA = $mkE($sInv, 'ZT-INV-EQA');
$eqB = $mkE($sInv2, 'ZT-INV-EQB');
$mkC = function (string $tipo, string $ser, string $estado, ?int $eq, ?int $salaDir = null) use ($pdo) {
    $pdo->prepare("INSERT INTO componentes (tipo, serial, codigo_inventario, estado, id_equipo_actual, id_sala_directa, marca) VALUES (?, ?, ?, ?, ?, ?, 'ZT<b>marca</b>')")->execute([$tipo, $ser, $ser, $estado, $eq, $salaDir]);
    return (int)$pdo->lastInsertId();
};
$cTorre = $mkC('torre', 'ZT-INV-TORRE', 'ACTIVO', $eqA);
$cLibre = $mkC('monitor', 'ZT-INV-LIBRE', 'ACTIVO', null);
$cRev = $mkC('mouse', 'ZT-INV-REV', 'EN_REVISION', null);
$cDef = $mkC('teclado', 'ZT-INV-DEF', 'DEFECTUOSO', null);
$cBaja = $mkC('parlante', 'ZT-INV-BAJA', 'DE_BAJA', null);
$cTorreLibre = $mkC('torre', 'ZT-INV-TORRE2', 'ACTIVO', null);
$cInfra = $mkC('aire_acondicionado', 'ZT-INV-AIRE', 'ACTIVO', null, $sInv);

// ── gestionar_inventario: acceso y listado
t('Inventario: beca/profesor/solicitante/secretaría no entran a gestionar_inventario', $beca->get('gestionar_inventario.php')->denied() && $C['profesor']->get('gestionar_inventario.php')->denied() && $C['solicitante']->get('gestionar_inventario.php')->denied() && $C['secretaria']->get('gestionar_inventario.php')->denied());
$r = $tec->get('gestionar_inventario.php');
t('gestionar_inventario: el técnico ve el formulario de registro', $r->status === 200 && $r->contains('name="crear_componente"') && $r->contains('name="id_equipo_inicial"'));
t('gestionar_inventario: el admin también ve el formulario', $adm->get('gestionar_inventario.php')->contains('name="crear_componente"'));
$r = $tec->get('gestionar_inventario.php?q=' . urlencode('ZT-INV-LIBRE'));
t('gestionar_inventario: búsqueda por serial encuentra la pieza con enlace a su ficha', $r->contains('ficha_componente.php?id=' . $cLibre));
$r = $tec->get('gestionar_inventario.php?q=' . urlencode('ZT-INV-NOEXISTE'));
t('gestionar_inventario: búsqueda sin resultados no lista piezas', !$r->contains('ficha_componente.php?id=' . $cLibre));
$r = $tec->get('gestionar_inventario.php?q=' . urlencode('ZT-INV-TORRE'));
t('gestionar_inventario: la marca con HTML se escapa (XSS)', !$r->contains('<b>marca</b>') && $r->contains('ZT&lt;b&gt;marca&lt;/b&gt;'));
$r = $tec->get('gestionar_inventario.php?q=' . urlencode('"><script>alert(1)</script>'));
t('gestionar_inventario: el término buscado no se refleja sin escapar', !$r->contains('<script>alert(1)</script>'));

// ── crear_componente
$nuevo = ['crear_componente' => '1', 'tipo' => 'monitor', 'serial' => 'ZT-INV-N1', 'codigo_inventario' => 'ZT-INV-N1', 'marca' => 'ZTMarca', 'modelo' => 'M1', 'descripcion' => 'ZT desc', 'id_equipo_inicial' => ''];
$r = $tec->post('gestionar_inventario.php', $nuevo);
$idN1 = $ci("SELECT id_componente FROM componentes WHERE serial='ZT-INV-N1'");
t('crear_componente: el técnico registra una pieza nueva (mensaje y estado ACTIVO)', $idN1 > 0 && $r->contains('ZT-INV-N1 registrado correctamente') && ($fi("SELECT estado, id_equipo_actual, marca, modelo FROM componentes WHERE id_componente=?", [$idN1])['estado'] ?? '') === 'ACTIVO');
t('crear_componente: deja historial REGISTRO y auditoría CREAR', $ci("SELECT COUNT(*) FROM historial_componente WHERE id_componente=? AND tipo_evento='REGISTRO' AND observacion='Alta inicial de componente'", [$idN1]) === 1 && $ci("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='componentes' AND id_registro=? AND accion='CREAR'", [$idN1]) === 1);
$r = $tec->post('gestionar_inventario.php', $nuevo);
t('crear_componente: serial/código duplicado → mensaje y sin duplicar', $r->contains('No se pudo registrar. ¿El serial o código de inventario ya existen?') && $ci("SELECT COUNT(*) FROM componentes WHERE serial='ZT-INV-N1'") === 1);
$r = $tec->post('gestionar_inventario.php', array_merge($nuevo, ['serial' => 'ZT-INV-N2', 'codigo_inventario' => 'ZT-INV-N2', 'tipo' => 'xx']));
t('crear_componente: tipo inválido se rechaza', $ci("SELECT COUNT(*) FROM componentes WHERE serial='ZT-INV-N2'") === 0 && $r->contains('Tipo, serial y código de inventario son obligatorios.'));
$r = $tec->post('gestionar_inventario.php', array_merge($nuevo, ['serial' => '', 'codigo_inventario' => 'ZT-INV-N3']));
t('crear_componente: serial vacío se rechaza', $ci("SELECT COUNT(*) FROM componentes WHERE codigo_inventario='ZT-INV-N3'") === 0 && $r->contains('Tipo, serial y código de inventario son obligatorios.'));
$r = $tec->post('gestionar_inventario.php', array_merge($nuevo, ['serial' => 'ZT-INV-N4', 'codigo_inventario' => 'ZT-INV-N4', 'id_equipo_inicial' => $eqA]));
$idN4 = $ci("SELECT id_componente FROM componentes WHERE serial='ZT-INV-N4'");
t('crear_componente: con equipo inicial queda asignado a ese equipo', $idN4 > 0 && $ci("SELECT id_equipo_actual FROM componentes WHERE id_componente=?", [$idN4]) === $eqA);
$adm->post('gestionar_inventario.php', array_merge($nuevo, ['serial' => 'ZT-INV-N5', 'codigo_inventario' => 'ZT-INV-N5']));
t('crear_componente: el admin también puede registrar', $ci("SELECT COUNT(*) FROM componentes WHERE serial='ZT-INV-N5'") === 1);
$tec->postRaw('gestionar_inventario.php', array_merge($nuevo, ['serial' => 'ZT-INV-N6', 'codigo_inventario' => 'ZT-INV-N6']));
t('crear_componente: sin CSRF no registra', $ci("SELECT COUNT(*) FROM componentes WHERE serial='ZT-INV-N6'") === 0);
$beca->postRaw('gestionar_inventario.php', array_merge($nuevo, ['serial' => 'ZT-INV-N7', 'codigo_inventario' => 'ZT-INV-N7']));
t('crear_componente: el beca no puede registrar', $ci("SELECT COUNT(*) FROM componentes WHERE serial='ZT-INV-N7'") === 0);

// ── editar_componente (handler sin formulario en la página, se conserva)
$r = $tec->post('gestionar_inventario.php', ['editar_componente' => '1', 'id_componente' => $idN1, 'marca' => 'ZTEdit', 'modelo' => 'ME', 'descripcion' => 'ZT editado']);
t('editar_componente: actualiza marca/modelo/descripcion y audita EDITAR', $r->contains('Componente actualizado.') && ($fi("SELECT marca, modelo, descripcion FROM componentes WHERE id_componente=?", [$idN1])['marca'] ?? '') === 'ZTEdit' && $ci("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='componentes' AND id_registro=? AND accion='EDITAR'", [$idN1]) === 1);

// ── ficha_componente: acceso y estados
t('ficha_componente: profesor/solicitante/secretaría no entran', $C['profesor']->get("ficha_componente.php?id=$cLibre")->denied() && $C['solicitante']->get("ficha_componente.php?id=$cLibre")->denied() && $C['secretaria']->get("ficha_componente.php?id=$cLibre")->denied());
foreach (['admin' => $adm, 'tecnico' => $tec, 'beca' => $beca] as $n => $c) {
    $r = $c->get("ficha_componente.php?id=$cLibre");
    t("ficha_componente: $n ve la ficha", $r->status === 200 && $r->contains('ZT-INV-LIBRE'));
}
$r = $tec->get('ficha_componente.php');
t('ficha_componente: sin id redirige a gestionar_inventario.php', $r->redirects() && strpos($r->location(), 'gestionar_inventario.php') !== false);
$r = $tec->get('ficha_componente.php?id=999999999');
t('ficha_componente: id inexistente redirige', $r->redirects());
$r = $tec->get('ficha_componente.php?id=' . $cLibre . '%27%20OR%201=1');
t('ficha_componente: id con SQL se castea a entero (sin 5xx)', $r->status < 500);
$r = $tec->get("ficha_componente.php?id=$cTorre");
t('ficha_componente: pieza asignada muestra su equipo y la línea de tiempo', $r->contains('ZT-INV-EQA') && $r->contains('data-qr-accion'));
t('ficha_componente: la marca con HTML se escapa', !$r->contains('<b>marca</b>'));
t('ficha_componente: pieza asignada no ofrece reinstalar', !$r->contains('name="reinstalar_componente"'));
$idEqTorre = (int)$pdo->query("SELECT id_equipo_actual FROM componentes WHERE id_componente=" . (int)$cTorre)->fetchColumn();
t('ficha_componente: botón para volver al equipo donde está la pieza (y a su sala)', $r->contains('href="ficha_equipo.php?id=' . $idEqTorre . '"') && $r->contains('← Volver al equipo ZT-INV-EQA') && $r->contains('#ficha-sala-'));
t('ficha_componente: diseño responsivo (cabecera, datos en rejilla y QR al lado)', $r->contains('class="panel-box comp-head"') && $r->contains('class="comp-datos"') && $r->contains('class="comp-aside"'));
$rl = $tec->get("ficha_componente.php?id=$cLibre");
t('ficha_componente: pieza en bodega ofrece volver a bodega', $rl->contains('← Volver a bodega'));
$rb = $C['beca_trabajo']->get("ficha_componente.php?id=$cTorre");
t('ficha_componente: la beca vuelve al equipo, pero no ve enlaces al listado por salón', $rb->contains('← Volver al equipo') && !$rb->contains('#ficha-sala-'));
$r = $tec->get("ficha_componente.php?id=$cLibre");
t('ficha_componente: pieza libre ACTIVA ofrece reinstalar al técnico (con salas)', $r->contains('name="reinstalar_componente"') && $r->contains('ZT-SALA-INV'));
t('ficha_componente: el beca ve "Avisar" y no el formulario de reinstalar', ($b = $beca->get("ficha_componente.php?id=$cLibre"))->contains('name="avisar_lista_reinstalar"') && !$b->contains('name="reinstalar_componente"'));
t('ficha_componente: pieza DEFECTUOSA no ofrece reinstalar', !$tec->get("ficha_componente.php?id=$cDef")->contains('name="reinstalar_componente"'));
$r = $adm->get("ficha_componente.php?id=$cBaja");
t('ficha_componente: DE_BAJA el admin la ve completa', $r->status === 200 && $r->contains('ZT-INV-BAJA') && $r->contains('data-qr-accion'));
foreach (['tecnico' => $tec, 'beca' => $beca] as $n => $c) {
    $r = $c->get("ficha_componente.php?id=$cBaja");
    t("ficha_componente: DE_BAJA $n ve solo la página mínima", $r->status === 200 && $r->contains('dado de baja definitivamente') && !$r->contains('data-qr-accion'));
}

// ── reinstalar
$rein = fn(array $o = []) => array_merge(['reinstalar_componente' => '1', 'id_equipo_destino' => $eqA, 'motivo_reinstalacion' => 'ZT-motivo'], $o);
$tec->postRaw("ficha_componente.php?id=$cLibre", $rein());
t('reinstalar: sin CSRF no cambia nada', $ci("SELECT id_equipo_actual FROM componentes WHERE id_componente=?", [$cLibre]) === 0);
$beca->post("ficha_componente.php?id=$cLibre", $rein(), "ficha_componente.php?id=$cLibre");
$r = $beca->get("ficha_componente.php?id=$cLibre");
t('reinstalar: el beca no puede (la pieza sigue sin equipo)', $ci("SELECT id_equipo_actual FROM componentes WHERE id_componente=?", [$cLibre]) === 0);
$r = $tec->post("ficha_componente.php?id=$cLibre", $rein(['id_equipo_destino' => '']));
t('reinstalar: sin equipo → "Selecciona a qué equipo se reinstala."', $r->contains('Selecciona a qué equipo se reinstala.'));
$r = $tec->post("ficha_componente.php?id=$cLibre", $rein(['id_equipo_destino' => 999999999]));
t('reinstalar: equipo inexistente → "El equipo seleccionado no existe."', $r->contains('El equipo seleccionado no existe.') && $ci("SELECT id_equipo_actual FROM componentes WHERE id_componente=?", [$cLibre]) === 0);
$r = $tec->post("ficha_componente.php?id=$cDef", $rein());
t('reinstalar: pieza DEFECTUOSA se rechaza', $r->contains('Este componente está marcado como DEFECTUOSO') && $ci("SELECT id_equipo_actual FROM componentes WHERE id_componente=?", [$cDef]) === 0);
$r = $tec->post("ficha_componente.php?id=$cTorre", $rein());
t('reinstalar: pieza ya asignada se rechaza', $ci("SELECT id_equipo_actual FROM componentes WHERE id_componente=?", [$cTorre]) === $eqA && $r->status === 200 && !$r->contains('Componente reinstalado'));
$r = $tec->post("ficha_componente.php?id=$cLibre", $rein());
t('reinstalar: el técnico reinstala (mensaje, estado ACTIVO, equipo)', $r->contains('Componente reinstalado en ZT-INV-EQA.') && $ci("SELECT id_equipo_actual FROM componentes WHERE id_componente=?", [$cLibre]) === $eqA);
t('reinstalar: deja historial ASIGNACION con el motivo y auditoría EDITAR', $ci("SELECT COUNT(*) FROM historial_componente WHERE id_componente=? AND tipo_evento='ASIGNACION' AND motivo='ZT-motivo' AND observacion='Reinstalado manualmente desde la ficha del componente'", [$cLibre]) === 1 && $ci("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='componentes' AND id_registro=? AND accion='EDITAR'", [$cLibre]) === 1);
$pdo->exec("UPDATE equipos SET operativo=0 WHERE id_equipo=$eqB");
$tec->post("ficha_componente.php?id=$cTorreLibre", $rein(['id_equipo_destino' => $eqB]));
t('reinstalar: una torre deja el equipo operativo=1', $ci("SELECT operativo FROM equipos WHERE id_equipo=?", [$eqB]) === 1);
$pdo->exec("UPDATE equipos SET operativo=0 WHERE id_equipo=$eqA");
$tec->post("ficha_componente.php?id=$cRev", $rein());
t('reinstalar: una pieza que no es torre NO toca operativo', $ci("SELECT id_equipo_actual FROM componentes WHERE id_componente=?", [$cRev]) === $eqA && $ci("SELECT operativo FROM equipos WHERE id_equipo=?", [$eqA]) === 0);

// ── avisar
$cAv = $mkC('monitor', 'ZT-INV-AVISO', 'ACTIVO', null);
$antes = $ci("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND mensaje LIKE '%ZT-INV-AVISO%'", [$uidI('tecnico')]);
$r = $beca->post("ficha_componente.php?id=$cAv", ['avisar_lista_reinstalar' => '1']);
t('avisar: el beca avisa y se notifica al técnico', $r->contains('Se avisó a los técnicos que esta pieza está lista para reinstalar.') && $ci("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=? AND mensaje LIKE '%ZT-INV-AVISO%'", [$uidI('tecnico')]) === $antes + 1);

// ── cambiar_estado_infra
$est = fn(array $o = []) => array_merge(['cambiar_estado_infra' => '1', 'nuevo_estado_infra' => 'EN_REVISION', 'observacion_estado_infra' => 'ZT-obs'], $o);
$r = $tec->get("ficha_componente.php?id=$cInfra");
t('infra: el técnico ve el formulario de cambio de estado', $r->contains('name="nuevo_estado_infra"'));
t('infra: el admin y el beca no lo ven', !$adm->get("ficha_componente.php?id=$cInfra")->contains('name="nuevo_estado_infra"') && !$beca->get("ficha_componente.php?id=$cInfra")->contains('name="nuevo_estado_infra"'));
$adm->post("ficha_componente.php?id=$cInfra", $est(['nuevo_estado_infra' => 'DEFECTUOSO']));
t('infra: el admin no puede cambiar el estado', $ci("SELECT COUNT(*) FROM componentes WHERE id_componente=? AND estado='ACTIVO'", [$cInfra]) === 1);
$r = $tec->post("ficha_componente.php?id=$cInfra", $est(['nuevo_estado_infra' => 'DE_BAJA']));
t('infra: estado inválido → "Selecciona un estado válido."', $r->contains('Selecciona un estado válido.') && $ci("SELECT COUNT(*) FROM componentes WHERE id_componente=? AND estado='ACTIVO'", [$cInfra]) === 1);
$r = $tec->post("ficha_componente.php?id=$cInfra", $est(['nuevo_estado_infra' => 'ACTIVO']));
t('infra: mismo estado → "Ese ya es el estado actual."', $r->contains('Ese ya es el estado actual.'));
$r = $tec->post("ficha_componente.php?id=$cInfra", $est());
t('infra: el técnico cambia el estado (historial CAMBIO_ESTADO + auditoría)', $r->contains('Estado actualizado a EN_REVISION.') && $ci("SELECT COUNT(*) FROM componentes WHERE id_componente=? AND estado='EN_REVISION'", [$cInfra]) === 1 && $ci("SELECT COUNT(*) FROM historial_componente WHERE id_componente=? AND tipo_evento='CAMBIO_ESTADO'", [$cInfra]) === 1 && $ci("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='componentes' AND id_registro=? AND accion='EDITAR'", [$cInfra]) === 1);
$tec->post("ficha_componente.php?id=$cTorre", $est(['nuevo_estado_infra' => 'DEFECTUOSO']));
t('infra: no aplica a piezas que no son infraestructura', $ci("SELECT COUNT(*) FROM componentes WHERE id_componente=? AND estado='ACTIVO'", [$cTorre]) === 1);

// ── ficha_equipo: acceso
t('ficha_equipo: lista solo admin/técnico; profesor/solicitante/secretaría/beca no', $C['profesor']->get('ficha_equipo.php')->denied() && $C['solicitante']->get('ficha_equipo.php')->denied() && $C['secretaria']->get('ficha_equipo.php')->denied() && $beca->get('ficha_equipo.php')->denied());
t('ficha_equipo: con ?id entran admin/técnico/beca y no profesor/solicitante/secretaría', !$beca->get("ficha_equipo.php?id=$eqA")->denied() && !$adm->get("ficha_equipo.php?id=$eqA")->denied() && $C['profesor']->get("ficha_equipo.php?id=$eqA")->denied() && $C['solicitante']->get("ficha_equipo.php?id=$eqA")->denied() && $C['secretaria']->get("ficha_equipo.php?id=$eqA")->denied());
$r = $tec->get('ficha_equipo.php?id=999999999');
t('ficha_equipo: id inexistente redirige a la lista', $r->redirects() && substr($r->location(), -16) === 'ficha_equipo.php');

// ── ficha_equipo: modo lista
$r = $tec->get('ficha_equipo.php?q=' . urlencode('ZT-INV-EQ'));
t('lista: la búsqueda por código encuentra los equipos agrupados por sala/dependencia', $r->contains('ZT-INV-EQA') && $r->contains('ZT-INV-EQB') && $r->contains('ZT-SALA-INV') && $r->contains($dep) && $r->contains('Sin dependencia asignada'));
$r = $tec->get('ficha_equipo.php?q=' . urlencode($dep));
t('lista: la búsqueda por dependencia solo trae esa dependencia', $r->contains('ZT-INV-EQA') && !$r->contains('ZT-INV-EQB'));
$r = $tec->get('ficha_equipo.php?q=' . urlencode('ZT-SALA-INV2'));
t('lista: la búsqueda por nombre de sala funciona', $r->contains('ZT-INV-EQB') && !$r->contains('ZT-INV-EQA'));
$r = $tec->get('ficha_equipo.php?q=' . urlencode('"><script>alert(1)</script>'));
t('lista: el término buscado no se refleja sin escapar', !$r->contains('<script>alert(1)</script>'));
$r = $tec->get('ficha_equipo.php?todas=1');
t('lista: todas=1 muestra equipos de todas las dependencias', $r->contains('ZT-INV-EQA') && $r->contains('ZT-INV-EQB'));
$pdo->prepare("UPDATE users SET dependencia=? WHERE id=?")->execute([$dep, $uidI('tecnico')]);
$r = $tec->get('ficha_equipo.php');
t('lista: sin búsqueda, un usuario con dependencia ve solo la suya', $r->contains('ZT-INV-EQA') && !$r->contains('ZT-INV-EQB'));
t('lista: con todas=1 ve también otras dependencias', $tec->get('ficha_equipo.php?todas=1')->contains('ZT-INV-EQB'));
$r = $tec->get('ficha_equipo.php?vista=equipos');
t('lista: «Fichas por equipo» es una tabla de equipos (no el plano por salón) con enlace a cada ficha', $r->status === 200 && $r->contains('<h1>Fichas por equipo</h1>') && $r->contains('ZT-INV-EQA') && $r->contains('ZT-INV-EQB')
    && $r->contains('<th>Piezas</th>') && $r->contains('Ver ficha') && !$r->contains('Plano de la sala'));
$r = $tec->get('ficha_equipo.php');
t('lista: «Fichas por salón» sigue mostrando el plano de cada sala', $r->contains('<h1>Fichas por salón</h1>') && $r->contains('Plano de la sala'));
$r = $tec->get('ficha_equipo.php?vista=equipos&q=ZT-INV-EQA');
t('lista: la búsqueda en «por equipo» se queda en esa vista', $r->contains('<h1>Fichas por equipo</h1>') && $r->contains('ZT-INV-EQA') && !$r->contains('ZT-INV-EQB') && $r->contains('name="vista" value="equipos"'));
$pdo->prepare("UPDATE users SET dependencia=NULL WHERE id=?")->execute([$uidI('tecnico')]);
$r = $tec->get('ficha_equipo.php?q=' . urlencode($dep));
t('lista: la infraestructura de la sala aparece con su ficha', $r->contains('ficha_componente.php?id=' . $cInfra) && $r->contains('ZT-INV-AIRE'));
t('lista: el técnico ve el formulario de agregar infraestructura; el admin no', $r->contains('name="agregar_componente_sala"') && !$adm->get('ficha_equipo.php?q=' . urlencode($dep))->contains('name="agregar_componente_sala"'));

// ── ficha_equipo: agregar infraestructura a una sala (solo técnico)
$infra = ['agregar_componente_sala' => '1', 'id_sala' => $sInv, 'tipo_infra' => 'iluminacion', 'serial_infra' => 'ZT-INV-ILU1', 'codigo_inventario_infra' => 'ZT-INV-ILU1', 'descripcion_infra' => 'ZT luz', 'id_fabricante' => '', 'id_modelo' => ''];
$r = $tec->post('ficha_equipo.php', $infra);
$idIlu = $ci("SELECT id_componente FROM componentes WHERE serial='ZT-INV-ILU1'");
t('infra sala: el técnico agrega (sala directa, ACTIVO, historial y auditoría)', $idIlu > 0 && $r->contains('ZT-INV-ILU1 agregado a la sala ZT-SALA-INV.') && ($fi("SELECT tipo, estado, id_sala_directa, id_equipo_actual FROM componentes WHERE id_componente=?", [$idIlu])['id_sala_directa'] ?? 0) == $sInv && $ci("SELECT COUNT(*) FROM historial_componente WHERE id_componente=? AND tipo_evento='REGISTRO'", [$idIlu]) === 1 && $ci("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='componentes' AND id_registro=? AND accion='CREAR'", [$idIlu]) === 1);
$r = $tec->post('ficha_equipo.php', $infra);
t('infra sala: duplicado → mensaje y sin duplicar', $r->contains('No se pudo registrar. ¿El serial o código de inventario ya existen?') && $ci("SELECT COUNT(*) FROM componentes WHERE serial='ZT-INV-ILU1'") === 1);
$r = $tec->post('ficha_equipo.php', array_merge($infra, ['serial_infra' => 'ZT-INV-ILU2', 'codigo_inventario_infra' => 'ZT-INV-ILU2', 'tipo_infra' => 'torre']));
t('infra sala: un tipo que no es infraestructura se rechaza', $ci("SELECT COUNT(*) FROM componentes WHERE serial='ZT-INV-ILU2'") === 0 && $r->contains('Sala, tipo, serial y código de inventario son obligatorios.'));
// Videobeam de la sala: es infraestructura (va frente al tablero en el plano), no equipo de cómputo
$tec->post('ficha_equipo.php', array_merge($infra, ['serial_infra' => 'ZT-INV-VB1', 'codigo_inventario_infra' => 'ZT-INV-VB1', 'tipo_infra' => 'videobeam']));
$idVbSala = $ci("SELECT id_componente FROM componentes WHERE serial='ZT-INV-VB1' AND id_sala_directa=?", [$sInv]);
t('infra sala: el videobeam se registra directo a la sala', $idVbSala > 0);
$r = $tec->get('ficha_equipo.php?q=' . urlencode('ZT-SALA-INV'));
t('ficha por salón: muestra el plano con el videobeam frente al tablero y el menú de acciones', $r->contains('data-plano-interactivo') && $r->contains('data-puesto="c' . $idVbSala . '"') && $r->contains('sala-puesto-infra') && $r->contains('class="menu-acciones"'));
$r = $tec->post('ficha_equipo.php', array_merge($infra, ['serial_infra' => '', 'codigo_inventario_infra' => 'ZT-INV-ILU3']));
t('infra sala: serial vacío se rechaza', $ci("SELECT COUNT(*) FROM componentes WHERE codigo_inventario='ZT-INV-ILU3'") === 0 && $r->contains('Sala, tipo, serial y código de inventario son obligatorios.'));
$adm->postRaw('ficha_equipo.php', array_merge($infra, ['serial_infra' => 'ZT-INV-ILU4', 'codigo_inventario_infra' => 'ZT-INV-ILU4']));
$tec->postRaw('ficha_equipo.php', array_merge($infra, ['serial_infra' => 'ZT-INV-ILU5', 'codigo_inventario_infra' => 'ZT-INV-ILU5']));
t('infra sala: el admin no agrega y sin CSRF tampoco', $ci("SELECT COUNT(*) FROM componentes WHERE serial IN ('ZT-INV-ILU4','ZT-INV-ILU5')") === 0);
$r = $tec->post('ficha_equipo.php', array_merge($infra, ['serial_infra' => 'ZT-INV-ILU6', 'codigo_inventario_infra' => 'ZT-INV-ILU6', 'id_fabricante' => '__nueva__', 'marca_nueva' => 'ZTFab', 'id_modelo' => '__nuevo__', 'modelo_nuevo' => 'ZTMod']));
$fx = $fi("SELECT marca, modelo, id_fabricante, id_modelo FROM componentes WHERE serial='ZT-INV-ILU6'");
t('infra sala: crea fabricante y modelo nuevos y los vincula', ($fx['marca'] ?? '') === 'ZTFab' && ($fx['modelo'] ?? '') === 'ZTMod' && $ci("SELECT COUNT(*) FROM fabricantes WHERE nombre='ZTFab'") === 1 && $ci("SELECT COUNT(*) FROM modelos_activo WHERE nombre='ZTMod'") === 1);

// ── ficha_equipo: detalle
$tkSala = $pdo->prepare("INSERT INTO tickets (user_id, origen, tipo_solicitud, descripcion, estado, nombre_dependencia, ubicacion, equipo, id_equipo, id_sala) VALUES (?, 'PANEL_INTERNO', 'INCIDENTE', ?, 'En espera', 'ZT', 'ZT', 'ZT', ?, ?)");
try { $tkSala->execute([$uidI('solicitante'), 'ZT-INV-TICKET', $eqA, $sInv]); } catch (Throwable $e) { echo "   (seed ticket inventario: " . substr($e->getMessage(), 0, 100) . ")\n"; }
foreach (['admin' => $adm, 'tecnico' => $tec, 'beca' => $beca] as $n => $c) {
    $r = $c->get("ficha_equipo.php?id=$eqA");
    t("ficha_equipo: $n ve el detalle con componentes, QR y tickets", $r->status === 200 && $r->contains('ZT-INV-EQA') && $r->contains('ZT-INV-TORRE') && $r->contains('data-qr-accion') && $r->contains('ZT-INV-TICKET'));
}
t('ficha_equipo: el beca NO ve el formulario de agregar componente; admin/técnico sí', !$beca->get("ficha_equipo.php?id=$eqA")->contains('name="agregar_componente_puesto"') && $tec->get("ficha_equipo.php?id=$eqA")->contains('name="agregar_componente_puesto"') && $adm->get("ficha_equipo.php?id=$eqA")->contains('name="agregar_componente_puesto"'));
t('ficha_equipo: la marca con HTML se escapa en el detalle', !$tec->get("ficha_equipo.php?id=$eqA")->contains('<b>marca</b>'));

// ── agregar_componente_puesto
$pst = ['agregar_componente_puesto' => '1', 'tipo' => 'teclado', 'serial' => 'ZT-INV-P1', 'codigo_inventario' => 'ZT-INV-P1', 'descripcion' => 'ZT puesto', 'id_fabricante' => '', 'id_modelo' => ''];
$r = $tec->post("ficha_equipo.php?id=$eqA", $pst);
$idP1 = $ci("SELECT id_componente FROM componentes WHERE serial='ZT-INV-P1'");
t('puesto: el técnico agrega un componente al equipo (mensaje, ACTIVO, historial, auditoría)', $idP1 > 0 && $r->contains('ZT-INV-P1 agregado a este puesto.') && $ci("SELECT id_equipo_actual FROM componentes WHERE id_componente=?", [$idP1]) === $eqA && $ci("SELECT COUNT(*) FROM historial_componente WHERE id_componente=? AND tipo_evento='REGISTRO' AND observacion LIKE '%asignado directo desde la ficha del puesto ZT-INV-EQA%'", [$idP1]) === 1 && $ci("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='componentes' AND id_registro=? AND accion='CREAR'", [$idP1]) === 1);
$r = $tec->post("ficha_equipo.php?id=$eqA", $pst);
t('puesto: duplicado → mensaje y sin duplicar', $r->contains('No se pudo registrar. ¿El serial o código de inventario ya existen?') && $ci("SELECT COUNT(*) FROM componentes WHERE serial='ZT-INV-P1'") === 1);
$r = $tec->post("ficha_equipo.php?id=$eqA", array_merge($pst, ['serial' => 'ZT-INV-P2', 'codigo_inventario' => 'ZT-INV-P2', 'tipo' => 'aire_acondicionado']));
t('puesto: un tipo de infraestructura se rechaza', $ci("SELECT COUNT(*) FROM componentes WHERE serial='ZT-INV-P2'") === 0 && $r->contains('obligatorios'));
$adm->post("ficha_equipo.php?id=$eqA", array_merge($pst, ['serial' => 'ZT-INV-P3', 'codigo_inventario' => 'ZT-INV-P3']));
t('puesto: el admin también agrega', $ci("SELECT COUNT(*) FROM componentes WHERE serial='ZT-INV-P3'") === 1);
$beca->postRaw("ficha_equipo.php?id=$eqA", array_merge($pst, ['serial' => 'ZT-INV-P4', 'codigo_inventario' => 'ZT-INV-P4']));
$tec->postRaw("ficha_equipo.php?id=$eqA", array_merge($pst, ['serial' => 'ZT-INV-P5', 'codigo_inventario' => 'ZT-INV-P5']));
t('puesto: el beca no agrega y sin CSRF tampoco', $ci("SELECT COUNT(*) FROM componentes WHERE serial IN ('ZT-INV-P4','ZT-INV-P5')") === 0);
$tec->post("ficha_equipo.php?id=$eqA", array_merge($pst, ['serial' => 'ZT-INV-P6', 'codigo_inventario' => 'ZT-INV-P6', 'id_fabricante' => '__nueva__', 'marca_nueva' => 'ZTFab2', 'id_modelo' => '__nuevo__', 'modelo_nuevo' => 'ZTMod2']));
$fx = $fi("SELECT marca, modelo FROM componentes WHERE serial='ZT-INV-P6'");
t('puesto: la marca/modelo nuevos se resuelven y guardan', ($fx['marca'] ?? '') === 'ZTFab2' && ($fx['modelo'] ?? '') === 'ZTMod2');

// ── Correcciones
seccion('Inventario: correcciones');
$r = $tec->post('gestionar_inventario.php', ['editar_componente' => '1', 'id_componente' => 999999999, 'marca' => 'x', 'modelo' => 'y', 'descripcion' => 'z']);
t('P34: editar_componente con id inexistente avisa y no audita', $r->contains('El componente no existe.') && !$r->contains('Componente actualizado.'));
$r = $tec->post('gestionar_inventario.php', array_merge($nuevo, ['serial' => 'ZT-INV-E1', 'codigo_inventario' => 'ZT-INV-E1', 'id_equipo_inicial' => 999999999]));
t('P34: crear con equipo inicial inexistente → "El equipo seleccionado no existe."', $r->contains('El equipo seleccionado no existe.') && $ci("SELECT COUNT(*) FROM componentes WHERE serial='ZT-INV-E1'") === 0);
$r = $tec->post('ficha_equipo.php', array_merge($infra, ['serial_infra' => 'ZT-INV-E2', 'codigo_inventario_infra' => 'ZT-INV-E2', 'id_sala' => 999999999]));
t('P34: infra en sala inexistente → "La sala seleccionada no existe."', $r->contains('La sala seleccionada no existe.') && $ci("SELECT COUNT(*) FROM componentes WHERE serial='ZT-INV-E2'") === 0);
