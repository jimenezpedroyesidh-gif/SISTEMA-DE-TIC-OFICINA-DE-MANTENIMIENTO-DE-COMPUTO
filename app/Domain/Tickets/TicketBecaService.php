<?php
/**
 * Casos de uso de create_ticket_beca.php: ticket de cambio de pieza y de cambio de posición creado por
 * beca_trabajo, técnico o admin. Devuelve ['error' => string, 'exito' => string]. Requiere AuditoriaService
 * cargado (lo carga el controlador).
 */

class TicketBecaService
{
    /** Revalida SIEMPRE contra la BD que el ticket de daño exista y lo haya tomado este mismo usuario (anti-IDOR). */
    public static function resolverTicketProfesorOrigen(PDO $pdo, $idPost, int $tecnicoActual): ?array
    {
        $id = (int)($idPost ?? 0);
        if (!$id) return null;
        $tp = TicketBecaRepository::ticketProfesorDanio($pdo, $id);
        return ($tp && (int)$tp['tecnico_id'] === $tecnicoActual) ? $tp : null;
    }

    public static function crear(PDO $pdo, int $idUsuario, string $rol, array $post): array
    {
        $tipo = (string)($post['tipo_ticket_beca'] ?? '');
        if ($tipo === 'cambio_pieza') return self::crearCambioPieza($pdo, $idUsuario, $rol, $post);
        if ($tipo === 'cambio_posicion') return self::crearCambioPosicion($pdo, $idUsuario, $rol, $post);
        return ['error' => "Selecciona un tipo de ticket válido.", 'exito' => ''];
    }

    private static function esTecnicoOAdmin(string $rol): bool { return in_array($rol, [Rol::TECNICO, Rol::ADMIN], true); }

    private static function crearCambioPieza(PDO $pdo, int $idUsuario, string $rol, array $post): array
    {
        // «Equipo» = conjunto completo. Se exige AL MENOS una pieza: solo saliente (sacar a bodega),
        // solo entrante (devolver una ya reparada) o ambas (cambio directo).
        $idSala = (int)($post['id_sala_pieza'] ?? 0);
        $idEquipo = (int)($post['id_equipo'] ?? 0);
        $idSaliente = !empty($post['id_componente_saliente']) ? (int)$post['id_componente_saliente'] : null;
        $idEntrante = !empty($post['id_componente_entrante']) ? (int)$post['id_componente_entrante'] : null;
        $descripcion = trim((string)($post['descripcion_pieza'] ?? ''));
        $origenTp = self::resolverTicketProfesorOrigen($pdo, $post['id_ticket_profesor_origen'] ?? null, $idUsuario);
        $faltan = ['error' => "Completa sala, equipo, al menos una pieza (saliente o entrante), y descripción.", 'exito' => ''];

        if (!$idSala || !$idEquipo || (!$idSaliente && !$idEntrante) || $descripcion === '') return $faltan;

        if ($idEntrante) {
            // Debe seguir disponible (otro ticket pudo tomarla): existe y sin equipo asignado.
            $entrante = TicketBecaRepository::componente($pdo, $idEntrante);
            if (!$entrante || $entrante['id_equipo_actual'] !== null) {
                return ['error' => "El componente de reemplazo ya no está disponible. Búscalo de nuevo.", 'exito' => ''];
            }
        }
        // sala, equipo y pieza saliente deben existir.
        $sala = TicketBecaRepository::sala($pdo, $idSala);
        $equipo = TicketBecaRepository::equipo($pdo, $idEquipo);
        if (!$sala || !$equipo) return $faltan;
        if ($idSaliente && !TicketBecaRepository::componente($pdo, $idSaliente)) {
            return ['error' => "La pieza saliente seleccionada no existe. Búscala de nuevo.", 'exito' => ''];
        }

        $esTec = self::esTecnicoOAdmin($rol);
        $idNuevo = TicketBecaRepository::crearCambioPieza($pdo, [
            'user_id' => $idUsuario, 'tecnico_id' => $esTec ? $idUsuario : null,
            'nombre_sala' => $sala['nombre_sala'], 'codigo_equipo' => $equipo['codigo'], 'descripcion' => $descripcion,
            'id_sala' => $idSala, 'id_equipo' => $idEquipo, 'id_saliente' => $idSaliente, 'id_entrante' => $idEntrante,
            'origen' => $esTec ? 'TECNICO_DIRECTO' : 'BECA_DIRECTO',
            'tabla_relacionado' => $origenTp ? TablaTicket::PROFESOR : null, 'id_relacionado' => $origenTp ? (int)$origenTp['id'] : null,
        ]);
        AuditoriaService::registrar($pdo, $idUsuario, TablaTicket::NORMAL, $idNuevo, 'CREAR', 'propuesta de cambio de pieza'
            . ($origenTp ? " (enlazado al ticket de daño de profesor #{$origenTp['id']})" : ''));

        if ($idSaliente && !$idEntrante) {
            $msg = "Ticket creado: se propone sacar la pieza a bodega/reparar. El equipo se queda en el salón (sin esa pieza) hasta que el técnico apruebe.";
        } elseif ($idEntrante && !$idSaliente) {
            $msg = "Ticket creado: se propone devolver la pieza ya reparada a este equipo. Falta la aprobación del técnico.";
        } else {
            $msg = "Ticket de cambio de pieza creado. Queda pendiente de asignación técnica y aprobación.";
        }
        return ['error' => '', 'exito' => $msg];
    }

    private static function crearCambioPosicion(PDO $pdo, int $idUsuario, string $rol, array $post): array
    {
        $idEquipo = (int)($post['id_equipo_mover'] ?? 0);
        $idSalaDestino = (int)($post['id_sala_destino'] ?? 0);
        $descripcion = trim((string)($post['descripcion_posicion'] ?? ''));

        $equipo = $idEquipo ? TicketBecaRepository::equipo($pdo, $idEquipo) : null;
        // La sala destino también debe existir.
        if (!$equipo || !$idSalaDestino || $descripcion === '' || !TicketBecaRepository::sala($pdo, $idSalaDestino)) {
            return ['error' => "Selecciona el equipo, la sala destino y describe el motivo del cambio.", 'exito' => ''];
        }
        if ((int)$equipo['id_sala'] === $idSalaDestino) {
            return ['error' => "La sala destino debe ser distinta a la sala actual del equipo.", 'exito' => ''];
        }
        $salaOrigen = TicketBecaRepository::sala($pdo, (int)$equipo['id_sala']);
        $esTec = self::esTecnicoOAdmin($rol);
        $idNuevo = TicketBecaRepository::crearCambioPosicion($pdo, [
            'user_id' => $idUsuario, 'tecnico_id' => $esTec ? $idUsuario : null,
            'nombre_sala' => $salaOrigen['nombre_sala'] ?? null, 'codigo_equipo' => $equipo['codigo'], 'descripcion' => $descripcion,
            'id_sala' => (int)$equipo['id_sala'], 'id_sala_destino' => $idSalaDestino, 'id_equipo' => $idEquipo,
            'origen' => $esTec ? 'TECNICO_DIRECTO' : 'BECA_DIRECTO', 'estado' => $esTec ? TicketWorkflow::SOLUCIONADO : TicketWorkflow::EN_ESPERA,
        ]);
        AuditoriaService::registrar($pdo, $idUsuario, TablaTicket::NORMAL, $idNuevo, 'CREAR', "propuesta de cambio de posición a sala #$idSalaDestino");
        return ['error' => '', 'exito' => $esTec
            ? "Ticket de cambio de posición creado y asignado a ti. Apruébalo en tu panel para ejecutar el traslado y cerrarlo."
            : "Ticket de cambio de posición creado. Un técnico debe tomarlo, aprobarlo y ejecutar el traslado."];
    }
}
