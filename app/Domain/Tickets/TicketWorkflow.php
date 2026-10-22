<?php
/**
 * Estados de un ticket (constantes que usa todo el sistema en vez del texto) y transiciones válidas entre ellos.
 * transicionar() aplica un cambio de estado con compare-and-swap; hoy lo usa la toma de tickets
 * (TicketAsignacionService::tomar).
 */
final class TicketWorkflow
{
    public const EN_ESPERA              = 'En espera';
    public const EN_PROCESO             = 'En proceso';
    public const SOLUCIONADO            = 'Solucionado';
    public const LISTO_SIN_VALIDAR      = 'Listo pero sin validar';
    public const APROBADO               = 'Aprobado';
    public const RECHAZADO              = 'Rechazado';
    public const CERRADO                = 'Cerrado';
    public const DESCARTADO             = 'Descartado';

    /**
     * Transiciones válidas por estado. Formato:
     * estado_actual => [estados_a_los_que_puede_pasar].
     *
     * 'Listo pero sin validar' está mapeado por analogía a
     * SOLUCIONADO (paso previo/alternativo a esperar aprobación);
     * confirmar en qué transición exacta del código se usa antes de
     * depender de ella en código nuevo.
     */
    private const TRANSICIONES = [
        self::EN_ESPERA         => [self::EN_PROCESO],
        // tickets.php: tomar_ticket/tomar_profesor (En espera -> En proceso)
        self::EN_PROCESO        => [self::SOLUCIONADO, self::EN_ESPERA],
        // liberar_ticket/liberar_profesor (En proceso -> En espera)
        // marcar solucionado (En proceso -> Solucionado)
        self::SOLUCIONADO       => [self::APROBADO, self::EN_PROCESO, self::DESCARTADO],
        // aprobar (dueño firma) / reabrir (rechazar solución, vuelve a
        // técnico) / descartar con motivo
        self::LISTO_SIN_VALIDAR => [self::APROBADO, self::EN_PROCESO],
        self::APROBADO          => [self::CERRADO],
        self::RECHAZADO         => [],
        self::CERRADO           => [],
        self::DESCARTADO        => [],
    ];

    public static function estadosValidos(): array
    {
        return array_keys(self::TRANSICIONES);
    }

    public static function puedeTransicionar(string $estadoActual, string $estadoNuevo): bool
    {
        return in_array($estadoNuevo, self::TRANSICIONES[$estadoActual] ?? [], true);
    }

    /**
     * Aplica una transición de estado de forma segura:
     *   - valida que la transición esté permitida,
     *   - usa el estado actual como parte del WHERE (compare-and-swap),
     *     para no pisar un cambio concurrente (ej. dos técnicos
     *     tomando el mismo ticket a la vez),
     *   - registra la auditoría genérica ya existente (tabla `auditoria`).
     *
     * $camposExtra: columnas adicionales a actualizar en el mismo UPDATE
     * (ej. ['solucion' => $texto, 'fecha_terminacion' => date(...)]).
     * Deliberadamente NO se asume que `tickets` y `tickets_profesor`
     * tengan las mismas columnas extra disponibles — eso lo decide
     * quien llama, según la tabla.
     *
     * @return bool true si el UPDATE afectó una fila (transición aplicada)
     */
    public static function transicionar(
        PDO $pdo,
        string $tabla,
        int $idTicket,
        string $estadoNuevo,
        array $camposExtra = [],
        ?int $idUsuarioQueAplica = null
    ): bool {
        if (!TablaTicket::valida($tabla)) {
            throw new InvalidArgumentException("Tabla de ticket no soportada: $tabla");
        }
        if (!in_array($estadoNuevo, self::estadosValidos(), true)) {
            throw new InvalidArgumentException("Estado destino no reconocido: $estadoNuevo");
        }

        $actual = TicketRepository::crudo($pdo, $tabla, $idTicket);
        if (!$actual) {
            return false;
        }
        if (!self::puedeTransicionar((string)$actual['estado'], $estadoNuevo)) {
            return false;
        }

        $sets = ['estado = ?'];
        $params = [$estadoNuevo];
        foreach ($camposExtra as $columna => $valor) {
            // Whitelist simple de nombre de columna para evitar cualquier
            // inyección si alguna vez $camposExtra llegara con una clave
            // no controlada por el propio código (defensa en profundidad,
            // no reemplaza validar el input antes de llegar aquí).
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string)$columna)) {
                throw new InvalidArgumentException("Nombre de columna inválido: $columna");
            }
            $sets[] = "`$columna` = ?";
            $params[] = $valor;
        }
        $params[] = $idTicket;
        $params[] = $actual['estado']; // compare-and-swap

        $sql = "UPDATE `$tabla` SET " . implode(', ', $sets) . " WHERE id = ? AND estado = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $aplicado = $stmt->rowCount() > 0;

        if ($aplicado) {
            $stmtAud = $pdo->prepare(
                "INSERT INTO auditoria (id_usuario, tabla_afectada, id_registro, accion, detalle)
                 VALUES (?, ?, ?, 'EDITAR', ?)"
            );
            $stmtAud->execute([
                $idUsuarioQueAplica,
                $tabla,
                $idTicket,
                "Transición de estado: {$actual['estado']} -> $estadoNuevo",
            ]);
        }

        return $aplicado;
    }
}
