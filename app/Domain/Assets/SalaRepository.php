<?php
/**
 * SalaRepository — persistencia de las salas y de lo que cuelga de ellas en la
 * pantalla «Salas y códigos QR»: salas_unidades, unidades_organizacionales,
 * oficina_staff y la marca es_oficina_mantenimiento.
 *
 * Solo SQL con consultas preparadas; las reglas viven en SalaService.
 * La tabla `salas` también la leen otros módulos (tickets, inventario, reportar…)
 * con sus propias consultas de solo lectura; este Repository es de escritura para Salas.
 */
final class SalaRepository
{
    // ───────────────────────── Lecturas puntuales

    public static function existe(PDO $pdo, int $idSala): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM salas WHERE id_sala = ?");
        $st->execute([$idSala]);
        return (bool)$st->fetchColumn();
    }

    /** Datos básicos de una sala (null si no existe). */
    public static function datos(PDO $pdo, int $idSala): ?array
    {
        $st = $pdo->prepare("SELECT id_sala, nombre_sala, abreviatura, dependencia FROM salas WHERE id_sala = ?");
        $st->execute([$idSala]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Nombre de una unidad organizacional ('' si no existe). */
    public static function nombreUnidad(PDO $pdo, int $idUnidad): string
    {
        $st = $pdo->prepare("SELECT nombre FROM unidades_organizacionales WHERE id_unidad = ?");
        $st->execute([$idUnidad]);
        return (string)($st->fetchColumn() ?: '');
    }

    public static function rolDeUsuario(PDO $pdo, int $idUsuario)
    {
        $st = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $st->execute([$idUsuario]);
        return $st->fetchColumn();
    }

    // ───────────────────────── Salas

    /** Crea la sala y (si se eligió) su unidad dueña, de forma atómica. Devuelve el id. Lanza PDOException. */
    public static function crear(PDO $pdo, string $nombre, string $abreviatura, ?string $dependencia, ?int $idUnidad, string $token): int
    {
        return Database::transaccion(function (PDO $pdo) use ($abreviatura, $dependencia, $idUnidad, $nombre, $token) {
            $st = $pdo->prepare("INSERT INTO salas (nombre_sala, abreviatura, dependencia, codigo_qr_token) VALUES (?, ?, ?, ?)");
            $st->execute([$nombre, $abreviatura, $dependencia, $token]);
            $id = (int)$pdo->lastInsertId();
            if ($idUnidad && $id) {
                $st = $pdo->prepare("INSERT IGNORE INTO salas_unidades (id_sala, id_unidad) VALUES (?, ?)");
                $st->execute([$id, $idUnidad]);
            }
            return $id;
        }, $pdo);
    }

    /** Bloque/edificio de la sala (filtro del historial de mantenimiento). '' = sin bloque. */
    public static function actualizarBloque(PDO $pdo, int $idSala, string $bloque): void
    {
        $pdo->prepare("UPDATE salas SET bloque = ? WHERE id_sala = ?")->execute([$bloque !== '' ? mb_substr($bloque, 0, 40) : null, $idSala]);
    }

    /** Actualiza abreviatura/dependencia y sincroniza la (única) unidad dueña. Lanza PDOException. */
    public static function actualizar(PDO $pdo, int $idSala, string $abreviatura, ?string $dependencia, ?int $idUnidad): void
    {
        Database::transaccion(function (PDO $pdo) use ($abreviatura, $dependencia, $idSala, $idUnidad) {
            $st = $pdo->prepare("UPDATE salas SET abreviatura=?, dependencia=? WHERE id_sala=?");
            $st->execute([$abreviatura, $dependencia, $idSala]);
            $st = $pdo->prepare("DELETE FROM salas_unidades WHERE id_sala = ?");
            $st->execute([$idSala]);
            if ($idUnidad) {
                $st = $pdo->prepare("INSERT IGNORE INTO salas_unidades (id_sala, id_unidad) VALUES (?, ?)");
                $st->execute([$idSala, $idUnidad]);
            }
        }, $pdo);
    }

    // ───────────────────────── Oficina de mantenimiento (única)

    public static function marcarOficina(PDO $pdo, int $idSala): void
    {
        Database::transaccion(function (PDO $pdo) use ($idSala) {
            $pdo->exec("UPDATE salas SET es_oficina_mantenimiento=0");
            $st = $pdo->prepare("UPDATE salas SET es_oficina_mantenimiento=1 WHERE id_sala=?");
            $st->execute([$idSala]);
        }, $pdo);
    }

    public static function quitarOficina(PDO $pdo): void
    {
        $pdo->exec("UPDATE salas SET es_oficina_mantenimiento=0");
    }

    // ───────────────────────── Personal de la oficina (tabla oficina_staff)

    /** Lanza PDOException si falta la tabla oficina_staff. */
    public static function asignarStaff(PDO $pdo, int $idSala, int $idUsuario, string $rolStaff): void
    {
        $st = $pdo->prepare("INSERT IGNORE INTO oficina_staff (id_sala, user_id, rol) VALUES (?, ?, ?)");
        $st->execute([$idSala, $idUsuario, $rolStaff]);
    }

    public static function quitarStaff(PDO $pdo, int $idSala, int $idUsuario): void
    {
        $st = $pdo->prepare("DELETE FROM oficina_staff WHERE id_sala=? AND user_id=?");
        $st->execute([$idSala, $idUsuario]);
    }

    // ───────────────────────── Unidades organizacionales

    /** Crea una unidad; si es la administradora del sistema, desmarca la anterior (solo puede haber una). */
    public static function crearUnidad(PDO $pdo, string $nombre, string $tipo, ?int $idPadre, int $esAdministradora): void
    {
        Database::transaccion(function (PDO $pdo) use ($esAdministradora, $idPadre, $nombre, $tipo) {
            if ($esAdministradora) {
                $pdo->exec("UPDATE unidades_organizacionales SET es_administradora_sistema=0");
            }
            $st = $pdo->prepare(
                "INSERT INTO unidades_organizacionales (nombre, tipo, id_unidad_padre, es_administradora_sistema)
                 VALUES (?, ?, ?, ?)"
            );
            $st->execute([$nombre, $tipo, $idPadre, $esAdministradora]);
        }, $pdo);
    }

    // ───────────────────────── Lecturas de la pantalla

    /** Salas con su número de equipos. */
    public static function listarConTotalEquipos(PDO $pdo): array
    {
        return $pdo->query("
            SELECT s.id_sala, s.nombre_sala, s.abreviatura, s.dependencia, s.bloque, s.codigo_qr_token, s.es_oficina_mantenimiento,
                   COUNT(e.id_equipo) AS total_equipos
            FROM salas s
            LEFT JOIN equipos e ON e.id_sala = s.id_sala
            GROUP BY s.id_sala, s.nombre_sala, s.abreviatura, s.dependencia, s.bloque, s.codigo_qr_token, s.es_oficina_mantenimiento
            ORDER BY s.dependencia, s.nombre_sala
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Clases en curso ahora mismo: id_sala => hora_fin. */
    public static function clasesEnCurso(PDO $pdo, int $dia, string $hora): array
    {
        $st = $pdo->prepare("
            SELECT id_sala, hora_fin FROM horarios
            WHERE tipo='CLASE' AND dia_semana=? AND hora_inicio <= ? AND hora_fin > ?
        ");
        $st->execute([$dia, $hora, $hora]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $out[$f['id_sala']] = $f['hora_fin'];
        }
        return $out;
    }

    /** id_sala => [equipos]. */
    public static function equiposPorSala(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query("SELECT id_sala, codigo, descripcion FROM equipos ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC) as $eq) {
            $out[$eq['id_sala']][] = $eq;
        }
        return $out;
    }

    public static function unidades(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT id_unidad, nombre, tipo, id_unidad_padre, es_administradora_sistema
             FROM unidades_organizacionales ORDER BY tipo, nombre"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** id_sala => [id_unidad, …]. */
    public static function unidadesPorSala(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query("SELECT id_sala, id_unidad FROM salas_unidades")->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $out[$f['id_sala']][] = (int)$f['id_unidad'];
        }
        return $out;
    }

    /** Técnicos y becas activos, para el selector «asignar a esta oficina». */
    public static function personalDisponible(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT id, nombre_completo, role FROM users WHERE estado_activo=1 AND (role = 'tecnico' OR (role = 'beca_trabajo' AND " . AreaBeca::condicionMantenimientoSQL($pdo) . ")) ORDER BY role, nombre_completo"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Personal asignado por sala: id_sala => filas. */
    public static function staffPorSala(PDO $pdo): array
    {
        $out = [];
        $filas = $pdo->query("
            SELECT os.id_sala, os.user_id, os.rol, u.nombre_completo
            FROM oficina_staff os
            JOIN users u ON u.id = os.user_id
            ORDER BY os.rol, u.nombre_completo
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($filas as $f) {
            $out[$f['id_sala']][] = $f;
        }
        return $out;
    }

    public static function abreviatura(PDO $pdo, int $idSala): string
    {
        $st = $pdo->prepare("SELECT abreviatura FROM salas WHERE id_sala = ?");
        $st->execute([$idSala]);
        return (string)$st->fetchColumn();
    }
}
