<?php
/**
 * Ubicaciones de GLPI para vincular salas: agrupadas por su ubicación padre (p. ej. «Aula Sur» →
 * SA401, SA402) y detección automática de la ubicación que corresponde a una sala por su nombre o
 * abreviatura. Así, al crear una sala nueva el vínculo con GLPI queda hecho de una vez (si hay
 * conexión con la base de GLPI); si no hay conexión, simplemente no se ofrece y todo sigue igual.
 * Solo lectura sobre GLPI (GlpiDb::ubicaciones).
 */
final class GlpiUbicaciones
{
    /** @return array<int, array{id:int, completo:string, nombre:string, grupo:string}> lista plana (vacía si no hay GLPI) */
    public static function listar(?PDO $glpi): array
    {
        if ($glpi === null) return [];
        $out = [];
        foreach (GlpiDb::ubicaciones($glpi) as $u) {
            $partes = array_map('trim', explode('>', (string)$u['completename']));
            $nombre = (string)array_pop($partes);
            $out[] = ['id' => (int)$u['id'], 'completo' => (string)$u['completename'], 'nombre' => $nombre, 'grupo' => $partes ? implode(' > ', $partes) : 'Sin ubicación padre'];
        }
        return $out;
    }

    /** Lista plana → [grupo => [ubicaciones...]] para pintar <optgroup>. */
    public static function agrupar(array $ubicaciones): array
    {
        $g = [];
        foreach ($ubicaciones as $u) { $g[$u['grupo']][] = $u; }
        ksort($g);
        return $g;
    }

    /**
     * Ubicación de GLPI que corresponde a una sala: primero coincidencia exacta del último tramo
     * («Aula Sur > SA401» ↔ «SA401») con el nombre o la abreviatura; si no, la que contenga uno
     * de los dos (solo si es una única candidata, para no adivinar mal). null si no hay coincidencia.
     */
    public static function detectar(array $ubicaciones, string $nombre, string $abreviatura = ''): ?int
    {
        $norm = static fn(string $s): string => preg_replace('/[^a-z0-9]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s));
        $claves = array_values(array_filter([$norm($nombre), $norm($abreviatura)], fn($k) => strlen($k) >= 2));
        if (!$claves) return null;
        foreach ($ubicaciones as $u) {
            if (in_array($norm($u['nombre']), $claves, true)) return $u['id'];
        }
        $candidatas = array_values(array_filter($ubicaciones, function ($u) use ($claves, $norm) {
            $n = $norm($u['nombre']);
            foreach ($claves as $k) { if (strlen($n) >= 3 && (str_contains($n, $k) || str_contains($k, $n))) return true; }
            return false;
        }));
        return count($candidatas) === 1 ? $candidatas[0]['id'] : null;
    }
}
