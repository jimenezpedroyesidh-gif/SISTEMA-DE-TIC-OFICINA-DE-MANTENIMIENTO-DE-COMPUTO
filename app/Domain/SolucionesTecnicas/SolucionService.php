<?php
/**
 * SolucionService — catálogo de soluciones técnicas (scripts, automatizaciones, agentes como GLPI Agent, políticas de
 * Windows, restricciones de aplicaciones, configuraciones especiales): datos, versiones con sus archivos y
 * aprobación, procedimientos documentados y descarga de archivos privados.
 *
 * Permisos (se revisan aquí, con el rol leído de la base, no solo en la pantalla):
 *  - técnico: crea y edita soluciones, sube versiones, documenta procedimientos (y gestiona el despliegue,
 *    ver SolucionDespliegueService).
 *  - admin: supervisa todo y autoriza: aprueba o rechaza versiones, retira versiones y suspende/reactiva/retira
 *    soluciones. Una solución queda ACTIVA cuando el admin aprueba su primera versión.
 *  - beca: solo sus tareas (SolucionDespliegueService::datosTareas) y los archivos/capturas de esas tareas.
 * Nada se sobrescribe: editar un procedimiento crea otro y el anterior queda como no vigente; los archivos son
 * inmutables (un cambio es una versión nueva); cada acción deja una fila en st_historial.
 */
final class SolucionService
{
    public const ESTADOS = ['BORRADOR' => 'Borrador', 'ACTIVA' => 'Activa', 'SUSPENDIDA' => 'Suspendida', 'RETIRADA' => 'Retirada'];
    public const CHIP_ESTADO = ['BORRADOR' => 'espera', 'ACTIVA' => 'solucionado', 'SUSPENDIDA' => 'incompleto', 'RETIRADA' => 'danio'];
    public const ESTADOS_VERSION = ['EN_REVISION' => 'En revisión', 'APROBADA' => 'Aprobada', 'RECHAZADA' => 'Rechazada', 'RETIRADA' => 'Retirada'];
    public const CHIP_VERSION = ['EN_REVISION' => 'espera', 'APROBADA' => 'solucionado', 'RECHAZADA' => 'danio', 'RETIRADA' => 'incompleto'];
    public const FASES = ['INSTALACION' => 'Instalación', 'EJECUCION' => 'Ejecución', 'VERIFICACION' => 'Verificación', 'REVERSION' => 'Reversión'];
    /** Cambios de estado que autoriza el admin: estado nuevo => estados desde los que se permite. */
    public const TRANSICIONES = ['ACTIVA' => ['SUSPENDIDA'], 'SUSPENDIDA' => ['ACTIVA'], 'RETIRADA' => ['BORRADOR', 'ACTIVA', 'SUSPENDIDA']];
    /** Texto de cada acción del historial (st_historial.accion). */
    public const ETIQUETAS_HISTORIAL = [
        'CREADA' => 'Solución registrada', 'EDITADA' => 'Datos editados', 'ESTADO' => 'Cambio de estado',
        'VERSION_ENVIADA' => 'Versión enviada a revisión', 'VERSION_APROBADA' => 'Versión aprobada', 'VERSION_RECHAZADA' => 'Versión rechazada',
        'VERSION_RETIRADA' => 'Versión retirada', 'PROCEDIMIENTO_CREADO' => 'Procedimiento agregado', 'PROCEDIMIENTO_ACTUALIZADO' => 'Procedimiento actualizado',
        'PROCEDIMIENTO_BAJA' => 'Procedimiento dado de baja', 'OBJETIVO_AGREGADO' => 'Lugares de aplicación agregados', 'OBJETIVO_QUITADO' => 'Lugar de aplicación quitado',
        'IMPLEMENTAR' => 'Implementación registrada', 'VERIFICAR' => 'Verificación registrada', 'REVERTIR' => 'Reversión registrada', 'PENDIENTE' => 'Vuelta a pendiente',
        'MANTENIMIENTO_PROGRAMADO' => 'Mantenimiento programado', 'MANTENIMIENTO_CERRADO' => 'Mantenimiento cerrado', 'EVIDENCIA_EJECUCION' => 'Evidencia de ejecución',
    ];
    private const MSG_SIN_PERMISO = 'Tu rol no tiene permiso para esta acción.';
    private const PATRON_CODIGO = '/^[A-Z0-9][A-Z0-9._-]{1,19}$/';

    private static function r(string $error = '', string $exito = '', int $id = 0): array
    {
        return ['error' => $error, 'exito' => $exito, 'id' => $id];
    }

    // ───────────────────────── Permisos

    public static function permisos(string $rol): array
    {
        return [
            'gestionar' => $rol === Rol::TECNICO,
            'autorizar' => $rol === Rol::ADMIN,
            'verTodo'   => in_array($rol, [Rol::ADMIN, Rol::TECNICO], true),
        ];
    }

    /** ¿El usuario (rol leído de la base) tiene ese permiso? Lo usan también los servicios de despliegue. */
    public static function puede(PDO $pdo, int $userId, string $permiso): bool
    {
        return !empty(self::permisos((string)MantenimientoRepository::rolDeUsuario($pdo, $userId))[$permiso]);
    }

    public static function historial(PDO $pdo, int $idSolucion, string $entidad, ?int $idEntidad, string $accion, string $detalle, ?int $userId): void
    {
        SolucionRepository::registrarHistorial($pdo, $idSolucion, $entidad, $idEntidad, $accion, $detalle, $userId);
    }

    // ───────────────────────── Catálogo

    /** Valida los datos del formulario de solución. @return array{0:string,1:array} [error, datos] */
    private static function leerDatos(PDO $pdo, array $in, int $idActual): array
    {
        $d = [
            'codigo'         => mb_strtoupper(trim((string)($in['codigo'] ?? ''))),
            'nombre'         => trim((string)($in['nombre'] ?? '')),
            'id_tipo'        => (int)($in['id_tipo'] ?? 0),
            'descripcion'    => trim((string)($in['descripcion'] ?? '')),
            'objetivo'       => trim((string)($in['objetivo'] ?? '')),
            'id_responsable' => !empty($in['id_responsable']) ? (int)$in['id_responsable'] : null,
        ];
        if ($d['nombre'] === '' || $d['descripcion'] === '' || $d['objetivo'] === '') {
            return ['Completa el nombre, la descripción y el objetivo de la solución.', $d];
        }
        if (mb_strlen($d['nombre']) > 150) {
            return ['El nombre admite hasta 150 caracteres.', $d];
        }
        if (!SolucionRepository::tipoExiste($pdo, $d['id_tipo'])) {
            return ['Elige el tipo de solución.', $d];
        }
        if ($d['codigo'] !== '' && !preg_match(self::PATRON_CODIGO, $d['codigo'])) {
            return ['El código lleva de 2 a 20 letras, números, puntos o guiones (ej. ST-GLPI-01).', $d];
        }
        if ($d['codigo'] !== '' && SolucionRepository::codigoUsado($pdo, $d['codigo'], $idActual)) {
            return ["Ya existe una solución con el código {$d['codigo']}.", $d];
        }
        // El responsable solo puede ser un técnico; un id manipulado se descarta.
        if ($d['id_responsable'] !== null && MantenimientoRepository::rolDeUsuario($pdo, $d['id_responsable']) !== Rol::TECNICO) {
            $d['id_responsable'] = null;
        }
        return ['', $d];
    }

    public static function crear(PDO $pdo, int $userId, array $in): array
    {
        if (!self::puede($pdo, $userId, 'gestionar')) {
            return self::r(self::MSG_SIN_PERMISO);
        }
        [$error, $d] = self::leerDatos($pdo, $in, 0);
        if ($error !== '') {
            return self::r($error);
        }
        $id = Database::transaccion(function (PDO $pdo) use ($d, $userId) {
            $automatico = $d['codigo'] === '';
            $id = SolucionRepository::insertar($pdo, ['codigo' => $automatico ? 'TMP-' . bin2hex(random_bytes(6)) : $d['codigo']] + $d, $userId);
            if ($automatico) {
                SolucionRepository::asignarCodigo($pdo, $id, sprintf('ST-%04d', $id));
            }
            self::historial($pdo, $id, 'SOLUCION', $id, 'CREADA', $d['nombre'], $userId);
            return $id;
        }, $pdo, 'st_crear');
        return self::r('', 'Solución registrada. Sube su primera versión para que el administrador la apruebe.', (int)$id);
    }

    public static function editar(PDO $pdo, int $userId, int $idSolucion, array $in): array
    {
        $s = SolucionRepository::buscar($pdo, $idSolucion);
        if (!self::puede($pdo, $userId, 'gestionar') || !$s) {
            return self::r(self::MSG_SIN_PERMISO);
        }
        if ($s['estado'] === 'RETIRADA') {
            return self::r('Una solución retirada ya no se edita.');
        }
        [$error, $d] = self::leerDatos($pdo, $in, $idSolucion);
        if ($error !== '') {
            return self::r($error);
        }
        if ($d['codigo'] === '') {
            $d['codigo'] = (string)$s['codigo'];
        }
        $cambios = [];
        foreach (['codigo' => 'Código', 'nombre' => 'Nombre', 'id_tipo' => 'Tipo', 'descripcion' => 'Descripción', 'objetivo' => 'Objetivo', 'id_responsable' => 'Responsable'] as $campo => $etq) {
            if ((string)$s[$campo] !== (string)$d[$campo]) {
                $cambios[] = in_array($campo, ['descripcion', 'objetivo'], true) ? "$etq (antes: «" . mb_substr((string)$s[$campo], 0, 300) . '»)' : "$etq: «{$s[$campo]}» → «{$d[$campo]}»";
            }
        }
        if (!$cambios) {
            return self::r('', 'No había cambios que guardar.');
        }
        Database::transaccion(function (PDO $pdo) use ($idSolucion, $d, $cambios, $userId) {
            SolucionRepository::actualizar($pdo, $idSolucion, $d);
            self::historial($pdo, $idSolucion, 'SOLUCION', $idSolucion, 'EDITADA', implode("\n", $cambios), $userId);
        }, $pdo, 'st_editar');
        return self::r('', 'Datos de la solución actualizados.');
    }

    /** Suspender, reactivar o retirar (solo el admin). */
    public static function cambiarEstado(PDO $pdo, int $userId, int $idSolucion, string $estado, string $motivo): array
    {
        $s = SolucionRepository::buscar($pdo, $idSolucion);
        if (!self::puede($pdo, $userId, 'autorizar') || !$s || !isset(self::TRANSICIONES[$estado])) {
            return self::r(self::MSG_SIN_PERMISO);
        }
        if (!in_array($s['estado'], self::TRANSICIONES[$estado], true)) {
            return self::r('Desde «' . self::ESTADOS[$s['estado']] . '» no se puede pasar a «' . self::ESTADOS[$estado] . '».');
        }
        if (mb_strlen($motivo) < 5) {
            return self::r('Escribe el motivo del cambio de estado.');
        }
        if ($estado === 'ACTIVA' && !SolucionRepository::vigente($pdo, $idSolucion)) {
            return self::r('No se puede reactivar: la solución no tiene ninguna versión aprobada.');
        }
        $falla = Database::transaccion(function (PDO $pdo) use ($idSolucion, $estado, $s, $motivo, $userId) {
            if (SolucionRepository::cambiarEstado($pdo, $idSolucion, $estado, (string)$s['estado']) === 0) {
                throw new TransaccionRevertida(self::r('La solución cambió de estado mientras tanto. Recarga la página.'));
            }
            self::historial($pdo, $idSolucion, 'SOLUCION', $idSolucion, 'ESTADO', self::ESTADOS[$s['estado']] . ' → ' . self::ESTADOS[$estado] . ". Motivo: $motivo", $userId);
            return null;
        }, $pdo, 'st_estado');
        if ($falla) {
            return $falla;
        }
        if (!empty($s['id_responsable']) && (int)$s['id_responsable'] !== $userId) {
            NotificationService::crear($pdo, (int)$s['id_responsable'], 'MANTENIMIENTO', "La solución técnica {$s['codigo']} quedó " . mb_strtolower(self::ESTADOS[$estado]) . ": $motivo", 'soluciones_tecnicas.php?id=' . $idSolucion);
        }
        return self::r('', 'La solución quedó ' . mb_strtolower(self::ESTADOS[$estado]) . '.');
    }

    // ───────────────────────── Versiones

    public static function crearVersion(PDO $pdo, int $userId, int $idSolucion, array $in, ?array $files): array
    {
        $s = SolucionRepository::buscar($pdo, $idSolucion);
        if (!self::puede($pdo, $userId, 'gestionar') || !$s) {
            return self::r(self::MSG_SIN_PERMISO);
        }
        $numero = trim((string)($in['numero'] ?? ''));
        $notas = trim((string)($in['notas_cambios'] ?? ''));
        if ($s['estado'] === 'RETIRADA') {
            return self::r('La solución está retirada: ya no recibe versiones.');
        }
        if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,19}$/', $numero)) {
            return self::r('Escribe el número de versión (ej. 1.0, 2.1.3 o 2026-10).');
        }
        if (mb_strlen($notas) < 10) {
            return self::r('Describe qué cambia en esta versión (mínimo 10 caracteres).');
        }
        if (SolucionRepository::numeroUsado($pdo, $idSolucion, $numero)) {
            return self::r("La versión $numero ya existe en esta solución: usa otro número.");
        }
        $subida = SolucionArchivos::guardarArchivosVersion($files);
        if ($subida['error'] !== '') {
            return self::r($subida['error']);
        }
        try {
            $idVersion = Database::transaccion(function (PDO $pdo) use ($idSolucion, $numero, $notas, $userId, $subida) {
                $idVersion = SolucionRepository::insertarVersion($pdo, $idSolucion, $numero, $notas, $userId);
                $lineas = [];
                foreach ($subida['datos'] as $a) {
                    SolucionRepository::insertarArchivo($pdo, $idVersion, $a, $userId);
                    $lineas[] = "{$a['nombre_original']} · SHA-256 {$a['sha256']}";
                }
                self::historial($pdo, $idSolucion, 'VERSION', $idVersion, 'VERSION_ENVIADA', "Versión $numero enviada a revisión. Archivos:\n" . implode("\n", $lineas), $userId);
                return $idVersion;
            }, $pdo, 'st_version');
        } catch (Throwable $e) {
            SolucionArchivos::borrar(array_column($subida['datos'], 'nombre_almacenado'));
            throw $e;
        }
        NotificationService::notificarAdmins($pdo, 'MANTENIMIENTO', "Nueva versión $numero de la solución técnica {$s['codigo']} por aprobar.", 'soluciones_tecnicas.php?id=' . $idSolucion . '&sec=versiones');
        return self::r('', "Versión $numero enviada a revisión del administrador.", (int)$idVersion);
    }

    /** Aprobar o rechazar una versión en revisión (solo el admin, y nunca quien la subió). */
    public static function revisarVersion(PDO $pdo, int $userId, int $idVersion, string $decision, string $observacion): array
    {
        $v = SolucionRepository::version($pdo, $idVersion);
        if (!self::puede($pdo, $userId, 'autorizar') || !$v || !in_array($decision, ['APROBADA', 'RECHAZADA'], true)) {
            return self::r(self::MSG_SIN_PERMISO);
        }
        if ((int)$v['id_autor'] === $userId) {
            return self::r('Quien sube una versión no puede aprobarla.');
        }
        if ($decision === 'RECHAZADA' && mb_strlen($observacion) < 5) {
            return self::r('Explica por qué se rechaza la versión.');
        }
        if ($decision === 'APROBADA' && $v['solucion_estado'] === 'RETIRADA') {
            return self::r('La solución está retirada: no se aprueban versiones nuevas.');
        }
        $idSolucion = (int)$v['id_solucion'];
        $falla = Database::transaccion(function (PDO $pdo) use ($v, $idVersion, $decision, $observacion, $userId, $idSolucion) {
            if (SolucionRepository::revisarVersion($pdo, $idVersion, $decision, $userId, $observacion) === 0) {
                throw new TransaccionRevertida(self::r('Esa versión ya fue revisada.'));
            }
            $activada = $decision === 'APROBADA' && $v['solucion_estado'] === 'BORRADOR' && SolucionRepository::cambiarEstado($pdo, $idSolucion, 'ACTIVA', 'BORRADOR') === 1;
            self::historial($pdo, $idSolucion, 'VERSION', $idVersion, $decision === 'APROBADA' ? 'VERSION_APROBADA' : 'VERSION_RECHAZADA',
                "Versión {$v['numero']}" . ($observacion !== '' ? ": $observacion" : '') . ($activada ? '. La solución quedó activa.' : ''), $userId);
            return null;
        }, $pdo, 'st_revisar');
        if ($falla) {
            return $falla;
        }
        $texto = $decision === 'APROBADA' ? 'aprobó' : 'rechazó';
        NotificationService::crear($pdo, (int)$v['id_autor'], 'MANTENIMIENTO', "El administrador $texto la versión {$v['numero']} de {$v['codigo']}" . ($observacion !== '' ? ": $observacion" : '.'), 'soluciones_tecnicas.php?id=' . $idSolucion . '&sec=versiones');
        return self::r('', $decision === 'APROBADA' ? "Versión {$v['numero']} aprobada: ahora es la vigente." : "Versión {$v['numero']} rechazada.");
    }

    /** Retira una versión aprobada (p. ej. tenía un error): vuelve a regir la aprobada anterior. Solo el admin. */
    public static function retirarVersion(PDO $pdo, int $userId, int $idVersion, string $motivo): array
    {
        $v = SolucionRepository::version($pdo, $idVersion);
        if (!self::puede($pdo, $userId, 'autorizar') || !$v) {
            return self::r(self::MSG_SIN_PERMISO);
        }
        if (mb_strlen($motivo) < 5) {
            return self::r('Escribe el motivo por el que se retira la versión.');
        }
        $falla = Database::transaccion(function (PDO $pdo) use ($v, $idVersion, $motivo, $userId) {
            if (SolucionRepository::retirarVersion($pdo, $idVersion) === 0) {
                throw new TransaccionRevertida(self::r('Solo se retira una versión aprobada.'));
            }
            self::historial($pdo, (int)$v['id_solucion'], 'VERSION', $idVersion, 'VERSION_RETIRADA', "Versión {$v['numero']}: $motivo", $userId);
            return null;
        }, $pdo, 'st_retirar_version');
        if ($falla) {
            return $falla;
        }
        $vigente = SolucionRepository::vigente($pdo, (int)$v['id_solucion']);
        return self::r('', "Versión {$v['numero']} retirada. " . ($vigente ? "Vuelve a regir la versión {$vigente['numero']}; los equipos con la retirada aparecen como desactualizados." : 'La solución no tiene otra versión aprobada.'));
    }

    // ───────────────────────── Procedimientos

    /** Nuevo procedimiento o nueva redacción de uno vigente (id_reemplaza): el anterior se conserva como no vigente. */
    public static function guardarProcedimiento(PDO $pdo, int $userId, int $idSolucion, array $in, ?array $files): array
    {
        $s = SolucionRepository::buscar($pdo, $idSolucion);
        if (!self::puede($pdo, $userId, 'gestionar') || !$s) {
            return self::r(self::MSG_SIN_PERMISO);
        }
        $p = [
            'fase'         => (string)($in['fase'] ?? ''),
            'titulo'       => trim((string)($in['titulo'] ?? '')),
            'pasos'        => trim((string)($in['pasos'] ?? '')),
            'enlace_video' => trim((string)($in['enlace_video'] ?? '')) ?: null,
        ];
        $idReemplaza = (int)($in['id_reemplaza'] ?? 0) ?: null;
        if ($s['estado'] === 'RETIRADA') {
            return self::r('La solución está retirada: ya no se documenta.');
        }
        if (!isset(self::FASES[$p['fase']]) || $p['titulo'] === '' || mb_strlen($p['pasos']) < 10) {
            return self::r('Elige la fase y escribe el título y los pasos (mínimo 10 caracteres).');
        }
        if (mb_strlen($p['titulo']) > 150) {
            return self::r('El título admite hasta 150 caracteres.');
        }
        if ($p['enlace_video'] !== null && (!filter_var($p['enlace_video'], FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $p['enlace_video']))) {
            return self::r('El enlace del video no es una URL válida (debe empezar por http:// o https://).');
        }
        $anterior = $idReemplaza ? SolucionRepository::procedimiento($pdo, $idReemplaza) : null;
        if ($idReemplaza && (!$anterior || (int)$anterior['id_solucion'] !== $idSolucion || (int)$anterior['vigente'] !== 1)) {
            return self::r('Ese procedimiento ya fue reemplazado por otra versión. Recarga la página.');
        }
        $capturas = SolucionArchivos::guardarEvidencias($files);
        try {
            $idProc = Database::transaccion(function (PDO $pdo) use ($idSolucion, $p, $idReemplaza, $userId, $capturas) {
                if ($idReemplaza && SolucionRepository::retirarProcedimiento($pdo, $idReemplaza, $idSolucion) === 0) {
                    throw new TransaccionRevertida(self::r('Ese procedimiento ya fue reemplazado por otra versión. Recarga la página.'));
                }
                $idProc = SolucionRepository::insertarProcedimiento($pdo, $idSolucion, $p, $idReemplaza, $userId);
                foreach ($capturas['guardadas'] as $e) {
                    SolucionRepository::insertarEvidencia($pdo, $idSolucion, $e, ['id_procedimiento' => $idProc], $userId);
                }
                self::historial($pdo, $idSolucion, 'PROCEDIMIENTO', $idProc, $idReemplaza ? 'PROCEDIMIENTO_ACTUALIZADO' : 'PROCEDIMIENTO_CREADO',
                    self::FASES[$p['fase']] . ": {$p['titulo']}" . ($idReemplaza ? " (reemplaza la redacción #$idReemplaza, que se conserva)" : '') . (count($capturas['guardadas']) ? ' · ' . count($capturas['guardadas']) . ' captura(s)' : ''), $userId);
                return $idProc;
            }, $pdo, 'st_procedimiento');
        } catch (Throwable $e) {
            SolucionArchivos::borrar(array_column($capturas['guardadas'], 'nombre_almacenado'));
            throw $e;
        }
        if (is_array($idProc)) { // se revirtió: las capturas ya no tienen a qué pertenecer
            SolucionArchivos::borrar(array_column($capturas['guardadas'], 'nombre_almacenado'));
            return $idProc;
        }
        $aviso =$capturas['rechazadas'] ? " {$capturas['rechazadas']} archivo(s) no se guardaron (solo fotos JPG, PNG o WEBP, o PDF, de hasta 8 MB)." : '';
        return self::r('', ($idReemplaza ? 'Procedimiento actualizado; la redacción anterior queda en el historial.' : 'Procedimiento agregado.') . $aviso, (int)$idProc);
    }

    /** Da de baja un procedimiento vigente (sigue visible entre las versiones anteriores). */
    public static function retirarProcedimiento(PDO $pdo, int $userId, int $idSolucion, int $idProcedimiento): array
    {
        $p = SolucionRepository::procedimiento($pdo, $idProcedimiento);
        if (!self::puede($pdo, $userId, 'gestionar') || !$p || (int)$p['id_solucion'] !== $idSolucion) {
            return self::r(self::MSG_SIN_PERMISO);
        }
        $falla = Database::transaccion(function (PDO $pdo) use ($idSolucion, $idProcedimiento, $p, $userId) {
            if (SolucionRepository::retirarProcedimiento($pdo, $idProcedimiento, $idSolucion) === 0) {
                throw new TransaccionRevertida(self::r('Ese procedimiento ya no estaba vigente.'));
            }
            self::historial($pdo, $idSolucion, 'PROCEDIMIENTO', $idProcedimiento, 'PROCEDIMIENTO_BAJA', self::FASES[$p['fase']] . ": {$p['titulo']}", $userId);
            return null;
        }, $pdo, 'st_procedimiento_baja');
        if ($falla) {
            return $falla;
        }
        return self::r('', 'Procedimiento dado de baja (se conserva entre las versiones anteriores).');
    }

    // ───────────────────────── Descarga de archivos privados

    /**
     * ¿Puede el usuario bajar ese archivo de versión ('archivo') o esa evidencia ('evidencia')? Resuelve su ruta.
     * Técnico y admin: todo. Beca: los archivos de la versión de su tarea activa, las capturas de los procedimientos
     * de esa solución y las evidencias que ella misma subió. El archivo de versión se entrega solo si su SHA-256
     * coincide con el registrado (si alguien lo cambió en el disco, no se entrega).
     * @return array{ok:bool,status:int,mensaje:string,ruta:?string,nombre:string,mime:string,adjunto:bool}
     */
    public static function resolverDescarga(PDO $pdo, string $tipo, int $id, string $rol, int $userId): array
    {
        $fallo = fn(int $s, string $m) => ['ok' => false, 'status' => $s, 'mensaje' => $m, 'ruta' => null, 'nombre' => '', 'mime' => '', 'adjunto' => true];
        $verTodo = self::permisos($rol)['verTodo'];
        if ($tipo === 'archivo') {
            $a = $id ? SolucionRepository::archivo($pdo, $id) : false;
            if (!$a) {
                return $fallo(404, 'Archivo no encontrado.');
            }
            if (!$verTodo && !($rol === Rol::BECA && $a['version_estado'] === 'APROBADA' && SolucionDespliegueRepository::becaPuedeVer($pdo, $userId, (int)$a['id_solucion'], (int)$a['id_version']))) {
                return $fallo(403, 'No tienes acceso a este archivo.');
            }
            $ruta = SolucionArchivos::ruta((string)$a['nombre_almacenado']);
            if ($ruta === null) {
                return $fallo(404, 'El archivo ya no está disponible en el servidor.');
            }
            if (!hash_equals((string)$a['sha256'], (string)hash_file('sha256', $ruta))) {
                RegistroErrores::registrar('Soluciones técnicas: SHA-256 distinto en el archivo #' . $id, new RuntimeException((string)$a['nombre_almacenado']));
                return $fallo(409, 'El archivo en el servidor no coincide con su huella SHA-256 registrada: no se entrega. Avisa al administrador.');
            }
            return ['ok' => true, 'status' => 200, 'mensaje' => '', 'ruta' => $ruta, 'nombre' => (string)$a['nombre_original'], 'mime' => 'application/octet-stream', 'adjunto' => true];
        }
        if ($tipo === 'evidencia') {
            $e = $id ? SolucionRepository::evidencia($pdo, $id) : false;
            if (!$e) {
                return $fallo(404, 'Evidencia no encontrada.');
            }
            $becaVe = $rol === Rol::BECA && ((int)$e['id_usuario_subio'] === $userId
                || (!empty($e['id_procedimiento']) && SolucionDespliegueRepository::becaPuedeVer($pdo, $userId, (int)$e['id_solucion'])));
            if (!$verTodo && !$becaVe) {
                return $fallo(403, 'No tienes acceso a esta evidencia.');
            }
            $ruta = SolucionArchivos::ruta((string)$e['nombre_almacenado']);
            if ($ruta === null || !in_array($e['mime_type'], SolucionArchivos::MIME_EVIDENCIA, true)) {
                return $fallo(404, 'La evidencia ya no está disponible.');
            }
            return ['ok' => true, 'status' => 200, 'mensaje' => '', 'ruta' => $ruta, 'nombre' => (string)$e['nombre_original'], 'mime' => (string)$e['mime_type'], 'adjunto' => false];
        }
        return $fallo(404, 'Archivo no encontrado.');
    }

    // ───────────────────────── Datos de las pantallas

    /** @return array{filtros:array,soluciones:array,tipos:array,tecnicos:array} */
    public static function datosCatalogo(PDO $pdo, array $get): array
    {
        $estado = (string)($get['estado'] ?? '');
        $filtros = [
            'estado'  => isset(self::ESTADOS[$estado]) ? $estado : '',
            'id_tipo' => (int)($get['tipo'] ?? 0),
            'texto'   => mb_substr(trim((string)($get['q'] ?? '')), 0, 80),
        ];
        $cobertura = SolucionDespliegueRepository::cobertura($pdo, 'solucion');
        $soluciones = array_map(fn($s) => $s + ['cobertura' => $cobertura[(int)$s['id_solucion']] ?? null], SolucionRepository::listar($pdo, $filtros));
        return [
            'filtros'    => $filtros,
            'soluciones' => $soluciones,
            'tipos'      => SolucionRepository::tipos($pdo),
            'tecnicos'   => SolucionRepository::tecnicos($pdo),
        ];
    }

    /** Ficha de una solución (null si no existe). La sección decide qué más se carga. */
    public static function datosFicha(PDO $pdo, int $idSolucion, string $seccion): ?array
    {
        $s = SolucionRepository::buscar($pdo, $idSolucion);
        if (!$s) {
            return null;
        }
        $datos = [
            'solucion'  => $s,
            'cobertura' => SolucionDespliegueRepository::cobertura($pdo, 'solucion')[$idSolucion] ?? null,
            'tipos'     => SolucionRepository::tipos($pdo),
            'tecnicos'  => SolucionRepository::tecnicos($pdo),
        ];
        if ($seccion === 'resumen') {
            $datos['objetivos'] = SolucionDespliegueRepository::objetivos($pdo, $idSolucion);
            $datos['salasElegibles'] = MantenimientoCampanaRepository::salasElegibles($pdo);
        } elseif ($seccion === 'versiones') {
            $versiones = SolucionRepository::versiones($pdo, $idSolucion);
            $datos['versiones'] = $versiones;
            $datos['archivosPorVersion'] = SolucionRepository::archivosDeVersiones($pdo, array_column($versiones, 'id_version'));
        } elseif ($seccion === 'procedimientos') {
            $vigentes = SolucionRepository::procedimientos($pdo, $idSolucion, true);
            $anteriores = SolucionRepository::procedimientos($pdo, $idSolucion, false);
            $datos['procedimientos'] = $vigentes;
            $datos['procedimientosAnteriores'] = $anteriores;
            $datos['capturas'] = SolucionRepository::evidenciasPor($pdo, 'id_procedimiento', array_merge(array_column($vigentes, 'id_procedimiento'), array_column($anteriores, 'id_procedimiento')));
        } elseif ($seccion === 'despliegue') {
            $datos += SolucionDespliegueService::datosDespliegue($pdo, $s);
        } elseif ($seccion === 'verificaciones') {
            $verificaciones = SolucionDespliegueRepository::verificaciones($pdo, $idSolucion);
            $datos['verificaciones'] = $verificaciones;
            $datos['evidenciasPorVerificacion'] = SolucionRepository::evidenciasPor($pdo, 'id_verificacion', array_column($verificaciones, 'id_verificacion'));
        } else {
            $datos['historial'] = SolucionRepository::historial($pdo, $idSolucion);
        }
        return $datos;
    }
}
