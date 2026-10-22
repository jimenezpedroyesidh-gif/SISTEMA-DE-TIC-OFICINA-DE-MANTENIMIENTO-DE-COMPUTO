<?php
/**
 * Pruebas HTTP del módulo Soluciones técnicas (se incluye desde run_http_tests.php, que aporta $pdo, $U, $C,
 * $hash, $CLAVE, cliente(), t() y seccion()). Ciclo completo: catálogo → versión con archivos (SHA-256) →
 * aprobación del admin → procedimientos → objetivos → despliegue manual y verificación → mantenimiento programado
 * con equipos asignados a la beca → tarea de la beca → cierre del mantenimiento y sincronización → versión
 * desactualizada y retiro. Además permisos por rol, CSRF, archivos no permitidos, integridad y XSS.
 */
seccion('Soluciones técnicas: catálogo, versiones, despliegue, mantenimiento y tareas de la beca');

$cs = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); })();
$fs = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(); })();
$tecST = $C['tecnico'];
$admST = $C['admin'];
$becaST = $C['beca_trabajo'];
$PNG_ST = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$ST = 'soluciones_tecnicas.php';

// Segunda beca (sin tareas) para comprobar que no ve lo ajeno.
$pdo->prepare("INSERT INTO users (codigo, correo, password, password_temporal, role, nombre_completo, cargo, estado_activo) VALUES ('ZTSOLB1', 'zt_beca_st@test.local', ?, 0, 'beca_trabajo', 'ZT beca ST', 'Prueba', 1)")->execute([$hash]);
$U['beca_st'] = ['id' => (int)$pdo->lastInsertId(), 'correo' => 'zt_beca_st@test.local', 'codigo' => 'ZTSOLB1'];
$becaOtraST = cliente($U['beca_st'], $CLAVE);

// Salas y equipos: dos PC con torre y un videobeam en una sala; otro PC en una segunda sala.
$salaST = function (string $n, string $a) use ($pdo) {
    $pdo->prepare("INSERT INTO salas (nombre_sala, abreviatura, dependencia, codigo_qr_token) VALUES (?, ?, 'x', ?)")->execute([$n, $a, 'zt' . bin2hex(random_bytes(8))]);
    return (int)$pdo->lastInsertId();
};
$equipoST = function (int $sala, string $cod, string $tipo) use ($pdo) {
    $pdo->prepare("INSERT INTO equipos (id_sala, codigo, descripcion) VALUES (?,?,'ZT')")->execute([$sala, $cod]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO componentes (tipo, serial, codigo_inventario, estado, id_equipo_actual) VALUES (?, ?, ?, 'ACTIVO', ?)")->execute([$tipo, 'ZT-SER-' . $cod, 'ZT-INV-' . $cod, $id]);
    return $id;
};
$sA = $salaST('ZT-SALA-ST-A', 'ZSA');
$sB = $salaST('ZT-SALA-ST-B', 'ZSB');
$pc1 = $equipoST($sA, 'ZT-ST-PC1', 'torre');
$pc2 = $equipoST($sA, 'ZT-ST-PC2', 'torre');
$vb = $equipoST($sA, 'ZT-ST-VB', 'videobeam');
$pc3 = $equipoST($sB, 'ZT-ST-PC3', 'todo_en_uno');

// ── Acceso por rol
foreach (['solicitante', 'profesor', 'secretaria'] as $r) {
    t("Soluciones: $r no entra al módulo ni a la descarga", $C[$r]->get($ST)->denied() && $C[$r]->get('descargar_solucion.php?archivo=1')->denied());
}
$r = $tecST->get($ST);
t('Soluciones: el técnico ve el panel de seguimiento', $r->status === 200 && $r->contains('Cobertura por solución'));
$r = $tecST->get("$ST?vista=catalogo");
t('Soluciones: el técnico ve «+ Nueva solución» en el catálogo', $r->contains('+ Nueva solución'));
t('Soluciones: el admin ve el catálogo pero no crea', ($r2 = $admST->get("$ST?vista=catalogo"))->status === 200 && !$r2->contains('+ Nueva solución'));
$r = $becaST->get($ST);
t('Soluciones: la beca solo ve «Mis tareas técnicas»', $r->contains('Mis tareas técnicas') && !$r->contains('Cobertura por solución'));
t('Soluciones: la beca no llega al catálogo ni a una ficha', !$becaST->get("$ST?vista=catalogo")->contains('Catálogo de soluciones') && !$becaST->get("$ST?id=1")->contains('Versiones y archivos'));
t('Soluciones: el menú del técnico y de la beca enlazan el módulo', $tecST->get('tickets.php')->contains('soluciones_tecnicas.php?vista=catalogo') && $becaST->get('tickets.php')->contains('Mis tareas técnicas'));

// ── Catálogo
$datosSol = ['accion_st' => 'crear', 'nombre' => 'ZT-Sol GLPI Agent', 'id_tipo' => '3', 'descripcion' => 'Agente que reporta el inventario', 'objetivo' => 'Inventario al día en GLPI', 'id_responsable' => (string)$U['tecnico']['id']];
$r = $admST->post("$ST?vista=catalogo", $datosSol);
t('Catálogo: el admin no puede crear soluciones', !$r->redirects() && (int)$cs("SELECT COUNT(*) FROM st_soluciones WHERE nombre LIKE 'ZT-%'") === 0);
$r = $becaST->post($ST, $datosSol);
t('Catálogo: la beca no puede crear soluciones', (int)$cs("SELECT COUNT(*) FROM st_soluciones WHERE nombre LIKE 'ZT-%'") === 0);
$r = $tecST->post("$ST?vista=catalogo", ['nombre' => ''] + $datosSol);
t('Catálogo: sin nombre se rechaza', !$r->redirects() && $r->contains('Completa el nombre'));
$r = $tecST->post("$ST?vista=catalogo", $datosSol);
$idSol = (int)$cs("SELECT id_solucion FROM st_soluciones WHERE nombre = 'ZT-Sol GLPI Agent'");
t('Catálogo: el técnico crea la solución y va a su ficha', $idSol > 0 && $r->redirects() && strpos($r->location(), "id=$idSol") !== false, $r->location());
$sol = $fs("SELECT * FROM st_soluciones WHERE id_solucion = ?", [$idSol]);
t('Catálogo: código automático ST-NNNN, en borrador y con responsable técnico', $sol['codigo'] === sprintf('ST-%04d', $idSol) && $sol['estado'] === 'BORRADOR' && (int)$sol['id_responsable'] === $U['tecnico']['id']);
$tecST->post("$ST?vista=catalogo", ['nombre' => 'ZT-Sol dup 1', 'codigo' => 'zt-dup'] + $datosSol);
$r = $tecST->post("$ST?vista=catalogo", ['nombre' => 'ZT-Sol dup 2', 'codigo' => 'ZT-DUP'] + $datosSol);
t('Catálogo: el código es único (sin distinguir mayúsculas)', $r->contains('Ya existe una solución con el código ZT-DUP') && (int)$cs("SELECT COUNT(*) FROM st_soluciones WHERE codigo = 'ZT-DUP'") === 1);
$r = $tecST->post("$ST?id=$idSol", ['accion_st' => 'editar', 'id_solucion' => $idSol, 'nombre' => 'ZT-Sol GLPI Agent <script>alert(1)</script>', 'id_responsable' => (string)$U['beca_trabajo']['id']] + $datosSol);
$sol = $fs("SELECT * FROM st_soluciones WHERE id_solucion = ?", [$idSol]);
t('Catálogo: editar guarda el cambio, descarta un responsable que no es técnico y deja el valor anterior en el historial',
    $sol['id_responsable'] === null && strpos((string)$cs("SELECT detalle FROM st_historial WHERE id_solucion = ? AND accion = 'EDITADA'", [$idSol]), 'ZT-Sol GLPI Agent') !== false);
$r = $tecST->get("$ST?id=$idSol");
t('Catálogo: el nombre con <script> se muestra escapado', $r->contains('&lt;script&gt;alert(1)&lt;/script&gt;') && !$r->contains('<script>alert(1)</script>'));

// ── Versiones y archivos
$PS1 = "Write-Host 'ZT instalar agente'\r\n";
$r = $tecST->postFile("$ST?id=$idSol&sec=versiones", ['accion_st' => 'version', 'id_solucion' => $idSol, 'numero' => '1.0', 'notas_cambios' => 'Primera versión del agente'],
    ['archivos[0]' => ['evil.php', '<?php echo 1;', 'text/plain']]);
t('Versiones: un .php se rechaza y no crea la versión', $r->contains('tipo de archivo no permitido') && (int)$cs("SELECT COUNT(*) FROM st_versiones WHERE id_solucion = ?", [$idSol]) === 0);
$r = $tecST->postFile("$ST?id=$idSol&sec=versiones", ['accion_st' => 'version', 'id_solucion' => $idSol, 'numero' => '1.0', 'notas_cambios' => 'Primera versión del agente'],
    ['archivos[0]' => ['instalar.ps1', $PS1, 'text/plain'], 'archivos[1]' => ['config agente ñ.json', '{"server":"https://glpi"}', 'application/json']]);
$v1 = $fs("SELECT * FROM st_versiones WHERE id_solucion = ? AND numero = '1.0'", [$idSol]);
t('Versiones: el técnico sube la versión 1.0 y queda en revisión', $r->redirects() && $v1 && $v1['estado'] === 'EN_REVISION');
$arch = $fs("SELECT * FROM st_archivos WHERE id_version = ? AND nombre_original = 'instalar.ps1'", [(int)$v1['id_version']]);
t('Versiones: el archivo guarda su SHA-256, categoría y un nombre aleatorio .dat en storage/soluciones',
    $arch && $arch['sha256'] === hash('sha256', $PS1) && $arch['categoria'] === 'SCRIPT' && preg_match('/^[0-9a-f]{32}\.dat$/', $arch['nombre_almacenado'])
    && is_file(__DIR__ . '/../../storage/soluciones/' . $arch['nombre_almacenado']));
t('Versiones: se avisó al admin para que la apruebe', (int)$cs("SELECT COUNT(*) FROM notificaciones WHERE id_usuario = ? AND mensaje LIKE '%por aprobar%'", [$U['admin']['id']]) >= 1);
$r = $tecST->postFile("$ST?id=$idSol&sec=versiones", ['accion_st' => 'version', 'id_solucion' => $idSol, 'numero' => '1.0', 'notas_cambios' => 'Repetida otra vez'], ['archivos[0]' => ['otro.ps1', 'x', 'text/plain']]);
t('Versiones: el número de versión no se repite', $r->contains('ya existe'));

// ── Descarga protegida e integridad
$idArch = (int)$arch['id_archivo'];
$r = $tecST->get("descargar_solucion.php?archivo=$idArch");
t('Descarga: el técnico baja el archivo tal cual, como adjunto y sin que el navegador lo interprete',
    $r->status === 200 && $r->body === $PS1 && stripos($r->header('content-disposition'), 'attachment') === 0 && $r->header('content-type') === 'application/octet-stream' && $r->header('x-content-type-options') === 'nosniff');
t('Descarga: la beca sin tarea no baja archivos (403)', $becaST->get("descargar_solucion.php?archivo=$idArch")->status === 403);
t('Descarga: un id inexistente o inválido da 404', $tecST->get('descargar_solucion.php?archivo=999999999')->status === 404 && $tecST->get('descargar_solucion.php?archivo=../../config/database')->status === 404);
$rutaArch = __DIR__ . '/../../storage/soluciones/' . $arch['nombre_almacenado'];
file_put_contents($rutaArch, 'alterado', FILE_APPEND);
t('Descarga: si el archivo cambió en el disco (SHA-256 distinto) no se entrega (409)', $tecST->get("descargar_solucion.php?archivo=$idArch")->status === 409);
file_put_contents($rutaArch, $PS1);

// ── Aprobación (solo admin)
$tecST->post("$ST?id=$idSol&sec=versiones", ['accion_st' => 'revisar_version', 'id_version' => $v1['id_version'], 'decision' => 'APROBADA']);
t('Aprobación: el técnico no puede aprobar', $cs("SELECT estado FROM st_versiones WHERE id_version = ?", [$v1['id_version']]) === 'EN_REVISION');
$r = $admST->post("$ST?id=$idSol&sec=versiones", ['accion_st' => 'revisar_version', 'id_version' => $v1['id_version'], 'decision' => 'RECHAZADA', 'observacion' => '']);
t('Aprobación: rechazar exige una observación', $r->contains('Explica por qué') && $cs("SELECT estado FROM st_versiones WHERE id_version = ?", [$v1['id_version']]) === 'EN_REVISION');
$r = $admST->post("$ST?id=$idSol&sec=versiones", ['accion_st' => 'revisar_version', 'id_version' => $v1['id_version'], 'decision' => 'APROBADA', 'observacion' => 'Probada en laboratorio']);
t('Aprobación: el admin aprueba y la solución queda activa', $r->redirects() && $cs("SELECT estado FROM st_versiones WHERE id_version = ?", [$v1['id_version']]) === 'APROBADA'
    && $cs("SELECT estado FROM st_soluciones WHERE id_solucion = ?", [$idSol]) === 'ACTIVA');
$r = $admST->post("$ST?id=$idSol&sec=versiones", ['accion_st' => 'revisar_version', 'id_version' => $v1['id_version'], 'decision' => 'RECHAZADA', 'observacion' => 'otra vez']);
t('Aprobación: una versión ya revisada no se vuelve a revisar', $r->contains('ya fue revisada') && $cs("SELECT estado FROM st_versiones WHERE id_version = ?", [$v1['id_version']]) === 'APROBADA');

// ── Procedimientos (redacción nueva sin sobrescribir)
$proc = ['accion_st' => 'procedimiento', 'id_solucion' => $idSol, 'fase' => 'INSTALACION', 'titulo' => 'ZT Instalar el agente', 'pasos' => "1. Ejecutar instalar.ps1\n2. Revisar el servicio"];
$r = $tecST->post("$ST?id=$idSol&sec=procedimientos", ['enlace_video' => 'javascript:alert(1)'] + $proc);
t('Procedimientos: un enlace de video que no es http(s) se rechaza', $r->contains('no es una URL válida') && (int)$cs("SELECT COUNT(*) FROM st_procedimientos WHERE id_solucion = ?", [$idSol]) === 0);
$r = $tecST->postFile("$ST?id=$idSol&sec=procedimientos", ['enlace_video' => 'https://video.example/zt'] + $proc, ['capturas[0]' => ['paso1.png', $PNG_ST, 'image/png']]);
$p1 = (int)$cs("SELECT id_procedimiento FROM st_procedimientos WHERE id_solucion = ? AND vigente = 1", [$idSol]);
t('Procedimientos: el técnico documenta la instalación con una captura', $r->redirects() && $p1 > 0 && (int)$cs("SELECT COUNT(*) FROM st_evidencias WHERE id_procedimiento = ?", [$p1]) === 1);
$r = $tecST->post("$ST?id=$idSol&sec=procedimientos", ['id_reemplaza' => $p1, 'titulo' => 'ZT Instalar el agente (v2)'] + $proc);
$p2 = (int)$cs("SELECT id_procedimiento FROM st_procedimientos WHERE id_solucion = ? AND vigente = 1", [$idSol]);
t('Procedimientos: actualizar crea una redacción nueva y conserva la anterior', $p2 > $p1 && (int)$cs("SELECT vigente FROM st_procedimientos WHERE id_procedimiento = ?", [$p1]) === 0
    && (int)$cs("SELECT id_reemplaza FROM st_procedimientos WHERE id_procedimiento = ?", [$p2]) === $p1);
$r = $tecST->post("$ST?id=$idSol&sec=procedimientos", ['id_reemplaza' => $p1, 'titulo' => 'ZT otra'] + $proc);
t('Procedimientos: no se puede reemplazar una redacción que ya no está vigente', $r->contains('ya fue reemplazado') && (int)$cs("SELECT COUNT(*) FROM st_procedimientos WHERE id_solucion = ?", [$idSol]) === 2);

// ── Dónde se aplica (objetivos) y despliegues
$r = $tecST->post("$ST?id=$idSol", ['accion_st' => 'objetivos', 'id_solucion' => $idSol, 'equipos' => 'ZT-NO-EXISTE']);
t('Objetivos: un código de equipo inexistente se rechaza', $r->contains('No hay equipos con estos códigos'));
$r = $tecST->post("$ST?id=$idSol", ['accion_st' => 'objetivos', 'id_solucion' => $idSol, 'salas' => [$sA], 'equipos' => 'zt-st-pc3']);
$desp = fn(int $eq) => $fs("SELECT * FROM st_despliegues WHERE id_solucion = ? AND id_equipo = ?", [$idSol, $eq]);
t('Objetivos: sala completa + equipo puntual crean despliegues pendientes solo en equipos de cómputo',
    $r->redirects() && ($desp($pc1)['estado'] ?? '') === 'PENDIENTE' && ($desp($pc2)['estado'] ?? '') === 'PENDIENTE' && ($desp($pc3)['estado'] ?? '') === 'PENDIENTE' && $desp($vb) === false);
$r = $tecST->post("$ST?id=$idSol", ['accion_st' => 'objetivos', 'id_solucion' => $idSol, 'salas' => [$sA]]);
t('Objetivos: repetir la misma sala no la duplica', $r->contains('ya estaba') && (int)$cs("SELECT COUNT(*) FROM st_objetivos WHERE id_solucion = ? AND activo = 1", [$idSol]) === 2);

$d3 = (int)$desp($pc3)['id_despliegue'];
$accionST = fn(array $d, array $files = []) => $tecST->postFile("$ST?id=$idSol&sec=despliegue", $d + ['accion_st' => 'despliegue', 'id_solucion' => $idSol], $files);
$accionST(['accion' => 'IMPLEMENTAR', 'despliegues[0]' => $d3, 'observaciones' => 'Instalado por GPO']);
t('Despliegue: registrar implementación deja el equipo implementado con la versión vigente', ($desp($pc3)['estado'] ?? '') === 'IMPLEMENTADO' && (int)$desp($pc3)['id_version'] === (int)$v1['id_version']);
$r = $accionST(['accion' => 'VERIFICAR', 'resultado' => 'FALLIDO', 'despliegues[0]' => $d3, 'observaciones' => '']);
t('Despliegue: verificar sin observaciones se rechaza', $r->contains('Escribe qué revisaste') && (int)$cs("SELECT COUNT(*) FROM st_verificaciones WHERE id_despliegue = ?", [$d3]) === 0);
$r = $accionST(['accion' => 'VERIFICAR', 'resultado' => 'FALLIDO', 'despliegues[0]' => $d3, 'observaciones' => 'El servicio no arranca'], ['evidencias[0]' => ['error.png', $PNG_ST, 'image/png']]);
$vf = $fs("SELECT * FROM st_verificaciones WHERE id_despliegue = ?", [$d3]);
t('Despliegue: una verificación fallida queda registrada (responsable, versión, evidencia) y el equipo con incidencia',
    $r->redirects() && $vf && (int)$vf['id_usuario'] === $U['tecnico']['id'] && (int)$vf['id_version'] === (int)$v1['id_version'] && ($desp($pc3)['estado'] ?? '') === 'CON_INCIDENCIA'
    && (int)$cs("SELECT COUNT(*) FROM st_evidencias WHERE id_verificacion = ?", [$vf['id_verificacion']]) === 1);
$accionST(['accion' => 'VERIFICAR', 'resultado' => 'CORRECTO', 'despliegues[0]' => $d3, 'observaciones' => 'Reinstalado y funcionando']);
t('Despliegue: la verificación nueva se agrega sin borrar la anterior', (int)$cs("SELECT COUNT(*) FROM st_verificaciones WHERE id_despliegue = ?", [$d3]) === 2 && ($desp($pc3)['estado'] ?? '') === 'VERIFICADO');
$r = $C['admin']->post("$ST?id=$idSol&sec=despliegue", ['accion_st' => 'despliegue', 'id_solucion' => $idSol, 'accion' => 'REVERTIR', 'despliegues' => [$d3], 'observaciones' => 'Prueba admin']);
t('Despliegue: el admin supervisa pero no cambia el estado de los equipos', ($desp($pc3)['estado'] ?? '') === 'VERIFICADO');

// ── Programar en el módulo de mantenimiento con los equipos asignados a la beca
$d1 = (int)$desp($pc1)['id_despliegue'];
$d2 = (int)$desp($pc2)['id_despliegue'];
$fechaST = date('Y-m-d\TH:i', strtotime('+40 days 08:00'));
$r = $tecST->post("$ST?id=$idSol&sec=despliegue", ['accion_st' => 'programar', 'id_solucion' => $idSol, 'id_sala' => $sB, 'despliegues' => [$d3], 'fecha_programada' => $fechaST, 'duracion_minutos' => '60']);
t('Programar: un equipo ya verificado con la versión vigente no se programa', $r->contains('Marca al menos un equipo'));
$r = $tecST->post("$ST?id=$idSol&sec=despliegue", ['accion_st' => 'programar', 'id_solucion' => $idSol, 'id_sala' => $sA, 'despliegues' => [$d1, $d2], 'fecha_programada' => $fechaST,
    'duracion_minutos' => '60', 'id_responsable' => $U['tecnico']['id'], 'id_asignado' => $U['beca_trabajo']['id']]);
$idProgST = (int)$cs("SELECT id_programado FROM st_mantenimiento_detalle WHERE id_solucion = ?", [$idSol]);
$mST = $fs("SELECT * FROM mantenimientos_programados WHERE id_programado = ?", [$idProgST]);
t('Programar: crea un mantenimiento de sala real (ciertos equipos, instalación de software) ligado a la solución',
    $r->redirects() && $mST && $mST['alcance_equipo'] === 'SELECCION' && $mST['tipo'] === 'INSTALACION_SOFTWARE' && (int)$mST['id_sala'] === $sA && strpos((string)$mST['actividades'], 'ZT Instalar el agente (v2)') !== false);
t('Programar: la checklist tiene los dos equipos asignados a la beca y los despliegues quedan programados',
    (int)$cs("SELECT COUNT(*) FROM mantenimiento_equipos WHERE id_programado = ? AND id_usuario_asignado = ?", [$idProgST, $U['beca_trabajo']['id']]) === 2
    && ($desp($pc1)['estado'] ?? '') === 'PROGRAMADO' && (int)$desp($pc2)['id_programado'] === $idProgST);
t('Programar: se avisó a la beca de su tarea', (int)$cs("SELECT COUNT(*) FROM notificaciones WHERE id_usuario = ? AND url_destino = 'soluciones_tecnicas.php'", [$U['beca_trabajo']['id']]) >= 1);
$accionST(['accion' => 'IMPLEMENTAR', 'despliegues[0]' => $d1]);
t('Programar: un equipo en un mantenimiento no se cambia a mano (se actualiza al cerrarlo)', ($desp($pc1)['estado'] ?? '') === 'PROGRAMADO');
t('Programar: el mantenimiento aparece en «Mantenimientos» de la beca', $becaST->get('mantenimientos_programados.php')->contains('Solución técnica ' . $sol['codigo']));

// ── Tareas de la beca
$r = $becaST->get($ST);
t('Tareas: la beca ve su tarea con instrucciones, archivos y sus equipos', $r->contains($sol['codigo']) && $r->contains('instalar.ps1') && $r->contains('ZT Instalar el agente (v2)') && $r->contains('ZT-ST-PC1'));
t('Tareas: otra beca sin equipos no ve la tarea', !$becaOtraST->get($ST)->contains($sol['codigo']));
t('Descarga: la beca baja el archivo de la versión de su tarea; otra beca no', $becaST->get("descargar_solucion.php?archivo=$idArch")->body === $PS1 && $becaOtraST->get("descargar_solucion.php?archivo=$idArch")->status === 403);
$idCaptura = (int)$cs("SELECT id_evidencia FROM st_evidencias WHERE id_procedimiento = ?", [$p1]);
t('Descarga: la beca abre las capturas de los procedimientos de su tarea; otra beca no', $becaST->get("descargar_solucion.php?evidencia=$idCaptura")->status === 200 && $becaOtraST->get("descargar_solucion.php?evidencia=$idCaptura")->status === 403);
$r = $becaST->postFile($ST, ['accion_st' => 'evidencia_tarea', 'id_despliegue' => $d3, 'nota' => 'No es mío'], ['evidencias[0]' => ['x.png', $PNG_ST, 'image/png']]);
t('Tareas: la beca no sube evidencia a un equipo que no está en sus tareas', $r->contains('no está en tus tareas') && (int)$cs("SELECT COUNT(*) FROM st_evidencias WHERE id_despliegue = ?", [$d3]) === 0);
$r = $becaST->postFile($ST, ['accion_st' => 'evidencia_tarea', 'id_despliegue' => $d1, 'nota' => 'Agente instalado y reportando'], ['evidencias[0]' => ['ok.png', $PNG_ST, 'image/png']]);
$idEvBeca = (int)$cs("SELECT id_evidencia FROM st_evidencias WHERE id_despliegue = ?", [$d1]);
t('Tareas: la beca sube la evidencia de ejecución de su equipo', $r->redirects() && $idEvBeca > 0 && $becaST->get("descargar_solucion.php?evidencia=$idEvBeca")->status === 200);
t('Tareas: un PHP disfrazado de imagen no se acepta como evidencia', $becaST->postFile($ST, ['accion_st' => 'evidencia_tarea', 'id_despliegue' => $d1, 'nota' => 'Malicioso'], ['evidencias[0]' => ['foto.png', '<?php system($_GET[1]);', 'image/png']])->contains('Adjunta al menos una foto'));

// ── Cierre del mantenimiento → los despliegues toman el resultado de la checklist (una sola vez)
$pdo->prepare("UPDATE mantenimiento_equipos SET estado = 'REALIZADO', id_usuario_ultimo = ?, fecha_actualizacion = NOW() WHERE id_programado = ? AND id_equipo = ?")->execute([$U['beca_trabajo']['id'], $idProgST, $pc1]);
$pdo->prepare("UPDATE mantenimiento_equipos SET estado = 'INCOMPLETO', observaciones = 'ZT sin red' WHERE id_programado = ? AND id_equipo = ?")->execute([$idProgST, $pc2]);
$pdo->prepare("UPDATE mantenimientos_programados SET estado = 'REALIZADO', fecha_realizado = NOW() WHERE id_programado = ?")->execute([$idProgST]);
$tecST->get("$ST?id=$idSol&sec=despliegue");
$tecST->get($ST);
t('Sincronización: Realizado → implementado (sin verificar) con la versión y quien lo hizo; Incompleto → con incidencia',
    ($desp($pc1)['estado'] ?? '') === 'IMPLEMENTADO' && (int)$desp($pc1)['id_version'] === (int)$v1['id_version'] && (int)$desp($pc1)['id_usuario_ultimo'] === $U['beca_trabajo']['id']
    && ($desp($pc2)['estado'] ?? '') === 'CON_INCIDENCIA' && strpos((string)$desp($pc2)['observaciones'], 'ZT sin red') !== false);
t('Sincronización: se hace una sola vez y queda en el historial', (int)$cs("SELECT COUNT(*) FROM st_historial WHERE id_solucion = ? AND accion = 'MANTENIMIENTO_CERRADO'", [$idSol]) === 1
    && $cs("SELECT sincronizado_en FROM st_mantenimiento_detalle WHERE id_programado = ?", [$idProgST]) !== null);
// (el código de la solución sigue en su campanita de avisos: se mira el contenido de la tarea)
t('Tareas: con el mantenimiento cerrado la beca ya no ve la tarea ni baja sus archivos', !$becaST->get($ST)->contains('instalar.ps1') && $becaST->get("descargar_solucion.php?archivo=$idArch")->status === 403);

// ── Nueva versión: los equipos con la anterior quedan desactualizados; retirarla vuelve a la anterior
$tecST->postFile("$ST?id=$idSol&sec=versiones", ['accion_st' => 'version', 'id_solucion' => $idSol, 'numero' => '2.0', 'notas_cambios' => 'Agente 1.8 con tareas nuevas'], ['archivos[0]' => ['instalar.ps1', "Write-Host 'v2'", 'text/plain']]);
$v2 = (int)$cs("SELECT id_version FROM st_versiones WHERE id_solucion = ? AND numero = '2.0'", [$idSol]);
$admST->post("$ST?id=$idSol&sec=versiones", ['accion_st' => 'revisar_version', 'id_version' => $v2, 'decision' => 'APROBADA', 'observacion' => '']);
$r = $tecST->get($ST);
t('Panel: con la versión 2.0 vigente, los equipos con la 1.0 aparecen como desactualizados', $r->contains('Versión desactualizada') && $r->contains('ZT-ST-PC1'));
$r = $tecST->get("$ST?sala=$sA");
t('Panel: la cobertura por equipo de la sala muestra cada PC y su estado', $r->contains('Equipos de ZSA') && $r->contains('ZT-ST-PC2') && $r->contains('Con incidencia'));
$admST->post("$ST?id=$idSol&sec=versiones", ['accion_st' => 'retirar_version', 'id_version' => $v2, 'motivo' => 'Falla en Windows 11']);
t('Versiones: retirar la 2.0 hace vigente otra vez la 1.0', $cs("SELECT estado FROM st_versiones WHERE id_version = ?", [$v2]) === 'RETIRADA'
    && strpos($tecST->get("$ST?id=$idSol")->body, 'versión vigente <strong>1.0</strong>') !== false);

// ── Estado de la solución (admin) y programación bloqueada
$admST->post("$ST?id=$idSol", ['accion_st' => 'estado', 'id_solucion' => $idSol, 'estado' => 'SUSPENDIDA', 'motivo' => 'Revisión de seguridad']);
$r = $tecST->post("$ST?id=$idSol&sec=despliegue", ['accion_st' => 'programar', 'id_solucion' => $idSol, 'id_sala' => $sA, 'despliegues' => [$d2], 'fecha_programada' => $fechaST]);
t('Estado: una solución suspendida no se programa', $cs("SELECT estado FROM st_soluciones WHERE id_solucion = ?", [$idSol]) === 'SUSPENDIDA' && $r->contains('Solo se programa una solución activa'));
$tecST->post("$ST?id=$idSol", ['accion_st' => 'estado', 'id_solucion' => $idSol, 'estado' => 'ACTIVA', 'motivo' => 'El técnico intenta']);
t('Estado: el técnico no cambia el estado (lo autoriza el admin)', $cs("SELECT estado FROM st_soluciones WHERE id_solucion = ?", [$idSol]) === 'SUSPENDIDA');
$admST->post("$ST?id=$idSol", ['accion_st' => 'estado', 'id_solucion' => $idSol, 'estado' => 'ACTIVA', 'motivo' => 'Revisión terminada']);
t('Estado: el admin la reactiva', $cs("SELECT estado FROM st_soluciones WHERE id_solucion = ?", [$idSol]) === 'ACTIVA');

// ── Historial, CSRF, parámetros hostiles y ninguna pantalla con error 5xx
$r = $tecST->get("$ST?id=$idSol&sec=historial");
t('Historial: muestra las acciones (versión aprobada, mantenimiento cerrado, verificación)', $r->contains('Versión aprobada') && $r->contains('Mantenimiento cerrado') && $r->contains('Verificación registrada'));
$antes = (int)$cs("SELECT COUNT(*) FROM st_objetivos WHERE id_solucion = ?", [$idSol]);
$r = $tecST->postRaw("$ST?id=$idSol", ['accion_st' => 'objetivos', 'id_solucion' => $idSol, 'salas' => [$sB]]);
t('CSRF: un POST sin token se rechaza', $r->status === 403 && (int)$cs("SELECT COUNT(*) FROM st_objetivos WHERE id_solucion = ?", [$idSol]) === $antes);
$sin5xx = true;
foreach (["$ST?vista=catalogo&q=%27%20OR%201%3D1%20--&estado=X&tipo=abc", "$ST?id=1%27", "$ST?id=$idSol&sec=%3Cscript%3E", "$ST?sala=-1", 'descargar_solucion.php?evidencia=%27'] as $pag) {
    foreach ([$tecST, $admST, $becaST] as $cli) { if ($cli->get($pag)->status >= 500) { $sin5xx = false; echo "   5xx en $pag\n"; } }
}
foreach (array_keys(['resumen' => 1, 'versiones' => 1, 'procedimientos' => 1, 'despliegue' => 1, 'verificaciones' => 1, 'historial' => 1]) as $sec) {
    foreach ([$tecST, $admST] as $cli) { if (($s5 = $cli->get("$ST?id=$idSol&sec=$sec")->status) !== 200) { $sin5xx = false; echo "   $s5 en sec=$sec\n"; } }
}
t('Soluciones: ninguna pantalla falla con parámetros hostiles ni en ninguna sección', $sin5xx);
