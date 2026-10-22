<?php
/**
 * Pruebas HTTP de calidad (indicadores, SLA, encuesta) y seguridad ( verificación en dos pasos, bitácora,
 * respaldos, incidentes, antispam del QR, aviso de privacidad), todo administrado por el admin. Lo incluye
 * run_http_tests.php (usa $pdo, $base, $U, $C, $CLAVE, $hash, t(), seccion()).
 */
require_once __DIR__ . '/../../app/Infrastructure/Auth/Totp.php';

seccion('Calidad del servicio (SLA) e indicadores');
$adm = $C['admin'];
foreach (['indicadores.php', 'sla.php', 'sla.php?vista=acuerdos', 'seguridad.php', 'seguridad.php?vista=bitacora', 'seguridad.php?vista=respaldos', 'seguridad.php?vista=incidentes'] as $pg) {
    $r = $adm->get($pg);
    t("admin abre $pg", $r->status === 200 && !$r->toLogin() && $r->contains('app-sidebar'), 'status ' . $r->status);
    t("$pg es solo del admin (técnico, beca y solicitante rebotan)", $C['tecnico']->get($pg)->toLogin() && $C['beca_trabajo']->get($pg)->toLogin() && $C['solicitante']->get($pg)->toLogin());
}
$menuAdm = $adm->get('tickets.php');
t('menú del admin: grupos «Calidad del servicio» y «Seguridad»', $menuAdm->contains('href="indicadores.php"') && $menuAdm->contains('href="seguridad.php?vista=bitacora"') && $menuAdm->contains('Calidad y seguridad'));
t('menú del técnico: sin calidad ni seguridad', !$C['tecnico']->get('tickets.php')->contains('href="seguridad.php"'));
$r = $adm->get('indicadores.php');
t('indicadores: tarjetas de cumplimiento, FCR, satisfacción y tablas por prioridad', $r->contains('Cumplimiento SLA · solución') && $r->contains('Solución al primer contacto') && $r->contains('Cumplimiento por prioridad') && $r->contains('Equipos operativos por sala'));
$csv = $adm->get('indicadores.php?desde=' . date('Y-m-01') . '&hasta=' . date('Y-m-d') . '&exportar=csv');
t('indicadores: exporta CSV para Excel (adjunto, UTF-8 con BOM)', strpos($csv->header('content-type'), 'text/csv') !== false && strpos($csv->header('content-disposition'), 'attachment') !== false && strpos($csv->body, "\xEF\xBB\xBF") === 0);
$pdf = $adm->get('indicadores.php?exportar=pdf');
t('indicadores: exporta PDF', strpos($pdf->header('content-type'), 'application/pdf') !== false && strpos($pdf->body, '%PDF') === 0);

// Ticket de prueba: alta, toma y medición
$pdo->prepare("INSERT INTO tickets (user_id, nombre_dependencia, ubicacion, equipo, descripcion, categoria) VALUES (?, 'ZT', 'ZT', 'PC', 'ZT-SLA ticket abierto', 'HARDWARE')")->execute([$U['solicitante']['id']]);
$tkS = (int)$pdo->lastInsertId();
$r = $adm->get('sla.php');
t('SLA: el ticket nuevo aparece en «Tickets abiertos» con su semáforo y prioridad', $r->contains('#' . $tkS . '</strong>') && $r->contains('A tiempo') && $r->contains('Media'));
$r = $adm->post('sla.php', ['form' => 'prio-tickets-' . $tkS, 'tabla' => 'tickets', 'id' => $tkS, 'impacto' => 1, 'urgencia' => 1, 'ajustar_prioridad' => '1']);
t('SLA: el admin ajusta la prioridad (crítica)', $r->redirects() && $pdo->query("SELECT prioridad FROM ticket_sla WHERE tabla_ticket='tickets' AND id_ticket=$tkS")->fetchColumn() === 'CRITICA');
$adm->post('sla.php', ['form' => 'pausa-tickets-' . $tkS, 'tabla' => 'tickets', 'id' => $tkS, 'motivo' => 'ZT el usuario no está', 'alternar_pausa' => '1']);
t('SLA: pausar el reloj deja el ticket «En pausa»', $adm->get('sla.php?semaforo=pausa')->contains('#' . $tkS . '</strong>'));
$adm->post('sla.php', ['form' => 'pausa-tickets-' . $tkS, 'tabla' => 'tickets', 'id' => $tkS, 'alternar_pausa' => '1']);
t('SLA: reanudar cierra la pausa', (int)$pdo->query("SELECT COUNT(*) FROM ticket_pausas WHERE tabla_ticket='tickets' AND id_ticket=$tkS AND fin IS NULL")->fetchColumn() === 0);
$r = $adm->post('sla.php?vista=acuerdos', ['form' => 'festivo', 'fecha' => '2031-12-24', 'descripcion' => 'ZT cierre de prueba', 'agregar_festivo' => '1']);
t('Acuerdos: agrega un día no hábil y lo lista en su año', $r->redirects() && $adm->get('sla.php?vista=acuerdos&anio=2031')->contains('ZT cierre de prueba'));
$adm->post('sla.php?vista=acuerdos&anio=2031', ['fecha' => '2031-12-24', 'quitar_festivo' => '1']);
t('Acuerdos: lo quita', !$adm->get('sla.php?vista=acuerdos&anio=2031')->contains('ZT cierre de prueba'));
$rTec = $C['tecnico']->postRaw('sla.php', ['tabla' => 'tickets', 'id' => $tkS, 'impacto' => 3, 'urgencia' => 3, 'ajustar_prioridad' => '1']);
t('SLA: el técnico no puede ajustar prioridades (POST rechazado)', ($rTec->status === 403 || $rTec->toLogin())
    && $pdo->query("SELECT prioridad FROM ticket_sla WHERE tabla_ticket='tickets' AND id_ticket=$tkS")->fetchColumn() === 'CRITICA');

seccion('Encuesta de satisfacción');
// Solicitante con un ticket cerrado
$pdo->prepare("INSERT INTO tickets (user_id, nombre_dependencia, ubicacion, equipo, descripcion, estado) VALUES (?, 'ZT', 'ZT', 'PC', 'ZT-SLA cerrado', 'Cerrado')")->execute([$U['solicitante']['id']]);
$tkC = (int)$pdo->lastInsertId();
$r = $C['solicitante']->get('tickets.php?pagina_solucionados=1');
t('solicitante: su ticket cerrado ofrece «Calificar atención»', $r->contains('data-vf-abrir="vf-encuesta-' . $tkC . '"'));
$r = $C['solicitante']->post('tickets.php', ['id_ticket' => $tkC, 'calificacion' => 5, 'comentario' => 'ZT excelente', 'calificar_servicio' => '1']);
t('solicitante: califica y queda guardado una sola vez', $r->redirects() && (int)$pdo->query("SELECT calificacion FROM encuestas_satisfaccion WHERE tabla_ticket='tickets' AND id_ticket=$tkC")->fetchColumn() === 5
    && $C['solicitante']->get('tickets.php?pagina_solucionados=1')->contains('Calificado: 5/5'));
$r = $C['otro']->post('tickets.php', ['id_ticket' => $tkS, 'calificacion' => 1, 'calificar_servicio' => '1']);
t('encuesta: nadie califica un ticket ajeno', (int)$pdo->query("SELECT COUNT(*) FROM encuestas_satisfaccion WHERE tabla_ticket='tickets' AND id_ticket=$tkS")->fetchColumn() === 0);
// Profesor
$pdo->prepare("INSERT INTO tickets_profesor (profesor_id, tipo_ticket, origen, descripcion_problema, estado) VALUES (?, 'danio', 'PANEL_PROFESOR', 'ZT-SLA prof cerrado', 'Cerrado')")->execute([$U['profesor']['id']]);
$tpC = (int)$pdo->lastInsertId();
t('profesor: su ticket cerrado ofrece «Calificar atención»', $C['profesor']->get('tickets.php?pagina_solucionados=1')->contains('vf-encuesta-' . $tpC));
$C['profesor']->post('tickets.php', ['id_ticket' => $tpC, 'calificacion' => 4, 'calificar_servicio' => '1']);
t('profesor: la calificación queda guardada', (int)$pdo->query("SELECT calificacion FROM encuestas_satisfaccion WHERE tabla_ticket='tickets_profesor' AND id_ticket=$tpC")->fetchColumn() === 4);
// Reporte anónimo por código de seguimiento
$pdo->prepare("INSERT INTO tickets (descripcion, origen, codigo_seguimiento, estado) VALUES ('ZT-SLA qr cerrado', 'QR_ANONIMO', 'ZTENC001', 'Cerrado')")->execute();
$tkQ = (int)$pdo->lastInsertId();
$an = new HttpClient($base);
t('consulta pública: un reporte resuelto muestra la encuesta', $an->get('consultar_estado.php?codigo=ZTENC001')->contains('¿Cómo calificas la atención de tu reporte?'));
$r = $an->post('consultar_estado.php?codigo=ZTENC001', ['codigo' => 'ZTENC001', 'calificacion' => 3, 'calificar' => '1'], 'consultar_estado.php?codigo=ZTENC001');
t('consulta pública: califica con el código y ya no la vuelve a pedir', $r->contains('Gracias por calificar') && (int)$pdo->query("SELECT calificacion FROM encuestas_satisfaccion WHERE tabla_ticket='tickets' AND id_ticket=$tkQ")->fetchColumn() === 3
    && $an->get('consultar_estado.php?codigo=ZTENC001')->contains('Ya calificaste esta atención'));
t('indicadores: reflejan las encuestas del periodo', !$adm->get('indicadores.php')->contains('· 0 encuestas'));

seccion('QR público: antispam y aviso de privacidad');
$tokQ = 'ztq' . bin2hex(random_bytes(8));
$pdo->prepare("INSERT INTO salas (nombre_sala, abreviatura, codigo_qr_token) VALUES ('ZT-SALA-QRSEG', 'ZTQ', ?)")->execute([$tokQ]);
$idSalaQ = (int)$pdo->lastInsertId();
$aq = new HttpClient($base);
$r = $aq->get('reportar.php?token=' . $tokQ);
t('QR: el formulario pide la verificación y la aceptación del aviso de privacidad', $r->contains('¿Cuánto es') && $r->contains('name="acepto_privacidad"') && $r->contains('aviso_privacidad.php') && $r->contains('name="sitio_web"'));
$r = $aq->reportarQr($tokQ, ['descripcion' => 'ZT-QRSEG sin aceptar'], ['acepto_privacidad' => null]);
t('QR: sin aceptar el aviso no se envía', $r->contains('acepta el aviso de privacidad') && (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE id_sala=$idSalaQ")->fetchColumn() === 0);
$r = $aq->reportarQr($tokQ, ['descripcion' => 'ZT-QRSEG mal'], ['reto' => '999']);
t('QR: con la verificación incorrecta no se envía', $r->contains('pregunta de verificación no es correcta') && (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE id_sala=$idSalaQ")->fetchColumn() === 0);
$r = $aq->reportarQr($tokQ, ['descripcion' => 'ZT-QRSEG robot'], ['sitio_web' => 'http://spam']);
t('QR: si se llena el campo trampa no se envía y queda en la bitácora', (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE id_sala=$idSalaQ")->fetchColumn() === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM bitacora_accesos WHERE evento='QR_BLOQUEADO' AND detalle LIKE '%ZT-SALA-QRSEG%'")->fetchColumn() >= 1);
$r = $aq->reportarQr($tokQ, ['descripcion' => 'ZT-QRSEG correcto']);
t('QR: con todo en regla se crea el reporte', $r->contains('Reporte enviado') && (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE id_sala=$idSalaQ")->fetchColumn() === 1);
$r = (new HttpClient($base))->get('aviso_privacidad.php');
t('aviso de privacidad: página pública con la Ley 1581, derechos y retención de la IP', $r->status === 200 && $r->contains('Ley 1581') && $r->contains('Tus derechos') && $r->contains('se borra a los'));
t('login y portada enlazan el aviso de privacidad', (new HttpClient($base))->get('login.php')->contains('aviso_privacidad.php') && (new HttpClient($base))->get('index.php')->contains('aviso_privacidad.php'));
$pdo->exec("DELETE FROM ticket_eventos WHERE tabla_ticket='tickets' AND id_ticket IN (SELECT id FROM tickets WHERE id_sala=$idSalaQ)");
$pdo->exec("DELETE FROM ticket_sla WHERE tabla_ticket='tickets' AND id_ticket IN (SELECT id FROM tickets WHERE id_sala=$idSalaQ)");
$pdo->exec("DELETE FROM notificaciones WHERE mensaje LIKE '%ZT-SALA-QRSEG%'");
$pdo->exec("DELETE FROM tickets WHERE id_sala=$idSalaQ");
$pdo->exec("DELETE FROM salas WHERE id_sala=$idSalaQ");

seccion('Contraseñas, verificación en dos pasos y bitácora');
$insU = $pdo->prepare("INSERT INTO users (codigo, correo, password, password_temporal, role, nombre_completo, cargo, estado_activo) VALUES (?,?,?,0,?,?,'Prueba',1)");
$insU->execute(['ZTMFA1', 'zt_mfa1@test.local', $hash, 'tecnico', 'ZT tecnico mfa']);
$uM = ['id' => (int)$pdo->lastInsertId(), 'codigo' => 'ZTMFA1', 'correo' => 'zt_mfa1@test.local'];
$cm = cliente($uM, $CLAVE);
$r = $cm->post('cambiar_clave.php', ['clave_actual' => $CLAVE, 'nueva_clave' => 'Password2026!', 'confirmar_clave' => 'Password2026!']);
t('contraseñas: una clave común (aunque tenga 12+ caracteres y símbolos) se rechaza', $r->contains('muy común') && password_verify($CLAVE, (string)$pdo->query("SELECT password FROM users WHERE id={$uM['id']}")->fetchColumn()));
t('perfil del técnico: ofrece activar la verificación en dos pasos', $cm->get('perfil.php')->contains('Activar ahora'));
$r = $cm->get('verificacion.php');
$secreto = preg_match('/<code>([A-Z2-7 ]{20,})<\/code>/', $r->body, $mm) ? str_replace(' ', '', $mm[1]) : '';
t('2 pasos: la página muestra el QR (otpauth) y la clave para escribir a mano', $secreto !== '' && $r->contains('data-qr-texto="otpauth://totp/'));
$r = $cm->post('verificacion.php', ['codigo' => '000000', 'confirmar_inscripcion' => '1']);
t('2 pasos: un código equivocado no la activa', $r->contains('El código no coincide') && (int)$pdo->query("SELECT mfa_activo FROM users WHERE id={$uM['id']}")->fetchColumn() === 0);
$r = $cm->post('verificacion.php', ['codigo' => Totp::codigo($secreto, intdiv(time(), 30)), 'confirmar_inscripcion' => '1']);
$rc = $r->redirects() ? $cm->get($r->location()) : $r;
preg_match_all('/<li>([A-Z2-9]{4}-[A-Z2-9]{4})<\/li>/', $rc->body, $codigosRec);
t('2 pasos: con el código correcto se activa y muestra 8 códigos de recuperación una sola vez', count($codigosRec[1]) === 8 && (int)$pdo->query("SELECT mfa_activo FROM users WHERE id={$uM['id']}")->fetchColumn() === 1
    && !$cm->get('verificacion.php?codigos=1')->contains($codigosRec[1][0] ?? 'nada'));
$r = $cm->post('verificacion.php', ['continuar' => '1']);
$cm->get('logout.php');
$cm2 = cliente($uM, $CLAVE);
$r = $cm2->get('tickets.php');
t('2 pasos: tras la contraseña, cualquier página lleva a verificacion.php', $r->redirects() && strpos($r->location(), 'verificacion.php') !== false);
t('2 pasos: los endpoints AJAX responden 403 mientras falta el código', $cm2->get('buscar_global.php?q=zt')->status === 403);
$r = $cm2->post('verificacion.php', ['codigo' => '111111', 'verificar_codigo' => '1']);
t('2 pasos: código incorrecto → aviso con intentos restantes', $r->contains('Te quedan 4 intento'));
$r = $cm2->post('verificacion.php', ['codigo' => $codigosRec[1][0] ?? '', 'verificar_codigo' => '1']);
t('2 pasos: un código de recuperación permite entrar', $r->redirects() && !$cm2->get('tickets.php')->redirects());
t('bitácora: registró el ingreso con segundo factor y el intento fallido', (int)$pdo->query("SELECT COUNT(*) FROM bitacora_accesos WHERE id_usuario={$uM['id']} AND evento='MFA_OK'")->fetchColumn() === 1
    && (int)$pdo->query("SELECT COUNT(*) FROM bitacora_accesos WHERE id_usuario={$uM['id']} AND evento='MFA_FALLIDO'")->fetchColumn() >= 1
    && (int)$pdo->query("SELECT COUNT(*) FROM bitacora_accesos WHERE id_usuario={$uM['id']} AND evento='LOGIN_OK' AND detalle LIKE '%segundo factor%'")->fetchColumn() === 1);
$r = $adm->get('seguridad.php?vista=bitacora&q=zt_mfa1');
t('bitácora: el admin la consulta filtrada', $r->contains('Segundo factor correcto') && $r->contains('ZT tecnico mfa'));
$bcsv = $adm->get('seguridad.php?vista=bitacora&q=zt_mfa1&exportar=csv');
t('bitácora: exporta CSV', strpos($bcsv->header('content-type'), 'text/csv') !== false && strpos($bcsv->body, 'zt_mfa1@test.local') !== false);
$r = $adm->post('seguridad.php?vista=bitacora', ['verificar_bitacora' => '1']);
t('bitácora: «Verificar integridad» confirma la cadena', ($v = $adm->get($r->location())) && $v->contains('Íntegra'));
$r = $adm->get('usuarios.php');
t('usuarios: el admin ve quién tiene 2 pasos y puede reiniciarla', $r->contains('Con verificación en dos pasos') && $r->contains('name="reiniciar_mfa_id" value="' . $uM['id'] . '"'));
$adm->post('usuarios.php', ['reiniciar_mfa_id' => $uM['id']]);
t('usuarios: reiniciar la apaga y queda en la bitácora', (int)$pdo->query("SELECT mfa_activo FROM users WHERE id={$uM['id']}")->fetchColumn() === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM bitacora_accesos WHERE id_usuario={$uM['id']} AND evento='MFA_REINICIADO'")->fetchColumn() === 1);
// Obligatoria
$pdo->exec("INSERT INTO config_sistema (clave, valor) VALUES ('seg_mfa_obligatorio', '1') ON DUPLICATE KEY UPDATE valor = '1'");
$cm3 = cliente($uM, $CLAVE);
$r = $cm3->get('tickets.php');
$rv = $cm3->get('verificacion.php');
t('2 pasos obligatoria: sin configurarla no se usa el sistema; se configura al entrar', $r->redirects() && strpos($r->location(), 'verificacion.php') !== false && $rv->contains('hizo obligatoria'));
t('2 pasos obligatoria: no aplica a otros roles (profesor entra normal)', !cliente($U['profesor'], $CLAVE)->get('tickets.php')->redirects());
$pdo->exec("UPDATE config_sistema SET valor = '0' WHERE clave = 'seg_mfa_obligatorio'");

seccion('Seguridad: configuración, respaldos e incidentes');
$r = $adm->post('seguridad.php', ['form' => 'config', 'seg_forzar_https' => '1', 'seg_retencion_bitacora_dias' => '365', 'seg_retencion_ip_dias' => '180', 'seg_qr_max_por_hora' => '100',
                                  'seg_respaldos_conservar' => '10', 'priv_responsable' => 'UFPS', 'priv_correo' => '', 'seg_qr_captcha' => '1', 'guardar_config' => '1']);
$rv = $adm->get($r->location());
t('config: forzar HTTPS por http:// se rechaza (no deja al admin por fuera)', $rv->contains('entra al sistema por https://') && $pdo->query("SELECT valor FROM config_sistema WHERE clave='seg_forzar_https'")->fetchColumn() !== '1');
$r = $adm->post('seguridad.php', ['form' => 'config', 'seg_retencion_bitacora_dias' => '365', 'seg_retencion_ip_dias' => '180', 'seg_qr_max_por_hora' => '100',
                                  'seg_respaldos_conservar' => '10', 'priv_responsable' => 'ZT Responsable de datos', 'priv_correo' => 'datos@ufps.edu.co', 'seg_qr_captcha' => '1', 'guardar_config' => '1']);
t('config: guarda el responsable de los datos y se ve en el aviso de privacidad', $adm->get($r->location())->contains('Configuración de seguridad guardada') && (new HttpClient($base))->get('aviso_privacidad.php')->contains('ZT Responsable de datos'));
$pdo->exec("DELETE FROM config_sistema WHERE clave IN ('priv_responsable', 'priv_correo')");
t('panel: el diagnóstico avisa que la app usa root y que no hay HTTPS', ($p = $adm->get('seguridad.php')) && $p->contains('Usuario de la base de datos') && $p->contains('Riesgo') && $p->contains('tools/usuario_minimo_bd.sql'));
$r = $adm->post('seguridad.php?vista=respaldos', ['form' => 'generar', 'frase' => 'zt-frase-http-2026', 'frase_confirmar' => 'zt-frase-http-2026', 'generar_respaldo' => '1']);
$idResp = (int)$pdo->query("SELECT MAX(id_respaldo) FROM respaldos WHERE id_usuario=" . (int)$U['admin']['id'])->fetchColumn();
t('respaldos: el admin genera un respaldo cifrado', $idResp > 0 && $adm->get($r->location())->contains('Respaldo cifrado generado'));
$d = $adm->get('seguridad.php?vista=respaldos&descargar=' . $idResp);
t('respaldos: la descarga es el archivo cifrado (no SQL legible)', strpos($d->header('content-type'), 'application/octet-stream') !== false && strpos($d->body, 'UFPSBK1') === 0 && strpos($d->body, 'INSERT INTO') === false);
t('respaldos: nadie más los descarga', $C['tecnico']->get('seguridad.php?vista=respaldos&descargar=' . $idResp)->toLogin());
$r = $adm->post('seguridad.php?vista=respaldos', ['form' => 'verificar-' . $idResp, 'id' => $idResp, 'frase' => 'zt-frase-http-2026', 'verificar_respaldo' => '1']);
t('respaldos: «Verificar» descifra y prueba la restauración', ($v = $adm->get($r->location())) && $v->contains('Restaurable'));
$adm->post('seguridad.php?vista=respaldos', ['id' => $idResp, 'eliminar_respaldo' => '1']);
t('respaldos: se eliminan del servidor', (int)$pdo->query("SELECT COUNT(*) FROM respaldos WHERE id_respaldo=$idResp")->fetchColumn() === 0);
$C['solicitante']->post('create_ticket.php', ['dependencia' => 'ZT', 'ubicacion' => 'ZT', 'equipo' => 'PC', 'descripcion' => 'ZT-SEG me llegó un correo pidiendo la clave', 'categoria' => 'SEGURIDAD']);
$tkSeg = (int)$pdo->query("SELECT id FROM tickets WHERE descripcion='ZT-SEG me llegó un correo pidiendo la clave'")->fetchColumn();
t('incidentes: el ticket «Incidente de seguridad» avisa al admin y abre su registro', $tkSeg > 0
    && (int)$pdo->query("SELECT COUNT(*) FROM notificaciones WHERE id_usuario=" . (int)$U['admin']['id'] . " AND mensaje LIKE 'Incidente de seguridad reportado%'")->fetchColumn() >= 1
    && $adm->get('seguridad.php?vista=incidentes')->contains('Ticket #' . $tkSeg));
$idInc = (int)$pdo->query("SELECT id_incidente FROM incidentes_seguridad WHERE tabla_ticket='tickets' AND id_ticket=$tkSeg")->fetchColumn();
$r = $adm->post('seguridad.php?vista=incidentes', ['form' => 'inc-' . $idInc, 'id' => $idInc, 'estado' => 'CERRADO', 'titulo' => 'ZT phishing', 'tipo' => 'PHISHING', 'severidad' => 'ALTA', 'actualizar_incidente' => '1']);
t('incidentes: no se cierra sin contención, causa y lección (la ventana se reabre con el error)', ($v = $adm->get($r->location())) && $v->contains('data-vf-abierta') && $pdo->query("SELECT estado FROM incidentes_seguridad WHERE id_incidente=$idInc")->fetchColumn() === 'REPORTADO');
$adm->post('seguridad.php?vista=incidentes', ['form' => 'inc-' . $idInc, 'id' => $idInc, 'estado' => 'CERRADO', 'titulo' => 'ZT phishing', 'tipo' => 'PHISHING', 'severidad' => 'ALTA',
    'contencion' => 'Se cambió la clave', 'causa' => 'Correo falso', 'leccion' => 'Campaña de sensibilización', 'afecta_confidencialidad' => '1', 'actualizar_incidente' => '1']);
t('incidentes: con todo diligenciado queda cerrado', $pdo->query("SELECT estado FROM incidentes_seguridad WHERE id_incidente=$idInc")->fetchColumn() === 'CERRADO');
$pdo->exec("DELETE FROM notificaciones WHERE mensaje LIKE 'Incidente de seguridad reportado%ZT-SEG%'");
