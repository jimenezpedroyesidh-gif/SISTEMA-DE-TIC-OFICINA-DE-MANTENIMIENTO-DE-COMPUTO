<?php
/** Exclusión de checklist por traslado y solicitudes de baja de componentes. */
final class ComponenteBajas
{
    // ===================== exclusión de checklist por traslado =====================

    /**
     * Cuando un equipo sale de su sala mientras hay un mantenimiento general
     * activo (PROGRAMADO/EN_PROCESO) — porque se traslada completo a otra
     * sala (ej. la oficina de mantenimiento), o porque le sacan la
     * torre/todo_en_uno y queda no operativo — su fila en la checklist
     * compartida (mantenimiento_equipos) ya no aplica: el equipo no está en
     * la sala que se está revisando. Se marca EXCLUIDO_TRASLADO en vez de
     * borrarla (para no perder el rastro), y deja de contar en la validación
     * de "checklist completa" que exige el cierre del mantenimiento.
     *
     * Debe llamarse SIEMPRE que algo cambie equipos.id_sala o equipos.operativo
     * a 0 fuera del propio flujo de la checklist (InventarioService: cambio de pieza
     * y cambio de posición). Desde la checklist no hace falta: ahí el equipo sigue en su sala.
     */
    public static function excluirEquipoDeMantenimientosActivos(PDO $pdo, int $idEquipo, string $motivo): void {
        $stmt = $pdo->prepare(
            "UPDATE mantenimiento_equipos me
             JOIN mantenimientos_programados mp ON mp.id_programado = me.id_programado
             SET me.estado = 'EXCLUIDO_TRASLADO',
                 me.observaciones = TRIM(CONCAT(COALESCE(me.observaciones, ''),
                     CASE WHEN COALESCE(me.observaciones, '') = '' THEN '' ELSE ' | ' END, ?)),
                 me.fecha_actualizacion = NOW()
             WHERE me.id_equipo = ?
               AND me.estado IN ('PENDIENTE', 'TOMADO', 'INCOMPLETO')
               AND mp.estado IN ('PENDIENTE', 'PROGRAMADO', 'REPROGRAMADO', 'EN_PROCESO')"
        );
        $stmt->execute([$motivo, $idEquipo]);
    }

    // ===================== solicitudes_baja =====================

    /**
     * Paso 1 (cualquier rol con acceso a inventario, típicamente
     * beca_trabajo): pide dar de baja un componente que ya está en revisión
     * o defectuoso, sin poder ejecutarlo él mismo.
     */
    public static function solicitarBajaComponente(
        PDO $pdo, int $idComponente, string $motivo, int $idSolicito,
        ?string $tablaTicketRelacionado = null, ?int $idTicketRelacionado = null
    ): int {
        $stmt = $pdo->prepare(
            "INSERT INTO solicitudes_baja (id_componente, motivo, id_solicito, tabla_ticket_relacionado, id_ticket_relacionado)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$idComponente, $motivo, $idSolicito, $tablaTicketRelacionado, $idTicketRelacionado]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Paso 2 (solo técnico/admin, misma regla de siempre): aprueba o
     * rechaza. Al aprobar, ejecuta de verdad `componentes.estado='DE_BAJA'`
     * — antes esa columna solo se tocaba directo desde
     * revisar_componentes.php sin este paso intermedio de solicitud.
     */
    public static function resolverSolicitudBaja(PDO $pdo, int $idSolicitud, bool $aprobar, int $idAprobo, ?string $observacion = null): bool {
        $stmt = $pdo->prepare("SELECT * FROM solicitudes_baja WHERE id=? AND estado='PENDIENTE'");
        $stmt->execute([$idSolicitud]);
        $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$solicitud) {
            return false;
        }

        try {
            return Database::transaccion(function (PDO $pdo) use ($aprobar, $idAprobo, $observacion, $idSolicitud, $solicitud) {
                $nuevoEstado = $aprobar ? 'APROBADA' : 'RECHAZADA';
                $stmt = $pdo->prepare(
                    "UPDATE solicitudes_baja SET estado=?, id_aprobo=?, fecha_aprobado=NOW(), observacion_aprobacion=? WHERE id=?"
                );
                $stmt->execute([$nuevoEstado, $idAprobo, $observacion, $idSolicitud]);

                if ($aprobar) {
                    $stmt = $pdo->prepare("UPDATE componentes SET estado='DE_BAJA' WHERE id_componente=?");
                    $stmt->execute([$solicitud['id_componente']]);
                    InventarioService::registrarHistorial(
                        $pdo, (int)$solicitud['id_componente'], 'BAJA', $idAprobo,
                        null, null, null, 'DE_BAJA',
                        $solicitud['id_ticket_relacionado'] !== null ? (int)$solicitud['id_ticket_relacionado'] : null,
                        'Baja aprobada — solicitud #' . $idSolicitud,
                        $observacion ?? ''
                    );
                }

                return true;
            }, $pdo);
        } catch (Throwable $e) {
            RegistroErrores::registrar("Error resolviendo solicitud de baja #$idSolicitud", $e);
            throw $e;
        }
    }

    /** Solicitudes pendientes, para la pantalla de técnico/admin. */
    public static function solicitudesBajaPendientes(PDO $pdo): array {
        return $pdo->query(
            "SELECT s.*, c.codigo_inventario, c.tipo, c.marca, c.modelo, u.nombre_completo AS solicito_nombre
             FROM solicitudes_baja s
             JOIN componentes c ON c.id_componente = s.id_componente
             JOIN users u ON u.id = s.id_solicito
             WHERE s.estado = 'PENDIENTE'
             ORDER BY s.fecha_solicitado ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
