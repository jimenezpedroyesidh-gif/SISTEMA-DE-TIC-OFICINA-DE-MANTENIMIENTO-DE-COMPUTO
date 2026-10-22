<?php
/**
 * Las consultas siguen en app/Support/inventario.php (buscarComponentes / buscarComponentePorId, compartidas
 * con el buscador global); este servicio solo decide cuál usar y da forma a la respuesta.
 */
require_once dirname(__DIR__, 2) . '/Support/inventario.php';

final class ComponenteBusquedaService
{
    /** @return array<int,array<string,mixed>> */
    public static function buscar(PDO $pdo, string $q, bool $soloDisponibles, ?int $idSalaContexto, ?int $idBuscado): array
    {
        if ($idBuscado) {
            // Búsqueda puntual por id (viene del QR de ficha_componente.php).
            $encontrado = buscarComponentePorId($pdo, $idBuscado, $soloDisponibles, $idSalaContexto);
            $resultados = $encontrado ? [$encontrado] : [];
        } else {
            if ($q === '' || mb_strlen($q) < 2) {
                return [];
            }
            $resultados = buscarComponentes($pdo, $q, $soloDisponibles, 10, $idSalaContexto);
        }
        return array_map(fn($c) => [
            'id' => (int)$c['id_componente'],
            'tipo' => $c['tipo'],
            'serial' => $c['serial'],
            'codigo_inventario' => $c['codigo_inventario'],
            'marca' => $c['marca'],
            'modelo' => $c['modelo'],
            'estado' => $c['estado'],
            'ubicacion' => $c['equipo_codigo'] ? ($c['nombre_sala'] . ' — ' . $c['equipo_codigo']) : 'En bodega',
            'etiqueta' => $c['codigo_inventario'] . ' — ' . ucfirst($c['tipo']) . ' ' . ($c['marca'] ?? ''),
        ], $resultados);
    }
}
