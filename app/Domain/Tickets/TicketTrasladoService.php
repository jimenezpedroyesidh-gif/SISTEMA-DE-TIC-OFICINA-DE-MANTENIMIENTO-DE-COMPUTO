<?php
/** Cambios de pieza, cambios de posición, traslados y reubicaciones. */

require_once __DIR__ . '/../../Support/paginacion.php';
require_once __DIR__ . '/../../Support/validacion.php'; // validarTexto()

final class TicketTrasladoService
{
    /**
     * Aprueba un ticket en Solucionado que trae propuesta de pieza: ejecuta
     * el cambio real (InventarioService) y lo deja Aprobado.
     *
     * @return array{ok:bool,ticket:?array,motivo:?string}  'ticket' = fila al aprobar (para avisar al profesor)
     */
    public static function aprobarCambioPieza(PDO $pdo, int $idTicket, int $idTecnico): array
    {
        try {
            return Database::transaccion(function (PDO $pdo) use ($idTicket, $idTecnico) {
                $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ? AND estado = ? FOR UPDATE");
                $stmt->execute([$idTicket, TicketWorkflow::SOLUCIONADO]);
                $t = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$t || (empty($t['id_componente_saliente']) && empty($t['id_componente_entrante']))) {
                    throw new TransaccionRevertida(['ok' => false, 'ticket' => null, 'motivo' => null]);
                }
                InventarioService::aprobarCambioPieza($pdo, $t, $idTecnico);
                $pdo->prepare("UPDATE tickets SET estado = ? WHERE id = ?")->execute([TicketWorkflow::APROBADO, $idTicket]);
                AuditoriaService::registrar($pdo, $idTecnico, TablaTicket::NORMAL, $idTicket, 'EDITAR', 'estado=Aprobado (cambio de inventario ejecutado)');
                return ['ok' => true, 'ticket' => $t, 'motivo' => null];
            }, $pdo, 'aprobar_pieza');
        } catch (Throwable $e) {
            RegistroErrores::registrar("aprobarCambioPieza ticket $idTicket", $e);
            return ['ok' => false, 'ticket' => null, 'motivo' => 'No se pudo ejecutar el cambio de inventario; el ticket sigue en Solucionado.'];
        }
    }

    /** Rechaza la propuesta de pieza: vuelve a En proceso, sin firma y sin piezas propuestas. */
    public static function rechazarCambioPieza(PDO $pdo, int $idTicket, int $idTecnico): bool
    {
        $stmt = $pdo->prepare(
            "UPDATE tickets SET estado = ?, firmado = 0, id_componente_saliente = NULL, id_componente_entrante = NULL
             WHERE id = ? AND estado = ?"
        );
        $stmt->execute([TicketWorkflow::EN_PROCESO, $idTicket, TicketWorkflow::SOLUCIONADO]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        AuditoriaService::registrar($pdo, $idTecnico, TablaTicket::NORMAL, $idTicket, 'EDITAR', 'propuesta de inventario rechazada, vuelve a En proceso');
        return true;
    }

    /**
     * Ticket de "cambio de posición" (id_sala_destino): aprobarlo MUEVE el
     * equipo de sala. Misma condición de siempre sobre quién puede (técnico
     * responsable o ticket tomado por una beca).
     *
     * @return array{aplica:bool,ok:bool,id_dueno:?int}
     *   aplica=false → no es de cambio de posición (usar aprobarTicketNormal);
     *   aplica=true, ok=false → el equipo ya no está en la sala de origen u otro fallo.
     */
    public static function aprobarCambioPosicion(PDO $pdo, int $idTicket, int $idTecnico): array
    {
        try {
            return Database::transaccion(function (PDO $pdo) use ($idTicket, $idTecnico) {
                $stmt = $pdo->prepare(
                    "SELECT * FROM tickets WHERE id = ? AND estado = ? AND " . TicketAprobacionService::COND_RESPONSABLE_O_BECA . " FOR UPDATE"
                );
                $stmt->execute([$idTicket, TicketWorkflow::SOLUCIONADO, $idTecnico]);
                $t = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$t || empty($t['id_sala_destino'])) {
                    throw new TransaccionRevertida(['aplica' => false, 'ok' => false, 'id_dueno' => null]);
                }
                if (!InventarioService::cambiarPosicion($pdo, $t, $idTecnico)) {
                    throw new TransaccionRevertida(['aplica' => true, 'ok' => false, 'id_dueno' => null]);
                }
                $pdo->prepare("UPDATE tickets SET estado = ? WHERE id = ? AND estado = ?")
                    ->execute([TicketWorkflow::APROBADO, $idTicket, TicketWorkflow::SOLUCIONADO]);
                AuditoriaService::registrar($pdo, $idTecnico, TablaTicket::NORMAL, $idTicket, 'EDITAR', 'estado=Aprobado (técnico, con cambio de posición ejecutado)');
                return ['aplica' => true, 'ok' => true, 'id_dueno' => !empty($t['user_id']) ? (int)$t['user_id'] : null];
            }, $pdo, 'aprobar_posicion');
        } catch (Throwable $e) {
            RegistroErrores::registrar("aprobarCambioPosicion ticket $idTicket", $e);
            return ['aplica' => true, 'ok' => false, 'id_dueno' => null];
        }
    }

    /**
     * Traslado a bodega/oficina, paso 1: el técnico confirma que el equipo salió. Si el ticket indica qué
     * componente se lleva, aquí se ejecuta el movimiento real. Si el movimiento falla NO se aprueba el
     * traslado.
     *
     * @return array{ok:bool,motivo:?string}  ok=false sin motivo = nada que aprobar (otro estado / ya aprobado)
     */
    public static function aprobarTraslado(PDO $pdo, int $idTicket, int $idTecnico): array
    {
        try {
            return Database::transaccion(function (PDO $pdo) use ($idTicket, $idTecnico) {
                $stmt = $pdo->prepare(
                    "UPDATE tickets SET traslado_aprobado = 1 WHERE id = ? AND estado = ? AND traslado_aprobado = 0"
                );
                $stmt->execute([$idTicket, TicketWorkflow::LISTO_SIN_VALIDAR]);
                if ($stmt->rowCount() === 0) {
                    throw new TransaccionRevertida(['ok' => false, 'motivo' => null]);
                }
                AuditoriaService::registrar($pdo, $idTecnico, TablaTicket::NORMAL, $idTicket, 'EDITAR', 'traslado_aprobado=1');
                $stmtT = $pdo->prepare("SELECT id, id_equipo, id_componente_saliente, id_componente_entrante, descripcion, solucion, user_id FROM tickets WHERE id = ?");
                $stmtT->execute([$idTicket]);
                $t = $stmtT->fetch(PDO::FETCH_ASSOC);
                if ($t && !empty($t['id_componente_saliente'])) {
                    InventarioService::aprobarCambioPieza($pdo, $t, $idTecnico);
                }
                return ['ok' => true, 'motivo' => null];
            }, $pdo, 'aprobar_traslado');
        } catch (Throwable $e) {
            RegistroErrores::registrar("aprobarTraslado ticket $idTicket", $e);
            return ['ok' => false, 'motivo' => 'No se pudo mover el inventario del componente, así que el traslado NO quedó aprobado. Revisa el componente y vuelve a intentarlo.'];
        }
    }

    /** Traslado, paso 2: se marca que el puesto ya quedó rearmado. */
    public static function marcarReubicado(PDO $pdo, int $idTicket, int $idTecnico): bool
    {
        $stmt = $pdo->prepare(
            "UPDATE tickets SET reubicado = 1 WHERE id = ? AND estado = ? AND traslado_aprobado = 1 AND reubicado = 0"
        );
        $stmt->execute([$idTicket, TicketWorkflow::LISTO_SIN_VALIDAR]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        AuditoriaService::registrar($pdo, $idTecnico, TablaTicket::NORMAL, $idTicket, 'EDITAR', 'reubicado=1');
        return true;
    }

    /**
     * Traslado, paso 3: aprobación final; cierra el ticket. Exige traslado
     * aprobado y reubicado.
     *
     * @return array{ok:bool,id_dueno:?int}
     */
    public static function aprobarReubicacionFinal(PDO $pdo, int $idTicket, int $idTecnico): array
    {
        $stmt = $pdo->prepare(
            "UPDATE tickets SET estado = ?, firmado = 1, fecha_terminacion = NOW()
             WHERE id = ? AND estado = ? AND traslado_aprobado = 1 AND reubicado = 1"
        );
        $stmt->execute([TicketWorkflow::CERRADO, $idTicket, TicketWorkflow::LISTO_SIN_VALIDAR]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'id_dueno' => null];
        }
        AuditoriaService::registrar($pdo, $idTecnico, TablaTicket::NORMAL, $idTicket, 'EDITAR', 'estado=Cerrado (reubicación final aprobada)');
        $fila = TicketRepository::crudo($pdo, TablaTicket::NORMAL, $idTicket);
        return ['ok' => true, 'id_dueno' => !empty($fila['user_id']) ? (int)$fila['user_id'] : null];
    }
}
