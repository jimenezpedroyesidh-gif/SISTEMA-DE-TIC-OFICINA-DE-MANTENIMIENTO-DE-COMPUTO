<?php
/**
 * Editor de la distribución física del salón (distribucion_sala.php): carga lo necesario para
 * maquetar filas, mesas y puestos, valida lo que envía el editor y lo guarda con auditoría.
 * Reglas de la estructura en DistribucionSala; SQL en DistribucionSalaRepository.
 */
final class DistribucionSalaService
{
    private static function r(string $error = '', string $exito = ''): array
    {
        return ['error' => $error, 'exito' => $exito];
    }

    /** Equipos de la sala con su tipo de puesto (pc / docente / videobeam / parlante). */
    public static function equiposDeSala(PDO $pdo, int $idSala): array
    {
        $eqs = ListadoAjaxRepository::equiposDeSala($pdo, $idSala);
        foreach ($eqs as &$e) { $e['id_equipo'] = (int)$e['id_equipo']; $e['tipo'] = tipoPuestoSala((string)$e['codigo'], (string)($e['descripcion'] ?? '')); }
        unset($e);
        usort($eqs, fn($a, $b) => strnatcasecmp((string)$a['codigo'], (string)$b['codigo']));
        return $eqs;
    }

    /**
     * Datos del editor. Si la sala aún no tiene distribución guardada, propone una inicial con el
     * mismo reparto del plano automático (para no empezar de cero).
     */
    public static function pantalla(PDO $pdo, int $idSala): ?array
    {
        $sala = SalaRepository::datos($pdo, $idSala);
        if (!$sala) return null;
        $equipos = self::equiposDeSala($pdo, $idSala);
        $guardada = DistribucionSalaRepository::deSala($pdo, $idSala);
        if ($guardada !== null) {
            $codigos = array_column($equipos, 'codigo', 'id_equipo');
            // Se vuelve a normalizar: si un equipo se trasladó a otra sala, su puesto queda vacío.
            $estructura = DistribucionSala::normalizar($guardada, array_keys($codigos), $codigos)['estructura'];
        } else {
            $pcs = array_values(array_map(fn($e) => $e['id_equipo'], array_filter($equipos, fn($e) => $e['tipo'] === 'pc')));
            $docentes = array_values(array_filter($equipos, fn($e) => $e['tipo'] === 'docente'));
            $estructura = DistribucionSala::inicial($pcs, $docentes ? $docentes[0]['id_equipo'] : null);
        }
        return [
            'sala' => $sala,
            'equipos' => $equipos,
            'estructura' => $estructura,
            'esNueva' => $guardada === null,
            'ultimaEdicion' => DistribucionSalaRepository::ultimaEdicion($pdo, $idSala),
        ];
    }

    /** Valida y guarda lo que envió el editor (JSON). */
    public static function guardar(PDO $pdo, int $idSala, string $json, int $idUsuario): array
    {
        if (!SalaRepository::existe($pdo, $idSala)) return self::r('La sala no existe.');
        $datos = json_decode($json, true);
        if (!is_array($datos)) return self::r('No se recibió una distribución válida.');
        $equipos = self::equiposDeSala($pdo, $idSala);
        $codigos = array_column($equipos, 'codigo', 'id_equipo');
        $res = DistribucionSala::normalizar($datos, array_keys($codigos), $codigos);
        if ($res['errores']) return self::r(implode(' ', array_slice($res['errores'], 0, 5)));
        if (!$res['estructura']['filas']) return self::r('Agrega al menos una fila con una mesa.');
        DistribucionSalaRepository::guardar($pdo, $idSala, $res['estructura'], $idUsuario);
        $total = count(DistribucionSala::recorrido($res['estructura']));
        $ubicados = count(DistribucionSala::idsUbicados($res['estructura']));
        AuditoriaService::registrar($pdo, $idUsuario, 'salas', $idSala, 'EDITAR', "Distribución del salón: " . count($res['estructura']['filas']) . " fila(s), $total puesto(s), $ubicados equipo(s) ubicados");
        $sinUbicar = count($equipos) - $ubicados;
        return self::r('', "Distribución guardada: $total puesto(s), $ubicados equipo(s) ubicados." . ($sinUbicar > 0 ? " Quedan $sinUbicar equipo(s) de la sala sin puesto en el plano." : ''));
    }

    /** Borra la distribución: el plano vuelve al reparto automático por código. */
    public static function restablecer(PDO $pdo, int $idSala, int $idUsuario): array
    {
        DistribucionSalaRepository::borrar($pdo, $idSala);
        AuditoriaService::registrar($pdo, $idUsuario, 'salas', $idSala, 'EDITAR', 'Distribución del salón restablecida al reparto automático');
        return self::r('', 'Distribución restablecida: el plano vuelve a repartir los equipos automáticamente.');
    }
}
