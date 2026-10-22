<?php
/** Aprobaciones y rechazos (técnico y admin) de tickets normales y de profesor, y confirmación de devolución. */

require_once __DIR__ . '/../../Support/paginacion.php';
require_once __DIR__ . '/../../Support/validacion.php'; // validarTexto()

final class TicketAprobacionService
{
    /** Condición SQL: el ticket es del técnico, o lo tomó una beca por su cuenta. */
    public const COND_RESPONSABLE_O_BECA =
        "(tecnico_id = ? OR tecnico_id IN (SELECT id FROM users WHERE role = 'beca_trabajo'))";

    /**
     * El técnico aprueba un ticket NORMAL ya Solucionado y SIN propuesta de
     * pieza (los que traen pieza se aprueban por inventario, que ejecuta el
     * movimiento real; los de cambio de posición, igual). Lo aprueba el
     * técnico responsable o cualquier técnico si lo tomó una beca por su
     * cuenta; el de otro técnico sigue protegido.
     *
     * @return array{ok:bool,id_dueno:?int,id_beca:?int}
     */
    public static function aprobarTicketNormal(PDO $pdo, int $idTicket, int $idTecnico): array
    {
        $stmt = $pdo->prepare(
            "UPDATE tickets SET estado = ?
             WHERE id = ? AND estado = ? AND " . self::COND_RESPONSABLE_O_BECA . "
               AND id_componente_saliente IS NULL AND id_componente_entrante IS NULL"
        );
        $stmt->execute([TicketWorkflow::APROBADO, $idTicket, TicketWorkflow::SOLUCIONADO, $idTecnico]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'id_dueno' => null, 'id_beca' => null];
        }
        AuditoriaService::registrar($pdo, $idTecnico, TablaTicket::NORMAL, $idTicket, 'EDITAR', 'estado=Aprobado (técnico)');
        $fila = TicketRepository::crudo($pdo, TablaTicket::NORMAL, $idTicket);
        return [
            'ok' => true,
            'id_dueno' => !empty($fila['user_id']) ? (int)$fila['user_id'] : null,
            'id_beca'  => !empty($fila['beca_id']) ? (int)$fila['beca_id'] : null,
        ];
    }

    /**
     * El técnico rechaza un ticket NORMAL en Solucionado: vuelve a En proceso
     * y se desfirma para que quien lo ejecutó lo corrija.
     *
     * @return array{ok:bool,id_beca:?int}
     */
    public static function rechazarTicketNormal(PDO $pdo, int $idTicket, int $idTecnico): array
    {
        $stmt = $pdo->prepare(
            "UPDATE tickets SET estado = ?, firmado = 0
             WHERE id = ? AND estado = ? AND " . self::COND_RESPONSABLE_O_BECA
        );
        $stmt->execute([TicketWorkflow::EN_PROCESO, $idTicket, TicketWorkflow::SOLUCIONADO, $idTecnico]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'id_beca' => null];
        }
        AuditoriaService::registrar($pdo, $idTecnico, TablaTicket::NORMAL, $idTicket, 'EDITAR', 'rechazado, reabierto para corrección');
        $fila = TicketRepository::crudo($pdo, TablaTicket::NORMAL, $idTicket);
        return ['ok' => true, 'id_beca' => !empty($fila['beca_id']) ? (int)$fila['beca_id'] : null];
    }

    /**
     * El técnico aprueba un ticket de PROFESOR en Solucionado. Por decisión
     * de negocio cualquier técnico puede revisar cualquier ticket de profesor
     * (lo ejecutó él o una beca), a diferencia de los tickets normales.
     *
     * Regla dura: un PRÉSTAMO (sala/equipo) no se cierra hasta confirmar la
     * devolución; en ese caso devuelve ok=false con 'motivo'.
     *
     * @return array{ok:bool,motivo:?string,id_profesor:?int,id_tecnico:?int,id_beca:?int}
     */
    public static function aprobarTicketProfesor(PDO $pdo, int $idTicket, int $idTecnico): array
    {
        $vacio = ['ok' => false, 'motivo' => null, 'id_profesor' => null, 'id_tecnico' => null, 'id_beca' => null];
        $t = TicketRepository::crudo($pdo, TablaTicket::PROFESOR, $idTicket);
        if (!$t) {
            return $vacio;
        }
        if (in_array($t['subtipo_requerimiento'] ?? '', ['PRESTAMO_SALA', 'PRESTAMO_EQUIPO'], true) && empty($t['devuelto'])) {
            $vacio['motivo'] = "Este ticket es un préstamo — no se puede cerrar hasta que confirmes que recibiste de vuelta lo prestado (botón 'Confirmar devolución').";
            return $vacio;
        }
        $stmt = $pdo->prepare("UPDATE tickets_profesor SET estado = ?, firmado = 1 WHERE id = ? AND estado = ?");
        $stmt->execute([TicketWorkflow::APROBADO, $idTicket, TicketWorkflow::SOLUCIONADO]);
        if ($stmt->rowCount() === 0) {
            return $vacio;
        }
        AuditoriaService::registrar($pdo, $idTecnico, TablaTicket::PROFESOR, $idTicket, 'EDITAR', 'estado=Aprobado (técnico)');
        return [
            'ok' => true, 'motivo' => null,
            'id_profesor' => !empty($t['profesor_id']) ? (int)$t['profesor_id'] : null,
            'id_tecnico'  => !empty($t['tecnico_id'])  ? (int)$t['tecnico_id']  : null,
            'id_beca'     => !empty($t['beca_id'])     ? (int)$t['beca_id']     : null,
        ];
    }

    /**
     * El técnico rechaza un ticket de PROFESOR en Solucionado (vuelve a En
     * proceso y se desfirma). Devuelve quién lo ejecutaba: la beca asignada
     * si la hay; si no, quien lo tomó.
     *
     * @return array{ok:bool,id_ejecutor:?int}
     */
    public static function rechazarTicketProfesor(PDO $pdo, int $idTicket, int $idTecnico): array
    {
        $stmt = $pdo->prepare("UPDATE tickets_profesor SET estado = ?, firmado = 0 WHERE id = ? AND estado = ?");
        $stmt->execute([TicketWorkflow::EN_PROCESO, $idTicket, TicketWorkflow::SOLUCIONADO]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'id_ejecutor' => null];
        }
        AuditoriaService::registrar($pdo, $idTecnico, TablaTicket::PROFESOR, $idTicket, 'EDITAR', 'rechazado, reabierto para corrección');
        $t = TicketRepository::crudo($pdo, TablaTicket::PROFESOR, $idTicket);
        $ej = !empty($t['beca_id']) ? $t['beca_id'] : ($t['tecnico_id'] ?? null);
        return ['ok' => true, 'id_ejecutor' => $ej ? (int)$ej : null];
    }

    /**
     * ¿Puede este técnico decidir (aprobar/rechazar/validar traslado) sobre
     * un ticket NORMAL con movimiento de inventario? Sí si es el técnico
     * responsable, o si el ticket lo tomó/ejecutó una beca o un admin (no hay
     * otro técnico dueño). NO si lo tiene otro técnico.
     *
     * Antes estas acciones no comprobaban nada: cualquier técnico podía
     * ejecutar el movimiento real de inventario de un ticket ajeno.
     */
    public static function tecnicoPuedeDecidir(PDO $pdo, int $idTicket, int $idTecnico): bool
    {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM tickets
             WHERE id = ? AND (tecnico_id = ? OR tecnico_id IN (SELECT id FROM users WHERE role IN ('beca_trabajo','admin')))"
        );
        $stmt->execute([$idTicket, $idTecnico]);
        return (bool)$stmt->fetchColumn();
    }

    /** @return array{ok:bool,bloqueo:?string,id_profesor:?int} bloqueo: 'danio' | 'prestamo' | null */
    public static function aprobarProfesorAdmin(PDO $pdo, int $idTicket, int $idAdmin): array
    {
        $t = TicketRepository::crudo($pdo, TablaTicket::PROFESOR, $idTicket);
        $vacio = ['ok' => false, 'bloqueo' => null, 'id_profesor' => null];
        if (!$t) {
            return $vacio;
        }
        if (($t['tipo_ticket'] ?? null) === 'danio') {
            return ['ok' => false, 'bloqueo' => 'danio', 'id_profesor' => null];
        }
        if (in_array($t['subtipo_requerimiento'] ?? '', ['PRESTAMO_SALA', 'PRESTAMO_EQUIPO'], true) && empty($t['devuelto'])) {
            return ['ok' => false, 'bloqueo' => 'prestamo', 'id_profesor' => null];
        }
        $stmt = $pdo->prepare("UPDATE tickets_profesor SET estado = ? WHERE id = ? AND estado = ?");
        $stmt->execute([TicketWorkflow::APROBADO, $idTicket, TicketWorkflow::SOLUCIONADO]);
        if ($stmt->rowCount() === 0) {
            return $vacio;
        }
        AuditoriaService::registrar($pdo, $idAdmin, TablaTicket::PROFESOR, $idTicket, 'EDITAR', 'estado=Aprobado');
        return ['ok' => true, 'bloqueo' => null, 'id_profesor' => !empty($t['profesor_id']) ? (int)$t['profesor_id'] : null];
    }

    /** @return array{ok:bool,bloqueo:?string,id_profesor:?int} */
    public static function rechazarProfesorAdmin(PDO $pdo, int $idTicket, int $idAdmin): array
    {
        $t = TicketRepository::crudo($pdo, TablaTicket::PROFESOR, $idTicket);
        $vacio = ['ok' => false, 'bloqueo' => null, 'id_profesor' => null];
        if (!$t) {
            return $vacio;
        }
        if (($t['tipo_ticket'] ?? null) === 'danio') {
            return ['ok' => false, 'bloqueo' => 'danio', 'id_profesor' => null];
        }
        $stmt = $pdo->prepare("UPDATE tickets_profesor SET estado = ?, firmado = 0 WHERE id = ? AND estado = ?");
        $stmt->execute([TicketWorkflow::RECHAZADO, $idTicket, TicketWorkflow::SOLUCIONADO]);
        if ($stmt->rowCount() === 0) {
            return $vacio;
        }
        AuditoriaService::registrar($pdo, $idAdmin, TablaTicket::PROFESOR, $idTicket, 'EDITAR', 'estado=Rechazado');
        return ['ok' => true, 'bloqueo' => null, 'id_profesor' => !empty($t['profesor_id']) ? (int)$t['profesor_id'] : null];
    }

    /**
     * El técnico responsable confirma que el profesor devolvió un préstamo
     * (sala/equipo). Misma exigencia de siempre: ticket suyo, sin firmar,
     * de subtipo préstamo y aún no devuelto.
     *
     * @return array{ok:bool,id_profesor:?int}
     */
    public static function confirmarDevolucion(PDO $pdo, int $idTicket, int $idTecnico): array
    {
        $stmt = $pdo->prepare(
            "UPDATE tickets_profesor SET devuelto = 1, fecha_devuelto = NOW()
             WHERE id = ? AND tecnico_id = ? AND firmado = 0
               AND subtipo_requerimiento IN ('PRESTAMO_SALA','PRESTAMO_EQUIPO') AND devuelto = 0"
        );
        $stmt->execute([$idTicket, $idTecnico]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'id_profesor' => null];
        }
        AuditoriaService::registrar($pdo, $idTecnico, TablaTicket::PROFESOR, $idTicket, 'EDITAR', 'devuelto=1');
        $t = TicketRepository::crudo($pdo, TablaTicket::PROFESOR, $idTicket);
        return ['ok' => true, 'id_profesor' => !empty($t['profesor_id']) ? (int)$t['profesor_id'] : null];
    }
}
