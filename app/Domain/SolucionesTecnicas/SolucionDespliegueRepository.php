<?php
/**
 * SolucionDespliegueRepository — dónde debe aplicarse cada solución técnica y dónde está aplicada de verdad:
 * objetivos (st_objetivos), estado por computadora (st_despliegues), verificaciones (st_verificaciones),
 * cobertura para el panel, vínculo con los mantenimientos programados (st_mantenimiento_detalle) y las tareas de
 * las becas. Solo SQL con consultas preparadas; las reglas viven en SolucionDespliegueService.
 */
final class SolucionDespliegueRepository
{
    /**
     * Pares (solución, equipo) que DEBEN tener la solución según sus objetivos activos: el equipo puntual, o los
     * equipos de cómputo operativos (con torre, portátil o todo en uno) que hoy están en la sala objetivo.
     */
    private const SQL_OBJETIVO =
        "SELECT o.id_solucion, e.id_equipo, e.id_sala
         FROM st_objetivos o JOIN equipos e ON e.id_equipo = o.id_equipo
         WHERE o.activo = 1 AND o.id_equipo IS NOT NULL
         UNION
         SELECT o.id_solucion, e.id_equipo, e.id_sala
         FROM st_objetivos o JOIN equipos e ON e.id_sala = o.id_sala AND e.operativo = 1
         WHERE o.activo = 1 AND o.id_equipo IS NULL
           AND EXISTS (SELECT 1 FROM componentes c WHERE c.id_equipo_actual = e.id_equipo AND c.tipo IN ('torre','portatil','todo_en_uno'))";

    /** Estados en que un mantenimiento sigue en curso (lista fija de MantenimientoAgenda, no viene del usuario). */
    private static function estadosActivosSql(): string
    {
        return "'" . implode("','", MantenimientoAgenda::ESTADOS_ACTIVOS) . "'";
    }

    private static function marcas(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    // ───────────────────────── Objetivos

    public static function objetivos(PDO $pdo, int $idSolucion): array
    {
        $st = $pdo->prepare(
            "SELECT o.id_objetivo, o.id_sala, o.id_equipo, o.creado_en, sa.nombre_sala, sa.abreviatura, e.codigo AS equipo_codigo,
                    u.nombre_completo AS creador_nombre,
                    CASE WHEN o.id_equipo IS NULL THEN
                        (SELECT COUNT(*) FROM equipos e2 WHERE e2.id_sala = o.id_sala AND e2.operativo = 1
                           AND EXISTS (SELECT 1 FROM componentes c WHERE c.id_equipo_actual = e2.id_equipo AND c.tipo IN ('torre','portatil','todo_en_uno')))
                    ELSE 1 END AS equipos
             FROM st_objetivos o
             JOIN salas sa ON sa.id_sala = o.id_sala
             JOIN users u ON u.id = o.id_creador
             LEFT JOIN equipos e ON e.id_equipo = o.id_equipo
             WHERE o.id_solucion = ? AND o.activo = 1
             ORDER BY sa.nombre_sala, o.id_equipo IS NOT NULL, e.codigo"
        );
        $st->execute([$idSolucion]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** ¿Ya hay un objetivo activo igual? (la sala completa, o ese equipo) */
    public static function objetivoActivoExiste(PDO $pdo, int $idSolucion, int $idSala, ?int $idEquipo): bool
    {
        if ($idEquipo === null) {
            $st = $pdo->prepare("SELECT 1 FROM st_objetivos WHERE id_solucion = ? AND activo = 1 AND id_sala = ? AND id_equipo IS NULL");
            $st->execute([$idSolucion, $idSala]);
        } else {
            $st = $pdo->prepare("SELECT 1 FROM st_objetivos WHERE id_solucion = ? AND activo = 1 AND id_equipo = ?");
            $st->execute([$idSolucion, $idEquipo]);
        }
        return (bool)$st->fetchColumn();
    }

    public static function insertarObjetivo(PDO $pdo, int $idSolucion, int $idSala, ?int $idEquipo, int $idUsuario): int
    {
        $pdo->prepare("INSERT INTO st_objetivos (id_solucion, id_sala, id_equipo, id_creador) VALUES (?,?,?,?)")->execute([$idSolucion, $idSala, $idEquipo, $idUsuario]);
        return (int)$pdo->lastInsertId();
    }

    /** Un objetivo activo de la solución, con nombres para el historial (false si no existe o ya se quitó). */
    public static function objetivo(PDO $pdo, int $idObjetivo, int $idSolucion)
    {
        $st = $pdo->prepare("SELECT o.*, sa.nombre_sala, e.codigo AS equipo_codigo FROM st_objetivos o JOIN salas sa ON sa.id_sala = o.id_sala LEFT JOIN equipos e ON e.id_equipo = o.id_equipo
                             WHERE o.id_objetivo = ? AND o.id_solucion = ? AND o.activo = 1");
        $st->execute([$idObjetivo, $idSolucion]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /** Se da de baja (no se borra: queda con su fecha). */
    public static function desactivarObjetivo(PDO $pdo, int $idObjetivo, int $idSolucion): int
    {
        $st = $pdo->prepare("UPDATE st_objetivos SET activo = 0, fecha_baja = NOW() WHERE id_objetivo = ? AND id_solucion = ? AND activo = 1");
        $st->execute([$idObjetivo, $idSolucion]);
        return $st->rowCount();
    }

    /** @return array<string,array{id_equipo:int,id_sala:int,codigo:string}> código en mayúsculas => equipo */
    public static function equiposPorCodigo(PDO $pdo, array $codigos): array
    {
        $codigos = array_values(array_unique(array_filter($codigos, fn($c) => $c !== '')));
        if (!$codigos) {
            return [];
        }
        $st = $pdo->prepare("SELECT id_equipo, id_sala, codigo FROM equipos WHERE codigo IN (" . self::marcas($codigos) . ")");
        $st->execute($codigos);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $out[mb_strtoupper((string)$e['codigo'])] = $e;
        }
        return $out;
    }

    // ───────────────────────── Despliegues

    /** Crea (PENDIENTE) el despliegue de cada equipo objetivo que todavía no lo tiene. Devuelve cuántos creó. */
    public static function crearPendientes(PDO $pdo): int
    {
        return (int)$pdo->exec(
            "INSERT IGNORE INTO st_despliegues (id_solucion, id_equipo, estado)
             SELECT t.id_solucion, t.id_equipo, 'PENDIENTE' FROM (" . self::SQL_OBJETIVO . ") t
             JOIN st_soluciones s ON s.id_solucion = t.id_solucion AND s.estado <> 'RETIRADA'"
        );
    }

    /**
     * Despliegues de una solución: los equipos objetivo y, además, los que la tienen aplicada aunque ya no sean
     * objetivo (en_objetivo = 0). Con el estado de su checklist si está en un mantenimiento y la última verificación.
     */
    public static function despliegues(PDO $pdo, int $idSolucion): array
    {
        $st = $pdo->prepare(
            "SELECT d.*, e.codigo AS equipo_codigo, e.descripcion AS equipo_descripcion, e.id_sala, sa.nombre_sala, sa.abreviatura,
                    v.numero AS version_numero, u.nombre_completo AS usuario_nombre, (t.id_equipo IS NOT NULL) AS en_objetivo,
                    me.estado AS estado_checklist, uv.resultado AS ultima_resultado, uv.fecha AS ultima_fecha
             FROM st_despliegues d
             JOIN equipos e ON e.id_equipo = d.id_equipo
             JOIN salas sa ON sa.id_sala = e.id_sala
             LEFT JOIN (" . self::SQL_OBJETIVO . ") t ON t.id_solucion = d.id_solucion AND t.id_equipo = d.id_equipo
             LEFT JOIN st_versiones v ON v.id_version = d.id_version
             LEFT JOIN users u ON u.id = d.id_usuario_ultimo
             LEFT JOIN mantenimiento_equipos me ON d.estado = 'PROGRAMADO' AND me.id_programado = d.id_programado AND me.id_equipo = d.id_equipo
             LEFT JOIN st_verificaciones uv ON uv.id_verificacion = (SELECT MAX(x.id_verificacion) FROM st_verificaciones x WHERE x.id_despliegue = d.id_despliegue)
             WHERE d.id_solucion = ? AND (t.id_equipo IS NOT NULL OR d.estado <> 'PENDIENTE')
             ORDER BY sa.nombre_sala, e.codigo"
        );
        $st->execute([$idSolucion]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Los despliegues pedidos que de verdad son de esa solución (nunca se confía en los ids del formulario). */
    public static function desplieguesPorIds(PDO $pdo, int $idSolucion, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $st = $pdo->prepare("SELECT d.*, e.codigo AS equipo_codigo, e.id_sala FROM st_despliegues d JOIN equipos e ON e.id_equipo = d.id_equipo
                             WHERE d.id_solucion = ? AND d.id_despliegue IN (" . self::marcas($ids) . ") ORDER BY e.codigo");
        $st->execute(array_merge([$idSolucion], $ids));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function registrarImplementacion(PDO $pdo, int $idDespliegue, int $idVersion, int $idUsuario, ?string $observaciones): void
    {
        $pdo->prepare("UPDATE st_despliegues SET estado = 'IMPLEMENTADO', id_version = ?, fecha_implementacion = NOW(), id_usuario_ultimo = ?, observaciones = ? WHERE id_despliegue = ?")
            ->execute([$idVersion, $idUsuario, $observaciones, $idDespliegue]);
    }

    public static function cambiarEstado(PDO $pdo, int $idDespliegue, string $estado, int $idUsuario, ?string $observaciones): void
    {
        $pdo->prepare("UPDATE st_despliegues SET estado = ?, id_usuario_ultimo = ?, observaciones = ? WHERE id_despliegue = ?")
            ->execute([$estado, $idUsuario, $observaciones, $idDespliegue]);
    }

    public static function marcarProgramados(PDO $pdo, array $idsDespliegue, int $idProgramado): void
    {
        if (!$idsDespliegue) {
            return;
        }
        $pdo->prepare("UPDATE st_despliegues SET estado = 'PROGRAMADO', id_programado = ? WHERE id_despliegue IN (" . self::marcas($idsDespliegue) . ")")
            ->execute(array_merge([$idProgramado], array_map('intval', $idsDespliegue)));
    }

    /** Resultado de la checklist de un mantenimiento cerrado sobre un equipo (crea el despliegue si no existía). */
    public static function aplicarResultadoChecklist(PDO $pdo, int $idSolucion, int $idEquipo, int $idVersion, string $estado, int $idProgramado, ?string $fecha, ?int $idUsuario, string $observaciones): void
    {
        $pdo->prepare(
            "INSERT INTO st_despliegues (id_solucion, id_equipo, id_version, estado, id_programado, fecha_implementacion, id_usuario_ultimo, observaciones)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id_version = VALUES(id_version), estado = VALUES(estado), id_programado = VALUES(id_programado),
                fecha_implementacion = VALUES(fecha_implementacion), id_usuario_ultimo = VALUES(id_usuario_ultimo), observaciones = VALUES(observaciones)"
        )->execute([$idSolucion, $idEquipo, $idVersion, $estado, $idProgramado, $fecha, $idUsuario, mb_substr($observaciones, 0, 500)]);
    }

    /** Lo que el mantenimiento cerrado no alcanzó a aplicar vuelve a PENDIENTE. */
    public static function devolverAPendiente(PDO $pdo, int $idSolucion, int $idProgramado, string $observaciones): int
    {
        $st = $pdo->prepare("UPDATE st_despliegues SET estado = 'PENDIENTE', observaciones = ? WHERE id_solucion = ? AND id_programado = ? AND estado = 'PROGRAMADO'");
        $st->execute([$observaciones, $idSolucion, $idProgramado]);
        return $st->rowCount();
    }

    // ───────────────────────── Verificaciones

    public static function insertarVerificacion(PDO $pdo, int $idDespliegue, ?int $idVersion, string $resultado, string $observaciones, int $idUsuario): int
    {
        $pdo->prepare("INSERT INTO st_verificaciones (id_despliegue, id_version, resultado, observaciones, id_usuario) VALUES (?,?,?,?,?)")
            ->execute([$idDespliegue, $idVersion, $resultado, $observaciones, $idUsuario]);
        return (int)$pdo->lastInsertId();
    }

    public static function verificaciones(PDO $pdo, int $idSolucion, int $limite = 200): array
    {
        $st = $pdo->prepare(
            "SELECT vf.*, e.codigo AS equipo_codigo, sa.nombre_sala, sa.abreviatura, v.numero AS version_numero, u.nombre_completo AS usuario
             FROM st_verificaciones vf
             JOIN st_despliegues d ON d.id_despliegue = vf.id_despliegue
             JOIN equipos e ON e.id_equipo = d.id_equipo
             JOIN salas sa ON sa.id_sala = e.id_sala
             JOIN users u ON u.id = vf.id_usuario
             LEFT JOIN st_versiones v ON v.id_version = vf.id_version
             WHERE d.id_solucion = ? ORDER BY vf.fecha DESC, vf.id_verificacion DESC LIMIT " . max(1, $limite)
        );
        $st->execute([$idSolucion]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ───────────────────────── Cobertura (solo soluciones ACTIVAS y equipos objetivo)

    /**
     * Cobertura agrupada por solución ('solucion') o por sala ('sala'). Cada par objetivo cae en una sola columna:
     * al_dia (implementada o verificada con la versión vigente), pendientes, programados, incidencias o
     * desactualizados (tiene una versión que ya no es la vigente). por_verificar y verificados son parte de al_dia.
     * @return array<int,array> id de la solución o de la sala => conteos
     */
    public static function cobertura(PDO $pdo, string $agrupar): array
    {
        $col = $agrupar === 'sala' ? 't.id_sala' : 't.id_solucion';
        $filas = $pdo->query(
            "SELECT $col AS id_grupo, COUNT(*) AS objetivo, COUNT(DISTINCT t.id_solucion) AS soluciones, COUNT(DISTINCT t.id_equipo) AS equipos,
                    SUM(d.estado IN ('IMPLEMENTADO','VERIFICADO') AND d.id_version = vg.id_vigente) AS al_dia,
                    SUM(d.estado = 'VERIFICADO' AND d.id_version = vg.id_vigente) AS verificados,
                    SUM(d.estado = 'IMPLEMENTADO' AND d.id_version = vg.id_vigente) AS por_verificar,
                    SUM(d.estado IS NULL OR d.estado IN ('PENDIENTE','REVERTIDO')) AS pendientes,
                    SUM(d.estado = 'PROGRAMADO') AS programados,
                    SUM(d.estado = 'CON_INCIDENCIA') AS incidencias,
                    SUM(d.estado IN ('IMPLEMENTADO','VERIFICADO') AND (vg.id_vigente IS NULL OR d.id_version <> vg.id_vigente)) AS desactualizados
             FROM (" . self::SQL_OBJETIVO . ") t
             JOIN st_soluciones s ON s.id_solucion = t.id_solucion AND s.estado = 'ACTIVA'
             LEFT JOIN (" . SolucionRepository::SQL_VIGENTE . ") vg ON vg.id_solucion = t.id_solucion
             LEFT JOIN st_despliegues d ON d.id_solucion = t.id_solucion AND d.id_equipo = t.id_equipo
             GROUP BY $col"
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($filas as $f) {
            $out[(int)$f['id_grupo']] = array_map('intval', $f);
        }
        return $out;
    }

    /** Equipos × soluciones de una sala (cobertura por equipo). */
    public static function matrizSala(PDO $pdo, int $idSala): array
    {
        $st = $pdo->prepare(
            "SELECT t.id_equipo, e.codigo AS equipo_codigo, t.id_solucion, s.codigo AS solucion_codigo, s.nombre AS solucion_nombre,
                    d.estado, d.id_version, v.numero AS version_numero, vg.id_vigente
             FROM (" . self::SQL_OBJETIVO . ") t
             JOIN equipos e ON e.id_equipo = t.id_equipo
             JOIN st_soluciones s ON s.id_solucion = t.id_solucion AND s.estado = 'ACTIVA'
             LEFT JOIN (" . SolucionRepository::SQL_VIGENTE . ") vg ON vg.id_solucion = t.id_solucion
             LEFT JOIN st_despliegues d ON d.id_solucion = t.id_solucion AND d.id_equipo = t.id_equipo
             LEFT JOIN st_versiones v ON v.id_version = d.id_version
             WHERE t.id_sala = ?
             ORDER BY e.codigo, s.codigo"
        );
        $st->execute([$idSala]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Equipos que piden atención: incidencias, versiones desactualizadas e implementaciones sin verificar. */
    public static function atencion(PDO $pdo, int $limite = 100): array
    {
        return $pdo->query(
            "SELECT d.id_despliegue, d.id_solucion, d.estado, d.actualizado_en, d.observaciones, e.codigo AS equipo_codigo, sa.nombre_sala, sa.abreviatura,
                    s.codigo, s.nombre, v.numero AS version_instalada, vv.numero AS version_vigente,
                    CASE WHEN d.estado = 'CON_INCIDENCIA' THEN 'INCIDENCIA'
                         WHEN vg.id_vigente IS NULL OR d.id_version <> vg.id_vigente THEN 'DESACTUALIZADA'
                         ELSE 'SIN_VERIFICAR' END AS motivo
             FROM (" . self::SQL_OBJETIVO . ") t
             JOIN st_despliegues d ON d.id_solucion = t.id_solucion AND d.id_equipo = t.id_equipo
             JOIN st_soluciones s ON s.id_solucion = t.id_solucion AND s.estado = 'ACTIVA'
             JOIN equipos e ON e.id_equipo = t.id_equipo
             JOIN salas sa ON sa.id_sala = t.id_sala
             LEFT JOIN (" . SolucionRepository::SQL_VIGENTE . ") vg ON vg.id_solucion = t.id_solucion
             LEFT JOIN st_versiones v ON v.id_version = d.id_version
             LEFT JOIN st_versiones vv ON vv.id_version = vg.id_vigente
             WHERE d.estado IN ('CON_INCIDENCIA','IMPLEMENTADO')
                OR (d.estado = 'VERIFICADO' AND (vg.id_vigente IS NULL OR d.id_version <> vg.id_vigente))
             ORDER BY FIELD(motivo, 'INCIDENCIA', 'DESACTUALIZADA', 'SIN_VERIFICAR'), d.actualizado_en
             LIMIT " . max(1, $limite)
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    // ───────────────────────── Mantenimientos

    public static function insertarDetalle(PDO $pdo, int $idProgramado, int $idSolucion, int $idVersion, int $idUsuario): void
    {
        $pdo->prepare("INSERT INTO st_mantenimiento_detalle (id_programado, id_solucion, id_version, id_creador) VALUES (?,?,?,?)")
            ->execute([$idProgramado, $idSolucion, $idVersion, $idUsuario]);
    }

    /** Mantenimientos ya cerrados (realizados o cancelados) cuya checklist todavía no se pasó a los despliegues. */
    public static function detallesPorSincronizar(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT d.*, m.estado AS estado_mantenimiento FROM st_mantenimiento_detalle d
             JOIN mantenimientos_programados m ON m.id_programado = d.id_programado
             WHERE d.sincronizado_en IS NULL AND m.estado IN ('REALIZADO','CANCELADO')"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Lo marca como sincronizado (atómico: si otra petición ya lo hizo devuelve 0). */
    public static function marcarSincronizado(PDO $pdo, int $idDetalle): int
    {
        $st = $pdo->prepare("UPDATE st_mantenimiento_detalle SET sincronizado_en = NOW() WHERE id_detalle = ? AND sincronizado_en IS NULL");
        $st->execute([$idDetalle]);
        return $st->rowCount();
    }

    /** Filas de la checklist de un mantenimiento. */
    public static function checklist(PDO $pdo, int $idProgramado): array
    {
        $st = $pdo->prepare("SELECT id_mant_equipo, id_equipo, estado, id_usuario_ultimo, id_usuario_asignado, fecha_actualizacion, observaciones FROM mantenimiento_equipos WHERE id_programado = ?");
        $st->execute([$idProgramado]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Mantenimientos de una solución (todos) o, con $idSolucion null, los activos de todas las soluciones. */
    public static function mantenimientos(PDO $pdo, ?int $idSolucion): array
    {
        $sql = "SELECT d.id_programado, d.id_solucion, d.sincronizado_en, v.numero AS version_numero, s.codigo, s.nombre,
                       m.id_sala, m.estado, m.fecha_programada, m.resultado, sa.nombre_sala, sa.abreviatura, r.nombre_completo AS responsable_nombre,
                       (SELECT COUNT(*) FROM mantenimiento_equipos me WHERE me.id_programado = d.id_programado AND me.estado <> 'EXCLUIDO_TRASLADO') AS total_equipos,
                       (SELECT COUNT(*) FROM mantenimiento_equipos me WHERE me.id_programado = d.id_programado AND me.estado = 'REALIZADO') AS equipos_realizados
                FROM st_mantenimiento_detalle d
                JOIN mantenimientos_programados m ON m.id_programado = d.id_programado
                JOIN st_soluciones s ON s.id_solucion = d.id_solucion
                JOIN st_versiones v ON v.id_version = d.id_version
                JOIN salas sa ON sa.id_sala = m.id_sala
                LEFT JOIN users r ON r.id = m.id_responsable";
        if ($idSolucion === null) {
            return $pdo->query($sql . " WHERE m.estado IN (" . self::estadosActivosSql() . ") ORDER BY m.fecha_programada LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        }
        $st = $pdo->prepare($sql . " WHERE d.id_solucion = ? ORDER BY m.fecha_programada DESC LIMIT 100");
        $st->execute([$idSolucion]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ───────────────────────── Tareas de la beca (equipos asignados o tomados en un mantenimiento activo)

    private static function condicionBeca(string $me): string
    {
        return "($me.id_usuario_asignado = ? OR $me.id_usuario_ultimo = ?)";
    }

    public static function tareasDeBeca(PDO $pdo, int $idUsuario): array
    {
        $st = $pdo->prepare(
            "SELECT d.id_programado, d.id_solucion, d.id_version, v.numero AS version_numero, m.fecha_programada, m.estado, m.descripcion,
                    sa.nombre_sala, sa.abreviatura, s.codigo, s.nombre, s.objetivo, r.nombre_completo AS responsable_nombre
             FROM st_mantenimiento_detalle d
             JOIN mantenimientos_programados m ON m.id_programado = d.id_programado
             JOIN st_soluciones s ON s.id_solucion = d.id_solucion
             JOIN st_versiones v ON v.id_version = d.id_version
             JOIN salas sa ON sa.id_sala = m.id_sala
             LEFT JOIN users r ON r.id = m.id_responsable
             WHERE m.estado IN (" . self::estadosActivosSql() . ")
               AND EXISTS (SELECT 1 FROM mantenimiento_equipos me WHERE me.id_programado = d.id_programado AND " . self::condicionBeca('me') . ")
             ORDER BY m.fecha_programada, d.id_programado"
        );
        $st->execute([$idUsuario, $idUsuario]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Equipos de la tarea que le tocan a la beca, con su despliegue. */
    public static function equiposDeTarea(PDO $pdo, int $idProgramado, int $idSolucion, int $idUsuario): array
    {
        $st = $pdo->prepare(
            "SELECT me.id_mant_equipo, me.estado AS estado_checklist, e.codigo AS equipo_codigo, e.descripcion AS equipo_descripcion,
                    dp.id_despliegue, dp.estado
             FROM mantenimiento_equipos me
             JOIN equipos e ON e.id_equipo = me.id_equipo
             LEFT JOIN st_despliegues dp ON dp.id_solucion = ? AND dp.id_equipo = me.id_equipo
             WHERE me.id_programado = ? AND " . self::condicionBeca('me') . "
             ORDER BY e.codigo"
        );
        $st->execute([$idSolucion, $idProgramado, $idUsuario, $idUsuario]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** ¿La beca tiene una tarea activa con esta solución? (con $idVersion: además con esa versión) */
    public static function becaPuedeVer(PDO $pdo, int $idUsuario, int $idSolucion, ?int $idVersion = null): bool
    {
        $sql = "SELECT 1 FROM st_mantenimiento_detalle d
                JOIN mantenimientos_programados m ON m.id_programado = d.id_programado
                JOIN mantenimiento_equipos me ON me.id_programado = d.id_programado
                WHERE d.id_solucion = ? AND m.estado IN (" . self::estadosActivosSql() . ") AND " . self::condicionBeca('me');
        $p = [$idSolucion, $idUsuario, $idUsuario];
        if ($idVersion !== null) {
            $sql .= ' AND d.id_version = ?';
            $p[] = $idVersion;
        }
        $st = $pdo->prepare($sql . ' LIMIT 1');
        $st->execute($p);
        return (bool)$st->fetchColumn();
    }

    /** El despliegue, solo si su equipo está en una tarea activa de la beca con esa solución (false si no). */
    public static function despliegueDeBeca(PDO $pdo, int $idDespliegue, int $idUsuario)
    {
        $st = $pdo->prepare(
            "SELECT dp.*, e.codigo AS equipo_codigo FROM st_despliegues dp JOIN equipos e ON e.id_equipo = dp.id_equipo
             WHERE dp.id_despliegue = ? AND EXISTS (
                SELECT 1 FROM st_mantenimiento_detalle d
                JOIN mantenimientos_programados m ON m.id_programado = d.id_programado
                JOIN mantenimiento_equipos me ON me.id_programado = d.id_programado AND me.id_equipo = dp.id_equipo
                WHERE d.id_solucion = dp.id_solucion AND m.estado IN (" . self::estadosActivosSql() . ") AND " . self::condicionBeca('me') . ")"
        );
        $st->execute([$idDespliegue, $idUsuario, $idUsuario]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }
}
