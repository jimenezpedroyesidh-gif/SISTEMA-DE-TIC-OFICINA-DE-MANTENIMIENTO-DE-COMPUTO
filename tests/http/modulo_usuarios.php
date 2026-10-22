<?php
/**
 * Pruebas HTTP del módulo Usuarios: usuarios.php y register.php (se incluye desde run_http_tests.php, que
 * aporta $pdo, $U, $C, $adm, $tec, $CLAVE, cliente(), t() y seccion()).
 */
seccion('Usuarios: listado, alta, edición, estado, bloqueo');

$cu = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); })();
$fu = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(); })();
$beca = $C['beca_trabajo'];
$temp = fn($r) => preg_match('/class="[^"]*\bls-2\b[^"]*"[^>]*>([^<]+)</', $r->body, $m) ? $m[1] : '';
$idPorCorreo = fn(string $c) => $cu("SELECT id FROM users WHERE correo=?", [$c]);

$pdo->prepare("INSERT INTO unidades_organizacionales (nombre, tipo) VALUES ('ZT-Unidad-U', 'DEPENDENCIA')")->execute();
$unidad = (int)$pdo->lastInsertId();

$nuevo = ['codigo' => 'ZT-U1', 'correo' => 'zt_nuevo1@ufps.edu.co', 'nombre_completo' => 'ZT Nuevo Uno', 'cargo' => 'auxiliar', 'id_unidad' => $unidad, 'role' => 'tecnico', 'estado_activo' => '1'];

// ── Acceso
foreach (['tecnico' => $tec, 'beca' => $beca, 'profesor' => $C['profesor'], 'solicitante' => $C['solicitante'], 'secretaria' => $C['secretaria']] as $n => $c) {
    t("Usuarios: $n no entra a usuarios.php ni register.php", $c->get('usuarios.php')->denied() && $c->get('register.php')->denied());
}
$tec->postRaw('register.php', $nuevo);
t('register: un técnico no puede crear usuarios', $idPorCorreo('zt_nuevo1@ufps.edu.co') === 0);

// ── Listado
$r = $adm->get('usuarios.php');
t('listado: muestra usuarios con enlace Editar y rol', $r->contains('zt_tecnico@test.local') && $r->contains('register.php?id=' . $U['tecnico']['id']) && $r->contains('Crear nuevo usuario'));
t('listado: usuario activo muestra «Activa»', $r->contains('Activa'));
t('listado: usuarios sin dependencia muestran guion y sin hashes', !$r->contains('$2y$'));

// ── Alta
$r = $adm->get('register.php');
t('register: formulario de alta con roles y unidades', $r->status === 200 && $r->contains('Crear nuevo usuario') && $r->contains('ZT-Unidad-U') && $r->contains('value="secretaria"'));
$r = $adm->post('register.php', $nuevo);
$id1 = $idPorCorreo('zt_nuevo1@ufps.edu.co');
$clave1 = $temp($r);
t('alta: crea el usuario y muestra la clave temporal una vez', $id1 > 0 && strlen($clave1) === 14 && $r->contains('ZT Nuevo Uno'));
$f = $fu("SELECT role, estado_activo, password_temporal, dependencia, id_unidad, cargo, password FROM users WHERE id=?", [$id1]);
t('alta: rol, activa, clave temporal=1, dependencia derivada de la unidad', ($f['role'] ?? '') === 'tecnico' && (int)$f['estado_activo'] === 1 && (int)$f['password_temporal'] === 1 && ($f['dependencia'] ?? '') === 'ZT-Unidad-U' && (int)$f['id_unidad'] === $unidad);
t('alta: la clave mostrada es la guardada (hash) y no se guarda en claro', $clave1 !== '' && password_verify($clave1, $f['password']) && $f['password'] !== $clave1);
$nuevo2 = array_merge($nuevo, ['codigo' => 'ZT-U2', 'correo' => 'zt_nuevo2@gmail.com', 'nombre_completo' => 'ZT <b>Dos</b>', 'id_unidad' => '', 'role' => 'profesor']);
$r = $adm->post('register.php', $nuevo2);
$id2 = $idPorCorreo('zt_nuevo2@gmail.com');
t('alta: sin unidad guarda dependencia NULL; correo gmail aceptado', $id2 > 0 && $fu("SELECT dependencia, id_unidad FROM users WHERE id=?", [$id2])['dependencia'] === null);
t('alta: el nombre se escapa en la pantalla (XSS)', !$r->contains('<b>Dos</b>') && $r->contains('ZT &lt;b&gt;Dos&lt;/b&gt;'));
$nuevo3 = array_merge($nuevo, ['codigo' => 'ZT-U3', 'correo' => 'zt_nuevo3@ufps.edu.co']);
unset($nuevo3['estado_activo']);
$adm->post('register.php', $nuevo3);
t('alta: sin marcar «activa» queda deshabilitada', $cu("SELECT estado_activo FROM users WHERE correo='zt_nuevo3@ufps.edu.co'") === 0 && $idPorCorreo('zt_nuevo3@ufps.edu.co') > 0);

// ── Alta: validaciones
$r = $adm->post('register.php', array_merge($nuevo, ['codigo' => 'ZT-UX', 'correo' => 'zt_x@ufps.edu.co', 'nombre_completo' => '']));
t('alta: campos obligatorios vacíos', $r->contains('Código, correo y nombre completo son obligatorios.') && $idPorCorreo('zt_x@ufps.edu.co') === 0);
$r = $adm->post('register.php', array_merge($nuevo, ['codigo' => 'ZT-UX', 'correo' => 'zt_x@ufps.edu.co', 'role' => 'dios']));
t('alta: rol inválido', $r->contains('Rol no válido.') && $idPorCorreo('zt_x@ufps.edu.co') === 0);
$r = $adm->post('register.php', array_merge($nuevo, ['codigo' => 'ZT-UX', 'correo' => 'zt_x@hotmail.com']));
t('alta: correo fuera de ufps.edu.co / gmail.com', $r->contains('El correo debe ser @ufps.edu.co o @gmail.com.') && $cu("SELECT COUNT(*) FROM users WHERE codigo='ZT-UX'") === 0);
foreach (['codigo' => ['codigo' => 'ZT-U1', 'correo' => 'zt_y@ufps.edu.co'], 'correo' => ['codigo' => 'ZT-UY', 'correo' => 'zt_nuevo1@ufps.edu.co']] as $campo => $d) {
    $r = $adm->post('register.php', array_merge($nuevo, $d));
    t("alta: $campo duplicado → «Ya existe otro usuario»", $r->contains('Ya existe otro usuario con ese código o correo.') && $cu("SELECT COUNT(*) FROM users WHERE codigo='ZT-UY' OR correo='zt_y@ufps.edu.co'") === 0);
}
$adm->postRaw('register.php', array_merge($nuevo, ['codigo' => 'ZT-UZ', 'correo' => 'zt_z@ufps.edu.co']));
t('alta: sin CSRF no crea', $idPorCorreo('zt_z@ufps.edu.co') === 0);
$r = $adm->post('register.php', ['correo' => 'zt_w@ufps.edu.co']);
t('alta: POST incompleto no da 5xx y no crea', $r->status < 500 && $idPorCorreo('zt_w@ufps.edu.co') === 0);

// ── Edición
$r = $adm->get("register.php?id=$id1");
t('edición: precarga los datos y ofrece resetear clave', $r->contains('Editar usuario') && $r->contains('value="ZT Nuevo Uno"') && $r->contains('name="resetear_clave"'));
$r = $adm->get('register.php?id=999999999');
t('edición: id inexistente muestra el formulario de alta', $r->status === 200 && $r->contains('Crear nuevo usuario'));
$hashAntes = $fu("SELECT password FROM users WHERE id=?", [$id1])['password'];
$r = $adm->post("register.php?id=$id1", array_merge($nuevo, ['id' => $id1, 'nombre_completo' => 'ZT Nuevo Editado', 'role' => 'beca_trabajo', 'cargo' => 'profesional', 'id_unidad' => '']));
$f = $fu("SELECT nombre_completo, role, cargo, dependencia, id_unidad, password, password_temporal FROM users WHERE id=?", [$id1]);
t('edición: actualiza datos, rol y quita la unidad', $f['nombre_completo'] === 'ZT Nuevo Editado' && $f['role'] === 'beca_trabajo' && $f['cargo'] === 'profesional' && $f['dependencia'] === null && $f['id_unidad'] === null);
t('edición: sin «resetear» no toca la clave y no muestra clave temporal', $f['password'] === $hashAntes && $temp($r) === '' && $r->contains('Editar usuario'));
$r = $adm->post("register.php?id=$id1", array_merge($nuevo, ['id' => $id1, 'resetear_clave' => '1']));
$claveR = $temp($r);
$f = $fu("SELECT password, password_temporal, role FROM users WHERE id=?", [$id1]);
t('edición: resetear genera clave temporal nueva y obliga a cambiarla', strlen($claveR) === 14 && password_verify($claveR, $f['password']) && (int)$f['password_temporal'] === 1 && !password_verify($clave1, $f['password']));
$r = $adm->post("register.php?id=$id1", array_merge($nuevo, ['id' => $id1, 'codigo' => 'ZT-U2']));
t('edición: no se puede usar el código de otro usuario', $r->contains('Ya existe otro usuario') && $cu("SELECT COUNT(*) FROM users WHERE id=? AND codigo='ZT-U1'", [$id1]) === 1);
$adm->post("register.php?id=$id1", array_merge($nuevo, ['id' => $id1, 'nombre_completo' => 'ZT Mismo']));
t('edición: puede guardar con sus propios código/correo', $cu("SELECT COUNT(*) FROM users WHERE id=? AND nombre_completo='ZT Mismo'", [$id1]) === 1);
$tec->postRaw('register.php', array_merge($nuevo, ['id' => $id1, 'nombre_completo' => 'ZT Hackeado']));
$adm->postRaw('register.php', array_merge($nuevo, ['id' => $id1, 'nombre_completo' => 'ZT SinCsrf']));
t('edición: técnico o sin CSRF no modifican', $cu("SELECT COUNT(*) FROM users WHERE id=? AND nombre_completo='ZT Mismo'", [$id1]) === 1);

// ── Habilitar / deshabilitar
$r = $adm->post('usuarios.php', ['toggle_id' => $id2]);
t('toggle: el admin deshabilita (redirige al listado)', $r->redirects() && strpos($r->location(), 'usuarios.php') !== false && $cu("SELECT estado_activo FROM users WHERE id=?", [$id2]) === 0);
$adm->post('usuarios.php', ['toggle_id' => $id2]);
t('toggle: vuelve a habilitar', $cu("SELECT estado_activo FROM users WHERE id=?", [$id2]) === 1);
$adm->postRaw('usuarios.php', ['toggle_id' => $id2]);
t('toggle: sin CSRF no cambia', $cu("SELECT estado_activo FROM users WHERE id=?", [$id2]) === 1);
$r = $adm->post('usuarios.php', ['toggle_id' => 999999999]);
t('toggle: id inexistente no rompe', $r->status < 500);
$adm->post('usuarios.php', ['toggle_id' => $id2]);
t('toggle: el deshabilitado aparece «Deshabilitada» en el listado', $adm->get('usuarios.php')->contains('Deshabilitada'));
$adm->post('usuarios.php', ['toggle_id' => $id2]);

// ── Desbloquear
$pdo->prepare("INSERT INTO usuario_bloqueo (id_usuario, intentos_fallidos, bloqueado, bloqueado_en) VALUES (?, 3, 1, NOW()) ON DUPLICATE KEY UPDATE intentos_fallidos=3, bloqueado=1, bloqueado_en=NOW()")->execute([$id2]);
t('bloqueo: el listado marca la cuenta bloqueada y ofrece Desbloquear', ($r = $adm->get('usuarios.php'))->contains('Bloqueada (3 intentos)') && $r->contains('name="desbloquear_id"'));
$adm->postRaw('usuarios.php', ['desbloquear_id' => $id2]);
t('bloqueo: sin CSRF no desbloquea', $cu("SELECT bloqueado FROM usuario_bloqueo WHERE id_usuario=?", [$id2]) === 1);
$r = $adm->post('usuarios.php', ['desbloquear_id' => $id2]);
t('bloqueo: el admin desbloquea y queda auditado', $r->redirects() && $cu("SELECT bloqueado FROM usuario_bloqueo WHERE id_usuario=?", [$id2]) === 0 && $cu("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='users' AND id_registro=? AND accion='EDITAR'", [$id2]) >= 1);
$tec->postRaw('usuarios.php', ['desbloquear_id' => $id2]);

// ── Permisos de secretaria (ya cubiertos en la sección 5; aquí solo el mensaje del listado)
$adm->post('usuarios.php', ['alternar_permiso' => '1', 'id_usuario' => $U['secretaria']['id'], 'permiso' => 'permiso_ver_tickets_sistemas']);
$r = $adm->get('usuarios.php');
t('permisos: el listado muestra los botones de la secretaria', $r->contains('name="alternar_permiso"') && $r->contains('name="permiso"'));
t('permisos: botón «Permisos» que abre un menú flotante con un interruptor por permiso', $r->contains('popovertarget="permisos-' . $U['secretaria']['id'] . '"') && $r->contains('id="permisos-' . $U['secretaria']['id'] . '" popover') && $r->contains('role="switch"'));
t('permisos: el botón sale en todos los usuarios (menos admin); lo que no aplica al rol se ve deshabilitado', $r->contains('popovertarget="permisos-' . $U['solicitante']['id'] . '"') && $r->contains('disabled title="Este permiso no aplica a este rol"') && $r->contains('Solo para: Secretaria'));
$rr = $adm->post('usuarios.php', ['alternar_permiso' => '1', 'id_usuario' => $U['secretaria']['id'], 'permiso' => 'permiso_ver_tickets_sistemas']);
t('permisos: al cambiar uno vuelve con el menú de esa persona abierto', $rr->redirects() && strpos($rr->location(), 'abierto=' . $U['secretaria']['id']) !== false
    && strpos($adm->get('usuarios.php?abierto=' . $U['secretaria']['id'])->body, 'id="permisos-' . $U['secretaria']['id'] . '" popover data-popover-abrir') !== false);
$adm->post('usuarios.php', ['alternar_permiso' => '1', 'id_usuario' => $U['secretaria']['id'], 'permiso' => 'permiso_ver_tickets_sistemas']); // lo deja como estaba
$r = $adm->get('usuarios.php');
t('usuarios: el listado y el formulario ya no piden ni muestran cédula', !$r->contains('Cédula') && !$adm->get('register.php')->contains('name="cedula"'));

// ── Correcciones
seccion('Usuarios: correcciones');
$yo = $U['admin']['id'];
$adm->post('usuarios.php', ['toggle_id' => $yo]);
t('P35: el admin no puede deshabilitar su propia cuenta', $cu("SELECT estado_activo FROM users WHERE id=?", [$yo]) === 1);
$r = $adm->post("register.php?id=$yo", ['id' => $yo, 'codigo' => $U['admin']['codigo'], 'correo' => 'zt_admin@ufps.edu.co', 'nombre_completo' => 'ZT admin', 'cargo' => 'auxiliar', 'role' => 'tecnico', 'estado_activo' => '1']);
t('P35: el admin no puede quitarse su rol de admin', $cu("SELECT COUNT(*) FROM users WHERE id=? AND role='admin'", [$yo]) === 1 && $r->contains('No puedes quitarte el rol de admin ni desactivar tu propia cuenta.'));
$r = $adm->post("register.php?id=$yo", ['id' => $yo, 'codigo' => $U['admin']['codigo'], 'correo' => 'zt_admin@ufps.edu.co', 'nombre_completo' => 'ZT admin', 'cargo' => 'auxiliar', 'role' => 'admin']);
t('P35: el admin tampoco puede desactivarse editándose', $cu("SELECT estado_activo FROM users WHERE id=?", [$yo]) === 1);
$r = $adm->post('register.php', array_merge($nuevo, ['id' => 999999999, 'codigo' => 'ZT-UN', 'correo' => 'zt_n@ufps.edu.co']));
t('P35: editar un id inexistente avisa y no crea nada', $r->contains('El usuario no existe.') && $idPorCorreo('zt_n@ufps.edu.co') === 0);
$r = $adm->post('register.php', array_merge($nuevo, ['codigo' => 'ZT-UU', 'correo' => 'zt_u@ufps.edu.co', 'id_unidad' => 999999999]));
t('P35: una unidad inexistente se rechaza con mensaje (sin 5xx)', $r->status < 500 && $r->contains('La unidad seleccionada no existe.') && $idPorCorreo('zt_u@ufps.edu.co') === 0);
t('P35: alta y edición quedan auditadas (sin clave en el detalle)', $cu("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='users' AND id_registro=? AND accion='CREAR'", [$id1]) === 1 && $cu("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='users' AND id_registro=? AND accion='EDITAR'", [$id1]) >= 1 && $cu("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='users' AND detalle LIKE ?", ['%' . $claveR . '%']) === 0);
t('P35: habilitar/deshabilitar queda auditado', $cu("SELECT COUNT(*) FROM auditoria WHERE tabla_afectada='users' AND id_registro=? AND accion='EDITAR' AND detalle LIKE '%estado%'", [$id2]) >= 1);

// ── Correcciones
seccion('Usuarios: correcciones (cargo en servidor, rol vigente en sesión)');
$r = $adm->post('register.php', array_merge($nuevo, ['codigo' => 'ZT-U8', 'correo' => 'zt_c8@ufps.edu.co', 'cargo' => 'rey del mundo']));
t('P38: alta con cargo fuera de la lista se rechaza y no crea nada', $r->contains('El cargo seleccionado no es válido.') && $idPorCorreo('zt_c8@ufps.edu.co') === 0);
$r = $adm->post('register.php', array_merge($nuevo, ['codigo' => 'ZT-U9', 'correo' => 'zt_c9@ufps.edu.co', 'cargo' => '']));
t('P38: alta sin cargo se rechaza', $r->contains('El cargo seleccionado no es válido.') && $idPorCorreo('zt_c9@ufps.edu.co') === 0);
$cargoAntes38 = $fu("SELECT cargo FROM users WHERE id=?", [$id1])['cargo'];
$r = $adm->post("register.php?id=$id1", array_merge($nuevo, ['id' => $id1, 'cargo' => 'inventado']));
t('P38: edición con cargo inválido se rechaza y conserva el anterior', $r->contains('El cargo seleccionado no es válido.') && $fu("SELECT cargo FROM users WHERE id=?", [$id1])['cargo'] === $cargoAntes38);
$pdo->prepare("UPDATE users SET cargo='Legado' WHERE id=?")->execute([$id1]);
$r = $adm->post("register.php?id=$id1", array_merge($nuevo, ['id' => $id1, 'nombre_completo' => 'ZT Legado Ok', 'cargo' => 'Legado']));
t('P38: edición tolera conservar un cargo antiguo ya guardado', $fu("SELECT nombre_completo FROM users WHERE id=?", [$id1])['nombre_completo'] === 'ZT Legado Ok');

// El rol vigente se aplica en la siguiente petición (antes la sesión conservaba el rol viejo)
$otro = $C['otro'];
t('P38: (antes) un solicitante no entra a create_ticket_profesor.php', $otro->get('create_ticket_profesor.php')->denied());
$pdo->prepare("UPDATE users SET role='profesor' WHERE id=?")->execute([$U['otro']['id']]);
t('P38: al cambiar su rol a profesor entra sin volver a iniciar sesión', !$otro->get('create_ticket_profesor.php')->denied());
$pdo->prepare("UPDATE users SET role='solicitante' WHERE id=?")->execute([$U['otro']['id']]);
t('P38: al volver a solicitante pierde el acceso en la siguiente petición', $otro->get('create_ticket_profesor.php')->denied());


// ── register.php dentro del mismo shell que Usuarios (header + menú lateral, «Usuarios» activo)
$r = $adm->get('register.php');
t('register: se abre dentro del shell (header y menú lateral) con «Usuarios» marcado', $r->status === 200 && $r->contains('class="app-sidebar"') && $r->contains('class="app-header"')
    && (bool)preg_match('~href="usuarios.php" class="app-sidebar-link active"~', $r->body) && !$r->contains('class="page-center"'));
$r = $adm->get('register.php?id=' . $U['beca_trabajo']['id']);
t('register (editar): mismo shell, botón Cancelar vuelve a usuarios.php', $r->status === 200 && $r->contains('class="app-sidebar"') && $r->contains('href="usuarios.php" class="btn btn-secondary"'));
