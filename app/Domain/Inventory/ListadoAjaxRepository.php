<?php
/** Listados cortos para selects dependientes: equipos de una sala y modelos de un fabricante. SQL preparado. */
final class ListadoAjaxRepository
{
    public static function equiposDeSala(PDO $pdo, int $idSala): array
    {
        // es_computo: tiene torre/portátil/todo en uno (instalar software solo aplica a esos).
        $stmt = $pdo->prepare("SELECT e.id_equipo, e.codigo, e.descripcion,
                                      EXISTS (SELECT 1 FROM componentes c WHERE c.id_equipo_actual = e.id_equipo AND c.tipo IN ('torre','portatil','todo_en_uno')) AS es_computo
                               FROM equipos e WHERE e.id_sala = ? ORDER BY e.codigo");
        $stmt->execute([$idSala]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function modelosDeFabricante(PDO $pdo, int $idFabricante): array
    {
        $stmt = $pdo->prepare("SELECT id_modelo, nombre FROM modelos_activo WHERE id_fabricante = ? ORDER BY nombre");
        $stmt->execute([$idFabricante]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
