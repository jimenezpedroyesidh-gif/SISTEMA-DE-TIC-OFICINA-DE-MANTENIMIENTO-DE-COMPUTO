<?php
/**
 * Lectura del inventario directamente en la base de GLPI (solo SELECT; el usuario de BD debe ser de solo lectura).
 * Tablas de GLPI 10/11 usadas: glpi_computers, glpi_monitors, glpi_peripherals, glpi_printers,
 * glpi_networkequipments, glpi_locations, glpi_manufacturers, glpi_*models, glpi_states.
 * Si una tabla no existe (otra versión, permisos), ese tipo simplemente no aparece.
 */
final class GlpiDb
{
    /** itemtype de GLPI => [tabla, tabla de modelos, columna de modelo, etiqueta] */
    public const TIPOS = [
        'Computer'         => ['glpi_computers', 'glpi_computermodels', 'computermodels_id', 'Computadores'],
        'Monitor'          => ['glpi_monitors', 'glpi_monitormodels', 'monitormodels_id', 'Monitores'],
        'Peripheral'       => ['glpi_peripherals', 'glpi_peripheralmodels', 'peripheralmodels_id', 'Periféricos'],
        'Printer'          => ['glpi_printers', 'glpi_printermodels', 'printermodels_id', 'Impresoras'],
        'NetworkEquipment' => ['glpi_networkequipments', 'glpi_networkequipmentmodels', 'networkequipmentmodels_id', 'Equipos de red'],
    ];

    /**
     * Activos de GLPI (no borrados ni plantillas). Filtro opcional por ubicación (incluye sububicaciones).
     * @return array<int,array{itemtype:string,id:int,nombre:string,serial:string,inventario:string,id_ubicacion:int,ubicacion:string,fabricante:string,modelo:string,estado:string}>
     */
    public static function activos(PDO $g, ?int $idUbicacion = null, int $limite = 5000): array
    {
        $filtroUbic = '';
        $params = [];
        if ($idUbicacion !== null) {
            $filtroUbic = ' AND (a.locations_id = ? OR a.locations_id IN (SELECT id FROM glpi_locations WHERE ancestors_cache LIKE ?))';
        }
        $out = [];
        foreach (self::TIPOS as $itemtype => [$tabla, $tablaModelo, $colModelo]) {
            $sql = "SELECT a.id, a.name, a.serial, a.otherserial, a.locations_id,
                           COALESCE(l.completename, '') AS ubicacion, COALESCE(m.name, '') AS fabricante,
                           COALESCE(mo.name, '') AS modelo, COALESCE(s.completename, '') AS estado
                    FROM `$tabla` a
                    LEFT JOIN glpi_locations l ON l.id = a.locations_id
                    LEFT JOIN glpi_manufacturers m ON m.id = a.manufacturers_id
                    LEFT JOIN `$tablaModelo` mo ON mo.id = a.`$colModelo`
                    LEFT JOIN glpi_states s ON s.id = a.states_id
                    WHERE a.is_deleted = 0 AND a.is_template = 0$filtroUbic
                    ORDER BY ubicacion, a.name
                    LIMIT " . (int)$limite;
            try {
                $st = $g->prepare($sql);
                $st->execute($idUbicacion !== null ? [$idUbicacion, '%"' . $idUbicacion . '"%'] : $params);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
                    $out[] = [
                        'itemtype' => $itemtype, 'id' => (int)$f['id'], 'nombre' => (string)$f['name'],
                        'serial' => (string)$f['serial'], 'inventario' => (string)$f['otherserial'],
                        'id_ubicacion' => (int)$f['locations_id'], 'ubicacion' => (string)$f['ubicacion'],
                        'fabricante' => (string)$f['fabricante'], 'modelo' => (string)$f['modelo'], 'estado' => (string)$f['estado'],
                    ];
                }
            } catch (PDOException $e) {
                // tabla ausente o sin permiso: ese tipo no se muestra
            }
        }
        return $out;
    }

    /** Ubicaciones de GLPI (id, nombre completo) para vincular salas. */
    public static function ubicaciones(PDO $g): array
    {
        try {
            return $g->query("SELECT id, completename FROM glpi_locations ORDER BY completename")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    /** Busca un activo por número de serie en todos los tipos: ['itemtype'=>, 'id'=>] o null. */
    public static function buscarPorSerial(PDO $g, string $serial): ?array
    {
        if (trim($serial) === '') return null;
        foreach (self::TIPOS as $itemtype => [$tabla]) {
            try {
                $st = $g->prepare("SELECT id FROM `$tabla` WHERE serial = ? AND is_deleted = 0 AND is_template = 0 LIMIT 1");
                $st->execute([$serial]);
                $id = $st->fetchColumn();
                if ($id !== false) return ['itemtype' => $itemtype, 'id' => (int)$id];
            } catch (PDOException $e) {
            }
        }
        return null;
    }
}
