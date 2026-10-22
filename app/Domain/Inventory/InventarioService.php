<?php
/**
 * Ejecución REAL de los movimientos de inventario que disparan los tickets. Si ya hay una transacción abierta
 * (mover inventario + cambiar estado del ticket en una sola operación), se une a ella; si no, abre la suya.
 */

require_once __DIR__ . '/../../Support/validacion.php';  // correoValido(), validarTexto()
require_once __DIR__ . '/../../Support/paginacion.php';

final class InventarioService
{
    /**
     * Inserta una fila en historial_componente. Nunca debe faltar cuando
     * cambia el estado o la asignación de un componente.
     */
    public static function registrarHistorial(
        PDO $pdo,
        int $idComponente,
        string $tipoEvento,
        int $idUsuarioResponsable,
        ?int $idEquipoAnterior = null,
        ?int $idEquipoNuevo = null,
        ?string $estadoAnterior = null,
        ?string $estadoNuevo = null,
        ?int $idTicket = null,
        string $observacion = '',
        ?string $motivo = null
    ): void {
        // $motivo: texto escrito por la persona responsable explicando POR QUÉ saca o regresa la pieza (ej.
        // "no enciende, se lleva a revisar" / "ya reparada, se reinstala en SA401-PC03"). Distinto de
        // $observacion, que puede seguir llevando una nota automática o adicional.
        $stmt = $pdo->prepare(
            "INSERT INTO historial_componente
                (id_componente, tipo_evento, id_equipo_anterior, id_equipo_nuevo,
                 estado_anterior, estado_nuevo, id_ticket, motivo, id_usuario_responsable, observacion)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $idComponente, $tipoEvento, $idEquipoAnterior, $idEquipoNuevo,
            $estadoAnterior, $estadoNuevo, $idTicket, $motivo, $idUsuarioResponsable, $observacion
        ]);

        // GLPI: toda alta y todo cambio de equipo/ubicación de un componente pasa por aquí, así que es el único
        // punto donde se encola el envío (alta, o movimiento hecho/aprobado por técnico o admin).
        if ($tipoEvento === 'REGISTRO') {
            GlpiSyncService::encolarCreacion($pdo, $idComponente, $idUsuarioResponsable);
        } elseif ($idEquipoAnterior !== $idEquipoNuevo) {
            GlpiSyncService::encolarCambioDeComponente($pdo, $idComponente, $idEquipoNuevo, $idUsuarioResponsable);
        }
    }


    /** Orígenes posibles de una propuesta de cambio de pieza (ver aplicarCambioPieza). */
    public const ORIGEN_TICKET        = 'ticket';
    public const ORIGEN_MANTENIMIENTO = 'mantenimiento';

    /** Roles que pueden APROBAR (y con eso ejecutar) un cambio de pieza. La beca solo propone. */
    public const ROLES_APRUEBAN_PIEZA = [Rol::TECNICO];

    /**
     * Cambio de pieza aprobado desde un TICKET (Solucionado → Aprobado, o traslado a bodega).
     * Envoltorio de aplicarCambioPieza(): misma API de siempre para TicketTrasladoService.
     *
     * Devuelve true si ejecutó el cambio, false si el ticket no tenía componentes vinculados.
     */
    public static function aprobarCambioPieza(PDO $pdo, array $ticket, int $idTecnico): bool {
        // Motivo que queda en el historial de la pieza: la «Solución» que escribió el técnico
        // (o, si no hay, la descripción original del daño). Así, quien busque la pieza por su
        // QR/serial en ficha_componente.php ve por qué está «en revisión».
        $motivo = trim((string)($ticket['solucion'] ?? '')) !== ''
            ? trim($ticket['solucion'])
            : trim((string)($ticket['descripcion'] ?? ''));
        return self::aplicarCambioPieza(
            $pdo,
            $ticket['id_componente_saliente'] ?? null, $ticket['id_componente_entrante'] ?? null, $ticket['id_equipo'] ?? null,
            $idTecnico, self::ORIGEN_TICKET, (int)$ticket['id'],
            $motivo !== '' ? $motivo : null,
            !empty($ticket['user_id']) ? (int)$ticket['user_id'] : null
        );
    }

    /**
     * Cambio de pieza aprobado desde la CHECKLIST de un mantenimiento programado.
     * Envoltorio de aplicarCambioPieza(). $mantEquipo = fila de mantenimiento_equipos.
     */
    public static function aprobarCambioPiezaMantenimiento(PDO $pdo, array $mantEquipo, int $idTecnico, int $idProgramado): bool {
        $motivo = trim((string)($mantEquipo['observaciones'] ?? ''));
        $solicito = $mantEquipo['id_usuario_asignado'] ?? ($mantEquipo['id_usuario_ultimo'] ?? null);
        return self::aplicarCambioPieza(
            $pdo,
            $mantEquipo['id_componente_saliente'] ?? null, $mantEquipo['id_componente_entrante'] ?? null, $mantEquipo['id_equipo'] ?? null,
            $idTecnico, self::ORIGEN_MANTENIMIENTO, $idProgramado,
            $motivo !== '' ? $motivo : null,
            $solicito !== null ? (int)$solicito : null
        );
    }

    /**
     * ÚNICA implementación del cambio de pieza (pieza que sale / pieza que entra en un equipo),
     * venga de un ticket o de la checklist de mantenimiento. Todo en una transacción: si algo
     * falla, no queda ningún cambio a medias.
     *
     * Reglas (iguales para todos los orígenes):
     *  1. Solo lo ejecuta quien puede aprobar (ROLES_APRUEBAN_PIEZA: técnico). La beca propone.
     *  2. Solo se aplica una propuesta PENDIENTE: la pieza que sale debe seguir instalada en el
     *     equipo y la que entra no debe estar ya instalada en él. Así no se aplica dos veces.
     *  3. La pieza que entra queda ACTIVA en el equipo; la que sale, EN_REVISION y suelta (bodega).
     *  4. El equipo es operativo mientras tenga su pieza principal (torre / todo en uno): si sale
     *     una y entra otra en el mismo cambio, sigue operativo.
     *  5. Cada pieza deja historial (con motivo) y un movimiento con quién solicitó y quién aprobó.
     *
     * @param string $origen   ORIGEN_TICKET | ORIGEN_MANTENIMIENTO
     * @param int    $idOrigen id del ticket o del mantenimiento programado
     * @return bool  false si no hay piezas propuestas (nada que hacer)
     * @throws CambioPiezaNoAplicable si quien aprueba no puede o la propuesta ya no está pendiente
     */
    public static function aplicarCambioPieza(
        PDO $pdo, $idSaliente, $idEntrante, $idEquipo, int $idAprobador,
        string $origen, int $idOrigen, ?string $motivo = null, ?int $idSolicito = null
    ): bool {
        $idSaliente = !empty($idSaliente) ? (int)$idSaliente : null;
        $idEntrante = !empty($idEntrante) ? (int)$idEntrante : null;
        $idEquipo   = !empty($idEquipo) ? (int)$idEquipo : null;
        if ($idSaliente === null && $idEntrante === null) {
            return false; // sin propuesta de cambio de pieza, nada que ejecutar
        }
        if (!in_array($origen, [self::ORIGEN_TICKET, self::ORIGEN_MANTENIMIENTO], true)) {
            throw new InvalidArgumentException("Origen de cambio de pieza no soportado: $origen");
        }

        // Regla 1: solo un técnico aprueba (se comprueba aquí, no solo en la pantalla).
        $stRol = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stRol->execute([$idAprobador]);
        if (!in_array($stRol->fetchColumn(), self::ROLES_APRUEBAN_PIEZA, true)) {
            throw new CambioPiezaNoAplicable('Solo un técnico puede aprobar un cambio de pieza.');
        }

        // Textos y enlaces según el origen (lo único que cambia entre ticket y mantenimiento).
        $esTicket = $origen === self::ORIGEN_TICKET;
        $ref = $esTicket ? "ticket #$idOrigen" : "checklist de mantenimiento #$idOrigen";
        $idTicketHistorial = $esTicket ? $idOrigen : null;            // historial_componente.id_ticket tiene FK a tickets
        $tablaMov = $esTicket ? TablaTicket::NORMAL : null;                      // movimientos_activos solo enlaza tickets
        $idMov    = $esTicket ? $idOrigen : null;
        $notaNoOperativo = $esTicket
            ? "Quedó no operativo por traslado de pieza (ticket #$idOrigen)"
            : "Quedó no operativo por cambio de pieza (mantenimiento #$idOrigen)";

        try {
            return Database::transaccion(function (PDO $pdo) use (
                $idSaliente, $idEntrante, $idEquipo, $idAprobador, $ref, $idTicketHistorial, $tablaMov, $idMov,
                $notaNoOperativo, $motivo, $idSolicito
            ) {
                // Bloquear las piezas y validar que la propuesta siga pendiente (regla 2).
                $bloquear = $pdo->prepare("SELECT * FROM componentes WHERE id_componente=? FOR UPDATE");
                $entrante = $saliente = null;
                if ($idEntrante !== null) {
                    $bloquear->execute([$idEntrante]);
                    $entrante = $bloquear->fetch(PDO::FETCH_ASSOC);
                    if (!$entrante) { throw new RuntimeException("Componente entrante no existe."); }
                    if ($idEquipo !== null && (int)$entrante['id_equipo_actual'] === $idEquipo) {
                        throw new CambioPiezaNoAplicable('La pieza que entra ya está instalada en este equipo: el cambio ya fue aplicado.');
                    }
                    if ($entrante['estado'] === 'DE_BAJA') {
                        throw new CambioPiezaNoAplicable('La pieza que entra está dada de baja.');
                    }
                }
                if ($idSaliente !== null) {
                    $bloquear->execute([$idSaliente]);
                    $saliente = $bloquear->fetch(PDO::FETCH_ASSOC);
                    if (!$saliente) { throw new RuntimeException("Componente saliente no existe."); }
                    $dondeEsta = $saliente['id_equipo_actual'] !== null ? (int)$saliente['id_equipo_actual'] : null;
                    if ($dondeEsta === null || ($idEquipo !== null && $dondeEsta !== $idEquipo)) {
                        throw new CambioPiezaNoAplicable('La pieza que sale ya no está instalada en este equipo: el cambio ya fue aplicado o la pieza se movió.');
                    }
                }

                // Sala del equipo: origen/destino de los movimientos (null = bodega / oficina).
                $idSalaEquipo = null;
                if ($idEquipo !== null) {
                    $st = $pdo->prepare("SELECT id_sala FROM equipos WHERE id_equipo=?");
                    $st->execute([$idEquipo]);
                    $idSalaEquipo = $st->fetchColumn() ?: null;
                    $idSalaEquipo = $idSalaEquipo !== null ? (int)$idSalaEquipo : null;
                }

                $entranteEsPrincipal = false;
                if ($entrante !== null) {
                    $pdo->prepare("UPDATE componentes SET estado='ACTIVO', id_equipo_actual=? WHERE id_componente=?")
                        ->execute([$idEquipo, $idEntrante]);
                    self::registrarHistorial(
                        $pdo, $idEntrante, 'ASIGNACION', $idAprobador,
                        $entrante['id_equipo_actual'] !== null ? (int)$entrante['id_equipo_actual'] : null, $idEquipo,
                        $entrante['estado'], 'ACTIVO',
                        $idTicketHistorial, "Asignado por aprobación de $ref", $motivo
                    );
                    // Bodega/oficina de mantenimiento → sala del equipo, con quién solicitó y aprobó.
                    TicketActivos::registrarMovimientoActivo(
                        $pdo, null, $idEntrante, null, $idSalaEquipo, 'CAMBIO_PIEZA_ENTRANTE',
                        $tablaMov, $idMov, $idSolicito, $idAprobador, $idAprobador
                    );
                    // Regla 4: si entra la pieza principal, el equipo vuelve a estar operativo.
                    $entranteEsPrincipal = in_array($entrante['tipo'], ['torre', 'todo_en_uno'], true);
                    if ($entranteEsPrincipal && $idEquipo !== null) {
                        $pdo->prepare("UPDATE equipos SET operativo=1 WHERE id_equipo=?")->execute([$idEquipo]);
                    }
                }

                if ($saliente !== null) {
                    $pdo->prepare("UPDATE componentes SET estado='EN_REVISION', id_equipo_actual=NULL WHERE id_componente=?")
                        ->execute([$idSaliente]);
                    self::registrarHistorial(
                        $pdo, $idSaliente, 'DESASIGNACION', $idAprobador,
                        (int)$saliente['id_equipo_actual'], null,
                        $saliente['estado'], 'EN_REVISION',
                        $idTicketHistorial, "Retirado por aprobación de $ref", $motivo
                    );
                    // Sala del equipo → bodega/oficina de mantenimiento.
                    TicketActivos::registrarMovimientoActivo(
                        $pdo, null, $idSaliente, $idSalaEquipo, null, 'CAMBIO_PIEZA_SALIENTE',
                        $tablaMov, $idMov, $idSolicito, $idAprobador, $idAprobador
                    );
                    // Regla 4: sale la pieza principal y NO entra otra → el equipo queda no operativo
                    // y sale de los mantenimientos activos (ya no está operativo en su sala).
                    if (!$entranteEsPrincipal && in_array($saliente['tipo'], ['torre', 'todo_en_uno'], true)) {
                        $pdo->prepare("UPDATE equipos SET operativo=0 WHERE id_equipo=?")->execute([(int)$saliente['id_equipo_actual']]);
                        ComponenteBajas::excluirEquipoDeMantenimientosActivos($pdo, (int)$saliente['id_equipo_actual'], $notaNoOperativo);
                    }
                }

                return true;
            }, $pdo);
        } catch (CambioPiezaNoAplicable $e) {
            throw $e; // rechazo esperado (regla 1 o 2): no es un error del sistema
        } catch (Throwable $e) {
            RegistroErrores::registrar("Error aplicando cambio de pieza ($ref)", $e);
            throw $e;
        }
    }


    /**
     * Ejecuta el movimiento real de un equipo entre salas cuando se aprueba un ticket de "cambio de posición"
     * (create_ticket_beca.php).
     */
    public static function cambiarPosicion(PDO $pdo, array $ticket, int $idTecnico): bool {
        $idEquipo = $ticket['id_equipo'] ?? null;
        $idSalaOrigen = $ticket['id_sala'] ?? null;
        $idSalaDestino = $ticket['id_sala_destino'] ?? null;

        if (empty($idEquipo) || empty($idSalaDestino)) {
            return false; // no es un ticket de cambio de posición
        }

        try {
            return Database::transaccion(function (PDO $pdo) use ($idEquipo, $idSalaOrigen, $idSalaDestino, $idTecnico, $ticket) {
                $stmt = $pdo->prepare("SELECT id_sala, codigo FROM equipos WHERE id_equipo=? FOR UPDATE");
                $stmt->execute([$idEquipo]);
                $equipo = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$equipo) { throw new RuntimeException("El equipo ya no existe."); }

                // Si alguien más ya lo movió mientras tanto (otro ticket, o
                // reasignación manual), no se pisa: se rechaza la aprobación para
                // que el técnico revise qué pasó.
                if ($idSalaOrigen !== null && (int)$equipo['id_sala'] !== (int)$idSalaOrigen) {
                    throw new TransaccionRevertida(false);
                }

                $stmt = $pdo->prepare("UPDATE equipos SET id_sala=? WHERE id_equipo=?");
                $stmt->execute([$idSalaDestino, $idEquipo]);

                // El equipo ya no está físicamente en la sala origen — si tenía una
                // fila activa en la checklist de algún mantenimiento general de esa
                // sala, ya no aplica (spec: "si en el mantenimiento lo sacan, ese
                // debe desaparecer del mantenimiento de sala").
                ComponenteBajas::excluirEquipoDeMantenimientosActivos(
                    $pdo, (int)$idEquipo,
                    "Trasladado de sala #$idSalaOrigen a sala #$idSalaDestino (ticket #{$ticket['id']})"
                );

                AuditoriaService::registrar(
                    $pdo, $idTecnico, 'equipos', (int)$idEquipo, 'EDITAR',
                    "Reubicado de sala #$idSalaOrigen a sala #$idSalaDestino por aprobación de ticket #{$ticket['id']}"
                );

                // Además del log genérico de auditoría (texto libre),
                // registrarMovimientoActivo deja un registro estructurado y
                // consultable (sala origen/destino, ticket relacionado, quién
                // ejecutó) — ver movimientos_activos.
                TicketActivos::registrarMovimientoActivo(
                    $pdo,
                    $idEquipo, null,
                    $idSalaOrigen, $idSalaDestino,
                    'CAMBIO_DE_POSICION',
                    TablaTicket::NORMAL, (int)$ticket['id'],
                    $ticket['user_id'] ?? null, $idTecnico, $idTecnico
                );

                return true;
            }, $pdo);
        } catch (Throwable $e) {
            RegistroErrores::registrar("Error ejecutando cambio de posición del ticket {$ticket['id']}", $e);
            throw $e;
        }
    }

}
