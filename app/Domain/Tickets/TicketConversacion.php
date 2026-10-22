<?php
/**
 * Conversación de un ticket entre quien lo atiende y quien lo reportó (profesor, solicitante o persona
 * que reportó por QR sin sesión). Vive en ticket_comentarios con visibilidad = 'CONVERSACION' (las notas
 * internas del equipo siguen siendo 'INTERNO' y nunca las ve el solicitante). Toda la conversación se
 * imprime en el PDF del ticket como constancia.
 *
 * Reglas:
 *  - Personal: solo quien atiende el ticket (el técnico/beca que lo tomó o la beca asignada) escribe; el
 *    admin la lee. Para hablar hay que tomar el ticket primero.
 *  - Solicitante: si el último mensaje es del personal, responde libremente. Si no (nadie le ha respondido,
 *    o su último mensaje sigue sin respuesta) solo puede REITERAR cuando se cumple el tiempo de respuesta
 *    del acuerdo de servicio (sla_politicas.horas_respuesta según tipo y prioridad del ticket, en horas
 *    hábiles del calendario del admin), contado desde su último mensaje o desde que creó el ticket.
 *    Así deja constancia sin saturar a soporte.
 *  - Con el ticket cerrado (Aprobado / Cerrado / Descartado) ya nadie escribe.
 */
final class TicketConversacion
{
    public const ESTADOS_CERRADOS = [TicketWorkflow::APROBADO, TicketWorkflow::CERRADO, TicketWorkflow::DESCARTADO];

    public const MAX_TEXTO = 1000;

    /** Horas de respuesta si el ticket todavía no tiene política SLA aplicable. */
    private const HORAS_RESPUESTA_POR_DEFECTO = 4.0;

    /**
     * Mensajes de la conversación de varios tickets (más antiguo primero).
     * @param int[] $ids
     * @return array<int,array<int,array>> id_ticket => mensajes con 'autor', 'rol', 'es_personal'
     */
    public static function deTickets(PDO $pdo, string $tabla, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !TablaTicket::valida($tabla)) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare(
            "SELECT c.id, c.id_ticket, c.id_usuario, c.comentario, c.es_reiteracion, c.fecha_creacion,
                    COALESCE(u.nombre_completo, c.autor_externo, 'Quien reportó') AS autor, u.role AS rol
             FROM ticket_comentarios c LEFT JOIN users u ON u.id = c.id_usuario
             WHERE c.tabla_ticket = ? AND c.visibilidad = 'CONVERSACION' AND c.id_ticket IN ($marcas)
             ORDER BY c.fecha_creacion, c.id"
        );
        $st->execute(array_merge([$tabla], $ids));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $m['es_personal'] = in_array($m['rol'], [Rol::TECNICO, Rol::BECA, Rol::ADMIN], true);
            $out[(int)$m['id_ticket']][] = $m;
        }
        return $out;
    }

    public static function deTicket(PDO $pdo, string $tabla, int $idTicket): array
    {
        return self::deTickets($pdo, $tabla, [$idTicket])[$idTicket] ?? [];
    }

    /** ¿Este usuario del personal puede escribir? (el que lo tomó o la beca asignada, ticket abierto) */
    public static function personalPuedeEscribir(array $t, int $idUsuario): bool
    {
        return !in_array($t['estado'] ?? '', self::ESTADOS_CERRADOS, true)
            && TicketConsultas::becaEsEjecutora($t, $idUsuario);
    }

    /** El personal escribe al solicitante. @return array{ok:bool,mensaje:string} */
    public static function enviarPersonal(PDO $pdo, string $tabla, int $idTicket, int $idUsuario, string $texto): array
    {
        $texto = self::limpiar($texto);
        if ($texto === '') return ['ok' => false, 'mensaje' => 'Escribe un mensaje.'];
        $t = self::ticket($pdo, $tabla, $idTicket);
        if (!$t || !self::personalPuedeEscribir($t, $idUsuario)) {
            return ['ok' => false, 'mensaje' => 'Debes tomar el ticket (y que siga abierto) para escribirle a quien lo reportó.'];
        }
        self::insertar($pdo, $tabla, $idTicket, $idUsuario, null, $texto, false);
        self::avisarSolicitante($pdo, $tabla, $t, $texto);
        return ['ok' => true, 'mensaje' => 'Mensaje enviado.'];
    }

    /**
     * ¿Puede el solicitante escribir ahora? Calcula si es respuesta libre o reiteración y desde cuándo.
     * @return array{puede:bool,tipo:string,desde:?DateTimeImmutable,motivo:string}
     */
    public static function estadoSolicitante(PDO $pdo, string $tabla, array $t, ?array $mensajes = null, ?DateTimeImmutable $ahora = null): array
    {
        $ahora ??= new DateTimeImmutable('now');
        if (in_array($t['estado'] ?? '', self::ESTADOS_CERRADOS, true)) {
            return ['puede' => false, 'tipo' => 'cerrado', 'desde' => null, 'motivo' => 'El ticket ya está cerrado.'];
        }
        $mensajes ??= self::deTicket($pdo, $tabla, (int)$t['id']);
        $ultimo = $mensajes ? $mensajes[count($mensajes) - 1] : null;
        if ($ultimo && $ultimo['es_personal']) {
            return ['puede' => true, 'tipo' => 'respuesta', 'desde' => null, 'motivo' => ''];
        }
        // Sin respuesta del personal: el reloj corre desde su último mensaje (o desde la creación).
        $referencia = new DateTimeImmutable((string)($ultimo['fecha_creacion'] ?? $t['fecha_creacion']));
        $horas = self::horasRespuesta($pdo, $tabla, $t);
        $desde = CalendarioHabil::desdeBase($pdo)->sumar($referencia, (int)round($horas * 3600));
        if ($ahora >= $desde) {
            return ['puede' => true, 'tipo' => 'reiteracion', 'desde' => $desde, 'motivo' => ''];
        }
        return [
            'puede' => false, 'tipo' => 'espera', 'desde' => $desde,
            'motivo' => 'Tu solicitud está dentro del tiempo de respuesta acordado (' . self::textoHoras($horas)
                . '). Podrás reiterarla desde el ' . $desde->format('d/m/Y H:i') . '.',
        ];
    }

    /**
     * El solicitante escribe: profesor/solicitante con sesión ($idUsuario) o quien reportó por QR sin
     * sesión ($idUsuario = null, ya identificado por su código de seguimiento).
     * @return array{ok:bool,mensaje:string}
     */
    public static function enviarSolicitante(PDO $pdo, string $tabla, int $idTicket, ?int $idUsuario, string $texto, ?string $autorExterno = null): array
    {
        $texto = self::limpiar($texto);
        if ($texto === '') return ['ok' => false, 'mensaje' => 'Escribe un mensaje.'];
        $t = self::ticket($pdo, $tabla, $idTicket);
        if (!$t || !self::esDueno($tabla, $t, $idUsuario)) {
            return ['ok' => false, 'mensaje' => 'No tienes acceso a este ticket.'];
        }
        $estado = self::estadoSolicitante($pdo, $tabla, $t);
        if (!$estado['puede']) {
            return ['ok' => false, 'mensaje' => $estado['motivo']];
        }
        $reiteracion = $estado['tipo'] === 'reiteracion';
        self::insertar($pdo, $tabla, $idTicket, $idUsuario, $idUsuario ? null : self::limpiar((string)$autorExterno, 150), $texto, $reiteracion);
        self::avisarPersonal($pdo, $tabla, $t, $reiteracion);
        return ['ok' => true, 'mensaje' => $reiteracion ? 'Reiteración registrada. Soporte fue notificado.' : 'Mensaje enviado.'];
    }

    /** Dueño del ticket: profesor_id / user_id; un QR sin sesión (sin dueño) solo con $idUsuario null. */
    private static function esDueno(string $tabla, array $t, ?int $idUsuario): bool
    {
        $dueno = (int)($tabla === TablaTicket::PROFESOR ? ($t['profesor_id'] ?? 0) : ($t['user_id'] ?? 0));
        if ($idUsuario === null) {
            return $tabla === TablaTicket::NORMAL && $dueno === 0 && ($t['origen'] ?? '') === 'QR_ANONIMO';
        }
        return $dueno === $idUsuario;
    }

    private static function ticket(PDO $pdo, string $tabla, int $id): ?array
    {
        return TablaTicket::valida($tabla) ? TicketRepository::crudo($pdo, $tabla, $id) : null;
    }

    private static function insertar(PDO $pdo, string $tabla, int $idTicket, ?int $idUsuario, ?string $autorExterno, string $texto, bool $reiteracion): void
    {
        $pdo->prepare(
            "INSERT INTO ticket_comentarios (tabla_ticket, id_ticket, id_usuario, autor_externo, visibilidad, es_reiteracion, comentario)
             VALUES (?, ?, ?, ?, 'CONVERSACION', ?, ?)"
        )->execute([$tabla, $idTicket, $idUsuario, $autorExterno, $reiteracion ? 1 : 0, $texto]);
    }

    private static function limpiar(string $texto, int $max = self::MAX_TEXTO): string
    {
        $texto = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texto) ?? '');
        return mb_substr($texto, 0, $max);
    }

    /** Horas de respuesta del acuerdo de servicio aplicable a este ticket. */
    public static function horasRespuesta(PDO $pdo, string $tabla, array $t): float
    {
        $st = $pdo->prepare('SELECT tipo, prioridad FROM ticket_sla WHERE tabla_ticket = ? AND id_ticket = ?');
        $st->execute([$tabla, (int)$t['id']]);
        $sla = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sla) {
            $tipo = $tabla === TablaTicket::PROFESOR
                ? (($t['tipo_ticket'] ?? '') === 'danio' ? 'INCIDENTE' : 'REQUERIMIENTO')
                : (in_array($t['tipo_solicitud'] ?? '', ['INCIDENTE', 'REQUERIMIENTO'], true) ? $t['tipo_solicitud'] : 'INCIDENTE');
            [$imp, $urg] = SlaService::impactoUrgenciaAutomaticos($tabla, $tipo, $t['categoria'] ?? null, $t['origen'] ?? null);
            $sla = ['tipo' => $tipo, 'prioridad' => SlaService::prioridadDe($imp, $urg)];
        }
        $pol = SlaRepository::politicas($pdo);
        $horas = (float)($pol[$sla['tipo']][$sla['prioridad']]['respuesta'] ?? self::HORAS_RESPUESTA_POR_DEFECTO);
        return $horas > 0 ? $horas : self::HORAS_RESPUESTA_POR_DEFECTO;
    }

    public static function textoHoras(float $horas): string
    {
        if ($horas < 1) return (int)round($horas * 60) . ' min hábiles';
        $h = rtrim(rtrim(number_format($horas, 1, ',', ''), '0'), ',');
        return $h . ($h === '1' ? ' hora hábil' : ' horas hábiles');
    }

    private static function avisarSolicitante(PDO $pdo, string $tabla, array $t, string $texto): void
    {
        $id = (int)$t['id'];
        if ($tabla === TablaTicket::PROFESOR && !empty($t['profesor_id'])) {
            NotificationService::crear($pdo, (int)$t['profesor_id'], 'TICKET_ESTADO', "Soporte te escribió en el ticket #$id", 'tickets.php#tk-' . $id);
        } elseif ($tabla === TablaTicket::NORMAL && !empty($t['user_id'])) {
            NotificationService::crear($pdo, (int)$t['user_id'], 'TICKET_ESTADO', "Soporte te escribió en el ticket #$id", 'tickets.php#tk-' . $id);
        } elseif ($tabla === TablaTicket::NORMAL && !empty($t['correo_contacto']) && class_exists('Mailer')) {
            // QR sin sesión: el aviso va al correo institucional que dejó al reportar (si el SMTP está configurado).
            try {
                Mailer::enviar(
                    (string)$t['correo_contacto'], (string)($t['nombre_contacto'] ?? ''),
                    "Respuesta a tu reporte (código {$t['codigo_seguimiento']})",
                    "Soporte técnico respondió a tu reporte:\n\n" . $texto
                    . "\n\nPuedes contestar desde «Consultar estado de mi reporte» con tu código: {$t['codigo_seguimiento']}"
                );
            } catch (Throwable $e) {
                error_log('[TicketConversacion] No se pudo enviar el correo: ' . $e->getMessage());
            }
        }
    }

    private static function avisarPersonal(PDO $pdo, string $tabla, array $t, bool $reiteracion): void
    {
        $id = (int)$t['id'];
        $msg = $reiteracion ? "El solicitante REITERÓ el ticket #$id (venció el tiempo de respuesta)" : "Nuevo mensaje del solicitante en el ticket #$id";
        $destinos = array_unique(array_filter([(int)($t['tecnico_id'] ?? 0), (int)($t['beca_id'] ?? 0)]));
        foreach ($destinos as $idDestino) {
            NotificationService::crear($pdo, $idDestino, 'TICKET_ESTADO', $msg, 'tickets.php');
        }
        if (!$destinos && $reiteracion) {
            // Nadie lo ha tomado: la reiteración llega a todos los técnicos para que alguien lo atienda.
            // Del Departamento: a todos los técnicos; de otra dependencia, solo a quien tiene ese permiso.
            $deSistemas = TicketConsultas::esTicketDeSistemas($pdo, $tabla, $id);
            foreach (NotificationRepository::idsPorRol($pdo, Rol::TECNICO) as $idTec) {
                if ($deSistemas || UserPermissions::tiene($pdo, (int)$idTec, UserPermissions::VER_TICKETS_EXTERNOS)) {
                    NotificationService::crear($pdo, (int)$idTec, 'TICKET_ESTADO', $msg, 'tickets.php');
                }
            }
        }
    }
}
