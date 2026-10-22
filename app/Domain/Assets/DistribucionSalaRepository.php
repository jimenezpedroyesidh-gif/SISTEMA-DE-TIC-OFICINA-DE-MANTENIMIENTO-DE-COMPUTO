<?php
/**
 * Persistencia de la distribución física de cada salón (tabla sala_distribucion, JSON validado
 * por DistribucionSala). Sala sin distribución guardada: el plano usa el reparto automático.
 */
final class DistribucionSalaRepository
{
    /** @return array<int, array> id_sala => estructura (solo las salas que tienen una guardada) */
    public static function porSalas(PDO $pdo, array $idsSala): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $idsSala))));
        if (!$ids) return [];
        try {
            $st = $pdo->prepare('SELECT id_sala, estructura FROM sala_distribucion WHERE id_sala IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $st->execute($ids);
        } catch (PDOException $e) {
            return [];
        }
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $e = json_decode((string)$r['estructura'], true);
            if (is_array($e) && isset($e['filas'])) $out[(int)$r['id_sala']] = $e;
        }
        return $out;
    }

    public static function deSala(PDO $pdo, int $idSala): ?array
    {
        return self::porSalas($pdo, [$idSala])[$idSala] ?? null;
    }

    /** Quién y cuándo la modificó por última vez (null si no hay o falta la tabla). */
    public static function ultimaEdicion(PDO $pdo, int $idSala): ?array
    {
        try {
            $st = $pdo->prepare('SELECT d.actualizado_en, u.nombre_completo FROM sala_distribucion d LEFT JOIN users u ON u.id = d.id_usuario WHERE d.id_sala = ?');
            $st->execute([$idSala]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }

    public static function guardar(PDO $pdo, int $idSala, array $estructura, int $idUsuario): void
    {
        $st = $pdo->prepare('INSERT INTO sala_distribucion (id_sala, estructura, id_usuario) VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE estructura = VALUES(estructura), id_usuario = VALUES(id_usuario), actualizado_en = CURRENT_TIMESTAMP');
        $st->execute([$idSala, json_encode($estructura, JSON_UNESCAPED_UNICODE), $idUsuario]);
    }

    public static function borrar(PDO $pdo, int $idSala): void
    {
        $pdo->prepare('DELETE FROM sala_distribucion WHERE id_sala = ?')->execute([$idSala]);
    }
}
