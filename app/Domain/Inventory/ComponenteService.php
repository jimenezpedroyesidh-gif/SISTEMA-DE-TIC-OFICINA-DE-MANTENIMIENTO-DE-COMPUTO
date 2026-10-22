<?php
/**
 * ComponenteService — casos de uso de las fichas de inventario (gestionar_inventario, ficha_equipo,
 * ficha_componente). Cada método devuelve ['error' => string, 'exito' => string]. No genera HTML ni toca $_POST/$_SESSION: el Controller le pasa los datos y el id del
 * usuario.
 *
 * Reutiliza lo que ya existía (no lo duplica): InventarioService::registrarHistorial() (→ InventarioService),
 * AuditoriaService::registrar(), NotificationService::notificarPorRol() y los resolvers de marca/modelo de lib_inventario.php.
 */
require_once __DIR__ . '/../../Support/lib_inventario.php';

final class ComponenteService
{
    public const TIPOS_EQUIPO_TI = ['torre', 'monitor', 'teclado', 'mouse', 'parlante', 'videobeam', 'portatil', 'todo_en_uno', 'otro'];
    /** Etiqueta de cada tipo de TIPOS_EQUIPO_TI en los <select> de alta de componente (mismo orden). */
    public const ETIQUETAS_EQUIPO_TI = [
        'torre' => 'Torre', 'monitor' => 'Monitor', 'teclado' => 'Teclado', 'mouse' => 'Mouse', 'parlante' => 'Parlante',
        'videobeam' => 'Videobeam', 'portatil' => 'Portátil', 'todo_en_uno' => 'Todo en uno', 'otro' => 'Otro',
    ];
    // videobeam y parlante también se registran directo a la sala: no son equipo de cómputo (van frente al tablero en el plano).
    public const TIPOS_INFRAESTRUCTURA = ['videobeam', 'parlante', 'aire_acondicionado', 'iluminacion', 'toma_electrica', 'silla', 'otro_infraestructura'];
    public const ESTADOS_INFRA = ['ACTIVO', 'EN_REVISION', 'DEFECTUOSO'];

    private const MSG_OBLIGATORIOS = 'Tipo, serial y código de inventario son obligatorios.';
    private const MSG_DUPLICADO = 'No se pudo registrar. ¿El serial o código de inventario ya existen?';

    private static function r(string $error = '', string $exito = ''): array
    {
        return ['error' => $error, 'exito' => $exito];
    }

    private static function nulo(string $v): ?string
    {
        $v = trim($v);
        return $v !== '' ? $v : null;   // equivale a `trim(...) ?: null` salvo el texto "0", que ya no se pierde
    }

    /** Alta desde «Registrar componente nuevo» (gestionar_inventario). */
    public static function crear(PDO $pdo, int $idUsuario, array $d): array
    {
        $tipo = (string)($d['tipo'] ?? '');
        $serial = trim((string)($d['serial'] ?? ''));
        $codigo = trim((string)($d['codigo_inventario'] ?? ''));
        $idEquipo = !empty($d['id_equipo_inicial']) ? (int)$d['id_equipo_inicial'] : null;

        if (!in_array($tipo, self::TIPOS_EQUIPO_TI, true) || $serial === '' || $codigo === '') {
            return self::r(self::MSG_OBLIGATORIOS);
        }
        if ($idEquipo !== null && ComponenteRepository::codigoEquipo($pdo, $idEquipo) === null) {
            return self::r('El equipo seleccionado no existe.');
        }
        try {
            Database::transaccion(function (PDO $pdo) use ($tipo, $serial, $codigo, $d, $idEquipo, $idUsuario) {
                $idNuevo = ComponenteRepository::insertar($pdo, $tipo, $serial, $codigo, self::nulo((string)($d['marca'] ?? '')), self::nulo((string)($d['modelo'] ?? '')), null, null, self::nulo((string)($d['descripcion'] ?? '')), $idEquipo, null);
                InventarioService::registrarHistorial($pdo, $idNuevo, 'REGISTRO', $idUsuario, null, $idEquipo, null, 'ACTIVO', null, 'Alta inicial de componente');
                AuditoriaService::registrar($pdo, $idUsuario, 'componentes', $idNuevo, 'CREAR', "tipo={$tipo} codigo_inventario={$codigo}");
            }, $pdo);
            return self::r('', "Componente {$codigo} registrado correctamente.");
        } catch (PDOException $e) {
            return self::r(self::MSG_DUPLICADO);
        }
    }

    /** Edita marca/modelo/descripción (no estado ni asignación). */
    public static function editar(PDO $pdo, int $idUsuario, int $id, array $d): array
    {
        if (!ComponenteRepository::existe($pdo, $id)) {
            return self::r('El componente no existe.');
        }
        ComponenteRepository::actualizarDescriptivos($pdo, $id, self::nulo((string)($d['marca'] ?? '')), self::nulo((string)($d['modelo'] ?? '')), self::nulo((string)($d['descripcion'] ?? '')));
        AuditoriaService::registrar($pdo, $idUsuario, 'componentes', $id, 'EDITAR', 'datos descriptivos actualizados');
        return self::r('', 'Componente actualizado.');
    }

    /** Alta directa en la ficha de un puesto (equipo). $equipo = ['id_equipo'=>, 'codigo'=>]. */
    public static function crearEnPuesto(PDO $pdo, int $idUsuario, array $equipo, array $d): array
    {
        $tipo = (string)($d['tipo'] ?? '');
        $serial = trim((string)($d['serial'] ?? ''));
        $codigo = trim((string)($d['codigo_inventario'] ?? ''));
        if (!in_array($tipo, self::TIPOS_EQUIPO_TI, true) || $serial === '' || $codigo === '') {
            return self::r(self::MSG_OBLIGATORIOS);
        }
        $idEquipo = (int)$equipo['id_equipo'];
        // La marca/modelo nuevos solo se crean en el catálogo si el alta es válida.
        [$idFab, $marca] = resolverFabricanteDesdePost($pdo, $d);
        [$idMod, $modelo] = resolverModeloDesdePost($pdo, $d, $idFab);
        try {
            Database::transaccion(function (PDO $pdo) use ($tipo, $serial, $codigo, $marca, $modelo, $idFab, $idMod, $d, $idEquipo, $idUsuario, $equipo) {
                $idNuevo = ComponenteRepository::insertar($pdo, $tipo, $serial, $codigo, $marca, $modelo, $idFab, $idMod, self::nulo((string)($d['descripcion'] ?? '')), $idEquipo, null);
                InventarioService::registrarHistorial($pdo, $idNuevo, 'REGISTRO', $idUsuario, null, $idEquipo, null, 'ACTIVO', null,
                    'Alta inicial de componente, asignado directo desde la ficha del puesto ' . $equipo['codigo']);
                AuditoriaService::registrar($pdo, $idUsuario, 'componentes', $idNuevo, 'CREAR', "tipo={$tipo} codigo_inventario={$codigo} id_equipo_actual={$idEquipo}");
            }, $pdo);
            return self::r('', "{$codigo} agregado a este puesto.");
        } catch (PDOException $e) {
            return self::r(self::MSG_DUPLICADO);
        }
    }

    /** Alta de infraestructura directo a una sala (solo la autoriza el Controller: técnico). */
    public static function crearEnSala(PDO $pdo, int $idUsuario, array $d): array
    {
        $idSala = (int)($d['id_sala'] ?? 0);
        $tipo = (string)($d['tipo_infra'] ?? '');
        $serial = trim((string)($d['serial_infra'] ?? ''));
        $codigo = trim((string)($d['codigo_inventario_infra'] ?? ''));
        if (!$idSala || !in_array($tipo, self::TIPOS_INFRAESTRUCTURA, true) || $serial === '' || $codigo === '') {
            return self::r('Sala, tipo, serial y código de inventario son obligatorios.');
        }
        $nombreSala = ComponenteRepository::nombreSala($pdo, $idSala);
        if ($nombreSala === null) {
            return self::r('La sala seleccionada no existe.');
        }
        [$idFab, $marca] = resolverFabricanteDesdePost($pdo, $d);
        [$idMod, $modelo] = resolverModeloDesdePost($pdo, $d, $idFab);
        try {
            Database::transaccion(function (PDO $pdo) use ($tipo, $serial, $codigo, $marca, $modelo, $idFab, $idMod, $d, $idSala, $idUsuario, $nombreSala) {
                $idNuevo = ComponenteRepository::insertar($pdo, $tipo, $serial, $codigo, $marca, $modelo, $idFab, $idMod, self::nulo((string)($d['descripcion_infra'] ?? '')), null, $idSala);
                InventarioService::registrarHistorial($pdo, $idNuevo, 'REGISTRO', $idUsuario, null, null, null, 'ACTIVO', null,
                    'Alta inicial de componente de infraestructura, asignado directo a la sala ' . $nombreSala);
                AuditoriaService::registrar($pdo, $idUsuario, 'componentes', $idNuevo, 'CREAR', "tipo={$tipo} codigo_inventario={$codigo} id_sala_directa={$idSala}");
            }, $pdo);
            return self::r('', "{$codigo} agregado a la sala {$nombreSala}.");
        } catch (PDOException $e) {
            return self::r(self::MSG_DUPLICADO);
        }
    }

    /** Reinstala una pieza suelta en un equipo (solo admin/técnico). */
    public static function reinstalar(PDO $pdo, int $idUsuario, string $rol, int $id, int $idEquipoDestino, string $motivo): array
    {
        if (!in_array($rol, [Rol::ADMIN, Rol::TECNICO], true)) {
            return self::r('Solo un técnico o el admin puede confirmar la reinstalación de una pieza.');
        }
        $motivo = trim($motivo);
        try {
            // El bloqueo FOR UPDATE ocurre DENTRO de la transacción.
            return Database::transaccion(function (PDO $pdo) use ($id, $idUsuario, $idEquipoDestino, $motivo) {
                $comp = ComponenteRepository::bloquearPorId($pdo, $id);
                $error = '';
                $codigoEquipo = null;
                if (!$comp) {
                    $error = 'El componente ya no existe.';
                } elseif (!empty($comp['id_equipo_actual'])) {
                    $error = 'Este componente ya está asignado a un equipo — no se puede reinstalar de nuevo sin antes retirarlo.';
                } elseif (!in_array($comp['estado'], ['ACTIVO', 'EN_REVISION'], true)) {
                    $error = "Este componente está marcado como {$comp['estado']} — no se puede reinstalar sin revisar antes su estado en Gestionar Inventario.";
                } elseif (!$idEquipoDestino) {
                    $error = 'Selecciona a qué equipo se reinstala.';
                } else {
                    $codigoEquipo = ComponenteRepository::codigoEquipo($pdo, $idEquipoDestino);
                    if ($codigoEquipo === null) {
                        $error = 'El equipo seleccionado no existe.';
                    }
                }
                if ($error !== '') {
                    throw new TransaccionRevertida(self::r($error));
                }

                ComponenteRepository::asignarReinstalado($pdo, $id, $idEquipoDestino);
                InventarioService::registrarHistorial($pdo, $id, 'ASIGNACION', $idUsuario, $comp['id_equipo_actual'], $idEquipoDestino, $comp['estado'], 'ACTIVO',
                    null, 'Reinstalado manualmente desde la ficha del componente', $motivo !== '' ? $motivo : null);
                if (in_array($comp['tipo'], ['torre', 'todo_en_uno'], true)) {
                    ComponenteRepository::marcarEquipoOperativo($pdo, $idEquipoDestino);
                }
                AuditoriaService::registrar($pdo, $idUsuario, 'componentes', $id, 'EDITAR', "reinstalado en equipo #$idEquipoDestino ($codigoEquipo)");
                return self::r('', "Componente reinstalado en {$codigoEquipo}.");
            }, $pdo);
        } catch (Throwable $e) {
            RegistroErrores::registrar("Error reinstalando componente $id", $e);
            return self::r('No se pudo reinstalar el componente. Intenta de nuevo.');
        }
    }

    /** El beca (u otro rol permitido) avisa a los técnicos que la pieza está lista. */
    public static function avisarListaReinstalar(PDO $pdo, int $id): array
    {
        $codigo = ComponenteRepository::codigoInventario($pdo, $id) ?: "#$id";
        NotificationService::notificarPorRol($pdo, Rol::TECNICO, 'SISTEMA', "El componente {$codigo} está listo para reinstalarse — falta que un técnico lo confirme.", "ficha_componente.php?id={$id}");
        return self::r('', 'Se avisó a los técnicos que esta pieza está lista para reinstalar.');
    }

    /** Cambia el estado de una pieza de infraestructura (el Controller exige rol técnico). $componente = fila de ficha(). */
    public static function cambiarEstadoInfra(PDO $pdo, int $idUsuario, array $componente, string $nuevoEstado, string $observacion): array
    {
        if (!in_array($nuevoEstado, self::ESTADOS_INFRA, true)) {
            return self::r('Selecciona un estado válido.');
        }
        if ($nuevoEstado === $componente['estado']) {
            return self::r('Ese ya es el estado actual.');
        }
        $id = (int)$componente['id_componente'];
        $observacion = trim($observacion);
        try {
            Database::transaccion(function (PDO $pdo) use ($id, $idUsuario, $componente, $nuevoEstado, $observacion) {
                ComponenteRepository::actualizarEstado($pdo, $id, $nuevoEstado);
                InventarioService::registrarHistorial($pdo, $id, 'CAMBIO_ESTADO', $idUsuario, null, null, $componente['estado'], $nuevoEstado,
                    null, 'Cambio de estado de infraestructura desde la ficha del componente', $observacion !== '' ? $observacion : null);
                AuditoriaService::registrar($pdo, $idUsuario, 'componentes', $id, 'EDITAR', "estado {$componente['estado']} -> {$nuevoEstado}");
            }, $pdo);
            return self::r('', "Estado actualizado a {$nuevoEstado}.");
        } catch (Throwable $e) {
            return self::r('No se pudo actualizar el estado. Intenta de nuevo.');
        }
    }
}
