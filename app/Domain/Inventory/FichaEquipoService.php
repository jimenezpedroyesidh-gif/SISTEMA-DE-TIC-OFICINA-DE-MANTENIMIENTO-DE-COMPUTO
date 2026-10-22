<?php
/**
 * FichaEquipoService — arma los datos de public/ficha_equipo.php (solo lectura):
 * el detalle de un puesto y el listado de equipos agrupado por dependencia → sala.
 */
require_once __DIR__ . '/../../Support/lib_inventario.php';

final class FichaEquipoService
{
    public const SIN_DEPENDENCIA = 'Sin dependencia asignada';

    /** Datos de la ficha de un puesto (el equipo ya fue cargado por el Controller). */
    public static function detalle(PDO $pdo, array $equipo): array
    {
        $idEquipo = (int)$equipo['id_equipo'];
        $idSala = (int)$equipo['id_sala'];
        return [
            // Mismo criterio de «operativo» que el plano de la sala (piezas retiradas, traslados, etc.)
            'estadoEquipo'              => PlanoSalaService::estadoEquipos($pdo, [$idEquipo], true)[$idEquipo] ?? null,
            'componentes'               => FichaEquipoRepository::componentes($pdo, $idEquipo),
            'historial'                 => FichaEquipoRepository::eventosRecientes($pdo, $idEquipo),
            'historialMantenimientos'   => FichaEquipoRepository::mantenimientos($pdo, $idEquipo),
            'historialTickets'          => FichaEquipoRepository::tickets($pdo, $idEquipo, $idSala),
            'historialTicketsArchivados' => ArchivoSemestralRepository::deEquipo($pdo, $idEquipo, $idSala),
            'historialTicketsProfesor'  => FichaEquipoRepository::ticketsProfesor($pdo, $idSala),
            // Puesto en la distribución del salón (número, fila/mesa y punto de red), si se maquetó.
            'puestoEnSala'              => ($dist = DistribucionSalaRepository::deSala($pdo, $idSala)) ? DistribucionSala::puestoDeEquipo($dist, $idEquipo) : null,
        ];
    }

    /**
     * Listado de equipos. Una búsqueda con texto SIEMPRE mira todo el inventario; sin texto, y si el
     * usuario tiene dependencia y no pidió «todas», se limita a la suya.
     * Devuelve: miDependencia, filtrandoPorMiDependencia, equipos, equiposAgrupados, estadosEquipos, frentePorSala y distribucionPorSala.
     */
    public static function listado(PDO $pdo, int $idUsuario, string $q, bool $verTodas): array
    {
        $miDependencia = FichaEquipoRepository::dependenciaDeUsuario($pdo, $idUsuario);
        $filtrando = ($miDependencia !== null && $q === '' && !$verTodas);

        if ($q !== '') {
            $equipos = FichaEquipoRepository::buscarEquipos($pdo, $q);
        } elseif ($filtrando) {
            $equipos = FichaEquipoRepository::equiposDeDependencia($pdo, $miDependencia);
        } else {
            $equipos = FichaEquipoRepository::todosLosEquipos($pdo);
        }

        // Plano de cada sala: estado real de cada puesto y videobeam/parlantes frente al tablero.
        $idsSala = array_values(array_unique(array_map(fn($e) => (int)$e['id_sala'], $equipos)));
        return [
            'estadosEquipos'            => PlanoSalaService::estadoEquipos($pdo, array_column($equipos, 'id_equipo'), true),
            'frentePorSala'             => PlanoSalaService::frentePorSalas($pdo, $idsSala, true),
            'distribucionPorSala'       => DistribucionSalaRepository::porSalas($pdo, $idsSala),
            'miDependencia'             => $miDependencia,
            'filtrandoPorMiDependencia' => $filtrando,
            'equipos'                   => $equipos,
            'equiposAgrupados'          => self::agrupar($pdo, $equipos),
        ];
    }

    /** dependencia → sala (por id_sala) → equipos + infraestructura; «Sin dependencia asignada» queda al final. */
    public static function agrupar(PDO $pdo, array $equipos): array
    {
        $agrupados = [];
        foreach ($equipos as $e) {
            $dep = trim((string)($e['dependencia'] ?? '')) !== '' ? $e['dependencia'] : self::SIN_DEPENDENCIA;
            $idSala = (int)$e['id_sala'];
            if (!isset($agrupados[$dep][$idSala])) {
                $agrupados[$dep][$idSala] = [
                    'nombre_sala' => $e['nombre_sala'],
                    'equipos' => [],
                    'infraestructura' => listarInfraestructuraPorSala($pdo, $idSala),
                ];
            }
            $agrupados[$dep][$idSala]['equipos'][] = $e;
        }
        if (isset($agrupados[self::SIN_DEPENDENCIA])) {
            $bloque = $agrupados[self::SIN_DEPENDENCIA];
            unset($agrupados[self::SIN_DEPENDENCIA]);
            $agrupados[self::SIN_DEPENDENCIA] = $bloque;
        }
        return $agrupados;
    }
}
