<?php
/** Consultas y estadísticas de tickets: Sistemas vs Externos, visibilidad de becas, listados y conteos. */
final class TicketConsultas
{
    /**
     * ===================== Departamento de Sistemas vs Externos =====================
     * Criterio único para decidir si un ticket pertenece al Departamento de Sistemas e Informática.
     * Centralizarlo aquí evita que un archivo se corrija y el resto quede desincronizado.
     */

    /** Compara el texto libre `salas.dependencia` contra "sistemas". */
    public static function esDependenciaSistemas(?string $dependencia): bool {
        return stripos((string)$dependencia, 'sistemas') !== false;
    }

    /**
     * Condición SQL para la tabla `tickets` (QR anónimo + solicitante
     * clásico + BECA_DIRECTO/TECNICO_DIRECTO). $aliasTicket/$aliasSala son
     * los alias ya usados en el FROM/JOIN de la consulta que la use.
     *
     * Nota: el formulario clásico de solicitante (create_ticket.php, origen PANEL_INTERNO) no guarda id_sala
     * — su campo "Dependencia" es texto libre sin relación con `salas`, así que cae en "Externos". Hoy la
     * secretaria registra equipos que llegan a la oficina de mantenimiento (sin sala): todo ticket creado por
     * una secretaria es del Departamento.
     */
    public static function condicionSistemasTicketsSQL(string $aliasTicket = 't', string $aliasSala = 's'): string {
        return "($aliasTicket.origen IN ('TECNICO_DIRECTO','BECA_DIRECTO')"
             . " OR ($aliasTicket.origen IN ('QR_ANONIMO','PANEL_INTERNO') AND COALESCE($aliasSala.dependencia,'') LIKE '%sistemas%')"
             // Lo que registra la secretaría del Departamento (equipos que llegan a la oficina, con o sin radicado DatArSoft) es del Departamento.
             . " OR EXISTS (SELECT 1 FROM users u_sec WHERE u_sec.id = $aliasTicket.user_id AND u_sec.role = 'secretaria'))";
    }

    /**
     * Los ve una secretaria con el permiso `permiso_ver_tickets_sistemas`. $soloFirmados: null = todos, false
     * = activos (firmado=0), true = firmados.
     *
     * @return array{datos: array, total: int, total_paginas: int, pagina: int}
     */
    public static function obtenerTicketsSistemas(PDO $pdo, ?bool $soloFirmados, int $pagina, int $porPagina): array {
        $cond = self::condicionSistemasTicketsSQL('t', 's');
        $sql = "SELECT t.*, sol.nombre_completo AS solicitante_nombre, tec.nombre_completo AS tecnico_nombre, s.nombre_sala
                FROM tickets t
                LEFT JOIN users sol ON t.user_id = sol.id
                LEFT JOIN users tec ON t.tecnico_id = tec.id
                LEFT JOIN salas s ON t.id_sala = s.id_sala
                WHERE $cond"
             . ($soloFirmados === null ? '' : ' AND t.firmado = ' . ($soloFirmados ? '1' : '0'))
             . " ORDER BY t.fecha_creacion DESC";
        return paginar($pdo, $sql, [], $pagina, $porPagina);
    }

    /**
     * Condición SQL para `tickets_profesor`. A diferencia de `tickets`, aquí
     * NO hay origen tipo TECNICO_DIRECTO/BECA_DIRECTO (todo ticket de
     * profesor viene de una cuenta de profesor) — la dependencia depende
     * 100% de a qué sala se refiere el ticket. Casi todos los subtipos
     * guardan la sala en `id_sala` (daño, instalación de software,
     * préstamo); el único que además tiene una sala de origen distinta es
     * "Cambio de Sala", que usa `id_sala_actual` para la sala que el
     * profesor deja. Se cuenta como del Departamento si CUALQUIERA de las
     * dos (destino o actual) pertenece a Sistemas, para no perder de vista
     * un cambio de sala que sale o entra al departamento.
     *
     * $aliasSala/$aliasSalaActual deben ser JOINs ya presentes contra
     * `salas` vía tp.id_sala y tp.id_sala_actual respectivamente.
     */
    public static function condicionSistemasProfesorSQL(string $aliasSala = 'sp', string $aliasSalaActual = 'spa'): string {
        return "(COALESCE($aliasSala.dependencia,'') LIKE '%sistemas%'"
             . " OR COALESCE($aliasSalaActual.dependencia,'') LIKE '%sistemas%')";
    }

    /**
     * ¿El ticket es del Departamento de Sistemas e Informática? (mismo criterio de las bandejas:
     * condicionSistemasTicketsSQL / condicionSistemasProfesorSQL). Un ticket inexistente cuenta como no.
     */
    public static function esTicketDeSistemas(PDO $pdo, string $tabla, int $idTicket): bool {
        if ($tabla === TablaTicket::NORMAL) {
            $sql = "SELECT 1 FROM tickets t LEFT JOIN salas s ON t.id_sala = s.id_sala WHERE t.id = ? AND " . self::condicionSistemasTicketsSQL('t', 's');
        } elseif ($tabla === TablaTicket::PROFESOR) {
            $sql = "SELECT 1 FROM tickets_profesor tp LEFT JOIN salas sp ON tp.id_sala = sp.id_sala LEFT JOIN salas spa ON tp.id_sala_actual = spa.id_sala
                    WHERE tp.id = ? AND " . self::condicionSistemasProfesorSQL('sp', 'spa');
        } else {
            return false;
        }
        $st = $pdo->prepare($sql);
        $st->execute([$idTicket]);
        return (bool)$st->fetchColumn();
    }

    /**
     * ¿Este técnico puede ver/atender tickets fuera del Departamento? Solo con el permiso que activa el admin.
     * El admin siempre los ve (solo supervisa); la beca sigue su propia regla (condicionVisibleBeca*).
     */
    public static function puedeVerExternos(PDO $pdo, int $idUsuario, string $rol): bool {
        if ($rol === Rol::ADMIN) return true;
        return $rol === Rol::TECNICO && UserPermissions::tiene($pdo, $idUsuario, UserPermissions::VER_TICKETS_EXTERNOS);
    }

    /**
     * REGLA DE VISIBILIDAD DEL ROL beca_trabajo (única fuente de verdad).
     *
     * Una beca de trabajo es becaria del Departamento de Sistemas, así que
     * SOLO puede ver/tomar tickets que cumplan alguna de estas condiciones:
     *   - se le asignaron / los tomó ella (`tecnico_id` o `beca_id` = su id;
     *     `beca_id` lo pone el técnico responsable, ver TicketAsignacionService::asignarBeca);
     *   - los creó ella misma (`user_id`, solo tabla `tickets`);
     *   - pertenecen a una sala del Departamento de Sistemas (`salas.dependencia`).
     * Cualquier otro ticket (p. ej. de una sala de otra dependencia que no
     * le asignaron) NO debe aparecerle ni poder tomarlo por ID manipulado.
     *
     * $idBeca se fuerza a int: es seguro interpolarlo en el SQL.
     * $aliasSala debe ser un JOIN ya presente contra `salas` vía t.id_sala.
     */
    public static function condicionVisibleBecaTicketsSQL(int $idBeca, string $aliasTicket = 't', string $aliasSala = 's'): string {
        $idBeca = (int)$idBeca;
        return "($aliasTicket.tecnico_id = $idBeca OR $aliasTicket.beca_id = $idBeca OR $aliasTicket.user_id = $idBeca"
             . " OR COALESCE($aliasSala.dependencia,'') LIKE '%sistemas%')";
    }

    /** Igual que la anterior, para `tickets_profesor` (sala destino o sala actual). */
    public static function condicionVisibleBecaProfesorSQL(int $idBeca, string $aliasTicket = 'tp', string $aliasSala = 'sp', string $aliasSalaActual = 'spa'): string {
        $idBeca = (int)$idBeca;
        return "($aliasTicket.tecnico_id = $idBeca OR $aliasTicket.beca_id = $idBeca OR " . self::condicionSistemasProfesorSQL($aliasSala, $aliasSalaActual) . ")";
    }

    /**
     * ¿La beca es quien EJECUTA este ticket? (lo tomó ella: tecnico_id, o el
     * técnico se lo asignó: beca_id). Solo el ejecutor puede marcarlo
     * Solucionado, sugerir mantenimiento o agregar equipos afectados.
     * $fila: fila de `tickets` o `tickets_profesor`.
     */
    public static function becaEsEjecutora(array $fila, int $idBeca): bool {
        return (int)($fila['tecnico_id'] ?? 0) === $idBeca || (int)($fila['beca_id'] ?? 0) === $idBeca;
    }

    /**
     * Enlace al que mandar a un usuario desde una notificación sobre un
     * ticket: la beca NO puede abrir los PDF de ticket (regla de negocio),
     * así que se le manda a su propia pantalla.
     */
    public static function enlaceTicketParaUsuario(PDO $pdo, int $idUsuario, string $urlPdf): string {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$idUsuario]);
        return $stmt->fetchColumn() === Rol::BECA ? 'tickets.php' : $urlPdf;
    }

    /**
     * ¿Puede esta beca ver/tomar ESTE ticket concreto? Se usa en las
     * acciones que reciben un id por POST/GET (anti-IDOR), porque las
     * listas filtradas no bastan: un id se puede manipular a mano.
     */
    public static function becaPuedeVerTicket(PDO $pdo, int $idBeca, string $tabla, int $idTicket): bool {
        if ($tabla === TablaTicket::NORMAL) {
            $sql = "SELECT 1 FROM tickets t LEFT JOIN salas s ON t.id_sala = s.id_sala
                    WHERE t.id = ? AND " . self::condicionVisibleBecaTicketsSQL($idBeca, 't', 's');
        } elseif ($tabla === TablaTicket::PROFESOR) {
            $sql = "SELECT 1 FROM tickets_profesor tp
                    LEFT JOIN salas sp ON tp.id_sala = sp.id_sala
                    LEFT JOIN salas spa ON tp.id_sala_actual = spa.id_sala
                    WHERE tp.id = ? AND " . self::condicionVisibleBecaProfesorSQL($idBeca, 'tp', 'sp', 'spa');
        } else {
            return false;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$idTicket]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Etiqueta legible para el `tipo_ticket` de `tickets_profesor` ('requerimiento' / 'danio'). El valor que
     * se guarda en la base de datos (columna/enum 'danio') NO cambia, solo el texto que se le muestra a la
     * persona.
     */
    public static function etiquetaTipoTicketProfesor(?string $tipoTicket): string {
        return $tipoTicket === 'requerimiento' ? 'Requerimiento' : 'Error de sala';
    }

    /**
     * Tickets de un usuario, en la tabla que corresponda a su rol. Reemplaza
     * las variantes casi idénticas de los paneles de solicitante y profesor
     * (ambas: "mis tickets, con nombre de técnico, filtrables por estado").
     *
     * $tabla debe ser 'tickets' (solicitante) o 'tickets_profesor' (profesor)
     * — son físicamente dos tablas distintas con columnas distintas, así que
     * este helper NO intenta forzarlas a un mismo shape; solo evita repetir
     * el mismo patrón de JOIN + WHERE + ORDER BY en cada archivo.
     *
     * tickets.php NO usa este helper para su listado de
     * tickets_profesor: ese archivo lista TODOS los tickets_profesor (el
     * becario actúa como técnico ahí, no como dueño), no "los suyos" — es un
     * caso distinto, no una variante del mismo query.
     *
     * @return array{datos: array, total: int, total_paginas: int, pagina: int}
     */
    public static function obtenerTicketsPorUsuario(
        PDO $pdo,
        int $idUsuario,
        string $tabla,
        ?array $filtroEstados = null,
        int $pagina = 1,
        int $porPagina = 0
    ): array {
        $porPagina = $porPagina > 0 ? $porPagina : ticketsPorPagina();
        if ($tabla === TablaTicket::NORMAL) {
            $sqlBase = "SELECT t.*, u.nombre_completo AS tecnico_nombre
                         FROM tickets t
                         LEFT JOIN users u ON t.tecnico_id = u.id
                         WHERE t.user_id = ?";
            $orden = " ORDER BY t.fecha_creacion DESC";
            $params = [$idUsuario];
            if ($filtroEstados) {
                $placeholders = implode(',', array_fill(0, count($filtroEstados), '?'));
                $sqlBase .= " AND t.estado IN ($placeholders)";
                $params = array_merge($params, $filtroEstados);
            }
        } elseif ($tabla === TablaTicket::PROFESOR) {
            $sqlBase = "SELECT t.*, u.nombre_completo AS tecnico_nombre
                         FROM tickets_profesor t
                         LEFT JOIN users u ON t.tecnico_id = u.id
                         WHERE t.profesor_id = ?";
            $orden = " ORDER BY t.fecha_creacion DESC";
            $params = [$idUsuario];
            if ($filtroEstados) {
                $placeholders = implode(',', array_fill(0, count($filtroEstados), '?'));
                $sqlBase .= " AND t.estado IN ($placeholders)";
                $params = array_merge($params, $filtroEstados);
            }
        } else {
            throw new InvalidArgumentException("Tabla no soportada: $tabla");
        }

        return paginar($pdo, $sqlBase . $orden, $params, $pagina, $porPagina);
    }

    /**
     * Conteo de tickets normales agrupados por estado. Reutiliza el mismo
     * query que ya usaba tickets.php para $stats (sección de
     * Estadísticas) y también sirve para el Módulo 3 (Tendencias).
     * $idTecnico opcional: si se pasa, limita el conteo a ese técnico.
     *
     * @return array<string,int> estado => total
     */
    public static function contarTicketsPorEstado(PDO $pdo, ?int $idTecnico = null): array {
        if ($idTecnico) {
            $stmt = $pdo->prepare("SELECT estado, COUNT(*) as total FROM tickets WHERE tecnico_id = ? GROUP BY estado");
            $stmt->execute([$idTecnico]);
        } else {
            $stmt = $pdo->query("SELECT estado, COUNT(*) as total FROM tickets GROUP BY estado");
        }
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Tickets creados por semana (tickets normales), últimas $semanas
     * semanas — para la gráfica de tendencias.
     *
     * @return array<int, array{semana:string, total:int}> ordenado ascendente
     *         (semana más antigua primero, como espera Chart.js para un eje
     *         de tiempo de izquierda a derecha).
     */
    public static function ticketsPorSemana(PDO $pdo, int $semanas = 8): array {
        $stmt = $pdo->prepare(
            "SELECT YEARWEEK(fecha_creacion, 3) AS semana, COUNT(*) AS total
             FROM tickets
             WHERE fecha_creacion >= DATE_SUB(CURDATE(), INTERVAL ? WEEK)
             GROUP BY YEARWEEK(fecha_creacion, 3)
             ORDER BY semana ASC"
        );
        $stmt->execute([$semanas]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Tiempo promedio de resolución en horas, sobre tickets con estado final
     * (Solucionado/Aprobado/Cerrado) que ya tienen fecha_terminacion.
     * $idTecnico opcional para acotar a un técnico puntual.
     */
    public static function tiempoPromedioResolucion(PDO $pdo, ?int $idTecnico = null): ?float {
        $sql = "SELECT AVG(TIMESTAMPDIFF(HOUR, fecha_creacion, fecha_terminacion)) AS promedio_horas
                FROM tickets
                WHERE fecha_terminacion IS NOT NULL
                  AND estado IN ('Solucionado','Aprobado','Cerrado')";
        $params = [];
        if ($idTecnico) {
            $sql .= " AND tecnico_id = ?";
            $params[] = $idTecnico;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $valor = $stmt->fetchColumn();
        return $valor !== null ? (float)$valor : null;
    }

    /** Conteo actual de tickets por técnico (pendientes/en proceso/resueltos). */
    public static function ticketsPorTecnico(PDO $pdo): array {
        return $pdo->query("
            SELECT u.nombre_completo as tecnico,
                   SUM(CASE WHEN t.estado='En espera' THEN 1 ELSE 0 END) as pendientes,
                   SUM(CASE WHEN t.estado='En proceso' THEN 1 ELSE 0 END) as en_proceso,
                   SUM(CASE WHEN t.estado IN ('Solucionado','Aprobado','Cerrado') THEN 1 ELSE 0 END) as resueltos
            FROM users u
            LEFT JOIN tickets t ON u.id = t.tecnico_id
            WHERE u.role='tecnico'
            GROUP BY u.id
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}
