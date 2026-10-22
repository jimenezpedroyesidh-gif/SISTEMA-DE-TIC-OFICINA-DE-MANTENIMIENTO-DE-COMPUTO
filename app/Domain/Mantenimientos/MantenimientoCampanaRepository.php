<?php
/**
 * MantenimientoCampanaRepository — persistencia de las campañas de mantenimiento (tabla mantenimiento_campanas).
 * Las intervenciones de cada sala son filas normales de mantenimientos_programados (id_campana + orden_campana):
 * aquí solo se leen agrupadas. Solo SQL con consultas preparadas; las reglas viven en MantenimientoCampanaService.
 */
final class MantenimientoCampanaRepository
{
    public static function insertar(PDO $pdo, array $c): int
    {
        $st = $pdo->prepare(
            "INSERT INTO mantenimiento_campanas
                (nombre, tipo, alcance_equipo, descripcion, actividades, enlace_referencia, fecha_inicio, duracion_minutos, secuencial, id_responsable, id_creador)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $st->execute([$c['nombre'], $c['tipo'], $c['alcance'], $c['descripcion'], $c['actividades'], $c['enlace'], $c['fecha_inicio'],
            $c['duracion'], $c['secuencial'] ? 1 : 0, $c['id_responsable'], $c['id_creador']]);
        return (int)$pdo->lastInsertId();
    }

    public static function buscar(PDO $pdo, int $id)
    {
        $st = $pdo->prepare("SELECT c.*, r.nombre_completo AS responsable_nombre, u.nombre_completo AS creador_nombre
                             FROM mantenimiento_campanas c LEFT JOIN users r ON r.id = c.id_responsable JOIN users u ON u.id = c.id_creador
                             WHERE c.id_campana = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /** Campañas con el conteo de sus intervenciones por estado (activas primero). */
    public static function listar(PDO $pdo, string $estado = ''): array
    {
        $sql = "SELECT c.id_campana, c.nombre, c.tipo, c.fecha_inicio, c.secuencial, c.estado, r.nombre_completo AS responsable_nombre,
                       COUNT(m.id_programado) AS total,
                       SUM(m.estado = 'REALIZADO') AS realizadas,
                       SUM(m.estado = 'CANCELADO') AS canceladas,
                       SUM(m.estado = 'PENDIENTE') AS bloqueadas,
                       SUM(m.estado = 'EN_PROCESO') AS en_proceso,
                       SUM(m.estado IN ('PROGRAMADO','REPROGRAMADO')) AS programadas,
                       SUM(EXISTS (SELECT 1 FROM mantenimiento_reprogramaciones rp WHERE rp.id_programado = m.id_programado)) AS reprogramadas
                FROM mantenimiento_campanas c
                LEFT JOIN users r ON r.id = c.id_responsable
                LEFT JOIN mantenimientos_programados m ON m.id_campana = c.id_campana";
        $p = [];
        if ($estado !== '') {
            $sql .= ' WHERE c.estado = ?';
            $p[] = $estado;
        }
        $sql .= " GROUP BY c.id_campana ORDER BY c.estado = 'ACTIVA' DESC, c.fecha_inicio DESC, c.id_campana DESC LIMIT 100";
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Intervenciones de la campaña en su orden, con avance de la checklist y si se reprogramaron. */
    public static function intervenciones(PDO $pdo, int $idCampana): array
    {
        $st = $pdo->prepare(
            "SELECT m.id_programado, m.id_sala, m.orden_campana, m.fecha_programada, m.duracion_minutos, m.estado, m.resultado, m.id_responsable,
                    m.fecha_realizado, s.nombre_sala, s.abreviatura,
                    (SELECT COUNT(*) FROM mantenimiento_equipos me WHERE me.id_programado = m.id_programado AND me.estado <> 'EXCLUIDO_TRASLADO') AS total_equipos,
                    (SELECT COUNT(*) FROM mantenimiento_equipos me WHERE me.id_programado = m.id_programado AND me.estado = 'REALIZADO') AS equipos_realizados,
                    (SELECT COUNT(*) FROM mantenimiento_reprogramaciones rp WHERE rp.id_programado = m.id_programado) AS veces_reprogramada
             FROM mantenimientos_programados m JOIN salas s ON s.id_sala = m.id_sala
             WHERE m.id_campana = ? ORDER BY m.orden_campana, m.id_programado"
        );
        $st->execute([$idCampana]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** ¿Queda alguna intervención en curso que no esté bloqueada? (programada, reprogramada o en proceso) */
    public static function hayEnCurso(PDO $pdo, int $idCampana): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM mantenimientos_programados WHERE id_campana = ? AND estado IN ('PROGRAMADO','REPROGRAMADO','EN_PROCESO') LIMIT 1");
        $st->execute([$idCampana]);
        return (bool)$st->fetchColumn();
    }

    /** La siguiente sala bloqueada (la de menor orden). */
    public static function primeraBloqueada(PDO $pdo, int $idCampana)
    {
        $st = $pdo->prepare("SELECT * FROM mantenimientos_programados WHERE id_campana = ? AND estado = 'PENDIENTE' ORDER BY orden_campana, id_programado LIMIT 1");
        $st->execute([$idCampana]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    public static function contarActivas(PDO $pdo, int $idCampana): int
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM mantenimientos_programados WHERE id_campana = ? AND estado IN ('PENDIENTE','PROGRAMADO','REPROGRAMADO','EN_PROCESO')");
        $st->execute([$idCampana]);
        return (int)$st->fetchColumn();
    }

    public static function desbloquear(PDO $pdo, int $idProgramado): void
    {
        $pdo->prepare("UPDATE mantenimientos_programados SET estado = 'PROGRAMADO' WHERE id_programado = ? AND estado = 'PENDIENTE'")->execute([$idProgramado]);
    }

    public static function cerrarCampana(PDO $pdo, int $idCampana, string $estado): void
    {
        $pdo->prepare("UPDATE mantenimiento_campanas SET estado = ?, fecha_cierre = NOW() WHERE id_campana = ? AND estado = 'ACTIVA'")->execute([$estado, $idCampana]);
    }

    /** Cancela lo que no ha empezado. Lo que ya está EN_PROCESO se deja para que lo cierren con su resultado. */
    public static function cancelarNoIniciadas(PDO $pdo, int $idCampana, string $motivo): int
    {
        $st = $pdo->prepare("UPDATE mantenimientos_programados SET estado = 'CANCELADO', observaciones_cierre = ?, fecha_realizado = NOW()
                             WHERE id_campana = ? AND estado IN ('PENDIENTE','PROGRAMADO','REPROGRAMADO')");
        $st->execute([$motivo, $idCampana]);
        return $st->rowCount();
    }

    /** Salas que pueden entrar a una campaña (todas menos las oficinas de mantenimiento). */
    public static function salasElegibles(PDO $pdo): array
    {
        return $pdo->query("SELECT id_sala, nombre_sala, abreviatura, bloque FROM salas WHERE es_oficina_mantenimiento = 0 ORDER BY nombre_sala")->fetchAll(PDO::FETCH_ASSOC);
    }
}
