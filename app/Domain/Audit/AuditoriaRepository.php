<?php
/**
 * Persistencia de la auditoría (tablas `auditoria` y `auditoria_detalle_campos`).
 * Solo SQL con sentencias preparadas; las reglas (no registrar cambios vacíos,
 * no tumbar la operación principal) viven en AuditoriaService.
 */
final class AuditoriaRepository
{
    /** Inserta el encabezado y devuelve su id. */
    public static function insertar(PDO $pdo, ?int $idUsuario, string $tabla, ?int $idRegistro, string $accion, string $detalle): string
    {
        $stmt = $pdo->prepare(
            "INSERT INTO auditoria (id_usuario, tabla_afectada, id_registro, accion, detalle)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$idUsuario, $tabla, $idRegistro, $accion, $detalle]);
        return $pdo->lastInsertId();
    }

    public static function insertarCampos(PDO $pdo, $idAuditoria, array $filas): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO auditoria_detalle_campos (id_auditoria, campo, valor_anterior, valor_nuevo)
             VALUES (?, ?, ?, ?)"
        );
        foreach ($filas as [$campo, $antes, $despues]) {
            $stmt->execute([$idAuditoria, $campo, $antes, $despues]);
        }
    }

    /** Acciones de un registro, de la más reciente a la más antigua. */
    public static function accionesDeRegistro(PDO $pdo, string $tabla, int $idRegistro): array
    {
        $stmt = $pdo->prepare(
            "SELECT a.id_auditoria, a.fecha_hora, a.accion, a.detalle, u.nombre_completo AS usuario
             FROM auditoria a
             LEFT JOIN users u ON u.id = a.id_usuario
             WHERE a.tabla_afectada = ? AND a.id_registro = ?
             ORDER BY a.fecha_hora DESC"
        );
        $stmt->execute([$tabla, $idRegistro]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Campos cambiados de un conjunto de acciones. */
    public static function camposDeAcciones(PDO $pdo, array $idsAuditoria): array
    {
        $placeholders = implode(',', array_fill(0, count($idsAuditoria), '?'));
        $stmt = $pdo->prepare(
            "SELECT id_auditoria, campo, valor_anterior, valor_nuevo
             FROM auditoria_detalle_campos
             WHERE id_auditoria IN ($placeholders)"
        );
        $stmt->execute($idsAuditoria);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
