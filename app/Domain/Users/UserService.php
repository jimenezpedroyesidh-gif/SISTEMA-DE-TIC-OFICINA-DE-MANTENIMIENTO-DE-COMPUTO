<?php
/**
 * UserService — casos de uso de la administración de usuarios por el admin
 * (alta, edición, habilitar/deshabilitar). No genera HTML ni toca $_POST/$_SESSION:
 * el Controller le pasa los datos y el id del admin. Reutiliza AuditoriaService::registrar().
 * (La clave propia es UserAccount; los permisos de secretaria, UserPermissions.)
 */
require_once __DIR__ . '/../../Support/validacion.php';  // correoValido(), validarTexto()
require_once __DIR__ . '/../../Support/paginacion.php';

final class UserService
{
    public const ROLES = [Rol::SOLICITANTE, Rol::TECNICO, Rol::ADMIN, Rol::PROFESOR, Rol::BECA, Rol::SECRETARIA, Rol::VIGILANTE, Rol::VISUALIZACION];
    public const CARGOS = ['secretario', 'director', 'administrativo', 'auxiliar', 'profesional', Rol::PROFESOR, Rol::BECA, Rol::VIGILANTE, 'pantalla'];


    /** Clave temporal legible (sin caracteres ambiguos 0/O, 1/l/I). */
    public static function generarClaveTemporal(int $largo = 14): string
    {
        $alfabeto = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $clave = '';
        for ($i = 0; $i < $largo; $i++) {
            $clave .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        return $clave;
    }

    /** Habilita/deshabilita una cuenta. Devuelve ['error' => string]. El admin no puede deshabilitarse a sí mismo. */
    public static function alternarEstado(PDO $pdo, int $idAdmin, int $id): array
    {
        if ($id === $idAdmin) {
            return ['error' => 'No puedes deshabilitar tu propia cuenta.'];
        }
        $antes = UserRepository::porId($pdo, $id);
        if ($antes === null) {
            return ['error' => ''];
        }
        UserRepository::alternarEstado($pdo, $id);
        $despues = (int)$antes['estado_activo'] === 1 ? 0 : 1;
        AuditoriaService::registrar($pdo, $idAdmin, 'users', $id, 'EDITAR', "estado_activo {$antes['estado_activo']} -> {$despues}");
        BitacoraAccesos::registrar($pdo, 'CUENTA_ESTADO', $id, (string)$antes['correo'], ($despues ? 'habilitada' : 'deshabilitada') . " por el admin #$idAdmin");
        return ['error' => ''];
    }

    /**
     * Alta (idPost = null) o edición. $d son los campos del formulario.
     * Devuelve ['error' => string, 'clave' => ?string (temporal, solo se muestra una vez),
     * 'nombre' => ?string (guardado), 'id' => ?int].
     */
    public static function guardar(PDO $pdo, int $idAdmin, array $d, ?int $idPost): array
    {
        $codigo = trim((string)($d['codigo'] ?? ''));
        $correo = trim((string)($d['correo'] ?? ''));
        $nombre = trim((string)($d['nombre_completo'] ?? ''));
        $cargo = trim((string)($d['cargo'] ?? ''));
        $idUnidad = !empty($d['id_unidad']) ? (int)$d['id_unidad'] : null;
        $role = (string)($d['role'] ?? '');
        $activo = isset($d['estado_activo']) ? 1 : 0;
        $resultado = ['error' => '', 'clave' => null, 'nombre' => null, 'id' => $idPost];

        if ($codigo === '' || $correo === '' || $nombre === '') {
            $resultado['error'] = 'Código, correo y nombre completo son obligatorios.';
            return $resultado;
        }
        if (!in_array($role, self::ROLES, true)) {
            $resultado['error'] = 'Rol no válido.';
            return $resultado;
        }
        // Solo los dominios de config/app.php (institucional + extra para quien aún no tiene correo institucional).
        if (!correoValido($correo)) {
            $resultado['error'] = 'El correo debe ser ' . correoDominiosTexto() . '.';
            return $resultado;
        }
        // La dependencia se deriva SIEMPRE del nombre de la unidad elegida (una sola fuente de verdad).
        $dependencia = '';
        if ($idUnidad) {
            foreach (UserRepository::unidades($pdo) as $u) {
                if ((int)$u['id_unidad'] === $idUnidad) { $dependencia = $u['nombre']; break; }
            }
            if ($dependencia === '') {
                $resultado['error'] = 'La unidad seleccionada no existe.';
                return $resultado;
            }
        }
        $datos = ['codigo' => $codigo, 'correo' => $correo, 'nombre_completo' => $nombre, 'cargo' => $cargo,
                  'dependencia' => $dependencia !== '' ? $dependencia : null, 'id_unidad' => $idUnidad, 'role' => $role, 'estado_activo' => $activo];

        $anterior = null;
        if ($idPost) {
            // Antes editar un id inexistente decía «guardado» sin hacer nada.
            $anterior = UserRepository::porId($pdo, $idPost);
            if ($anterior === null) {
                $resultado['error'] = 'El usuario no existe.';
                return $resultado;
            }
            // Evita que el admin se quede sin acceso por accidente.
            if ($idPost === $idAdmin && ($role !== Rol::ADMIN || $activo !== 1)) {
                $resultado['error'] = 'No puedes quitarte el rol de admin ni desactivar tu propia cuenta.';
                return $resultado;
            }
        }
        // En edición se tolera conservar el valor ya guardado (datos anteriores a la lista).
        if (!in_array($cargo, self::CARGOS, true) && !($anterior !== null && $cargo === (string)$anterior['cargo'])) {
            $resultado['error'] = 'El cargo seleccionado no es válido.';
            return $resultado;
        }
        // Área de la beca (solo beca_trabajo): oficina de mantenimiento o secretaría (redes, más adelante).
        $areaBeca = null;
        if ($role === Rol::BECA) {
            $areaBeca = (string)($d['area_beca'] ?? AreaBeca::MANTENIMIENTO);
            if (($msgArea = AreaBeca::validar($areaBeca)) !== null) {
                $resultado['error'] = $msgArea;
                return $resultado;
            }
        }
        if (UserRepository::existeDuplicado($pdo, $codigo, $correo, $idPost ?? 0)) {
            $resultado['error'] = 'Ya existe otro usuario con ese código o correo.';
            return $resultado;
        }

        if ($idPost) {
            UserRepository::actualizar($pdo, $idPost, $datos);
            AreaBeca::guardar($pdo, $idPost, $areaBeca);
            $detalle = "datos actualizados role={$anterior['role']} -> {$role}, estado_activo={$anterior['estado_activo']} -> {$activo}";
            if (($anterior['area_beca'] ?? null) !== $areaBeca) {
                $detalle .= ', área ' . ($anterior['area_beca'] ?? 'sin asignar') . ' -> ' . ($areaBeca ?? 'ninguna');
            }
            if (!empty($d['resetear_clave'])) {
                $resultado['clave'] = self::generarClaveTemporal();
                UserRepository::guardarClaveTemporal($pdo, $idPost, password_hash($resultado['clave'], PASSWORD_DEFAULT));
                $detalle .= ', clave temporal reiniciada';
            }
            AuditoriaService::registrar($pdo, $idAdmin, 'users', $idPost, 'EDITAR', $detalle);
            if ($anterior['role'] !== $role) {
                BitacoraAccesos::registrar($pdo, 'CAMBIO_ROL', $idPost, $correo, "{$anterior['role']} → {$role} (admin #$idAdmin)");
            }
            if ((int)$anterior['estado_activo'] !== $activo) {
                BitacoraAccesos::registrar($pdo, 'CUENTA_ESTADO', $idPost, $correo, ($activo ? 'habilitada' : 'deshabilitada') . " por el admin #$idAdmin");
            }
            $resultado['nombre'] = $nombre;
            return $resultado;
        }

        $resultado['clave'] = self::generarClaveTemporal();
        $hash = password_hash($resultado['clave'], PASSWORD_DEFAULT);
        $idNuevo = UserRepository::insertar($pdo, $datos, $hash);
        AreaBeca::guardar($pdo, $idNuevo, $areaBeca);
        AuditoriaService::registrar($pdo, $idAdmin, 'users', $idNuevo, 'CREAR', "role={$role} codigo={$codigo}" . ($areaBeca ? " area={$areaBeca}" : ''));
        BitacoraAccesos::registrar($pdo, 'CAMBIO_ROL', $idNuevo, $correo, "cuenta creada como {$role} (admin #$idAdmin)");
        $resultado['nombre'] = $nombre;
        $resultado['id'] = $idNuevo;
        return $resultado;
    }
}
