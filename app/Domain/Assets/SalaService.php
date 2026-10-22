<?php
/**
 * SalaService — casos de uso de la pantalla «Salas y códigos QR».
 *
 * Cada método devuelve ['error' => string, 'exito' => string]. No genera HTML ni toca $_POST/$_SESSION: el
 * Controller le pasa datos ya leídos.
 */

final class SalaService
{
    public const TIPOS_UNIDAD = ['DEPARTAMENTO', 'DEPENDENCIA', 'OFICINA', 'PLAN_ESTUDIOS'];

    private const MSG_SALA_NO_EXISTE = 'La sala seleccionada no existe.';

    private static function r(string $error = '', string $exito = ''): array
    {
        return ['error' => $error, 'exito' => $exito];
    }

    /** Texto de la dependencia derivado de la unidad elegida ('' si no hay o no existe). */
    private static function dependenciaDe(PDO $pdo, ?int $idUnidad): string
    {
        return $idUnidad ? SalaRepository::nombreUnidad($pdo, $idUnidad) : '';
    }

    /**
     * $idUbicacionGlpi: ubicación de GLPI elegida en el formulario. Si viene vacía y hay conexión con GLPI,
     * se detecta sola por el nombre/abreviatura de la sala (GlpiUbicaciones::detectar). Sin GLPI no cambia nada.
     */
    public static function crear(PDO $pdo, string $nombre, string $abreviatura, ?int $idUnidad, ?int $idUbicacionGlpi = null): array
    {
        $nombre = trim($nombre);
        $abreviatura = trim($abreviatura);
        $dependencia = self::dependenciaDe($pdo, $idUnidad);

        if ($nombre === '') {
            return self::r('El nombre de la sala es obligatorio.');
        }
        // Token aleatorio y no adivinable — mismo criterio de seguridad que csrf.php
        $token = bin2hex(random_bytes(16));
        try {
            $idNueva = SalaRepository::crear($pdo, $nombre, $abreviatura, $dependencia !== '' ? $dependencia : null, $idUnidad, $token);
            return self::r('', "Sala \"{$nombre}\" creada correctamente." . self::vincularGlpi($pdo, $idNueva, $nombre, $abreviatura, $idUbicacionGlpi, true));
        } catch (PDOException $e) {
            return self::r('No se pudo crear la sala. ¿El nombre ya existe?');
        }
    }

    /**
     * Vincula la sala con su ubicación de GLPI (elegida o detectada). Devuelve el texto a añadir al
     * mensaje de éxito ('' si no hay GLPI o no se encontró ubicación). $detectar=false: solo guarda la elegida.
     */
    private static function vincularGlpi(PDO $pdo, int $idSala, string $nombre, string $abreviatura, ?int $idUbicacion, bool $detectar): string
    {
        $ubicaciones = GlpiUbicaciones::listar(Database::glpi());
        if (!$ubicaciones || !$idSala) return '';
        $porId = array_column($ubicaciones, null, 'id');
        if ($idUbicacion !== null && !isset($porId[$idUbicacion])) $idUbicacion = null; // id que no existe en GLPI: se ignora
        $auto = false;
        if ($idUbicacion === null && $detectar) {
            $idUbicacion = GlpiUbicaciones::detectar($ubicaciones, $nombre, $abreviatura);
            $auto = $idUbicacion !== null;
        }
        if ($idUbicacion === null) return $detectar ? ' No se encontró su ubicación en GLPI: puedes vincularla después desde «Editar sala».' : '';
        GlpiColaRepository::vincularSala($pdo, $idSala, $idUbicacion);
        return ' Vinculada a GLPI: ' . $porId[$idUbicacion]['completo'] . ($auto ? ' (detectada automáticamente).' : '.');
    }

    /** Vincula de una vez con GLPI todas las salas sin vínculo cuya ubicación se detecta por nombre/abreviatura. */
    public static function vincularSugeridasGlpi(PDO $pdo, int $idUsuario): array
    {
        $ubicaciones = GlpiUbicaciones::listar(Database::glpi());
        if (!$ubicaciones) return self::r('No hay conexión con la base de GLPI. El administrador la configura en «Conexiones».');
        $n = 0;
        foreach (GlpiColaRepository::salas($pdo) as $sg) {
            if ($sg['glpi_location_id'] !== null) continue;
            $abrev = SalaRepository::abreviatura($pdo, (int)$sg['id_sala']);
            $id = GlpiUbicaciones::detectar($ubicaciones, (string)$sg['nombre_sala'], $abrev);
            if ($id === null) continue;
            GlpiColaRepository::vincularSala($pdo, (int)$sg['id_sala'], $id);
            $n++;
        }
        AuditoriaService::registrar($pdo, $idUsuario, 'salas', 0, 'EDITAR', "Vínculo automático sala ↔ ubicación GLPI ($n salas)");
        return self::r('', $n > 0 ? "$n sala(s) vinculadas con su ubicación de GLPI." : 'No había salas pendientes con una ubicación de GLPI que coincida.');
    }

    /** $idUbicacionGlpi: solo se toca el vínculo con GLPI si el formulario lo envió ($cambiarGlpi = true). */
    public static function editar(PDO $pdo, int $idSala, string $abreviatura, ?int $idUnidad, ?int $idUbicacionGlpi = null, bool $cambiarGlpi = false, ?string $bloque = null): array
    {
        if (!$idSala) {
            return self::r();
        }
        $abreviatura = trim($abreviatura);
        $dependencia = self::dependenciaDe($pdo, $idUnidad);
        try {
            SalaRepository::actualizar($pdo, $idSala, $abreviatura, $dependencia !== '' ? $dependencia : null, $idUnidad);
            if ($bloque !== null) {
                SalaRepository::actualizarBloque($pdo, $idSala, trim($bloque));
            }
            if ($cambiarGlpi) {
                if ($idUbicacionGlpi === null) { GlpiColaRepository::vincularSala($pdo, $idSala, null); }
                else { self::vincularGlpi($pdo, $idSala, '', '', $idUbicacionGlpi, false); }
            }
            return self::r('', 'Datos de la sala actualizados.');
        } catch (PDOException $e) {
            return self::r('No se pudo actualizar la sala.');
        }
    }

    public static function marcarOficina(PDO $pdo, int $idSala): array
    {
        if (!$idSala) {
            return self::r();
        }
        // Ahora se valida primero.
        if (!SalaRepository::existe($pdo, $idSala)) {
            return self::r(self::MSG_SALA_NO_EXISTE);
        }
        SalaRepository::marcarOficina($pdo, $idSala);
        return self::r('', 'Esa sala ahora es la oficina de mantenimiento (bodega). Los equipos que se trasladen ahí quedarán visibles para los becas en su panel.');
    }

    public static function quitarOficina(PDO $pdo): array
    {
        SalaRepository::quitarOficina($pdo);
        return self::r('', 'Ya ninguna sala está marcada como oficina de mantenimiento.');
    }

    public static function asignarStaff(PDO $pdo, int $idSala, int $idUsuario): array
    {
        if (!$idSala || !$idUsuario) {
            return self::r();
        }
        // El rol se deriva del usuario real, no de un campo oculto.
        $rol = SalaRepository::rolDeUsuario($pdo, $idUsuario);
        $rolStaff = $rol === Rol::TECNICO ? Rol::TECNICO : ($rol === Rol::BECA ? 'beca' : null);
        if ($rolStaff === null) {
            return self::r('Ese usuario no es técnico ni beca de trabajo.');
        }
        if (!SalaRepository::existe($pdo, $idSala)) {
            return self::r(self::MSG_SALA_NO_EXISTE);
        }
        try {
            SalaRepository::asignarStaff($pdo, $idSala, $idUsuario, $rolStaff);
            return self::r('', 'Persona asignada a la oficina.');
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'oficina_staff') !== false) {
                return self::r('Falta la tabla oficina_staff — actualiza tu base de datos con tickets_db.sql.');
            }
            throw $e;
        }
    }

    public static function quitarStaff(PDO $pdo, int $idSala, int $idUsuario): array
    {
        if (!$idSala || !$idUsuario) {
            return self::r();
        }
        SalaRepository::quitarStaff($pdo, $idSala, $idUsuario);
        return self::r('', 'Persona quitada de la oficina.');
    }

    /** Solo un administrador define la lista única de unidades organizacionales. */
    public static function crearUnidad(PDO $pdo, bool $esAdmin, string $nombre, string $tipo, ?int $idPadre, bool $esAdministradora): array
    {
        if (!$esAdmin) {
            return self::r('Solo un administrador puede crear dependencias, departamentos o planes de estudio.');
        }
        $nombre = trim($nombre);
        if ($nombre === '' || !in_array($tipo, self::TIPOS_UNIDAD, true)) {
            return self::r('Nombre y tipo de unidad son obligatorios.');
        }
        try {
            SalaRepository::crearUnidad($pdo, $nombre, $tipo, $idPadre, $esAdministradora ? 1 : 0);
            return self::r('', "Unidad \"{$nombre}\" creada.");
        } catch (PDOException $e) {
            return self::r('No se pudo crear la unidad. ¿El nombre ya existe bajo el mismo padre?');
        }
    }

    /** Agrupa las salas por dependencia (clave «Sin dependencia asignada» si no tiene). */
    public static function agruparPorDependencia(array $salas): array
    {
        $out = [];
        foreach ($salas as $s) {
            $clave = $s['dependencia'] !== null && $s['dependencia'] !== '' ? $s['dependencia'] : 'Sin dependencia asignada';
            $out[$clave][] = $s;
        }
        return $out;
    }

    /** Todos los datos que necesita la vista (solo lectura). */
    public static function datosPantalla(PDO $pdo): array
    {
        $salas = SalaRepository::listarConTotalEquipos($pdo);
        $claseActualPorSala = SalaRepository::clasesEnCurso($pdo, (int)date('N'), date('H:i:s'));
        $equiposPorSala = $salas ? SalaRepository::equiposPorSala($pdo) : [];

        $servicioResponsablePorSala = [];
        foreach ($salas as $s) {
            $servicioResponsablePorSala[$s['id_sala']] = ServicioResolver::servicioResponsable($pdo, (int)$s['id_sala'], null);
        }
        $staffPorSala = SalaRepository::staffPorSala($pdo);

        // GLPI (si hay conexión): ubicaciones agrupadas por su padre, vínculo actual y sugerencia por sala.
        $ubicacionesGlpi = GlpiUbicaciones::listar(Database::glpi());
        $glpiPorSala = [];
        $sugerenciaGlpiPorSala = [];
        if ($ubicacionesGlpi) {
            $porId = array_column($ubicacionesGlpi, null, 'id');
            foreach (GlpiColaRepository::salas($pdo) as $sg) {
                $idS = (int)$sg['id_sala'];
                $actual = $sg['glpi_location_id'] !== null ? (int)$sg['glpi_location_id'] : null;
                $glpiPorSala[$idS] = $actual !== null && isset($porId[$actual]) ? $porId[$actual] : null;
            }
            foreach ($salas as $s) {
                if (empty($glpiPorSala[(int)$s['id_sala']])) {
                    $sug = GlpiUbicaciones::detectar($ubicacionesGlpi, (string)$s['nombre_sala'], (string)($s['abreviatura'] ?? ''));
                    if ($sug !== null) $sugerenciaGlpiPorSala[(int)$s['id_sala']] = $porId[$sug];
                }
            }
        }
        return [
            'ubicacionesGlpi'            => $ubicacionesGlpi,
            'ubicacionesGlpiAgrupadas'   => GlpiUbicaciones::agrupar($ubicacionesGlpi),
            'glpiPorSala'                => $glpiPorSala,
            'sugerenciaGlpiPorSala'      => $sugerenciaGlpiPorSala,
            'salas'                      => $salas,
            'distribucionPorSala'        => DistribucionSalaRepository::porSalas($pdo, array_column($salas, 'id_sala')),
            'salasPorDependencia'        => self::agruparPorDependencia($salas),
            'claseActualPorSala'         => $claseActualPorSala,
            'equiposPorSala'             => $equiposPorSala,
            'unidades'                   => SalaRepository::unidades($pdo),
            'unidadesPorSala'            => SalaRepository::unidadesPorSala($pdo),
            'servicioResponsablePorSala' => $servicioResponsablePorSala,
            'staffDisponible'            => SalaRepository::personalDisponible($pdo),
            'staffPorSala'               => $staffPorSala,
        ];
    }
}
