<?php
/**
 * Acceso a los tickets de las dos tablas (`tickets` y `tickets_profesor`) como una sola entidad.
 * Normaliza solo lo que es común a ambas (id, estado, tecnico_id, fecha_creacion, sala/equipo,
 * descripción) y conserva la fila cruda en 'raw' para lo específico de cada tabla.
 */

require_once __DIR__ . '/../../Support/validacion.php';  // correoValido(), validarTexto()
require_once __DIR__ . '/../../Support/paginacion.php';

final class TicketRepository
{
    private static function validarTabla(string $tabla): void
    {
        if (!TablaTicket::valida($tabla)) {
            throw new InvalidArgumentException("Tabla de ticket no soportada: $tabla");
        }
    }

    /**
     * Fila cruda, tal cual está en la base de datos, sin normalizar.
     * Úsalo cuando necesites un campo específico de una tabla (ej.
     * `tickets_profesor.subtipo_requerimiento`) que la vista normalizada
     * no expone a propósito.
     */
    public static function crudo(PDO $pdo, string $tabla, int $id): ?array
    {
        self::validarTabla($tabla);
        $stmt = $pdo->prepare("SELECT * FROM `$tabla` WHERE id = ?");
        $stmt->execute([$id]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        return $fila ?: null;
    }

    /**
     * Vista normalizada de un ticket, independientemente de en cuál de
     * las dos tablas viva. Pensada para pantallas transversales
     * (buscador global, línea de tiempo, notificaciones) que hoy tienen
     * que preguntar "¿es de tickets o de tickets_profesor?" antes de
     * poder mostrar algo.
     *
     * @return array{
     *   tabla:string, id:int, solicitante_id:?int, tecnico_id:?int,
     *   estado:string, origen:string, tipo:string, id_sala:?int,
     *   id_equipo:?int, descripcion:string, fecha_creacion:string,
     *   firmado:bool, raw:array
     * }|null
     */
    public static function buscar(PDO $pdo, string $tabla, int $id): ?array
    {
        self::validarTabla($tabla);
        $fila = self::crudo($pdo, $tabla, $id);
        if (!$fila) {
            return null;
        }
        return self::normalizar($tabla, $fila);
    }

    private static function normalizar(string $tabla, array $fila): array
    {
        if ($tabla === TablaTicket::NORMAL) {
            return [
                'tabla'          => TablaTicket::NORMAL,
                'id'             => (int)$fila['id'],
                'solicitante_id' => $fila['user_id'] !== null ? (int)$fila['user_id'] : null,
                'tecnico_id'     => $fila['tecnico_id'] !== null ? (int)$fila['tecnico_id'] : null,
                'estado'         => (string)$fila['estado'],
                // Origen (QR_ANONIMO / PANEL_INTERNO / BECA_DIRECTO / TECNICO_DIRECTO), refinado en QR_EQUIPO vs
                // QR_SALA a partir de `id_equipo`.
                'origen'         => self::refinarOrigenTickets((string)($fila['origen'] ?? 'PANEL_INTERNO'), $fila['id_equipo'] ?? null),
                'tipo'           => strtolower((string)($fila['tipo_solicitud'] ?? 'INCIDENTE')),
                // Categoría: NULL mientras no se haya clasificado.
                'categoria'      => $fila['categoria'] ?? null,
                'id_sala'        => $fila['id_sala'] !== null ? (int)$fila['id_sala'] : null,
                'id_equipo'      => $fila['id_equipo'] !== null ? (int)$fila['id_equipo'] : null,
                'descripcion'    => (string)($fila['descripcion'] ?? ''),
                'fecha_creacion' => (string)$fila['fecha_creacion'],
                'firmado'        => (bool)($fila['firmado'] ?? false),
                'raw'            => $fila,
            ];
        }

        // tickets_profesor
        return [
            'tabla'          => TablaTicket::PROFESOR,
            'id'             => (int)$fila['id'],
            'solicitante_id' => $fila['profesor_id'] !== null ? (int)$fila['profesor_id'] : null,
            'tecnico_id'     => $fila['tecnico_id'] !== null ? (int)$fila['tecnico_id'] : null,
            'estado'         => (string)$fila['estado'],
            // Origen: PANEL_PROFESOR o QR_PROFESOR.
            'origen'         => (string)($fila['origen'] ?? 'PANEL_PROFESOR'),
            'tipo'           => (string)$fila['tipo_ticket'],
            'categoria'      => $fila['categoria'] ?? null,
            // `tickets_profesor` no referencia `id_equipo` puntual, solo
            // sala (columnas de texto libre `sala_danio`/`salon` o
            // `sala_requerimiento`, más `id_horario_referencia`). Se dejan
            // en null aquí a propósito; quien necesite ese detalle debe
            // ir a 'raw'.
            'id_sala'        => null,
            'id_equipo'      => null,
            'descripcion'    => (string)($fila['descripcion_problema'] ?? $fila['descripcion_actividad'] ?? ''),
            'fecha_creacion' => (string)$fila['fecha_creacion'],
            'firmado'        => (bool)($fila['firmado'] ?? false),
            'raw'            => $fila,
        ];
    }

    /**
     * `tickets.origen` distingue QR_ANONIMO/PANEL_INTERNO/BECA_DIRECTO/
     * TECNICO_DIRECTO. Para distinguir además QR_EQUIPO de QR_SALA dentro
     * de un reporte QR, no se necesita columna nueva: ya es 100%
     * derivable de `id_equipo` (NULL = reporte general de la sala, no
     * NULL = equipo puntual marcado). Se resuelve aquí, en lectura, en
     * vez de duplicar
     * el dato en el esquema.
     */
    private static function refinarOrigenTickets(string $origen, $idEquipo): string
    {
        if ($origen === 'QR_ANONIMO') {
            return $idEquipo !== null ? 'QR_EQUIPO' : 'QR_SALA';
        }
        return $origen;
    }

    // ===================== Creación de tickets de profesor =====================

    /** Columnas de tickets_profesor que la creación de un ticket de profesor puede escribir. */
    private const COLUMNAS_INSERT_PROFESOR = [
        'profesor_id', 'tipo_ticket', 'subtipo_requerimiento', 'id_horario_referencia', 'id_sala_actual', 'sala_actual_nombre',
        'id_sala', 'sala_requerimiento', 'motivo', 'programa', 'descripcion_actividad', 'cantidad_alumnos', 'para_que',
        'fecha_requerida', 'duracion_prestamo', 'fecha_devolucion_prevista', 'sala_danio', 'descripcion_problema',
    ];

    /** Inserta un ticket de profesor. Los nombres de columna salen de una lista blanca; los valores van preparados. */
    public static function insertarProfesor(PDO $pdo, array $fila): int
    {
        $cols = array_keys($fila);
        foreach ($cols as $c) {
            if (!in_array($c, self::COLUMNAS_INSERT_PROFESOR, true)) {
                throw new InvalidArgumentException("Columna no permitida: $c");
            }
        }
        $pdo->prepare('INSERT INTO tickets_profesor (' . implode(', ', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($fila));
        return (int)$pdo->lastInsertId();
    }

    /** Clases (horarios tipo CLASE) de un profesor: alimentan el select «Horario actual» del cambio de sala. */
    public static function horariosDeClaseDelProfesor(PDO $pdo, int $idProfesor): array
    {
        $s = $pdo->prepare("
            SELECT h.id_horario, h.dia_semana, h.hora_inicio, h.hora_fin, h.detalle,
                   h.id_sala, s.nombre_sala
            FROM horarios h
            LEFT JOIN salas s ON h.id_sala = s.id_sala
            WHERE h.id_usuario = ? AND h.tipo = 'CLASE'
            ORDER BY h.dia_semana, h.hora_inicio
        ");
        $s->execute([$idProfesor]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Salas reales (id_sala, nombre_sala) para ligar el ticket a una sala de verdad. */
    public static function salasParaSelect(PDO $pdo): array
    {
        return $pdo->query("SELECT id_sala, nombre_sala FROM salas ORDER BY nombre_sala")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Salas de UNA dependencia (para el admin cuando pide algo como docente: solo las de su dependencia).
     * Sin dependencia registrada (o si es el Departamento de Sistemas) se toman las salas del Departamento.
     */
    public static function salasDeDependencia(PDO $pdo, ?string $dependencia): array
    {
        $dependencia = trim((string)$dependencia);
        // El Departamento se escribe de varias formas («Departamento de Sistemas», «… e Informática»): mismo
        // criterio que las bandejas (TicketConsultas::esDependenciaSistemas).
        if ($dependencia === '' || TicketConsultas::esDependenciaSistemas($dependencia)) {
            return $pdo->query("SELECT id_sala, nombre_sala FROM salas WHERE COALESCE(dependencia,'') LIKE '%sistemas%' ORDER BY nombre_sala")->fetchAll(PDO::FETCH_ASSOC);
        }
        $st = $pdo->prepare("SELECT id_sala, nombre_sala FROM salas WHERE dependencia = ? ORDER BY nombre_sala");
        $st->execute([$dependencia]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Dependencia registrada en el perfil de un usuario (null si no tiene). */
    public static function dependenciaDeUsuario(PDO $pdo, int $idUsuario): ?string
    {
        $st = $pdo->prepare("SELECT dependencia FROM users WHERE id = ?");
        $st->execute([$idUsuario]);
        $v = $st->fetchColumn();
        return $v !== false && $v !== null && trim((string)$v) !== '' ? (string)$v : null;
    }

    /** Nombre de la sala marcada como oficina de mantenimiento (o null si no hay). */
    public static function nombreOficinaMantenimiento(PDO $pdo): ?string
    {
        $v = $pdo->query("SELECT nombre_sala FROM salas WHERE es_oficina_mantenimiento = 1 LIMIT 1")->fetchColumn();
        return $v !== false ? (string)$v : null;
    }

}
