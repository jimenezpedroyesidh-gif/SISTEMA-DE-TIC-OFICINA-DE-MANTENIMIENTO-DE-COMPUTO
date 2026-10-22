<?php
/**
 * BecaPanelRepository
 * =============================
 * Persistencia (solo LECTURA) del panel de beca_trabajo (public/tickets.php). También agrupa dos consultas
 * pequeñas compartidas con los paneles de solicitante/profesor (datos del usuario).
 */
require_once __DIR__ . '/../../Support/validacion.php';  // correoValido(), validarTexto()
require_once __DIR__ . '/../../Support/paginacion.php';
require_once __DIR__ . '/../../Support/paginacion.php';

final class BecaPanelRepository
{
    /** IDs de los técnicos (para notificarles). */
    public static function idsTecnicos(PDO $pdo): array
    {
        return $pdo->query("SELECT id FROM users WHERE role='tecnico'")->fetchAll(PDO::FETCH_COLUMN);
    }

    /** ¿El ticket (tabla tickets) es un reporte QR anónimo? */
    public static function esReporteQrAnonimo(PDO $pdo, int $idTicket): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM tickets WHERE id=? AND origen='QR_ANONIMO'");
        $stmt->execute([$idTicket]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * «Reportes de daño de sala» de la beca de mantenimiento: en UNA sola lista los errores de sala de
     * los profesores (con sesión, desde su panel o el QR) y los reportes por QR sin sesión, más recientes
     * primero. Los requerimientos de los profesores no van aquí: los atiende el técnico.
     * Cada fila lleva '_tipo' = 'prof' (tickets_profesor) o 'qr' (tickets, QR anónimo).
     */
    public static function reportesDanio(PDO $pdo, int $becaId): array
    {
        $prof = $pdo->query("
    SELECT tp.*, prof.nombre_completo AS profesor_nombre, tec.nombre_completo AS tecnico_nombre, 'prof' AS _tipo
    FROM tickets_profesor tp
    JOIN users prof ON tp.profesor_id = prof.id
    LEFT JOIN users tec ON tp.tecnico_id = tec.id
    LEFT JOIN salas sp ON tp.id_sala = sp.id_sala
    LEFT JOIN salas spa ON tp.id_sala_actual = spa.id_sala
    WHERE tp.tipo_ticket = 'danio' AND " . TicketConsultas::condicionVisibleBecaProfesorSQL($becaId, 'tp', 'sp', 'spa'))->fetchAll(PDO::FETCH_ASSOC);
        $qr = $pdo->query("
    SELECT t.*, tec.nombre_completo AS tecnico_nombre, s.nombre_sala, 'qr' AS _tipo
    FROM tickets t
    LEFT JOIN users tec ON t.tecnico_id = tec.id
    LEFT JOIN salas s ON t.id_sala = s.id_sala
    WHERE t.origen = 'QR_ANONIMO' AND t.estado NOT IN ('Cerrado','Descartado')
      AND " . TicketConsultas::condicionVisibleBecaTicketsSQL($becaId, 't', 's'))->fetchAll(PDO::FETCH_ASSOC);
        $todos = array_merge($prof, $qr);
        usort($todos, fn($a, $b) => strcmp((string)$b['fecha_creacion'], (string)$a['fecha_creacion']));
        return $todos;
    }

    /** ¿El ticket de profesor es un error de sala (daño)? Los requerimientos son del técnico. */
    public static function esDanioProfesor(PDO $pdo, int $idTicket): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM tickets_profesor WHERE id = ? AND tipo_ticket = 'danio'");
        $stmt->execute([$idTicket]);
        return (bool)$stmt->fetchColumn();
    }

    /** Tickets creados por la propia beca (origen BECA_DIRECTO), paginado. */
    public static function ticketsCreadosPorBeca(PDO $pdo, int $becaId, int $pagina, int $porPagina): array
    {
        $sql = "
    SELECT t.*, tec.nombre_completo AS tecnico_nombre, COALESCE(mp.id_programado, mpv.id_programado) AS mantenimiento_generado
    FROM tickets t
    LEFT JOIN users tec ON t.tecnico_id = tec.id
    LEFT JOIN mantenimientos_programados mp ON mp.id_ticket_origen = t.id
    LEFT JOIN mantenimiento_vinculos mvx ON mvx.tabla_ticket = 'tickets' AND mvx.id_ticket = t.id
    LEFT JOIN mantenimientos_programados mpv ON mpv.id_programado = mvx.id_programado
    WHERE t.user_id = ? AND t.origen = 'BECA_DIRECTO'
    ORDER BY t.fecha_creacion DESC
";
        return paginar($pdo, $sql, [$becaId], $pagina, $porPagina);
    }

    /** Mantenimientos de sala activos (checklist compartida). */
    public static function mantenimientosActivos(PDO $pdo, int $becaId): array
    {
        $stmt = $pdo->prepare("
    SELECT m.id_programado, m.id_sala, m.tipo, m.alcance_equipo, m.estado, m.descripcion, m.fecha_programada, s.nombre_sala, s.abreviatura,
           r.nombre_completo AS responsable_nombre
    FROM mantenimientos_programados m
    JOIN salas s ON m.id_sala = s.id_sala
    LEFT JOIN users r ON m.id_responsable = r.id
    WHERE m.estado IN ('PENDIENTE','PROGRAMADO','REPROGRAMADO','EN_PROCESO')
      AND (m.id_responsable = ? OR m.id_responsable IS NULL OR m.id_responsable = m.id_responsable)
    ORDER BY m.fecha_programada ASC
");
        $stmt->execute([$becaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Checklist de equipos por mantenimiento: [id_programado => filas]. Solo incluye
     * mantenimientos con al menos un equipo (igual que el código original).
     */
    public static function checklistPorMantenimiento(PDO $pdo, array $mantenimientos): array
    {
        $out = [];
        if (empty($mantenimientos)) {
            return $out;
        }
        $stmt = $pdo->prepare("
        SELECT me.*, eq.codigo AS equipo_codigo, eq.descripcion AS equipo_descripcion, eq.id_sala, u.nombre_completo AS marcado_por, ua.nombre_completo AS asignado_nombre
        FROM mantenimiento_equipos me
        JOIN equipos eq ON me.id_equipo = eq.id_equipo
        LEFT JOIN users u ON me.id_usuario_ultimo = u.id
        LEFT JOIN users ua ON me.id_usuario_asignado = ua.id
        WHERE me.id_programado = ?
        ORDER BY eq.codigo
    ");
        foreach ($mantenimientos as $ma) {
            $stmt->execute([$ma['id_programado']]);
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($filas)) {
                $out[$ma['id_programado']] = $filas;
            }
        }
        return $out;
    }

    /** Piezas en bodega que se muestran por página en el panel de la beca. */
    public const BODEGA_POR_PAGINA = 30;

    /**
     * Piezas sueltas en bodega (componentes sin equipo, no dadas de baja), por páginas.
     *
     * Ahora trae una página (índice idx_comp_bodega, ya ordenado por tipo y código) y el equipo de origen de
     * SOLO esas piezas (índice idx_hist_comp_tipo). Se excluye la infraestructura registrada directo en una
     * sala (sillas, aires…: id_sala_directa), que no está «en bodega».
     *
     * @return array{datos: array, pagina: int, hay_siguiente: bool}
     */
    public static function componentesEnBodega(PDO $pdo, int $pagina = 1, int $porPagina = self::BODEGA_POR_PAGINA): array
    {
        $pagina = max(1, $pagina);
        $porPagina = max(1, $porPagina);
        $offset = ($pagina - 1) * $porPagina;
        $limite = $porPagina + 1; // una de más para saber si hay página siguiente sin contar toda la tabla
        $filas = $pdo->query("
    SELECT c.id_componente, c.tipo, c.codigo_inventario, c.marca, c.modelo, c.estado,
           ea.codigo AS equipo_anterior_codigo, sa.nombre_sala AS sala_anterior_nombre
    FROM (
        SELECT id_componente, tipo, codigo_inventario, marca, modelo, estado
        FROM componentes
        WHERE id_equipo_actual IS NULL AND estado <> 'DE_BAJA' AND id_sala_directa IS NULL
        ORDER BY tipo, codigo_inventario
        LIMIT $limite OFFSET $offset
    ) c
    LEFT JOIN equipos ea ON ea.id_equipo = (
        SELECT h.id_equipo_anterior FROM historial_componente h
        WHERE h.id_componente = c.id_componente AND h.tipo_evento = 'DESASIGNACION'
        ORDER BY h.fecha_evento DESC, h.id_historial DESC LIMIT 1
    )
    LEFT JOIN salas sa ON ea.id_sala = sa.id_sala
    ORDER BY c.tipo, c.codigo_inventario
")->fetchAll(PDO::FETCH_ASSOC);
        return [
            'datos'         => array_slice($filas, 0, $porPagina),
            'pagina'        => $pagina,
            'hay_siguiente' => count($filas) > $porPagina,
        ];
    }

    /** Datos de cabecera del usuario logueado (paneles de solicitante/profesor). */
    public static function datosUsuario(PDO $pdo, int $userId): array|false
    {
        $stmt = $pdo->prepare("SELECT nombre_completo, cargo, correo FROM users WHERE id=?");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
