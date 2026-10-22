<?php
/**
 * ComponenteRepository — SQL (solo consultas preparadas) de las fichas de inventario:
 * alta/edición de piezas, ficha de un componente y su historial.
 * Sin reglas de negocio ni HTML: las decisiones viven en ComponenteService.
 */
final class ComponenteRepository
{
    /** Alta de una pieza en estado ACTIVO (equipo o sala directa; ambos pueden ser null = bodega). */
    public static function insertar(
        PDO $pdo, string $tipo, string $serial, string $codigoInventario, ?string $marca, ?string $modelo,
        ?int $idFabricante, ?int $idModelo, ?string $descripcion, ?int $idEquipo, ?int $idSalaDirecta
    ): int {
        $pdo->prepare(
            "INSERT INTO componentes (tipo, serial, codigo_inventario, marca, modelo, id_fabricante, id_modelo, descripcion, estado, id_equipo_actual, id_sala_directa)
             VALUES (?,?,?,?,?,?,?,?, 'ACTIVO', ?, ?)"
        )->execute([$tipo, $serial, $codigoInventario, $marca, $modelo, $idFabricante, $idModelo, $descripcion, $idEquipo, $idSalaDirecta]);
        return (int)$pdo->lastInsertId();
    }

    public static function existe(PDO $pdo, int $id): bool
    {
        $s = $pdo->prepare("SELECT 1 FROM componentes WHERE id_componente = ?");
        $s->execute([$id]);
        return (bool)$s->fetchColumn();
    }

    public static function actualizarDescriptivos(PDO $pdo, int $id, ?string $marca, ?string $modelo, ?string $descripcion): void
    {
        $pdo->prepare("UPDATE componentes SET marca=?, modelo=?, descripcion=? WHERE id_componente=?")
            ->execute([$marca, $modelo, $descripcion, $id]);
    }

    /** Código del equipo o null si no existe. */
    public static function codigoEquipo(PDO $pdo, int $idEquipo): ?string
    {
        $s = $pdo->prepare("SELECT codigo FROM equipos WHERE id_equipo = ?");
        $s->execute([$idEquipo]);
        $v = $s->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    /** Nombre de la sala o null si no existe. */
    public static function nombreSala(PDO $pdo, int $idSala): ?string
    {
        $s = $pdo->prepare("SELECT nombre_sala FROM salas WHERE id_sala = ?");
        $s->execute([$idSala]);
        $v = $s->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    /** Fila completa del componente con bloqueo (usar dentro de una transacción). */
    public static function bloquearPorId(PDO $pdo, int $id): ?array
    {
        $s = $pdo->prepare("SELECT * FROM componentes WHERE id_componente = ? FOR UPDATE");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function asignarReinstalado(PDO $pdo, int $id, int $idEquipo): void
    {
        $pdo->prepare("UPDATE componentes SET estado='ACTIVO', id_equipo_actual=? WHERE id_componente=?")->execute([$idEquipo, $id]);
    }

    public static function marcarEquipoOperativo(PDO $pdo, int $idEquipo): void
    {
        $pdo->prepare("UPDATE equipos SET operativo=1 WHERE id_equipo=?")->execute([$idEquipo]);
    }

    public static function actualizarEstado(PDO $pdo, int $id, string $estado): void
    {
        $pdo->prepare("UPDATE componentes SET estado=? WHERE id_componente=?")->execute([$estado, $id]);
    }

    public static function codigoInventario(PDO $pdo, int $id): ?string
    {
        $s = $pdo->prepare("SELECT codigo_inventario FROM componentes WHERE id_componente = ?");
        $s->execute([$id]);
        $v = $s->fetchColumn();
        return $v ? (string)$v : null;
    }

    /** Ficha del componente con su equipo/sala actual (o sala directa). */
    public static function ficha(PDO $pdo, int $id): ?array
    {
        $s = $pdo->prepare("
            SELECT c.*, e.codigo AS equipo_codigo, e.descripcion AS equipo_descripcion, s.nombre_sala, s.id_sala AS id_sala_equipo,
                   sd.nombre_sala AS nombre_sala_directa
            FROM componentes c
            LEFT JOIN equipos e ON c.id_equipo_actual = e.id_equipo
            LEFT JOIN salas s ON e.id_sala = s.id_sala
            LEFT JOIN salas sd ON c.id_sala_directa = sd.id_sala
            WHERE c.id_componente = ?
        ");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function historial(PDO $pdo, int $id): array
    {
        $s = $pdo->prepare("
            SELECT h.*, u.nombre_completo AS responsable_nombre,
                   ea.codigo AS equipo_anterior_codigo, en.codigo AS equipo_nuevo_codigo
            FROM historial_componente h
            LEFT JOIN users u ON h.id_usuario_responsable = u.id
            LEFT JOIN equipos ea ON h.id_equipo_anterior = ea.id_equipo
            LEFT JOIN equipos en ON h.id_equipo_nuevo = en.id_equipo
            WHERE h.id_componente = ?
            ORDER BY h.fecha_evento DESC
        ");
        $s->execute([$id]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Salas para el desplegable «Reinstalar». */
    public static function salasParaSelect(PDO $pdo): array
    {
        return $pdo->query("SELECT id_sala, nombre_sala FROM salas ORDER BY nombre_sala")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Equipos con su sala para el desplegable «Asignar a equipo». */
    public static function equiposParaSelect(PDO $pdo): array
    {
        return $pdo->query("
            SELECT e.id_equipo, e.codigo, e.descripcion, s.nombre_sala
            FROM equipos e JOIN salas s ON e.id_sala = s.id_sala
            ORDER BY s.nombre_sala, e.codigo
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}
