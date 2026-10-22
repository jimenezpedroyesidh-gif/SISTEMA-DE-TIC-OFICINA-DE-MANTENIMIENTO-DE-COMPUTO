<?php
/**
 * Workflow de estados: qué estados puede escribir cada rol y actualización de estado/solución por técnico y
 * beca.
 */

require_once __DIR__ . '/../../Support/paginacion.php';
require_once __DIR__ . '/../../Support/validacion.php'; // validarTexto()

final class TicketEstadoService
{
    /**
     * Estados que cada rol puede ESCRIBIR al "actualizar" un ticket desde su formulario (son exactamente las
     * opciones del <select> de cada pantalla). Aprobar, rechazar, descartar y cerrar tienen sus propias
     * acciones y NO se hacen por aquí.
     */
    public const ESTADOS_ACTUALIZABLES_TECNICO_TICKET   = [TicketWorkflow::EN_ESPERA, TicketWorkflow::EN_PROCESO, TicketWorkflow::SOLUCIONADO, TicketWorkflow::LISTO_SIN_VALIDAR];

    public const ESTADOS_ACTUALIZABLES_TECNICO_PROFESOR = [TicketWorkflow::EN_ESPERA, TicketWorkflow::EN_PROCESO, TicketWorkflow::SOLUCIONADO];

    public const ESTADOS_ACTUALIZABLES_BECA             = [TicketWorkflow::EN_ESPERA, TicketWorkflow::EN_PROCESO, TicketWorkflow::SOLUCIONADO];

    /**
     * El técnico responsable actualiza el estado de un ticket de PROFESOR
     * (solo estado/firmado). Regla de siempre: solo el técnico que lo tiene
     * asignado, mientras no esté firmado. 'Solucionado' deja firmado=1
     * (queda a la espera de aprobar o rechazar).
     */
    public static function actualizarEstadoProfesorTecnico(PDO $pdo, int $idTicket, int $idTecnico, string $estado, ?string $solucion = null): bool
    {
        if (!in_array($estado, self::ESTADOS_ACTUALIZABLES_TECNICO_PROFESOR, true)) {
            return false;
        }
        $t = TicketRepository::crudo($pdo, TablaTicket::PROFESOR, $idTicket);
        if (!$t || !empty($t['firmado']) || (int)$t['tecnico_id'] !== $idTecnico) {
            return false;
        }
        // Daño de sala / instalación de software: no pasa a Solucionado sin explicación + foto (TicketEvidencia).
        if ($estado === TicketWorkflow::SOLUCIONADO && TicketEvidencia::faltante($pdo, TablaTicket::PROFESOR, $t, $solucion) !== null) {
            return false;
        }
        $solucionFinal = $solucion !== null ? trim($solucion) : $t['solucion'];
        $pdo->prepare("UPDATE tickets_profesor SET estado = ?, firmado = ?, solucion = ?,
                              fecha_solucion = IF(? = 'Solucionado', NOW(), fecha_solucion) WHERE id = ?")
            ->execute([$estado, $estado === TicketWorkflow::SOLUCIONADO ? 1 : (int)$t['firmado'], $solucionFinal, $estado, $idTicket]);
        AuditoriaService::registrar($pdo, $idTecnico, TablaTicket::PROFESOR, $idTicket, 'EDITAR', "estado={$estado} (técnico, actualización)");
        return true;
    }

    /**
     * La beca EJECUTORA (la que lo tomó o a la que el técnico se lo asignó)
     * deja constancia de su avance. NUNCA aprueba ni cierra: solo puede
     * dejar En espera / En proceso / Solucionado. El técnico aprueba o
     * rechaza desde tickets.php.
     *
     * $origenRequerido: p. ej. 'QR_ANONIMO' para la pantalla de reportes QR
     * (solo tabla `tickets`). $solucion se guarda en ambas tablas (null = conservar la anterior).
     */
    public static function actualizarEstadoEjecutor(
        PDO $pdo, string $tabla, int $idTicket, int $idBeca, string $estado,
        ?string $solucion = null, ?string $origenRequerido = null
    ): bool {
        if (!TablaTicket::valida($tabla)) {
            throw new InvalidArgumentException("Tabla de ticket no soportada: $tabla");
        }
        if (!in_array($estado, self::ESTADOS_ACTUALIZABLES_BECA, true)) {
            return false;
        }
        $t = TicketRepository::crudo($pdo, $tabla, $idTicket);
        if (!$t || !empty($t['firmado']) || !TicketConsultas::becaEsEjecutora($t, $idBeca)) {
            return false;
        }
        if ($origenRequerido !== null && ($t['origen'] ?? null) !== $origenRequerido) {
            return false;
        }
        // Explicación + foto obligatorias para cerrar daños de sala, QR e instalación de software.
        if ($estado === TicketWorkflow::SOLUCIONADO && TicketEvidencia::faltante($pdo, $tabla, $t, $solucion) !== null) {
            return false;
        }
        $solucionFinal = $solucion !== null ? trim($solucion) : $t['solucion'];
        if ($tabla === TablaTicket::NORMAL) {
            $pdo->prepare("UPDATE tickets SET estado = ?, solucion = ? WHERE id = ?")
                ->execute([$estado, (string)$solucionFinal, $idTicket]);
        } else {
            $pdo->prepare("UPDATE tickets_profesor SET estado = ?, solucion = ?,
                                  fecha_solucion = IF(? = 'Solucionado', NOW(), fecha_solucion) WHERE id = ?")
                ->execute([$estado, $solucionFinal, $estado, $idTicket]);
        }
        AuditoriaService::registrar($pdo, $idBeca, $tabla, $idTicket, 'EDITAR', "estado={$estado} (beca ejecutora)");
        return true;
    }

    /**
     * El técnico responsable actualiza un ticket NORMAL (tabla `tickets`):
     * estado, solución, y —según el modo de resolución— liga el ticket a la
     * sala/equipo real y propone cambio de pieza o traslado. Es la
     * extracción literal de lo que hacía tickets.php (update_ticket),
     * más la lista de estados permitidos.
     *
     * $in: estado, modo_resolucion, marca_modelo, numero_inventario,
     *      solucion, id_sala_libre, id_equipo_afectado,
     *      tipo_resolucion_general, id_componente_saliente,
     *      id_componente_entrante, id_componente_saliente_traslado.
     *
     * No ejecuta inventario: solo guarda la PROPUESTA; el movimiento real
     * ocurre al aprobar (aprobar_inventario_ticket / aprobar_traslado_ticket).
     *
     * @return array{ok:bool,estado:?string,id_dueno:?int}
     */
    public static function actualizarTicketTecnico(PDO $pdo, int $idTicket, int $idTecnico, array $in): array
    {
        $fallo = ['ok' => false, 'estado' => null, 'id_dueno' => null];
        $estado = (string)($in['estado'] ?? '');
        if (!in_array($estado, self::ESTADOS_ACTUALIZABLES_TECNICO_TICKET, true)) {
            return $fallo;
        }
        $modoResolucion    = $in['modo_resolucion'] ?? 'libre';
        $marca_modelo      = $in['marca_modelo'] ?? null;
        $numero_inventario = $in['numero_inventario'] ?? null;
        $solucion          = $in['solucion'] ?? null;

        $stmt = $pdo->prepare("SELECT id, firmado, tecnico_id, id_equipo, id_sala, origen FROM tickets WHERE id = ?");
        $stmt->execute([$idTicket]);
        $t = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$t || $t['firmado'] || (int)$t['tecnico_id'] !== $idTecnico) {
            return $fallo;
        }

        // Ticket "libre" (formulario clásico de solicitante) ligado a una
        // sala real si el técnico así lo indicó.
        if ($modoResolucion === 'libre_con_sala' && empty($t['id_sala'])) {
            $idSalaLibre = !empty($in['id_sala_libre']) ? (int)$in['id_sala_libre'] : null;
            if ($idSalaLibre) {
                $stmtSala = $pdo->prepare("SELECT id_sala, nombre_sala FROM salas WHERE id_sala = ?");
                $stmtSala->execute([$idSalaLibre]);
                $sala = $stmtSala->fetch(PDO::FETCH_ASSOC);
                if ($sala) {
                    $pdo->prepare("UPDATE tickets SET id_sala = ?, nombre_dependencia = ? WHERE id = ?")
                        ->execute([$idSalaLibre, $sala['nombre_sala'], $idTicket]);
                    $t['id_sala'] = $idSalaLibre;
                    AuditoriaService::registrar($pdo, $idTecnico, TablaTicket::NORMAL, $idTicket, 'EDITAR',
                        "ligado a la sala #$idSalaLibre ({$sala['nombre_sala']}) al resolver ticket libre");
                }
            }
        }

        // Ticket de sala general: se liga al equipo afectado, revalidando en
        // servidor que ese equipo pertenece a la sala del ticket (nunca se
        // confía en el <select> del navegador).
        if (in_array($modoResolucion, ['sala_general', 'libre_con_sala'], true) && empty($t['id_equipo']) && !empty($t['id_sala'])) {
            $idEquipoAfectado = !empty($in['id_equipo_afectado']) ? (int)$in['id_equipo_afectado'] : null;
            if ($idEquipoAfectado) {
                $stmtEq = $pdo->prepare("SELECT id_equipo, codigo FROM equipos WHERE id_equipo = ? AND id_sala = ?");
                $stmtEq->execute([$idEquipoAfectado, $t['id_sala']]);
                $equipo = $stmtEq->fetch(PDO::FETCH_ASSOC);
                if ($equipo) {
                    $pdo->prepare("UPDATE tickets SET id_equipo = ?, equipo = ? WHERE id = ?")
                        ->execute([$idEquipoAfectado, $equipo['codigo'], $idTicket]);
                    $t['id_equipo'] = $idEquipoAfectado;
                    AuditoriaService::registrar($pdo, $idTecnico, TablaTicket::NORMAL, $idTicket, 'EDITAR',
                        "ligado al equipo #$idEquipoAfectado ({$equipo['codigo']}) al resolver ticket de sala general");
                }
            }
        }

        // "Traslado a bodega/oficina" desde sala general: reutiliza el flujo
        // de 2 pasos (Listo pero sin validar -> aprobar traslado -> reubicado
        // -> aprobar y cerrar).
        if (in_array($modoResolucion, ['sala_general', 'libre_con_sala'], true) && ($in['tipo_resolucion_general'] ?? '') === 'traslado_oficina') {
            $estado = TicketWorkflow::LISTO_SIN_VALIDAR;
        }

        // Marca/modelo/inventario como texto libre solo si NO hay equipo real.
        if ($modoResolucion !== 'libre') {
            $marca_modelo = null;
            $numero_inventario = null;
        }

        // Reporte por QR sin sesión: no pasa a Solucionado sin explicación + foto (TicketEvidencia).
        if ($estado === TicketWorkflow::SOLUCIONADO && TicketEvidencia::faltante($pdo, TablaTicket::NORMAL, $t, $solucion) !== null) {
            return $fallo;
        }

        $idDueno = null;
        if ($estado === TicketWorkflow::SOLUCIONADO) {
            // Propuesta de cambio de pieza: solo se guarda en el ticket.
            $idCompSaliente = !empty($in['id_componente_saliente']) ? (int)$in['id_componente_saliente'] : null;
            $idCompEntrante = !empty($in['id_componente_entrante']) ? (int)$in['id_componente_entrante'] : null;
            $pdo->prepare("UPDATE tickets
                SET estado = ?, marca_modelo = ?, numero_inventario = ?, solucion = ?, firmado = 1, fecha_terminacion = NOW(),
                    id_componente_saliente = ?, id_componente_entrante = ?
                WHERE id = ?")
                ->execute([$estado, $marca_modelo, $numero_inventario, $solucion, $idCompSaliente, $idCompEntrante, $idTicket]);
            $stmtDueno = $pdo->prepare("SELECT user_id FROM tickets WHERE id = ?");
            $stmtDueno->execute([$idTicket]);
            $idDueno = $stmtDueno->fetchColumn() ?: null;
        } elseif ($estado === TicketWorkflow::LISTO_SIN_VALIDAR) {
            // Además del estado, guarda qué componente físico se lleva a
            // bodega/oficina (el movimiento real se ejecuta al aprobar el traslado).
            $idCompTraslado = !empty($in['id_componente_saliente'])
                ? (int)$in['id_componente_saliente']
                : (!empty($in['id_componente_saliente_traslado']) ? (int)$in['id_componente_saliente_traslado'] : null);
            $pdo->prepare("UPDATE tickets SET estado = ?, solucion = ?, id_componente_saliente = ? WHERE id = ?")
                ->execute([$estado, $solucion, $idCompTraslado, $idTicket]);
        } else {
            $pdo->prepare("UPDATE tickets SET estado = ?, marca_modelo = ?, numero_inventario = ?, solucion = ? WHERE id = ?")
                ->execute([$estado, $marca_modelo, $numero_inventario, $solucion, $idTicket]);
        }
        AuditoriaService::registrar($pdo, $idTecnico, TablaTicket::NORMAL, $idTicket, 'EDITAR', "estado={$estado} (técnico, actualización)");

        return ['ok' => true, 'estado' => $estado, 'id_dueno' => $idDueno ? (int)$idDueno : null];
    }
}
