<?php
/** Las reglas de quién ve qué las decide BusquedaGlobalService. */

final class BusquedaGlobalRepository
{
    /**
     * Tickets normales. $alcance: 'todos' | 'beca' | 'propios' (solo los del usuario).
     */
    public static function tickets(PDO $pdo, string $q, string $alcance, int $idUsuario): array
    {
        $sql = "SELECT t.id, t.nombre_dependencia, t.equipo, t.estado, 'normal' AS origen_tipo
                FROM tickets t WHERE 1=1";
        $params = [];
        if (ctype_digit($q)) {
            $sql .= " AND t.id = ?";
            $params[] = (int)$q;
        } else {
            $like = '%' . $q . '%';
            $sql .= " AND (t.nombre_dependencia LIKE ? OR t.equipo LIKE ? OR t.marca_modelo LIKE ? OR t.numero_inventario LIKE ?)";
            array_push($params, $like, $like, $like, $like);
        }
        if ($alcance === 'beca') {
            $sql = str_replace('FROM tickets t WHERE 1=1', 'FROM tickets t LEFT JOIN salas s ON t.id_sala = s.id_sala WHERE 1=1', $sql);
            $sql .= " AND " . TicketConsultas::condicionVisibleBecaTicketsSQL($idUsuario, 't', 's');
        } elseif ($alcance === 'propios') {
            $sql .= " AND t.user_id = ?";
            $params[] = $idUsuario;
        }
        $sql .= " ORDER BY t.fecha_creacion DESC LIMIT 8";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Tickets de profesor. $alcance: 'todos' | 'beca' | 'propios' (profesor_id = usuario).
     */
    public static function ticketsProfesor(PDO $pdo, string $q, string $alcance, int $idUsuario): array
    {
        $sql = "SELECT t.id, t.sala_requerimiento AS nombre_dependencia, t.sala_danio AS equipo, t.estado, 'profesor' AS origen_tipo
                FROM tickets_profesor t WHERE 1=1";
        $params = [];
        if (ctype_digit($q)) {
            $sql .= " AND t.id = ?";
            $params[] = (int)$q;
        } else {
            $like = '%' . $q . '%';
            $sql .= " AND (t.sala_requerimiento LIKE ? OR t.sala_danio LIKE ? OR t.programa LIKE ?)";
            array_push($params, $like, $like, $like);
        }
        if ($alcance === 'propios') {
            $sql .= " AND t.profesor_id = ?";
            $params[] = $idUsuario;
        } elseif ($alcance === 'beca') {
            $sql = str_replace(
                'FROM tickets_profesor t WHERE 1=1',
                'FROM tickets_profesor t LEFT JOIN salas sp ON t.id_sala = sp.id_sala LEFT JOIN salas spa ON t.id_sala_actual = spa.id_sala WHERE 1=1',
                $sql
            ) . " AND " . TicketConsultas::condicionVisibleBecaProfesorSQL($idUsuario, 't', 'sp', 'spa');
        }
        $sql .= " ORDER BY t.fecha_creacion DESC LIMIT 8";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function usuarios(PDO $pdo, string $q): array
    {
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare(
            "SELECT id, nombre_completo, codigo, role FROM users
             WHERE nombre_completo LIKE ? OR codigo LIKE ?
             ORDER BY nombre_completo LIMIT 8"
        );
        $stmt->execute([$like, $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
