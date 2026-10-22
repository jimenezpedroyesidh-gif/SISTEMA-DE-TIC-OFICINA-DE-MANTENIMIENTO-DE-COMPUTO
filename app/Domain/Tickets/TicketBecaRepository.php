<?php
/**
 * Persistencia de los tickets que crean beca/técnico/admin desde create_ticket_beca.php: cambio de pieza y
 * cambio de posición. Solo SQL con consultas preparadas.
 */
class TicketBecaRepository
{
    /** Ticket de daño de profesor (tipo 'danio') o null. */
    public static function ticketProfesorDanio(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare("SELECT id, id_sala, sala_danio, tecnico_id FROM tickets_profesor WHERE id = ? AND tipo_ticket = 'danio'");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function sala(PDO $pdo, int $idSala): ?array
    {
        $st = $pdo->prepare("SELECT id_sala, nombre_sala FROM salas WHERE id_sala=?");
        $st->execute([$idSala]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function equipo(PDO $pdo, int $idEquipo): ?array
    {
        $st = $pdo->prepare("SELECT id_equipo, id_sala, codigo FROM equipos WHERE id_equipo=?");
        $st->execute([$idEquipo]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function componente(PDO $pdo, int $idComponente): ?array
    {
        $st = $pdo->prepare("SELECT id_componente, estado, id_equipo_actual FROM componentes WHERE id_componente=?");
        $st->execute([$idComponente]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** INSERT del ticket de cambio de pieza; devuelve el id nuevo. */
    public static function crearCambioPieza(PDO $pdo, array $d): int
    {
        $pdo->prepare(
            "INSERT INTO tickets
                (user_id, tecnico_id, nombre_dependencia, ubicacion, equipo, descripcion,
                 id_sala, id_equipo, id_componente_saliente, id_componente_entrante, origen,
                 tabla_ticket_relacionado, id_ticket_relacionado)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $d['user_id'], $d['tecnico_id'], $d['nombre_sala'], $d['nombre_sala'], $d['codigo_equipo'], $d['descripcion'],
            $d['id_sala'], $d['id_equipo'], $d['id_saliente'], $d['id_entrante'], $d['origen'],
            $d['tabla_relacionado'], $d['id_relacionado'],
        ]);
        return (int)$pdo->lastInsertId();
    }

    /** INSERT del ticket de cambio de posición; devuelve el id nuevo. */
    public static function crearCambioPosicion(PDO $pdo, array $d): int
    {
        $pdo->prepare(
            "INSERT INTO tickets
                (user_id, tecnico_id, nombre_dependencia, ubicacion, equipo, descripcion,
                 id_sala, id_sala_destino, id_equipo, origen, estado)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $d['user_id'], $d['tecnico_id'], $d['nombre_sala'], $d['nombre_sala'], $d['codigo_equipo'], $d['descripcion'],
            $d['id_sala'], $d['id_sala_destino'], $d['id_equipo'], $d['origen'], $d['estado'],
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function salasParaSelect(PDO $pdo): array
    {
        return $pdo->query("SELECT id_sala, nombre_sala FROM salas ORDER BY nombre_sala")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function equiposParaSelect(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT eq.id_equipo, eq.codigo, eq.descripcion, s.nombre_sala
             FROM equipos eq JOIN salas s ON eq.id_sala = s.id_sala
             ORDER BY s.nombre_sala, eq.codigo"
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
