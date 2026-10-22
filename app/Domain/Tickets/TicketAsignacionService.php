<?php
/** Asignación de tickets: tomar, liberar, asignar/quitar beca ejecutora. */

require_once __DIR__ . '/../../Support/paginacion.php';
require_once __DIR__ . '/../../Support/validacion.php'; // validarTexto()

final class TicketAsignacionService
{
    /**
     * Un técnico (tabla `tickets`) o un becario actuando como técnico
     * (tabla `tickets_profesor`) toma un ticket que está "En espera".
     * Deja tecnico_id asignado y registra el historial de asignación.
     */
    public static function tomar(PDO $pdo, string $tabla, int $idTicket, int $idTecnico): bool
    {
        // Solo se puede tomar un ticket que NADIE tiene asignado. La
        // transición En espera -> En proceso ya impide pisar estados
        // finales; esta guarda impide además "robar" uno ya asignado.
        $actual = TicketRepository::crudo($pdo, $tabla, $idTicket);
        if (!$actual || !empty($actual['tecnico_id'])) {
            return false;
        }
        $ok = TicketWorkflow::transicionar(
            $pdo,
            $tabla,
            $idTicket,
            TicketWorkflow::EN_PROCESO,
            ['tecnico_id' => $idTecnico],
            $idTecnico
        );
        if ($ok) {
            TicketSeguimiento::registrarAsignacionTicket($pdo, $tabla, $idTicket, $idTecnico);
        }
        return $ok;
    }

    /**
     * Libera un ticket que el técnico/becario tenía tomado, sin
     * resolverlo. Solo funciona si quien libera es quien lo tiene
     * asignado (misma regla que ya aplican tickets.php y
     * tickets.php vía `AND tecnico_id = ?`).
     */
    public static function liberar(PDO $pdo, string $tabla, int $idTicket, int $idTecnico, ?string $motivo = null): bool
    {
        if (!TablaTicket::valida($tabla)) {
            throw new InvalidArgumentException("Tabla de ticket no soportada: $tabla");
        }
        // Igual que el UPDATE que había en tickets.php: quien lo
        // tiene asignado puede devolverlo a espera mientras el ticket no
        // esté finalizado (Aprobado/Cerrado/Descartado) ni firmado.
        $stmt = $pdo->prepare(
            "UPDATE `$tabla` SET tecnico_id = NULL, estado = ?
             WHERE id = ? AND tecnico_id = ? AND COALESCE(firmado, 0) = 0
               AND estado NOT IN (?, ?, ?)"
        );
        $stmt->execute([
            TicketWorkflow::EN_ESPERA, $idTicket, $idTecnico,
            TicketWorkflow::APROBADO, TicketWorkflow::CERRADO, TicketWorkflow::DESCARTADO,
        ]);
        $ok = $stmt->rowCount() > 0;
        if ($ok) {
            TicketSeguimiento::liberarAsignacionTicket($pdo, $tabla, $idTicket, $idTecnico, $motivo);
        }
        return $ok;
    }

    /**
     * El técnico responsable asigna una beca para que EJECUTE el ticket.
     * La beca lo resuelve (Solucionado) y el técnico lo aprueba/rechaza.
     *
     * - Si el ticket está libre, el técnico lo toma primero (queda como
     *   responsable) y luego se asigna la beca.
     * - Si ya tiene otro técnico responsable, no se puede asignar.
     * - No se asigna en estados finales ni Solucionado (pendiente de
     *   revisión del técnico: primero se aprueba o se rechaza).
     * - Si ya había otra beca, se cierra su asignación y entra la nueva.
     *
     * 'cambio' es true solo si la beca quedó asignada ahora (no si ya lo estaba): quien llama decide si notifica.
     * @return array{ok:bool,cambio:bool,mensaje:string,id_beca_anterior:?int}
     */
    public static function asignarBeca(PDO $pdo, string $tabla, int $idTicket, int $idTecnico, int $idBeca): array
    {
        if (!TablaTicket::valida($tabla)) {
            throw new InvalidArgumentException("Tabla de ticket no soportada: $tabla");
        }
        $fallo = static fn(string $m): array => ['ok' => false, 'cambio' => false, 'mensaje' => $m, 'id_beca_anterior' => null];

        $stmtB = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'beca_trabajo' AND estado_activo = 1 AND " . AreaBeca::condicionMantenimientoSQL($pdo));
        $stmtB->execute([$idBeca]);
        if (!$stmtB->fetchColumn()) {
            return $fallo('Esa persona no es una beca trabajo activa de la oficina de mantenimiento.');
        }

        $t = TicketRepository::crudo($pdo, $tabla, $idTicket);
        if (!$t) {
            return $fallo('El ticket no existe.');
        }
        $estado = (string)$t['estado'];
        if (!empty($t['firmado']) || in_array($estado, [TicketWorkflow::APROBADO, TicketWorkflow::CERRADO, TicketWorkflow::DESCARTADO], true)) {
            return $fallo('El ticket ya está cerrado o descartado.');
        }
        if ($estado === TicketWorkflow::SOLUCIONADO) {
            return $fallo('El ticket está en Solucionado: aprúebalo o recházalo antes de reasignarlo.');
        }
        if (!empty($t['tecnico_id']) && (int)$t['tecnico_id'] !== $idTecnico) {
            return $fallo('Otro técnico es el responsable de este ticket.');
        }

        try {
            return Database::transaccion(function (PDO $pdo) use ($t, $tabla, $idTicket, $idTecnico, $idBeca, $fallo) {
                if (empty($t['tecnico_id']) && !self::tomar($pdo, $tabla, $idTicket, $idTecnico)) {
                    throw new TransaccionRevertida($fallo('No se pudo tomar el ticket (puede que otro técnico lo haya tomado justo ahora).'));
                }
                $anterior = !empty($t['beca_id']) ? (int)$t['beca_id'] : null;
                if ($anterior === $idBeca) {
                    return ['ok' => true, 'cambio' => false, 'mensaje' => 'Esa beca ya estaba asignada.', 'id_beca_anterior' => null];
                }
                if ($anterior !== null) {
                    TicketSeguimiento::liberarAsignacionTicket($pdo, $tabla, $idTicket, $anterior, 'Reasignado por el técnico');
                }
                $pdo->prepare("UPDATE `$tabla` SET beca_id = ? WHERE id = ? AND COALESCE(firmado,0) = 0")
                    ->execute([$idBeca, $idTicket]);
                TicketSeguimiento::registrarAsignacionTicket($pdo, $tabla, $idTicket, $idBeca);
                AuditoriaService::registrar($pdo, $idTecnico, $tabla, $idTicket, 'EDITAR', "Beca asignada por el técnico: usuario #$idBeca" . ($anterior ? " (antes #$anterior)" : ''));
                return ['ok' => true, 'cambio' => true, 'mensaje' => 'Beca asignada.', 'id_beca_anterior' => $anterior];
            }, $pdo, 'asignar_beca');
        } catch (Throwable $e) {
            RegistroErrores::registrar('TicketAsignacionService::asignarBeca', $e);
            return $fallo('No se pudo asignar la beca. Intenta de nuevo.');
        }
    }

    /**
     * El técnico responsable quita la beca asignada. El ticket sigue con
     * el técnico y en el mismo estado (si la beca ya lo había marcado
     * Solucionado, el técnico decide aprobarlo o rechazarlo).
     *
     * @return array{ok:bool,id_beca:?int}
     */
    public static function quitarBeca(PDO $pdo, string $tabla, int $idTicket, int $idTecnico): array
    {
        if (!TablaTicket::valida($tabla)) {
            throw new InvalidArgumentException("Tabla de ticket no soportada: $tabla");
        }
        $t = TicketRepository::crudo($pdo, $tabla, $idTicket);
        if (!$t || empty($t['beca_id']) || (int)$t['tecnico_id'] !== $idTecnico || !empty($t['firmado'])
            || in_array((string)$t['estado'], [TicketWorkflow::APROBADO, TicketWorkflow::CERRADO, TicketWorkflow::DESCARTADO], true)) {
            return ['ok' => false, 'id_beca' => null];
        }
        $idBeca = (int)$t['beca_id'];
        try {
            return Database::transaccion(function (PDO $pdo) use ($tabla, $idTicket, $idTecnico, $idBeca) {
                $pdo->prepare("UPDATE `$tabla` SET beca_id = NULL WHERE id = ? AND tecnico_id = ? AND beca_id = ?")
                    ->execute([$idTicket, $idTecnico, $idBeca]);
                TicketSeguimiento::liberarAsignacionTicket($pdo, $tabla, $idTicket, $idBeca, 'Quitada por el técnico');
                AuditoriaService::registrar($pdo, $idTecnico, $tabla, $idTicket, 'EDITAR', "Beca quitada por el técnico: usuario #$idBeca");
                return ['ok' => true, 'id_beca' => $idBeca];
            }, $pdo, 'quitar_beca');
        } catch (Throwable $e) {
            RegistroErrores::registrar('TicketAsignacionService::quitarBeca', $e);
            return ['ok' => false, 'id_beca' => null];
        }
    }
}
