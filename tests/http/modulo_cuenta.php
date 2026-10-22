<?php
/**
 * Pruebas HTTP de cuenta y acceso: login, logout, perfil, cambiar_clave, recuperar_clave, restablecer_clave y
 * google_callback. Se incluye desde run_http_tests.php (aporta $pdo, $U, $C, $adm, $tec, $anon, $base, $hash,
 * $CLAVE, cliente(), t() y seccion()). Complementa las de bloqueo, restablecimiento y Google de
 * tests/run_tests.php.
 */
seccion('Cuenta y acceso: login, logout, perfil, cambiar_clave, recuperar/restablecer, google_callback');

$cc = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); })();
$fc = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(PDO::FETCH_ASSOC); })();
$seqCta = 20;
$mkUser = function (string $rol = 'solicitante', array $extra = []) use ($pdo, $hash, &$seqCta) {
    $n = ++$seqCta;
    $d = array_merge(['codigo' => "ZT$n", 'correo' => "zt_c{$n}@test.local", 'nombre' => "ZT cuenta $n", 'temporal' => 0, 'activo' => 1], $extra);
    $pdo->prepare("INSERT INTO users (codigo, correo, password, password_temporal, role, nombre_completo, cargo, estado_activo) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$d['codigo'], $d['correo'], $hash, $d['temporal'], $rol, $d['nombre'], 'Prueba', $d['activo']]);
    $d['id'] = (int)$pdo->lastInsertId();
    return $d;
};
$loginCon = function (array $u, string $clave = null) use ($base, $CLAVE) { $c = new HttpClient($base); return [$c, $c->login($u['codigo'], $u['correo'], $clave ?? $CLAVE)]; };

// ── login.php
$r = $anon->get('login.php');
t('login: formulario con código, correo y contraseña, enlace de recuperación y sin Google si no está configurado', $r->status === 200 && $r->contains('name="codigo"') && $r->contains('name="correo"') && $r->contains('name="password"') && $r->contains('recuperar_clave.php') && !$r->contains('google_login.php'));
$u1 = $mkUser('tecnico');
[$c1, $r] = $loginCon($u1);
t('login correcto: redirige a tickets.php y marca online + último login', $r->redirects() && strpos($r->location(), 'tickets.php') !== false && $cc("SELECT online FROM users WHERE id=?", [$u1['id']]) === 1 && $fc("SELECT ultimo_login FROM users WHERE id=?", [$u1['id']])['ultimo_login'] !== null);
$u2 = $mkUser('solicitante');
$c2 = new HttpClient($base);
$r = $c2->login(strtolower($u2['codigo']), strtoupper($u2['correo']) === $u2['correo'] ? $u2['correo'] : $u2['correo'], $CLAVE);
t('login: el código no distingue mayúsculas/minúsculas', $r->redirects() && !$r->toLogin());
$u3 = $mkUser('solicitante', ['temporal' => 1]);
[$c3, $r] = $loginCon($u3);
t('login con clave temporal: redirige a cambiar_clave.php', $r->redirects() && strpos($r->location(), 'cambiar_clave.php') !== false);
$u4 = $mkUser('solicitante', ['activo' => 0]);
[$c4, $r] = $loginCon($u4);
t('login: cuenta deshabilitada → mensaje y sin sesión', !$r->redirects() && $r->contains('Esta cuenta está deshabilitada.'));
$u5 = $mkUser('solicitante');
$k = new HttpClient($base);
$r = $k->login($u5['codigo'], $u5['correo'], 'mala1');
t('login fallido #1 → "Te quedan 2 intentos."', $r->contains('Código, correo o contraseña incorrectos') && $r->contains('quedan 2 intentos'));
$r = $k->login($u5['codigo'], $u5['correo'], 'mala2');
t('login fallido #2 → "Te queda 1 intento."', $r->contains('queda 1 intento'));
$r = $k->login($u5['codigo'], $u5['correo'], 'mala3');
t('login fallido #3 → cuenta bloqueada con enlace a recuperar', $r->contains('Cuenta bloqueada por 3 intentos fallidos') && $cc("SELECT bloqueado FROM usuario_bloqueo WHERE id_usuario=?", [$u5['id']]) === 1);
$r = $k->login($u5['codigo'], $u5['correo'], $CLAVE);
t('login: con la cuenta bloqueada ni la clave correcta entra', !$r->redirects() && $r->contains('Cuenta bloqueada'));
$u6 = $mkUser('solicitante');
$k6 = new HttpClient($base);
$k6->login($u6['codigo'], $u6['correo'], 'mala');
$k6->login($u6['codigo'], $u6['correo'], $CLAVE);
$r = (new HttpClient($base))->login($u6['codigo'], $u6['correo'], 'mala');
t('login: un acierto reinicia el contador de fallos', $r->contains('quedan 2 intentos'));
$r = (new HttpClient($base))->login($u6['codigo'], 'noexiste_zt@test.local', 'x');
t('login: correo inexistente → mensaje genérico sin contador', $r->contains('Código, correo o contraseña incorrectos') && !$r->contains('intento'));
$u7 = $mkUser('solicitante');
$r = (new HttpClient($base))->login('OTRO-COD', $u7['correo'], $CLAVE);
t('login: correo correcto con código de otro usuario → falla y cuenta como intento', $r->contains('quedan 2 intentos') && !$r->redirects());
$r = (new HttpClient($base))->login('<script>ZT</script>', '"><b>zt@x.y', '<i>x</i>');
t('login: el error no refleja lo escrito', !$r->contains('<script>ZT</script>') && !$r->contains('<b>zt@x.y'));

// ── logout.php
$r = $c1->get('logout.php');
t('logout: redirige a index.php, cierra la sesión y marca offline', $r->redirects() && strpos($r->location(), 'index.php') !== false && $c1->get('tickets.php')->toLogin() && $cc("SELECT online FROM users WHERE id=?", [$u1['id']]) === 0);
t('logout: sin sesión no falla', (new HttpClient($base))->get('logout.php')->redirects());

// ── cambiar_clave.php
$u8 = $mkUser('tecnico');
[$c8] = $loginCon($u8);
$r = $c8->get('cambiar_clave.php');
t('cambiar_clave (voluntario): layout completo con "Contraseña actual" y enlace al perfil', $r->status === 200 && $r->contains('Contraseña actual') && $r->contains('Volver a mi perfil') && $r->contains('app-sidebar'));
$r = $c3->get('cambiar_clave.php');
t('cambiar_clave (obligatorio): layout mínimo, sin sidebar', $r->status === 200 && $r->contains('Contraseña temporal que te dieron') && !$r->contains('app-sidebar'));
t('cambiar_clave: con clave temporal el resto de páginas rebota a cambiar_clave.php', ($x = $c3->get('tickets.php')) && $x->redirects() && strpos($x->location(), 'cambiar_clave.php') !== false);
t('cambiar_clave: sin sesión → login', (new HttpClient($base))->get('cambiar_clave.php')->toLogin());
$r = $c8->post('cambiar_clave.php', ['clave_actual' => 'incorrecta', 'nueva_clave' => 'Nueva-clave-Http-41', 'confirmar_clave' => 'Nueva-clave-Http-41']);
t('cambiar_clave: actual incorrecta → mensaje y sin cambio', $r->contains('La contraseña actual no es correcta.') && password_verify($CLAVE, (string)$fc("SELECT password FROM users WHERE id=?", [$u8['id']])['password']));
$r = $c8->post('cambiar_clave.php', ['clave_actual' => $CLAVE, 'nueva_clave' => 'corta', 'confirmar_clave' => 'corta']);
t('cambiar_clave: menos de 12 caracteres → mensaje', $r->contains('al menos 12 caracteres'));
$r = $c8->post('cambiar_clave.php', ['clave_actual' => $CLAVE, 'nueva_clave' => 'Nueva-clave-Http-41', 'confirmar_clave' => 'Distinta-clave-Http-41']);
t('cambiar_clave: confirmación distinta → mensaje', $r->contains('Las contraseñas no coinciden.') && password_verify($CLAVE, (string)$fc("SELECT password FROM users WHERE id=?", [$u8['id']])['password']));
t('cambiar_clave: POST sin CSRF → 403', $c8->postRaw('cambiar_clave.php', ['clave_actual' => $CLAVE, 'nueva_clave' => 'Hack123456', 'confirmar_clave' => 'Hack123456'])->status === 403);
$r = $c3->post('cambiar_clave.php', ['clave_actual' => $CLAVE, 'nueva_clave' => 'Definitiva-clave-Http', 'confirmar_clave' => 'Definitiva-clave-Http']);
$siguiente = $r->redirects() ? $c3->get($r->location()) : null;
t('cambiar_clave (obligatorio): guarda, quita la marca temporal y lleva al panel', $r->redirects() && $siguiente && $siguiente->status === 200 && !$siguiente->toLogin() && $cc("SELECT password_temporal FROM users WHERE id=?", [$u3['id']]) === 0 && password_verify('Definitiva-clave-Http', (string)$fc("SELECT password FROM users WHERE id=?", [$u3['id']])['password']));
t('cambiar_clave: tras el cambio obligatorio ya se puede navegar', !$c3->get('tickets.php')->redirects());
$destinos = [];
foreach (['admin', 'tecnico', 'solicitante', 'beca_trabajo', 'profesor'] as $rol) {
    $ur = $mkUser($rol);
    [$cr] = $loginCon($ur);
    $rr = $cr->post('cambiar_clave.php', ['clave_actual' => $CLAVE, 'nueva_clave' => 'Cambio-de-clave-Http', 'confirmar_clave' => 'Cambio-de-clave-Http']);
    $dest = $rr->redirects() ? $cr->get($rr->location()) : null;
    $destinos[$rol] = $dest && $dest->status === 200 && !$dest->toLogin();
}
t('cambiar_clave: cada rol (salvo secretaría) termina en su panel (el destino responde 200)', !in_array(false, $destinos, true), json_encode($destinos));

// ── perfil.php
$u9 = $mkUser('beca_trabajo', ['nombre' => '<b>ZT Perfil</b>']);
[$c9] = $loginCon($u9);
$r = $c9->get('perfil.php');
t('perfil: muestra nombre (escapado), correo y rol con etiqueta', $r->status === 200 && $r->contains('&lt;b&gt;ZT Perfil&lt;/b&gt;') && !$r->contains('<b>ZT Perfil</b>') && $r->contains($u9['correo']) && $r->contains('Rol') && $r->contains('Cédula') === false);
t('perfil: ?clave=ok muestra el aviso', $c9->get('perfil.php?clave=ok')->contains('Contraseña actualizada correctamente.'));
t('perfil: sin sesión → login', (new HttpClient($base))->get('perfil.php')->toLogin());
$r = $c9->post('perfil.php', ['subir_foto' => '1']);
t('perfil: subir sin archivo → "Selecciona una imagen primero."', $r->contains('Selecciona una imagen primero.'));
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$r = $c9->postFile('perfil.php', ['subir_foto' => '1'], ['foto_perfil' => ['f.png', $png, 'image/png']], 'perfil.php');
$foto1 = (string)$fc("SELECT foto_perfil FROM users WHERE id=?", [$u9['id']])['foto_perfil'];
t('perfil: subir foto válida → guarda nombre generado u<id>_*.png y avisa', $r->contains('Foto de perfil actualizada.') && preg_match('/^u' . $u9['id'] . '_[0-9a-f]{12}\.png$/', $foto1) === 1 && $c9->get('assets/uploads/perfiles/' . $foto1)->status === 200);
$r = $c9->postFile('perfil.php', ['subir_foto' => '1'], ['foto_perfil' => ['g.png', $png, 'image/png']], 'perfil.php');
$foto2 = (string)$fc("SELECT foto_perfil FROM users WHERE id=?", [$u9['id']])['foto_perfil'];
t('perfil: cambiar foto borra el archivo anterior', $foto2 !== '' && $foto2 !== $foto1 && $c9->get('assets/uploads/perfiles/' . $foto1)->status === 404);
$r = $c9->post('perfil.php', ['quitar_foto' => '1']);
t('perfil: quitar foto → NULL en BD, archivo borrado y mensaje', $r->contains('Foto de perfil eliminada. Ahora se muestran tus iniciales.') && $fc("SELECT foto_perfil FROM users WHERE id=?", [$u9['id']])['foto_perfil'] === null && $c9->get('assets/uploads/perfiles/' . $foto2)->status === 404);
t('perfil: POST sin CSRF → 403', $c9->postRaw('perfil.php', ['quitar_foto' => '1'])->status === 403);

// ── recuperar_clave.php / restablecer_clave.php
$r = $anon->get('recuperar_clave.php');
t('recuperar_clave: formulario con campo correo y CSRF', $r->status === 200 && $r->contains('name="correo"') && $r->contains('name="csrf_token"'));
t('recuperar_clave: POST sin CSRF → 403', $anon->postRaw('recuperar_clave.php', ['correo' => 'a@b.co'])->status === 403);
$an = new HttpClient($base);
$r = $an->post('recuperar_clave.php', ['correo' => 'no-es-correo']);
t('recuperar_clave: correo inválido → "Escribe un correo válido."', $r->contains('Escribe un correo válido.'));
$u10 = $mkUser('solicitante', ['correo' => 'zt_rec10@ufps.edu.co']);
$r = $an->post('recuperar_clave.php', ['correo' => $u10['correo']]);
t('recuperar_clave: correo registrado → respuesta genérica y un enlace guardado (hash) con vigencia', $r->contains('Si el correo está registrado y activo') && $cc("SELECT COUNT(*) FROM recuperacion_clave WHERE id_usuario=? AND usado_en IS NULL AND expira_en > NOW()", [$u10['id']]) === 1);
$r2 = $an->post('recuperar_clave.php', ['correo' => 'noexiste_zt@test.local']);
t('recuperar_clave: correo inexistente → la misma respuesta (no enumera)', $r2->contains('Si el correo está registrado y activo'));
for ($i = 0; $i < 4; $i++) $an->post('recuperar_clave.php', ['correo' => $u10['correo']]);
t('recuperar_clave: máximo 3 enlaces por hora y solo el último vale', $cc("SELECT COUNT(*) FROM recuperacion_clave WHERE id_usuario=?", [$u10['id']]) === 3 && $cc("SELECT COUNT(*) FROM recuperacion_clave WHERE id_usuario=? AND usado_en IS NULL", [$u10['id']]) === 1);
$u11 = $mkUser('solicitante', ['activo' => 0, 'correo' => 'zt_rec11@ufps.edu.co']);
$an->post('recuperar_clave.php', ['correo' => $u11['correo']]);
t('recuperar_clave: cuenta inactiva no genera enlace', $cc("SELECT COUNT(*) FROM recuperacion_clave WHERE id_usuario=?", [$u11['id']]) === 0);

$seedTok = function (int $idUsuario, string $interval = '+30 MINUTE') use ($pdo) {
    $tok = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO recuperacion_clave (id_usuario, token_hash, expira_en) VALUES (?, ?, NOW() " . ($interval[0] === '-' ? '- INTERVAL ' . substr($interval, 1) : '+ INTERVAL ' . substr($interval, 1)) . ")")->execute([$idUsuario, hash('sha256', $tok)]);
    return $tok;
};
$u12 = $mkUser('solicitante');
$pdo->prepare("INSERT INTO usuario_bloqueo (id_usuario, intentos_fallidos, bloqueado, bloqueado_en) VALUES (?, 3, 1, NOW())")->execute([$u12['id']]);
$tok = $seedTok($u12['id']);
$rs = new HttpClient($base);
$r = $rs->get('restablecer_clave.php?token=' . $tok);
t('restablecer: token vigente muestra el formulario', $r->status === 200 && $r->contains('name="nueva_clave"') && $r->contains('name="confirmar_clave"') && $r->contains('value="' . $tok . '"'));
t('restablecer: POST sin CSRF → 403', $rs->postRaw('restablecer_clave.php', ['token' => $tok, 'nueva_clave' => 'Restablecida-clave-Http', 'confirmar_clave' => 'Restablecida-clave-Http'])->status === 403);
$r = $rs->post('restablecer_clave.php?token=' . $tok, ['token' => $tok, 'nueva_clave' => 'corta', 'confirmar_clave' => 'corta']);
t('restablecer: clave corta → mensaje y el token sigue vigente', $r->contains('al menos 12 caracteres') && $cc("SELECT COUNT(*) FROM recuperacion_clave WHERE token_hash=? AND usado_en IS NULL", [hash('sha256', $tok)]) === 1);
$r = $rs->post('restablecer_clave.php?token=' . $tok, ['token' => $tok, 'nueva_clave' => 'Restablecida-clave-Http', 'confirmar_clave' => 'Otra-clave-distinta-Http']);
t('restablecer: confirmación distinta → mensaje', $r->contains('Las contraseñas no coinciden.'));
$r = $rs->post('restablecer_clave.php?token=' . $tok, ['token' => $tok, 'nueva_clave' => 'Restablecida-clave-Http', 'confirmar_clave' => 'Restablecida-clave-Http']);
$loginPage = $r->redirects() ? $rs->get($r->location()) : null;
t('restablecer: guarda la clave, gasta el token, desbloquea la cuenta y avisa en el login', $r->redirects() && strpos($r->location(), 'login.php') !== false && $loginPage && $loginPage->contains('Contraseña actualizada. Ya puedes iniciar sesión.')
    && password_verify('Restablecida-clave-Http', (string)$fc("SELECT password FROM users WHERE id=?", [$u12['id']])['password']) && $cc("SELECT COUNT(*) FROM recuperacion_clave WHERE token_hash=? AND usado_en IS NULL", [hash('sha256', $tok)]) === 0
    && $cc("SELECT COALESCE(MAX(bloqueado),0) FROM usuario_bloqueo WHERE id_usuario=?", [$u12['id']]) === 0);
[$cx, $rx] = $loginCon($u12, 'Restablecida-clave-Http');
t('restablecer: con la clave nueva se puede entrar', $rx->redirects() && !$rx->toLogin());
$r = (new HttpClient($base))->get('restablecer_clave.php?token=' . $tok);
t('restablecer: un token ya usado no sirve', $r->contains('El enlace no es válido o ya venció. Solicita uno nuevo.') && $r->contains('recuperar_clave.php'));
$tokVenc = $seedTok($u12['id'], '-5 MINUTE');
t('restablecer: un token vencido no sirve', (new HttpClient($base))->get('restablecer_clave.php?token=' . $tokVenc)->contains('El enlace no es válido o ya venció.'));
t('restablecer: token con formato inválido o ausente no sirve', (new HttpClient($base))->get('restablecer_clave.php?token=zzz')->contains('El enlace no es válido') && (new HttpClient($base))->get('restablecer_clave.php')->contains('El enlace no es válido'));
$tokInact = $seedTok($u11['id']);
t('restablecer: el token de una cuenta inactiva no sirve', (new HttpClient($base))->get('restablecer_clave.php?token=' . $tokInact)->contains('El enlace no es válido'));
$r = (new HttpClient($base))->get('restablecer_clave.php?token=' . urlencode('"><script>alert(1)</script>'));
t('restablecer: el token no se refleja sin escapar', !$r->contains('<script>alert(1)</script>'));

// ── google_callback.php
$r = $anon->get('google_callback.php?code=x&state=y');
t('google_callback: sin configuración de Google → 404 con aviso', $r->status === 404 && $r->contains('no está habilitado'));

// ── Correcciones
$uA = $mkUser('tecnico');
[$cA] = $loginCon($uA);
$cA->post('cambiar_clave.php', ['clave_actual' => $CLAVE, 'nueva_clave' => 'Auditada-clave-Http-41', 'confirmar_clave' => 'Auditada-clave-Http-41']);
t('P41 cambiar_clave: el cambio de contraseña queda en auditoría (EDITAR users)', $cc("SELECT COUNT(*) FROM auditoria WHERE id_usuario=? AND tabla_afectada='users' AND id_registro=? AND accion='EDITAR'", [$uA['id'], $uA['id']]) === 1);
$rr = (new HttpClient($base)); $uB = $mkUser('solicitante'); $rr->login($uB['codigo'], $uB['correo'], $CLAVE);
$r = $rr->post('cambiar_clave.php', ['clave_actual' => $CLAVE, 'nueva_clave' => 'Cambio-de-clave-Http', 'confirmar_clave' => 'Cambio-de-clave-Http']);
t('P41 cambiar_clave: el destino tras el cambio es tickets.php (como en login), no una URL antigua por rol', $r->redirects() && $r->location() === 'tickets.php');
$uS = $mkUser('secretaria'); [$cS] = $loginCon($uS);
$rS = $cS->post('cambiar_clave.php', ['clave_actual' => $CLAVE, 'nueva_clave' => 'Cambio-de-clave-Http', 'confirmar_clave' => 'Cambio-de-clave-Http']);
$dS = $rS->redirects() ? $cS->get($rS->location()) : null;
t('P41 cambiar_clave: la secretaría llega a su panel (antes la mandaba a tickets.php, que le niega el acceso)', $dS && $dS->status === 200 && !$dS->toLogin());
$uC = $mkUser('solicitante');
[$cC] = $loginCon($uC);
$cC->post('perfil.php', ['quitar_foto' => '1']);
t('P41 perfil: quitar foto sin foto previa no falla', $cC->get('perfil.php')->status === 200);
