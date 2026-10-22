<?php
/**
 * Activos (equipos/componentes) ligados a tickets: reporte de daño repetido, asignación de equipos,
 * movimientos.
 */
final class TicketActivos
{
    /**
     * Estados que se consideran definitivamente cerrados para efectos de
     * "reporte repetido". Mientras un ticket de daño esté en cualquier otro
     * estado, se considera "en curso" y bloquea nuevos reportes sobre la
     * misma sala o el mismo equipo puntual.
     */
    public const ESTADOS_TICKET_CERRADOS_DANIO = [TicketWorkflow::CERRADO, TicketWorkflow::DESCARTADO, TicketWorkflow::RECHAZADO];

    /**
     * Evita que la bandeja de mantenimiento se llene con el MISMO reporte de
     * daño repetido: revisa si ya existe un ticket de daño en curso (no
     * cerrado) para la misma sala o el mismo equipo puntual, sin importar
     * quién lo creó ni por qué vía. Se consulta en las dos tablas reales
     * (`tickets` para QR anónimo/solicitante, `tickets_profesor` para
     * docentes) porque un mismo salón puede haber sido reportado por
     * cualquiera de los dos caminos.
     *
     * Se revisa primero si hay un ticket de SALA COMPLETA en curso (cubre
     * cualquier equipo de esa sala, ej. mantenimiento general), y solo si no
     * lo hay se revisa el equipo puntual.
     *
     * Devuelve los datos básicos del ticket ya existente (para mostrarle al
     * usuario "esto ya está reportado, en estado X") o null si no hay
     * ningún reporte en curso.
     *
     * @return array{origen_tabla:string, id:int, estado:string}|null
     */
    public static function ticketDanioActivoExistente(PDO $pdo, ?int $idSala, ?int $idEquipo): ?array {
        if (!$idSala && !$idEquipo) {
            return null;
        }
        $ph = implode(',', array_fill(0, count(self::ESTADOS_TICKET_CERRADOS_DANIO), '?'));

        if ($idSala) {
            // Ticket de sala completa ya en curso en `tickets` (ej. reporte
            // general de la sala vía QR, sin equipo puntual asociado).
            $stmt = $pdo->prepare(
                "SELECT id, estado, 'tickets' AS origen_tabla FROM tickets
                 WHERE id_sala = ? AND id_equipo IS NULL AND estado NOT IN ($ph)
                 ORDER BY fecha_creacion DESC LIMIT 1"
            );
            $stmt->execute(array_merge([$idSala], self::ESTADOS_TICKET_CERRADOS_DANIO));
            if ($fila = $stmt->fetch(PDO::FETCH_ASSOC)) return $fila;

            // Ticket de daño de esa misma sala ya en curso, reportado por un
            // profesor (tickets_profesor solo maneja daño a nivel de sala,
            // no de equipo puntual).
            $stmt = $pdo->prepare(
                "SELECT id, estado, 'tickets_profesor' AS origen_tabla FROM tickets_profesor
                 WHERE id_sala = ? AND tipo_ticket = 'danio' AND estado NOT IN ($ph)
                 ORDER BY fecha_creacion DESC LIMIT 1"
            );
            $stmt->execute(array_merge([$idSala], self::ESTADOS_TICKET_CERRADOS_DANIO));
            if ($fila = $stmt->fetch(PDO::FETCH_ASSOC)) return $fila;
        }

        if ($idEquipo) {
            // Ticket puntual sobre ese equipo exacto ya en curso (columna
            // directa `tickets.id_equipo`, el caso de un solo equipo).
            $stmt = $pdo->prepare(
                "SELECT id, estado, 'tickets' AS origen_tabla FROM tickets
                 WHERE id_equipo = ? AND estado NOT IN ($ph)
                 ORDER BY fecha_creacion DESC LIMIT 1"
            );
            $stmt->execute(array_merge([$idEquipo], self::ESTADOS_TICKET_CERRADOS_DANIO));
            if ($fila = $stmt->fetch(PDO::FETCH_ASSOC)) return $fila;

            // Ese mismo equipo, colgado como uno-de-varios de un ticket
            // multi-activo (ver ticket_activos): si "PC-002" ya está en un
            // ticket de 4 equipos sin cerrar, no se abre un ticket aparte
            // para "PC-002" solo. Se revisa contra las dos tablas reales.
            $stmt = $pdo->prepare(
                "SELECT tk.id, tk.estado, 'tickets' AS origen_tabla
                 FROM ticket_activos ta
                 JOIN tickets tk ON tk.id = ta.id_ticket AND ta.tabla_ticket = 'tickets'
                 WHERE ta.id_equipo = ? AND tk.estado NOT IN ($ph)
                 ORDER BY tk.fecha_creacion DESC LIMIT 1"
            );
            $stmt->execute(array_merge([$idEquipo], self::ESTADOS_TICKET_CERRADOS_DANIO));
            if ($fila = $stmt->fetch(PDO::FETCH_ASSOC)) return $fila;

            $stmt = $pdo->prepare(
                "SELECT tp.id, tp.estado, 'tickets_profesor' AS origen_tabla
                 FROM ticket_activos ta
                 JOIN tickets_profesor tp ON tp.id = ta.id_ticket AND ta.tabla_ticket = 'tickets_profesor'
                 WHERE ta.id_equipo = ? AND tp.estado NOT IN ($ph)
                 ORDER BY tp.fecha_creacion DESC LIMIT 1"
            );
            $stmt->execute(array_merge([$idEquipo], self::ESTADOS_TICKET_CERRADOS_DANIO));
            if ($fila = $stmt->fetch(PDO::FETCH_ASSOC)) return $fila;
        }

        return null;
    }

    /**
     * Asocia varios activos (equipos y/o componentes puntuales) a un
     * ticket ya creado, en `ticket_activos`. Pensada para el caso "varios
     * PCs de la sala fallaron a la vez" o "un PC + sus periféricos": el
     * ticket sigue siendo uno solo, pero queda claro qué activos concretos
     * cubre. Silenciosamente ignora duplicados (mismo ticket + mismo
     * activo) gracias al UNIQUE KEY de la tabla.
     *
     * @param string $tablaTicket 'tickets' o 'tickets_profesor'
     * @param int[]  $idsEquipo   ids de equipos completos afectados
     * @param int[]  $idsComponente ids de componentes puntuales afectados
     */
    public static function agregarActivosATicket(
        PDO $pdo,
        string $tablaTicket,
        int $idTicket,
        array $idsEquipo = [],
        array $idsComponente = [],
        string $tipoAfectacion = 'DAÑADO',
        ?string $observacion = null
    ): void {
        if (!TablaTicket::valida($tablaTicket)) {
            return;
        }
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO ticket_activos
                (tabla_ticket, id_ticket, id_equipo, id_componente, tipo_afectacion, observacion)
             VALUES (?, ?, ?, NULL, ?, ?)"
        );
        foreach (array_unique(array_filter($idsEquipo)) as $idEquipo) {
            $stmt->execute([$tablaTicket, $idTicket, $idEquipo, $tipoAfectacion, $observacion]);
        }

        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO ticket_activos
                (tabla_ticket, id_ticket, id_equipo, id_componente, tipo_afectacion, observacion)
             VALUES (?, ?, NULL, ?, ?, ?)"
        );
        foreach (array_unique(array_filter($idsComponente)) as $idComponente) {
            $stmt->execute([$tablaTicket, $idTicket, $idComponente, $tipoAfectacion, $observacion]);
        }
    }

    /**
     * Filtra una lista de ids de equipo (normalmente $_POST['ids_equipo']
     * o similar) dejando solo los que de verdad pertenecen a $idSala.
     *
     * Centraliza una validación que se repetía copiada y pegada en varios
     * formularios (reportar.php, create_ticket_profesor.php,
     * tickets.php, tickets.php): NUNCA se confía en los
     * ids de equipo que llegan por POST tal cual — alguien podría mandar el
     * id de un equipo de otra sala completamente distinta. Al vivir en un
     * solo lugar, un cambio a esta regla (por ejemplo, excluir equipos ya
     * dados de baja) se aplica automáticamente a los 4 formularios sin
     * tener que acordarse de tocarlos uno por uno.
     *
     * @param array $idsPost ids "crudos" recibidos por POST (strings o ints)
     * @return int[] solo los ids que sí pertenecen a $idSala, sin duplicados
     */
    public static function equiposValidosDeSala(PDO $pdo, int $idSala, array $idsPost): array {
        $idsPost = array_unique(array_map('intval', array_filter($idsPost)));
        if (!$idSala || empty($idsPost)) return [];

        $stmt = $pdo->prepare("SELECT id_equipo FROM equipos WHERE id_sala = ?");
        $stmt->execute([$idSala]);
        $equiposDeLaSala = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        return array_values(array_intersect($idsPost, $equiposDeLaSala));
    }

    /**
     * Trae en bloque (una sola consulta, no una por ticket) los equipos ya registrados en `ticket_activos`
     * para un conjunto de tickets de una misma tabla.
     *
     * @param string $tablaTicket 'tickets' o 'tickets_profesor'
     * @param int[]  $idsTicket   ids de ticket a consultar
     * @return array [id_ticket => [ ['id_equipo','codigo','descripcion'], ... ]]
     */
    public static function equiposYaAsignadosATickets(PDO $pdo, string $tablaTicket, array $idsTicket): array {
        if (empty($idsTicket)) return [];
        $marcadores = implode(',', array_fill(0, count($idsTicket), '?'));
        $stmt = $pdo->prepare("
            SELECT ta.id_ticket, e.id_equipo, e.codigo, e.descripcion
            FROM ticket_activos ta
            JOIN equipos e ON e.id_equipo = ta.id_equipo
            WHERE ta.tabla_ticket = ? AND ta.id_ticket IN ($marcadores)
            ORDER BY e.codigo
        ");
        $stmt->execute(array_merge([$tablaTicket], $idsTicket));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['id_ticket']][] = $row;
        }
        return $out;
    }

    /**
     * Trae en bloque los equipos disponibles de un conjunto de salas —
     * complemento de equiposYaAsignadosATickets(): para las salas de los
     * tickets que TODAVÍA no tienen nada en ticket_activos, esto arma el
     * checklist de "agregar equipo(s)".
     *
     * @param int[] $idsSala
     * @return array [id_sala => [ ['id_equipo','codigo','descripcion'], ... ]]
     */
    public static function equiposPorSalas(PDO $pdo, array $idsSala): array {
        $idsSala = array_unique(array_map('intval', array_filter($idsSala)));
        if (empty($idsSala)) return [];
        $marcadores = implode(',', array_fill(0, count($idsSala), '?'));
        $stmt = $pdo->prepare("SELECT id_sala, id_equipo, codigo, descripcion FROM equipos WHERE id_sala IN ($marcadores) ORDER BY codigo");
        $stmt->execute($idsSala);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['id_sala']][] = $row;
        }
        return $out;
    }

    /**
     * Registra un movimiento físico real de un equipo o componente entre
     * salas (o hacia/desde bodega, con id_sala_* en NULL para eso), dejando
     * constancia de quién solicitó, quién aprobó y quién ejecutó — la
     * "Regla de modificación del inventario" que ya estaba descrita en el
     * documento de lógica pero nunca se había llevado a una tabla real.
     * Se llama en cada punto donde el código YA decide mover algo (traslado
     * a bodega, reubicación, cambio de pieza aprobado), no reemplaza esos
     * flujos — solo deja el rastro.
     */
    public static function registrarMovimientoActivo(
        PDO $pdo,
        ?int $idEquipo,
        ?int $idComponente,
        ?int $idSalaOrigen,
        ?int $idSalaDestino,
        string $motivo,
        ?string $tablaTicketRelacionado = null,
        ?int $idTicketRelacionado = null,
        ?int $idSolicito = null,
        ?int $idAprobo = null,
        ?int $idEjecuto = null,
        ?string $evidencia = null
    ): int {
        $stmt = $pdo->prepare(
            "INSERT INTO movimientos_activos
                (id_equipo, id_componente, id_sala_origen, id_sala_destino, motivo,
                 tabla_ticket_relacionado, id_ticket_relacionado,
                 id_solicito, id_aprobo, id_ejecuto, fecha_aprobado, fecha_ejecutado, evidencia)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $ahora = $idEjecuto ? date('Y-m-d H:i:s') : null;
        $stmt->execute([
            $idEquipo, $idComponente, $idSalaOrigen, $idSalaDestino, $motivo,
            $tablaTicketRelacionado, $idTicketRelacionado,
            $idSolicito, $idAprobo, $idEjecuto,
            $idAprobo ? date('Y-m-d H:i:s') : null,
            $ahora,
            $evidencia,
        ]);
        $idMov = (int)$pdo->lastInsertId();
        // GLPI: si el técnico aprobó el traslado de un EQUIPO completo, cada componente suyo cambia de ubicación.
        // (Los movimientos de un componente suelto ya se encolan desde InventarioService::registrarHistorial.)
        if ($idAprobo !== null && $idEquipo !== null && $idComponente === null) {
            GlpiSyncService::encolarMovimiento($pdo, $idEquipo, null, $idSalaDestino, $idMov, $idAprobo);
        }
        return $idMov;
    }
}
