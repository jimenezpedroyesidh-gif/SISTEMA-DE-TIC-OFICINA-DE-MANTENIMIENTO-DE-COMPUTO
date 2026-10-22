<?php
/** Seguimiento de un ticket: comentarios, descarte, equipos afectados y sugerencia de mantenimiento. */

require_once __DIR__ . '/../../Support/paginacion.php';
require_once __DIR__ . '/../../Support/validacion.php'; // validarTexto()

final class TicketSeguimientoService
{

    /**
     * Descarta un reporte inválido, duplicado o que ya no aplica, sin
     * forzarlo como 'Cerrado' (que implica que sí se resolvió algo).
     *
     * Conserva EXACTAMENTE la regla que tenía tickets.php: se puede
     * descartar desde cualquier estado salvo Cerrado/Descartado, lo haya
     * tomado o no el técnico (por eso no pasa por TicketWorkflow, cuya
     * tabla solo permite Solucionado -> Descartado).
     *
     *  - tickets:           guarda el motivo en `solucion` y `fecha_terminacion`.
     *  - tickets_profesor:  solo tipo 'danio'; agrega el motivo al final de
     *                       `descripcion_problema` (el subtipo requerimiento
     *                       usa otras columnas y no se descarta aquí).
     *
     * @return bool true si se descartó
     */
    public static function descartar(PDO $pdo, string $tabla, int $idTicket, int $idUsuario, string $motivo = ''): bool
    {
        if (!TablaTicket::valida($tabla)) {
            throw new InvalidArgumentException("Tabla de ticket no soportada: $tabla");
        }
        $motivo = trim($motivo);
        if ($tabla === TablaTicket::NORMAL) {
            $stmt = $pdo->prepare(
                "UPDATE tickets SET estado = ?, solucion = ?, fecha_terminacion = NOW()
                 WHERE id = ? AND estado NOT IN (?, ?)"
            );
            $stmt->execute([
                TicketWorkflow::DESCARTADO,
                $motivo !== '' ? $motivo : 'Descartado sin motivo especificado',
                $idTicket, TicketWorkflow::CERRADO, TicketWorkflow::DESCARTADO,
            ]);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE tickets_profesor
                 SET estado = ?, descripcion_problema = CONCAT(COALESCE(descripcion_problema, ''), ?)
                 WHERE id = ? AND tipo_ticket = 'danio' AND estado NOT IN (?, ?)"
            );
            $stmt->execute([
                TicketWorkflow::DESCARTADO,
                ' [Descartado: ' . ($motivo !== '' ? $motivo : 'sin motivo especificado') . ']',
                $idTicket, TicketWorkflow::CERRADO, TicketWorkflow::DESCARTADO,
            ]);
        }
        $ok = $stmt->rowCount() > 0;
        if ($ok) {
            AuditoriaService::registrar($pdo, $idUsuario, $tabla, $idTicket, 'EDITAR', 'estado=Descartado: ' . $motivo);
        }
        return $ok;
    }

    /**
     * Quien tomó un ticket de QR registra qué equipo(s) están realmente
     * afectados. Solo tickets de QR con sala, solo si aún no hay equipos
     * (no se pisa lo que ya indicó el profesor) y los ids se revalidan
     * contra la sala. $comoBeca=true: basta ser la beca ejecutora; si no,
     * debe ser el técnico responsable (tecnico_id).
     */
    public static function agregarEquiposAfectados(PDO $pdo, string $tabla, int $idTicket, int $idUsuario, $idsPost, bool $comoBeca = false): bool
    {
        if (!TablaTicket::valida($tabla) || $idTicket <= 0) {
            return false;
        }
        $origenQr = $tabla === TablaTicket::NORMAL ? 'QR_ANONIMO' : 'QR_PROFESOR';
        $stmt = $pdo->prepare("SELECT id_sala, tecnico_id, beca_id FROM `$tabla` WHERE id = ? AND origen = ?");
        $stmt->execute([$idTicket, $origenQr]);
        $t = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$t || empty($t['id_sala'])) {
            return false;
        }
        $autorizado = $comoBeca ? TicketConsultas::becaEsEjecutora($t, $idUsuario) : ((int)$t['tecnico_id'] === $idUsuario);
        if (!$autorizado) {
            return false;
        }
        $stmtYa = $pdo->prepare("SELECT COUNT(*) FROM ticket_activos WHERE tabla_ticket = ? AND id_ticket = ?");
        $stmtYa->execute([$tabla, $idTicket]);
        if ((int)$stmtYa->fetchColumn() > 0) {
            return false;
        }
        $ids = TicketActivos::equiposValidosDeSala($pdo, (int)$t['id_sala'], is_array($idsPost) ? $idsPost : []);
        if (!$ids) {
            return false;
        }
        TicketActivos::agregarActivosATicket($pdo, $tabla, $idTicket, $ids);
        AuditoriaService::registrar($pdo, $idUsuario, $tabla, $idTicket, 'EDITAR',
            'agregó equipo(s) afectado(s) al tomar un ticket de QR: ' . implode(',', $ids));
        return true;
    }

    /** La beca (tomó o ejecuta el ticket) sugiere elevar el ticket de profesor a mantenimiento general. */
    public static function sugerirMantenimientoProfesor(PDO $pdo, int $idTicket, int $idBeca): bool
    {
        $stmt = $pdo->prepare(
            "UPDATE tickets_profesor SET sugerido_mantenimiento_general = 1 WHERE id = ? AND (tecnico_id = ? OR beca_id = ?)"
        );
        $stmt->execute([$idTicket, $idBeca, $idBeca]);
        return $stmt->rowCount() > 0;
    }
}
