<?php
/**
 * HorarioRepository — persistencia del módulo Horarios (tablas `horarios` y
 * `excepciones_horario`, más las lecturas de apoyo que pinta la pantalla).
 *
 * Solo SQL con consultas preparadas: sin sesión, sin HTML y sin reglas de
 * negocio (esas viven en HorarioService). Todo método es estático y recibe
 * $pdo, igual que TicketRepository.
 */
final class HorarioRepository
{
    // ───────────────────────── Existencia de referencias

    public static function salaExiste(PDO $pdo, int $idSala): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM salas WHERE id_sala = ?");
        $st->execute([$idSala]);
        return (bool)$st->fetchColumn();
    }

    public static function usuarioExiste(PDO $pdo, int $idUsuario): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM users WHERE id = ?");
        $st->execute([$idUsuario]);
        return (bool)$st->fetchColumn();
    }

    /** Fila mínima de un horario (id_horario, dia_semana) o null. */
    public static function buscarBasico(PDO $pdo, int $idHorario): ?array
    {
        $st = $pdo->prepare("SELECT id_horario, dia_semana, id_sala FROM horarios WHERE id_horario = ?");
        $st->execute([$idHorario]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        return $f ?: null;
    }

    // ───────────────────────── Solapamiento

    /**
     * Regla de cruces por tipo de franja (mismo día y periodo, horas que se pisan):
     *  - CLASE: la sala no puede tener dos CLASES a la vez. Los turnos de beca/técnico en esa sala NO la
     *    ocupan (son personas de soporte, no uso del salón), así que no bloquean una clase.
     *  - TURNO_BECA / TURNO_TECNICO: varias personas pueden estar en el mismo horario y la misma sala
     *    (p. ej. 3 becas de 6 a 10). Solo se rechaza si ESA MISMA PERSONA ya tiene otro turno que se cruce.
     * Devuelve 'sala' | 'persona' (qué chocó) o null si no hay cruce.
     */
    public static function cruce(PDO $pdo, string $tipo, ?int $idSala, ?int $idUsuario, int $dia, string $horaInicio, string $horaFin, ?int $idHorarioExcluir, string $periodo): ?string
    {
        $base = " AND dia_semana = ? AND periodo = ? AND hora_inicio < ? AND hora_fin > ?" . ($idHorarioExcluir ? " AND id_horario <> ?" : '');
        $p = [$dia, $periodo, $horaFin, $horaInicio];
        if ($idHorarioExcluir) $p[] = $idHorarioExcluir;
        $hay = static function (string $sql, array $params) use ($pdo): bool {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return (int)$st->fetchColumn() > 0;
        };
        if ($tipo === 'CLASE') {
            if ($idSala && $hay("SELECT COUNT(*) FROM horarios WHERE id_sala = ? AND tipo = 'CLASE'" . $base, array_merge([$idSala], $p))) return 'sala';
            if (!$idSala && $idUsuario && $hay("SELECT COUNT(*) FROM horarios WHERE id_usuario = ?" . $base, array_merge([$idUsuario], $p))) return 'persona';
            return null;
        }
        if ($idUsuario && $hay("SELECT COUNT(*) FROM horarios WHERE id_usuario = ? AND tipo IN ('TURNO_BECA','TURNO_TECNICO')" . $base, array_merge([$idUsuario], $p))) return 'persona';
        return null;
    }

    // ───────────────────────── Escritura de horarios

    /** @param array{id_sala:?int,id_usuario:?int,tipo:string,dia:int,periodo:string,hora_inicio:string,hora_fin:string,detalle:?string} $d */
    public static function insertar(PDO $pdo, array $d, int $idCreador): int
    {
        $st = $pdo->prepare(
            "INSERT INTO horarios (id_sala, id_usuario, tipo, dia_semana, periodo, hora_inicio, hora_fin, detalle, id_creador)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        $st->execute([$d['id_sala'], $d['id_usuario'], $d['tipo'], $d['dia'], $d['periodo'], $d['hora_inicio'], $d['hora_fin'], $d['detalle'], $idCreador]);
        return (int)$pdo->lastInsertId();
    }

    public static function actualizar(PDO $pdo, int $idHorario, array $d): void
    {
        $st = $pdo->prepare(
            "UPDATE horarios SET id_sala=?, id_usuario=?, tipo=?, dia_semana=?, periodo=?, hora_inicio=?, hora_fin=?, detalle=?
             WHERE id_horario=?"
        );
        $st->execute([$d['id_sala'], $d['id_usuario'], $d['tipo'], $d['dia'], $d['periodo'], $d['hora_inicio'], $d['hora_fin'], $d['detalle'], $idHorario]);
    }

    /**
     * Borra un horario junto con sus excepciones puntuales (la clave foránea de
     * excepciones_horario impedía borrar un horario que ya tuviera alguna).
     * Devuelve cuántas filas de `horarios` se borraron (0 si no existía).
     */
    public static function borrar(PDO $pdo, int $idHorario): int
    {
        return Database::transaccion(function (PDO $pdo) use ($idHorario) {
            $pdo->prepare("DELETE FROM excepciones_horario WHERE id_horario = ?")->execute([$idHorario]);
            $st = $pdo->prepare("DELETE FROM horarios WHERE id_horario = ?");
            $st->execute([$idHorario]);
            return $st->rowCount();
        }, $pdo);
    }

    /** Vacía un periodo (con las excepciones de sus horarios). Devuelve las franjas borradas. */
    public static function borrarPorPeriodo(PDO $pdo, string $periodo): int
    {
        return Database::transaccion(function (PDO $pdo) use ($periodo) {
            $pdo->prepare("DELETE FROM excepciones_horario WHERE id_horario IN (SELECT id_horario FROM horarios WHERE periodo = ?)")->execute([$periodo]);
            $st = $pdo->prepare("DELETE FROM horarios WHERE periodo = ?");
            $st->execute([$periodo]);
            return $st->rowCount();
        }, $pdo);
    }

    // ───────────────────────── Excepciones puntuales

    /**
     * Crea o actualiza (UNIQUE id_horario+fecha) la excepción de una fecha.
     * Devuelve true si se creó una fila nueva, false si se actualizó una existente.
     */
    public static function guardarExcepcion(PDO $pdo, int $idHorario, string $fecha, string $tipo, ?int $nuevaSala, ?string $nuevaInicio, ?string $nuevaFin, ?string $motivo, int $idCreador): bool
    {
        $existia = $pdo->prepare("SELECT 1 FROM excepciones_horario WHERE id_horario = ? AND fecha = ?");
        $existia->execute([$idHorario, $fecha]);
        $yaExistia = (bool)$existia->fetchColumn();
        $st = $pdo->prepare(
            "INSERT INTO excepciones_horario (id_horario, fecha, tipo_excepcion, nueva_id_sala, nueva_hora_inicio, nueva_hora_fin, motivo, id_creador)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE tipo_excepcion=VALUES(tipo_excepcion), nueva_id_sala=VALUES(nueva_id_sala),
                                      nueva_hora_inicio=VALUES(nueva_hora_inicio), nueva_hora_fin=VALUES(nueva_hora_fin),
                                      motivo=VALUES(motivo), id_creador=VALUES(id_creador)"
        );
        $st->execute([$idHorario, $fecha, $tipo, $nuevaSala, $nuevaInicio, $nuevaFin, $motivo, $idCreador]);
        return !$yaExistia;
    }

    public static function borrarExcepcion(PDO $pdo, int $idExcepcion): int
    {
        $st = $pdo->prepare("DELETE FROM excepciones_horario WHERE id_excepcion = ?");
        $st->execute([$idExcepcion]);
        return $st->rowCount();
    }

    // ───────────────────────── Permiso de edición de secretaría

    public static function rolDeUsuario(PDO $pdo, int $idUsuario)
    {
        $st = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $st->execute([$idUsuario]);
        return $st->fetchColumn();
    }

    /** ¿El admin le dio a esta secretaria el permiso de editar horarios? */
    public static function permisoSecretaria(PDO $pdo, int $idUsuario): bool
    {
        $st = $pdo->prepare("SELECT permiso_horarios_extra FROM users WHERE id = ?");
        $st->execute([$idUsuario]);
        return ((int)$st->fetchColumn()) === 1;
    }

    public static function fijarPermisoSecretaria(PDO $pdo, int $idUsuario, int $valor): void
    {
        $pdo->prepare("UPDATE users SET permiso_horarios_extra = ? WHERE id = ?")->execute([$valor, $idUsuario]);
    }

    // ───────────────────────── Lecturas de apoyo de la pantalla

    public static function salas(PDO $pdo): array
    {
        return $pdo->query("SELECT id_sala, nombre_sala, abreviatura FROM salas ORDER BY nombre_sala")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function profesores(PDO $pdo): array
    {
        return $pdo->query("SELECT id, nombre_completo FROM users WHERE role='profesor' ORDER BY nombre_completo")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function personalTurnos(PDO $pdo): array
    {
        return $pdo->query("SELECT id, nombre_completo, role FROM users WHERE role IN ('beca_trabajo','tecnico') ORDER BY role, nombre_completo")->fetchAll(PDO::FETCH_ASSOC);
    }

    // ───────────────────────── Alcance de la secretaría

    /** Salas que el admin le habilitó a una secretaria (vacío si falta la tabla). @return int[] */
    public static function salasDeSecretaria(PDO $pdo, int $idUsuario): array
    {
        try {
            $st = $pdo->prepare("SELECT id_sala FROM secretaria_salas WHERE id_usuario = ?");
            $st->execute([$idUsuario]);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            return [];
        }
    }

    /** Salas habilitadas de todas las secretarias (para la tabla del admin). @return array<int,int[]> */
    public static function salasPorSecretaria(PDO $pdo): array
    {
        $out = [];
        try {
            foreach ($pdo->query("SELECT id_usuario, id_sala FROM secretaria_salas")->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $out[(int)$f['id_usuario']][] = (int)$f['id_sala'];
            }
        } catch (PDOException $e) {
        }
        return $out;
    }

    /** Reemplaza las salas habilitadas de una secretaria (transacción la maneja quien llama). */
    public static function fijarSalasSecretaria(PDO $pdo, int $idUsuario, array $idsSala, int $idAdmin): void
    {
        $pdo->prepare("DELETE FROM secretaria_salas WHERE id_usuario = ?")->execute([$idUsuario]);
        $ins = $pdo->prepare("INSERT INTO secretaria_salas (id_usuario, id_sala, asignado_por) VALUES (?, ?, ?)");
        foreach ($idsSala as $idSala) {
            $ins->execute([$idUsuario, (int)$idSala, $idAdmin]);
        }
    }

    /** Becas asignadas a la secretaría (área «secretaria»). @return int[] */
    public static function becasDeSecretaria(PDO $pdo): array
    {
        $st = $pdo->prepare("SELECT id FROM users WHERE role = 'beca_trabajo' AND area_beca = ?");
        $st->execute([AreaBeca::SECRETARIA]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Fila del horario para revisar el alcance (sala, tipo, persona) o null. */
    public static function alcanceDeHorario(PDO $pdo, int $idHorario): ?array
    {
        $st = $pdo->prepare("SELECT id_horario, id_sala, tipo, id_usuario FROM horarios WHERE id_horario = ?");
        $st->execute([$idHorario]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function horarioDeExcepcion(PDO $pdo, int $idExcepcion): ?int
    {
        $st = $pdo->prepare("SELECT id_horario FROM excepciones_horario WHERE id_excepcion = ?");
        $st->execute([$idExcepcion]);
        $v = $st->fetchColumn();
        return $v !== false ? (int)$v : null;
    }

    /** Lanza PDOException si falta la columna permiso_horarios_extra. */
    public static function secretarias(PDO $pdo): array
    {
        return $pdo->query("SELECT id, nombre_completo, correo, permiso_horarios_extra FROM users WHERE role='secretaria' ORDER BY nombre_completo")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Listado completo (admin/técnico/secretaria) con filtros opcionales. */
    public static function listarTodos(PDO $pdo, string $filtroPeriodo, int $filtroSala, string $filtroTipo, int $filtroDia): array
    {
        $sql = "SELECT h.*, s.nombre_sala, s.abreviatura, u.nombre_completo AS usuario_nombre
                FROM horarios h
                LEFT JOIN salas s ON h.id_sala = s.id_sala
                LEFT JOIN users u ON h.id_usuario = u.id
                WHERE 1=1";
        $params = [];
        if ($filtroPeriodo !== 'TODOS') { $sql .= " AND h.periodo = ?"; $params[] = $filtroPeriodo; }
        if ($filtroSala) { $sql .= " AND h.id_sala = ?"; $params[] = $filtroSala; }
        if ($filtroTipo !== '') { $sql .= " AND h.tipo = ?"; $params[] = $filtroTipo; }
        if ($filtroDia >= 1 && $filtroDia <= 7) { $sql .= " AND h.dia_semana = ?"; $params[] = $filtroDia; }
        $sql .= " ORDER BY h.dia_semana, h.hora_inicio";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Solo el turno personal de una beca. */
    public static function listarTurnosBeca(PDO $pdo, int $idUsuario, string $periodo): array
    {
        $st = $pdo->prepare("SELECT h.*, s.nombre_sala, s.abreviatura, u.nombre_completo AS usuario_nombre
                              FROM horarios h
                              LEFT JOIN salas s ON h.id_sala = s.id_sala
                              LEFT JOIN users u ON h.id_usuario = u.id
                              WHERE h.tipo = 'TURNO_BECA' AND h.id_usuario = ? AND h.periodo = ?
                              ORDER BY h.dia_semana, h.hora_inicio");
        $st->execute([$idUsuario, $periodo]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Solo las clases propias de un profesor. */
    public static function listarClasesProfesor(PDO $pdo, int $idUsuario, string $periodo): array
    {
        $st = $pdo->prepare("SELECT h.*, s.nombre_sala, s.abreviatura
                              FROM horarios h
                              LEFT JOIN salas s ON h.id_sala = s.id_sala
                              WHERE h.id_usuario = ? AND h.tipo = 'CLASE' AND h.periodo = ?
                              ORDER BY h.dia_semana, h.hora_inicio");
        $st->execute([$idUsuario, $periodo]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mantenimientos programados de una semana. $limitarSalas = null → todas las salas;
     * [] → ninguna (profesor sin clases); [ids] → solo esas.
     */
    public static function mantenimientosEntre(PDO $pdo, string $desde, string $hasta, ?array $limitarSalas): array
    {
        $sql = "SELECT m.id_programado, m.id_sala, m.tipo, m.descripcion, m.fecha_programada, m.estado,
                       s.nombre_sala, s.abreviatura, ur.nombre_completo AS responsable_nombre
                FROM mantenimientos_programados m
                JOIN salas s ON s.id_sala = m.id_sala
                LEFT JOIN users ur ON ur.id = m.id_responsable
                WHERE m.fecha_programada BETWEEN ? AND ? AND m.estado <> 'CANCELADO'";
        $params = [$desde, $hasta];
        if ($limitarSalas !== null) {
            if ($limitarSalas) {
                $sql .= " AND m.id_sala IN (" . implode(',', array_fill(0, count($limitarSalas), '?')) . ")";
                array_push($params, ...array_values($limitarSalas));
            } else {
                $sql .= " AND 1=0";
            }
        }
        $sql .= " ORDER BY m.fecha_programada";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
