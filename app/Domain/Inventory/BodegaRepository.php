<?php
/**
 * Persistencia de la revisión de componentes en bodega y de las solicitudes de baja. Solo SQL con consultas
 * preparadas; las reglas viven en BodegaService. Las operaciones de baja (crear/resolver solicitud) siguen en
 * ComponenteBajas, que ya existía.
 */
class BodegaRepository
{
    /** Componente EN_REVISION con ese id, o null. */
    public static function enRevisionPorId(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare("SELECT * FROM componentes WHERE id_componente=? AND estado='EN_REVISION'");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Componente EN_REVISION o DEFECTUOSO (candidato a solicitud de baja), o null. */
    public static function candidatoBaja(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare("SELECT * FROM componentes WHERE id_componente=? AND estado IN ('EN_REVISION','DEFECTUOSO')");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function cambiarEstado(PDO $pdo, int $id, string $estado): void
    {
        $pdo->prepare("UPDATE componentes SET estado=? WHERE id_componente=?")->execute([$estado, $id]);
    }

    public static function tieneSolicitudPendiente(PDO $pdo, int $idComponente): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM solicitudes_baja WHERE id_componente=? AND estado='PENDIENTE' LIMIT 1");
        $st->execute([$idComponente]);
        return (bool)$st->fetchColumn();
    }

    /** Componentes por página en «Revisar componentes en bodega». */
    public const POR_PAGINA = 25;

    /**
     * EN_REVISION, más recientes primero, por páginas (índice idx_comp_estado_fecha).
     * @return array{datos: array, total: int, total_paginas: int, pagina: int}
     */
    public static function enRevision(PDO $pdo, int $pagina = 1, int $porPagina = self::POR_PAGINA): array
    {
        return paginar($pdo, "SELECT * FROM componentes WHERE estado = 'EN_REVISION' ORDER BY fecha_registro DESC", [], $pagina, $porPagina);
    }

    /** Defectuosos sin solicitud de baja pendiente. */
    /** @return array{datos: array, total: int, total_paginas: int, pagina: int} */
    public static function defectuososSinSolicitud(PDO $pdo, int $pagina = 1, int $porPagina = self::POR_PAGINA): array
    {
        return paginar($pdo,
            "SELECT c.* FROM componentes c
             WHERE c.estado = 'DEFECTUOSO'
               AND NOT EXISTS (SELECT 1 FROM solicitudes_baja s WHERE s.id_componente = c.id_componente AND s.estado = 'PENDIENTE')
             ORDER BY c.fecha_registro DESC", [], $pagina, $porPagina);
    }

    /** Datos de una solicitud (para notificar al solicitante). */
    public static function solicitudPorId(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare("SELECT id_solicito, id_componente FROM solicitudes_baja WHERE id=?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function codigoComponente(PDO $pdo, int $idComponente): string
    {
        $st = $pdo->prepare("SELECT codigo_inventario FROM componentes WHERE id_componente=?");
        $st->execute([$idComponente]);
        return (string)($st->fetchColumn() ?: '#' . $idComponente);
    }

    /** Últimas solicitudes ya resueltas (más recientes primero). */
    public static function solicitudesResueltas(PDO $pdo, int $limite = 30): array
    {
        $st = $pdo->prepare(
            "SELECT s.*, c.codigo_inventario, u.nombre_completo AS solicito_nombre, ua.nombre_completo AS aprobo_nombre
             FROM solicitudes_baja s
             JOIN componentes c ON c.id_componente = s.id_componente
             JOIN users u ON u.id = s.id_solicito
             LEFT JOIN users ua ON ua.id = s.id_aprobo
             WHERE s.estado <> 'PENDIENTE'
             ORDER BY s.fecha_aprobado DESC
             LIMIT " . max(1, $limite)
        );
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
