<?php
/**
 * Gestión de incidentes de seguridad de la información (ISO/IEC 27001 A.5.24 a A.5.28): registro,
 * clasificación (tipo, severidad y qué se afectó: confidencialidad, integridad, disponibilidad),
 * contención, causa y lección aprendida. Un ticket con categoría «Incidente de seguridad» abre su
 * registro solo y avisa al admin al instante; el admin también puede registrar uno a mano.
 */
final class IncidenteService
{
    public const TIPOS = [
        'ACCESO_NO_AUTORIZADO' => 'Acceso no autorizado', 'MALWARE' => 'Virus o software malicioso', 'PHISHING' => 'Correo o enlace engañoso (phishing)',
        'FUGA_INFORMACION' => 'Fuga o exposición de información', 'PERDIDA_EQUIPO' => 'Pérdida o robo de equipo', 'DENEGACION_SERVICIO' => 'Caída o saturación del servicio', 'OTRO' => 'Otro',
    ];
    public const ESTADOS = ['REPORTADO' => 'Reportado', 'EN_ANALISIS' => 'En análisis', 'CONTENIDO' => 'Contenido', 'CERRADO' => 'Cerrado'];
    /** Clases literales de chip por estado (el lint de CSS busca los nombres completos). */
    public const CHIP_ESTADO = ['REPORTADO' => 'chip danio', 'EN_ANALISIS' => 'chip espera', 'CONTENIDO' => 'chip proceso', 'CERRADO' => 'chip solucionado'];

    /** Crea el registro de los tickets «Incidente de seguridad» que aún no lo tienen y avisa a los admin. */
    public static function desdeTickets(PDO $pdo): int
    {
        try {
            $filas = $pdo->query("
                SELECT 'tickets' AS tabla, t.id, LEFT(COALESCE(t.descripcion, ''), 400) AS descripcion, t.fecha_creacion, t.user_id AS id_reporto
                FROM tickets t LEFT JOIN incidentes_seguridad i ON i.tabla_ticket = 'tickets' AND i.id_ticket = t.id
                WHERE t.categoria = 'SEGURIDAD' AND i.id_incidente IS NULL
                UNION ALL
                SELECT 'tickets_profesor', p.id, LEFT(COALESCE(p.descripcion_problema, p.descripcion_actividad, ''), 400), p.fecha_creacion, p.profesor_id
                FROM tickets_profesor p LEFT JOIN incidentes_seguridad i ON i.tabla_ticket = 'tickets_profesor' AND i.id_ticket = p.id
                WHERE p.categoria = 'SEGURIDAD' AND i.id_incidente IS NULL")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return 0;
        }
        foreach ($filas as $f) {
            $titulo = 'Ticket #' . (int)$f['id'] . ': ' . mb_substr(trim((string)$f['descripcion']) ?: 'incidente de seguridad', 0, 120);
            $pdo->prepare('INSERT IGNORE INTO incidentes_seguridad (tabla_ticket, id_ticket, titulo, descripcion, id_reporto, fecha_deteccion) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$f['tabla'], (int)$f['id'], $titulo, $f['descripcion'], $f['id_reporto'], $f['fecha_creacion']]);
            NotificationService::notificarAdmins($pdo, 'SISTEMA', 'Incidente de seguridad reportado: ' . mb_substr($titulo, 0, 180), 'seguridad.php?vista=incidentes');
        }
        return count($filas);
    }

    /** @return list<array<string,mixed>> */
    public static function listar(PDO $pdo, string $estado = ''): array
    {
        $sql = 'SELECT i.*, ur.nombre_completo AS reporto, ux.nombre_completo AS responsable
                FROM incidentes_seguridad i LEFT JOIN users ur ON ur.id = i.id_reporto LEFT JOIN users ux ON ux.id = i.id_responsable';
        $p = [];
        if (isset(self::ESTADOS[$estado])) { $sql .= ' WHERE i.estado = ?'; $p[] = $estado; }
        $sql .= " ORDER BY FIELD(i.estado, 'REPORTADO', 'EN_ANALISIS', 'CONTENIDO', 'CERRADO'), i.fecha_deteccion DESC";
        try {
            $st = $pdo->prepare($sql);
            $st->execute($p);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Cuántos siguen abiertos (no cerrados). */
    public static function abiertos(PDO $pdo): int
    {
        try {
            return (int)$pdo->query("SELECT COUNT(*) FROM incidentes_seguridad WHERE estado <> 'CERRADO'")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Admin y técnicos que pueden quedar a cargo de un incidente. */
    public static function responsables(PDO $pdo): array
    {
        return $pdo->query("SELECT id, nombre_completo, role FROM users WHERE estado_activo = 1 AND role IN ('admin','tecnico') ORDER BY nombre_completo")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Valida los campos comunes del formulario. @return array{0:?string,1:array<string,mixed>} [error, datos] */
    private static function datos(array $e): array
    {
        $titulo = trim((string)($e['titulo'] ?? ''));
        if ($titulo === '' || mb_strlen($titulo) > 150) return ['Escribe un título (máximo 150 caracteres).', []];
        $tipo = (string)($e['tipo'] ?? 'OTRO');
        $sev = (string)($e['severidad'] ?? 'ALTA');
        if (!isset(self::TIPOS[$tipo])) return ['Tipo de incidente no válido.', []];
        if (!isset(SlaService::PRIORIDADES[$sev])) return ['Severidad no válida.', []];
        $textos = [];
        foreach (['descripcion', 'contencion', 'causa', 'leccion'] as $c) {
            $v = trim((string)($e[$c] ?? ''));
            if (mb_strlen($v) > 5000) return ['Cada campo de texto admite máximo 5000 caracteres.', []];
            $textos[$c] = $v !== '' ? $v : null;
        }
        return [null, ['titulo' => $titulo, 'tipo' => $tipo, 'severidad' => $sev,
            'afecta_confidencialidad' => !empty($e['afecta_confidencialidad']) ? 1 : 0, 'afecta_integridad' => !empty($e['afecta_integridad']) ? 1 : 0,
            'afecta_disponibilidad' => !empty($e['afecta_disponibilidad']) ? 1 : 0, 'id_responsable' => !empty($e['id_responsable']) ? (int)$e['id_responsable'] : null] + $textos];
    }

    public static function registrar(PDO $pdo, array $e, int $idAdmin): array
    {
        [$err, $d] = self::datos($e);
        if ($err !== null) return ['ok' => false, 'mensaje' => $err];
        $pdo->prepare('INSERT INTO incidentes_seguridad (titulo, descripcion, tipo, severidad, afecta_confidencialidad, afecta_integridad, afecta_disponibilidad, contencion, causa, leccion, id_reporto, id_responsable)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$d['titulo'], $d['descripcion'], $d['tipo'], $d['severidad'], $d['afecta_confidencialidad'], $d['afecta_integridad'], $d['afecta_disponibilidad'],
                       $d['contencion'], $d['causa'], $d['leccion'], $idAdmin, $d['id_responsable']]);
        $id = (int)$pdo->lastInsertId();
        AuditoriaService::registrar($pdo, $idAdmin, 'incidentes_seguridad', $id, 'CREAR', $d['titulo']);
        return ['ok' => true, 'mensaje' => "Incidente #$id registrado."];
    }

    /** Actualiza clasificación, avance y cierre. Cerrar exige causa y lección aprendida. */
    public static function actualizar(PDO $pdo, int $id, array $e, int $idAdmin): array
    {
        [$err, $d] = self::datos($e);
        if ($err !== null) return ['ok' => false, 'mensaje' => $err];
        $estado = (string)($e['estado'] ?? 'REPORTADO');
        if (!isset(self::ESTADOS[$estado])) return ['ok' => false, 'mensaje' => 'Estado no válido.'];
        if ($estado === 'CERRADO' && ($d['causa'] === null || $d['leccion'] === null)) {
            return ['ok' => false, 'mensaje' => 'Para cerrar el incidente escribe la causa y la lección aprendida.'];
        }
        if (in_array($estado, ['CONTENIDO', 'CERRADO'], true) && $d['contencion'] === null) {
            return ['ok' => false, 'mensaje' => 'Describe las acciones de contención antes de marcarlo como contenido o cerrado.'];
        }
        $st = $pdo->prepare('SELECT estado FROM incidentes_seguridad WHERE id_incidente = ?');
        $st->execute([$id]);
        $antes = $st->fetchColumn();
        if ($antes === false) return ['ok' => false, 'mensaje' => 'Ese incidente no existe.'];
        $pdo->prepare("UPDATE incidentes_seguridad SET titulo = ?, descripcion = ?, tipo = ?, severidad = ?, afecta_confidencialidad = ?, afecta_integridad = ?,
                         afecta_disponibilidad = ?, contencion = ?, causa = ?, leccion = ?, id_responsable = ?, estado = ?,
                         fecha_contencion = IF(? IN ('CONTENIDO','CERRADO') AND fecha_contencion IS NULL, NOW(), fecha_contencion),
                         fecha_cierre = IF(? = 'CERRADO', COALESCE(fecha_cierre, NOW()), NULL)
                       WHERE id_incidente = ?")
            ->execute([$d['titulo'], $d['descripcion'], $d['tipo'], $d['severidad'], $d['afecta_confidencialidad'], $d['afecta_integridad'], $d['afecta_disponibilidad'],
                       $d['contencion'], $d['causa'], $d['leccion'], $d['id_responsable'], $estado, $estado, $estado, $id]);
        AuditoriaService::registrar($pdo, $idAdmin, 'incidentes_seguridad', $id, 'EDITAR', "estado $antes -> $estado");
        return ['ok' => true, 'mensaje' => "Incidente #$id actualizado (" . self::ESTADOS[$estado] . ').'];
    }
}
