<?php
/**
 * SolucionRepository — persistencia del catálogo de soluciones técnicas: soluciones, versiones y sus archivos,
 * procedimientos, evidencias e historial (tablas st_*). Solo SQL con consultas preparadas; las reglas viven en
 * SolucionService. Ninguna función actualiza ni borra el historial, las versiones aprobadas o los archivos.
 */
final class SolucionRepository
{
    /** Versión vigente de cada solución: su versión APROBADA más reciente. */
    public const SQL_VIGENTE = "SELECT id_solucion, MAX(id_version) AS id_vigente FROM st_versiones WHERE estado = 'APROBADA' GROUP BY id_solucion";

    /** Columnas de st_evidencias por las que se agrupan las evidencias (nombres fijos, nunca del formulario). */
    private const CAMPOS_EVIDENCIA = ['id_procedimiento', 'id_verificacion', 'id_despliegue'];

    // ───────────────────────── Catálogo

    public static function tipos(PDO $pdo): array
    {
        return $pdo->query("SELECT id_tipo, nombre, descripcion FROM st_tipos ORDER BY id_tipo")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function tipoExiste(PDO $pdo, int $idTipo): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM st_tipos WHERE id_tipo = ?");
        $st->execute([$idTipo]);
        return (bool)$st->fetchColumn();
    }

    public static function codigoUsado(PDO $pdo, string $codigo, int $excluirId = 0): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM st_soluciones WHERE codigo = ? AND id_solucion <> ?");
        $st->execute([$codigo, $excluirId]);
        return (bool)$st->fetchColumn();
    }

    /** Técnicos activos (responsables posibles de una solución). */
    public static function tecnicos(PDO $pdo): array
    {
        $st = $pdo->prepare("SELECT id, nombre_completo FROM users WHERE role = ? AND estado_activo = 1 ORDER BY nombre_completo");
        $st->execute([Rol::TECNICO]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function insertar(PDO $pdo, array $s, int $idCreador): int
    {
        $st = $pdo->prepare("INSERT INTO st_soluciones (codigo, nombre, id_tipo, descripcion, objetivo, id_responsable, id_creador) VALUES (?,?,?,?,?,?,?)");
        $st->execute([$s['codigo'], $s['nombre'], $s['id_tipo'], $s['descripcion'], $s['objetivo'], $s['id_responsable'], $idCreador]);
        return (int)$pdo->lastInsertId();
    }

    public static function asignarCodigo(PDO $pdo, int $idSolucion, string $codigo): void
    {
        $pdo->prepare("UPDATE st_soluciones SET codigo = ? WHERE id_solucion = ?")->execute([$codigo, $idSolucion]);
    }

    public static function actualizar(PDO $pdo, int $idSolucion, array $s): void
    {
        $pdo->prepare("UPDATE st_soluciones SET codigo = ?, nombre = ?, id_tipo = ?, descripcion = ?, objetivo = ?, id_responsable = ? WHERE id_solucion = ?")
            ->execute([$s['codigo'], $s['nombre'], $s['id_tipo'], $s['descripcion'], $s['objetivo'], $s['id_responsable'], $idSolucion]);
    }

    public static function cambiarEstado(PDO $pdo, int $idSolucion, string $estado, string $estadoAnterior): int
    {
        $st = $pdo->prepare("UPDATE st_soluciones SET estado = ? WHERE id_solucion = ? AND estado = ?");
        $st->execute([$estado, $idSolucion, $estadoAnterior]);
        return $st->rowCount();
    }

    /** Una solución con su tipo, responsable, creador y versión vigente (false si no existe). */
    public static function buscar(PDO $pdo, int $idSolucion)
    {
        $st = $pdo->prepare(
            "SELECT s.*, t.nombre AS tipo_nombre, r.nombre_completo AS responsable_nombre, c.nombre_completo AS creador_nombre,
                    v.id_version AS id_vigente, v.numero AS version_vigente
             FROM st_soluciones s
             JOIN st_tipos t ON t.id_tipo = s.id_tipo
             JOIN users c ON c.id = s.id_creador
             LEFT JOIN users r ON r.id = s.id_responsable
             LEFT JOIN (" . self::SQL_VIGENTE . ") vg ON vg.id_solucion = s.id_solucion
             LEFT JOIN st_versiones v ON v.id_version = vg.id_vigente
             WHERE s.id_solucion = ?"
        );
        $st->execute([$idSolucion]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Catálogo con filtros (estado, tipo, texto en código/nombre/descripción). Activas primero.
     * @param array{estado:string,id_tipo:int,texto:string} $f
     */
    public static function listar(PDO $pdo, array $f): array
    {
        $sql = "SELECT s.id_solucion, s.codigo, s.nombre, s.estado, s.creado_en, t.nombre AS tipo_nombre, r.nombre_completo AS responsable_nombre,
                       v.numero AS version_vigente,
                       (SELECT COUNT(*) FROM st_versiones x WHERE x.id_solucion = s.id_solucion AND x.estado = 'EN_REVISION') AS en_revision
                FROM st_soluciones s
                JOIN st_tipos t ON t.id_tipo = s.id_tipo
                LEFT JOIN users r ON r.id = s.id_responsable
                LEFT JOIN (" . self::SQL_VIGENTE . ") vg ON vg.id_solucion = s.id_solucion
                LEFT JOIN st_versiones v ON v.id_version = vg.id_vigente
                WHERE 1 = 1";
        $p = [];
        if ($f['estado'] !== '') {
            $sql .= ' AND s.estado = ?';
            $p[] = $f['estado'];
        }
        if ($f['id_tipo'] > 0) {
            $sql .= ' AND s.id_tipo = ?';
            $p[] = $f['id_tipo'];
        }
        if ($f['texto'] !== '') {
            $sql .= ' AND (s.codigo LIKE ? OR s.nombre LIKE ? OR s.descripcion LIKE ?)';
            $like = '%' . addcslashes($f['texto'], '%_\\') . '%';
            array_push($p, $like, $like, $like);
        }
        $st = $pdo->prepare($sql . " ORDER BY FIELD(s.estado, 'ACTIVA', 'BORRADOR', 'SUSPENDIDA', 'RETIRADA'), s.codigo LIMIT 300");
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ───────────────────────── Versiones y archivos

    public static function versiones(PDO $pdo, int $idSolucion): array
    {
        $st = $pdo->prepare(
            "SELECT v.*, a.nombre_completo AS autor_nombre, rv.nombre_completo AS revisor_nombre
             FROM st_versiones v JOIN users a ON a.id = v.id_autor LEFT JOIN users rv ON rv.id = v.id_revisor
             WHERE v.id_solucion = ? ORDER BY v.id_version DESC"
        );
        $st->execute([$idSolucion]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Una versión con el código y nombre de su solución (false si no existe). */
    public static function version(PDO $pdo, int $idVersion)
    {
        $st = $pdo->prepare("SELECT v.*, s.codigo, s.nombre AS solucion_nombre, s.estado AS solucion_estado FROM st_versiones v JOIN st_soluciones s ON s.id_solucion = v.id_solucion WHERE v.id_version = ?");
        $st->execute([$idVersion]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    public static function numeroUsado(PDO $pdo, int $idSolucion, string $numero): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM st_versiones WHERE id_solucion = ? AND numero = ?");
        $st->execute([$idSolucion, $numero]);
        return (bool)$st->fetchColumn();
    }

    public static function insertarVersion(PDO $pdo, int $idSolucion, string $numero, string $notas, int $idAutor): int
    {
        $pdo->prepare("INSERT INTO st_versiones (id_solucion, numero, notas_cambios, id_autor) VALUES (?,?,?,?)")->execute([$idSolucion, $numero, $notas, $idAutor]);
        return (int)$pdo->lastInsertId();
    }

    public static function insertarArchivo(PDO $pdo, int $idVersion, array $a, int $idUsuario): int
    {
        $pdo->prepare("INSERT INTO st_archivos (id_version, categoria, nombre_original, nombre_almacenado, tamano_bytes, sha256, id_usuario_subio) VALUES (?,?,?,?,?,?,?)")
            ->execute([$idVersion, $a['categoria'], $a['nombre_original'], $a['nombre_almacenado'], $a['tamano_bytes'], $a['sha256'], $idUsuario]);
        return (int)$pdo->lastInsertId();
    }

    /** Aprueba o rechaza una versión que sigue EN_REVISION. Devuelve filas afectadas (0 = ya la revisó otro). */
    public static function revisarVersion(PDO $pdo, int $idVersion, string $estado, int $idRevisor, string $observacion): int
    {
        $st = $pdo->prepare("UPDATE st_versiones SET estado = ?, id_revisor = ?, fecha_revision = NOW(), observacion_revision = ? WHERE id_version = ? AND estado = 'EN_REVISION'");
        $st->execute([$estado, $idRevisor, $observacion !== '' ? $observacion : null, $idVersion]);
        return $st->rowCount();
    }

    /** Retira una versión APROBADA (la revisión original se conserva; el motivo va al historial). */
    public static function retirarVersion(PDO $pdo, int $idVersion): int
    {
        $st = $pdo->prepare("UPDATE st_versiones SET estado = 'RETIRADA' WHERE id_version = ? AND estado = 'APROBADA'");
        $st->execute([$idVersion]);
        return $st->rowCount();
    }

    /** Versión vigente (la aprobada más reciente) o false. */
    public static function vigente(PDO $pdo, int $idSolucion)
    {
        $st = $pdo->prepare("SELECT * FROM st_versiones WHERE id_solucion = ? AND estado = 'APROBADA' ORDER BY id_version DESC LIMIT 1");
        $st->execute([$idSolucion]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array<int,array>> id_version => archivos */
    public static function archivosDeVersiones(PDO $pdo, array $idsVersion): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $idsVersion))));
        if (!$ids) {
            return [];
        }
        $st = $pdo->prepare("SELECT * FROM st_archivos WHERE id_version IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY categoria, nombre_original");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $out[(int)$a['id_version']][] = $a;
        }
        return $out;
    }

    /** Un archivo con su versión y solución (para autorizar la descarga). */
    public static function archivo(PDO $pdo, int $idArchivo)
    {
        $st = $pdo->prepare("SELECT a.*, v.id_solucion, v.estado AS version_estado FROM st_archivos a JOIN st_versiones v ON v.id_version = a.id_version WHERE a.id_archivo = ?");
        $st->execute([$idArchivo]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /** Versiones que esperan la autorización del administrador (más antiguas primero). */
    public static function versionesEnRevision(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT v.id_version, v.id_solucion, v.numero, v.creado_en, s.codigo, s.nombre, a.nombre_completo AS autor_nombre
             FROM st_versiones v JOIN st_soluciones s ON s.id_solucion = v.id_solucion JOIN users a ON a.id = v.id_autor
             WHERE v.estado = 'EN_REVISION' ORDER BY v.creado_en, v.id_version"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    // ───────────────────────── Procedimientos

    /** Procedimientos (vigentes o reemplazados) en el orden de las fases. */
    public static function procedimientos(PDO $pdo, int $idSolucion, bool $vigentes): array
    {
        $st = $pdo->prepare(
            "SELECT p.*, u.nombre_completo AS autor_nombre FROM st_procedimientos p JOIN users u ON u.id = p.id_autor
             WHERE p.id_solucion = ? AND p.vigente = ?
             ORDER BY FIELD(p.fase, 'INSTALACION', 'EJECUCION', 'VERIFICACION', 'REVERSION'), p.id_procedimiento" . ($vigentes ? '' : ' DESC')
        );
        $st->execute([$idSolucion, $vigentes ? 1 : 0]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function procedimiento(PDO $pdo, int $idProcedimiento)
    {
        $st = $pdo->prepare("SELECT * FROM st_procedimientos WHERE id_procedimiento = ?");
        $st->execute([$idProcedimiento]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    public static function insertarProcedimiento(PDO $pdo, int $idSolucion, array $p, ?int $idReemplaza, int $idAutor): int
    {
        $pdo->prepare("INSERT INTO st_procedimientos (id_solucion, fase, titulo, pasos, enlace_video, id_reemplaza, id_autor) VALUES (?,?,?,?,?,?,?)")
            ->execute([$idSolucion, $p['fase'], $p['titulo'], $p['pasos'], $p['enlace_video'], $idReemplaza, $idAutor]);
        return (int)$pdo->lastInsertId();
    }

    /** Deja de estar vigente (lo reemplaza otro o se da de baja). Devuelve filas afectadas. */
    public static function retirarProcedimiento(PDO $pdo, int $idProcedimiento, int $idSolucion): int
    {
        $st = $pdo->prepare("UPDATE st_procedimientos SET vigente = 0 WHERE id_procedimiento = ? AND id_solucion = ? AND vigente = 1");
        $st->execute([$idProcedimiento, $idSolucion]);
        return $st->rowCount();
    }

    // ───────────────────────── Evidencias

    public static function insertarEvidencia(PDO $pdo, int $idSolucion, array $e, array $destino, int $idUsuario): int
    {
        $pdo->prepare(
            "INSERT INTO st_evidencias (id_solucion, id_procedimiento, id_verificacion, id_despliegue, descripcion, nombre_original, nombre_almacenado, mime_type, tamano_bytes, id_usuario_subio)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $idSolucion, $destino['id_procedimiento'] ?? null, $destino['id_verificacion'] ?? null, $destino['id_despliegue'] ?? null,
            $destino['descripcion'] ?? null, $e['nombre_original'], $e['nombre_almacenado'], $e['mime_type'], $e['tamano_bytes'], $idUsuario,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function evidencia(PDO $pdo, int $idEvidencia)
    {
        $st = $pdo->prepare("SELECT * FROM st_evidencias WHERE id_evidencia = ?");
        $st->execute([$idEvidencia]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Evidencias agrupadas por procedimiento, verificación o despliegue.
     * @return array<int,array<int,array>> id => evidencias (más antigua primero)
     */
    public static function evidenciasPor(PDO $pdo, string $campo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !in_array($campo, self::CAMPOS_EVIDENCIA, true)) {
            return [];
        }
        $st = $pdo->prepare(
            "SELECT e.id_evidencia, e.$campo AS id_grupo, e.descripcion, e.nombre_original, e.mime_type, e.fecha_subida, e.id_usuario_subio, u.nombre_completo AS subio
             FROM st_evidencias e JOIN users u ON u.id = e.id_usuario_subio
             WHERE e.$campo IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY e.fecha_subida, e.id_evidencia"
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $out[(int)$e['id_grupo']][] = $e;
        }
        return $out;
    }

    // ───────────────────────── Historial (solo se agregan filas)

    public static function registrarHistorial(PDO $pdo, int $idSolucion, string $entidad, ?int $idEntidad, string $accion, string $detalle, ?int $idUsuario): void
    {
        $pdo->prepare("INSERT INTO st_historial (id_solucion, entidad, id_entidad, accion, detalle, id_usuario) VALUES (?,?,?,?,?,?)")
            ->execute([$idSolucion, $entidad, $idEntidad, $accion, $detalle !== '' ? $detalle : null, $idUsuario]);
    }

    public static function historial(PDO $pdo, int $idSolucion, int $limite = 300): array
    {
        $st = $pdo->prepare(
            "SELECT h.*, u.nombre_completo AS usuario FROM st_historial h LEFT JOIN users u ON u.id = h.id_usuario
             WHERE h.id_solucion = ? ORDER BY h.fecha DESC, h.id_historial DESC LIMIT " . max(1, $limite)
        );
        $st->execute([$idSolucion]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
