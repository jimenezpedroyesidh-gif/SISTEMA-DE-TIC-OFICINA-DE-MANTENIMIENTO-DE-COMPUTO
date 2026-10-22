<?php
/**
 * SQL de los indicadores de la mesa de ayuda que no salen de los tickets: mantenimientos preventivos,
 * disponibilidad de equipos por sala y equipos reincidentes. Solo lectura.
 */
final class IndicadoresRepository
{
    /** Preventivos programados en el periodo (hasta hoy): cuántos se hicieron y cuántos a tiempo (máx. 1 día después). */
    public static function preventivos(PDO $pdo, string $desde, string $hasta, ?int $idSala, string $dependencia): array
    {
        $sql = "SELECT COUNT(*) AS programados,
                       SUM(mp.estado = 'REALIZADO') AS realizados,
                       SUM(mp.estado = 'REALIZADO' AND mp.fecha_realizado <= DATE_ADD(DATE(mp.fecha_programada), INTERVAL 2 DAY)) AS a_tiempo
                FROM mantenimientos_programados mp JOIN salas s ON s.id_sala = mp.id_sala
                WHERE mp.tipo = 'MANTENIMIENTO_PREVENTIVO' AND mp.estado <> 'CANCELADO'
                  AND mp.fecha_programada BETWEEN ? AND LEAST(?, NOW())";
        $p = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];
        if ($idSala) { $sql .= ' AND mp.id_sala = ?'; $p[] = $idSala; }
        if ($dependencia !== '') { $sql .= ' AND s.dependencia = ?'; $p[] = $dependencia; }
        $st = $pdo->prepare($sql);
        $st->execute($p);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['programados' => (int)($r['programados'] ?? 0), 'realizados' => (int)($r['realizados'] ?? 0), 'a_tiempo' => (int)($r['a_tiempo'] ?? 0)];
    }

    /** Equipos operativos sobre el total, por sala (foto de hoy). */
    public static function disponibilidad(PDO $pdo, ?int $idSala, string $dependencia): array
    {
        $sql = "SELECT s.id_sala, s.nombre_sala, COUNT(e.id_equipo) AS total, SUM(e.operativo = 1) AS operativos
                FROM salas s JOIN equipos e ON e.id_sala = s.id_sala
                WHERE s.es_oficina_mantenimiento = 0";
        $p = [];
        if ($idSala) { $sql .= ' AND s.id_sala = ?'; $p[] = $idSala; }
        if ($dependencia !== '') { $sql .= ' AND s.dependencia = ?'; $p[] = $dependencia; }
        $sql .= ' GROUP BY s.id_sala, s.nombre_sala ORDER BY (SUM(e.operativo = 1) / COUNT(e.id_equipo)), s.nombre_sala';
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Equipos con más de un incidente entre los tickets dados (tabla => ids).
     * @return list<array{codigo:string,nombre_sala:?string,incidentes:int}>
     */
    public static function reincidentes(PDO $pdo, array $ids, int $minimo = 2): array
    {
        $partes = []; $p = [];
        foreach ($ids as $tabla => $lista) {
            $lista = array_values(array_unique(array_map('intval', $lista)));
            if (!$lista) continue;
            $partes[] = '(ta.tabla_ticket = ? AND ta.id_ticket IN (' . implode(',', array_fill(0, count($lista), '?')) . '))';
            $p[] = $tabla;
            array_push($p, ...$lista);
        }
        if (!$partes) return [];
        $sql = "SELECT e.codigo, s.nombre_sala, COUNT(DISTINCT CONCAT(ta.tabla_ticket, '#', ta.id_ticket)) AS incidentes
                FROM ticket_activos ta JOIN equipos e ON e.id_equipo = ta.id_equipo LEFT JOIN salas s ON s.id_sala = e.id_sala
                WHERE ta.id_equipo IS NOT NULL AND (" . implode(' OR ', $partes) . ")
                GROUP BY e.id_equipo, e.codigo, s.nombre_sala HAVING incidentes >= ?
                ORDER BY incidentes DESC, e.codigo LIMIT 15";
        $p[] = $minimo;
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
