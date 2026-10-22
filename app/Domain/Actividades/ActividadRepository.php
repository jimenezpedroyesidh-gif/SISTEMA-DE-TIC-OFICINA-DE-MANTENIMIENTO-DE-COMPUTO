<?php
/** SQL del tablero de actividades de la secretaría (tabla actividades_tablero). Sin reglas: ActividadService. */
final class ActividadRepository
{
    /** Actividades con el nombre de quien la tiene y de quien la creó. $soloDe: solo las de esa beca (y las sin asignar). */
    public static function listar(PDO $pdo, ?int $soloDe = null): array
    {
        $sql = "SELECT a.*, ua.nombre_completo AS asignado_nombre, uc.nombre_completo AS creador_nombre
                FROM actividades_tablero a
                LEFT JOIN users ua ON ua.id = a.id_asignado
                LEFT JOIN users uc ON uc.id = a.id_creador"
             . ($soloDe !== null ? " WHERE a.id_asignado = ? OR a.id_asignado IS NULL" : '')
             . " ORDER BY FIELD(a.estado,'POR_HACER','EN_CURSO','HECHO'), a.fecha_limite IS NULL, a.fecha_limite, a.actualizado_en DESC";
        $st = $pdo->prepare($sql);
        $st->execute($soloDe !== null ? [$soloDe] : []);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function porId(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM actividades_tablero WHERE id_actividad = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function crear(PDO $pdo, array $d, int $idCreador): int
    {
        $pdo->prepare('INSERT INTO actividades_tablero (titulo, detalle, color, id_asignado, id_creador, fecha_limite) VALUES (?,?,?,?,?,?)')
            ->execute([$d['titulo'], $d['detalle'], $d['color'], $d['id_asignado'], $idCreador, $d['fecha_limite']]);
        return (int)$pdo->lastInsertId();
    }

    public static function actualizar(PDO $pdo, int $id, array $d): void
    {
        $pdo->prepare('UPDATE actividades_tablero SET titulo=?, detalle=?, color=?, id_asignado=?, fecha_limite=? WHERE id_actividad=?')
            ->execute([$d['titulo'], $d['detalle'], $d['color'], $d['id_asignado'], $d['fecha_limite'], $id]);
    }

    public static function mover(PDO $pdo, int $id, string $estado, ?int $idAsignado): void
    {
        $pdo->prepare('UPDATE actividades_tablero SET estado=?, id_asignado=? WHERE id_actividad=?')->execute([$estado, $idAsignado, $id]);
    }

    public static function borrar(PDO $pdo, int $id): void
    {
        $pdo->prepare('DELETE FROM actividades_tablero WHERE id_actividad=?')->execute([$id]);
    }
}
