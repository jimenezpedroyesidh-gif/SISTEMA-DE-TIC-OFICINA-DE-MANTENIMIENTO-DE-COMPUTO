<?php
/**
 * Persistencia de la tabla `notificaciones` (y consultas mínimas sobre `users`
 * necesarias para decidir a quién notificar). Solo SQL preparado.
 */
final class NotificationRepository
{
    public static function insertar(PDO $pdo, int $idUsuario, string $tipo, string $mensaje, ?string $urlDestino): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO notificaciones (id_usuario, tipo, mensaje, url_destino)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$idUsuario, $tipo, $mensaje, $urlDestino]);
    }

    /** Últimas $limite notificaciones (leídas o no), más recientes primero. */
    public static function ultimas(PDO $pdo, int $idUsuario, int $limite): array
    {
        $stmt = $pdo->prepare(
            "SELECT id_notificacion, tipo, mensaje, url_destino, leido, fecha_creacion
             FROM notificaciones
             WHERE id_usuario = ?
             ORDER BY fecha_creacion DESC
             LIMIT " . (int)$limite
        );
        $stmt->execute([$idUsuario]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Una notificación solo si es del usuario (anti-IDOR). */
    public static function deUsuario(PDO $pdo, int $idNotificacion, int $idUsuario): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM notificaciones WHERE id_notificacion = ? AND id_usuario = ?");
        $stmt->execute([$idNotificacion, $idUsuario]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function contar(PDO $pdo, int $idUsuario, bool $soloNoLeidas): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE id_usuario = ?" . ($soloNoLeidas ? " AND leido = 0" : ""));
        $stmt->execute([$idUsuario]);
        return (int)$stmt->fetchColumn();
    }

    public static function listar(PDO $pdo, int $idUsuario, bool $soloNoLeidas, int $limite, int $desde): array
    {
        $stmt = $pdo->prepare("SELECT id_notificacion, tipo, mensaje, url_destino, leido, fecha_creacion FROM notificaciones
                               WHERE id_usuario = ?" . ($soloNoLeidas ? " AND leido = 0" : "") . "
                               ORDER BY fecha_creacion DESC, id_notificacion DESC LIMIT " . (int)$limite . " OFFSET " . (int)$desde);
        $stmt->execute([$idUsuario]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function contarNoLeidas(PDO $pdo, int $idUsuario): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE id_usuario = ? AND leido = 0");
        $stmt->execute([$idUsuario]);
        return (int)$stmt->fetchColumn();
    }

    /** Solo marca si la notificación pertenece al usuario (anti-IDOR). */
    public static function marcarLeida(PDO $pdo, int $idNotificacion, int $idUsuario): bool
    {
        $stmt = $pdo->prepare(
            "UPDATE notificaciones SET leido = 1, fecha_leido = NOW()
             WHERE id_notificacion = ? AND id_usuario = ? AND leido = 0"
        );
        $stmt->execute([$idNotificacion, $idUsuario]);
        return $stmt->rowCount() > 0;
    }

    public static function marcarTodasLeidas(PDO $pdo, int $idUsuario): void
    {
        $stmt = $pdo->prepare(
            "UPDATE notificaciones SET leido = 1, fecha_leido = NOW() WHERE id_usuario = ? AND leido = 0"
        );
        $stmt->execute([$idUsuario]);
    }

    /** Ids de los usuarios ACTIVOS con ese rol. */
    public static function idsPorRol(PDO $pdo, string $rol): array
    {
        // Avisos para «todas las becas» = las que atienden tickets (no las de secretaría).
        $area = $rol === Rol::BECA ? ' AND ' . AreaBeca::condicionMantenimientoSQL($pdo) : '';
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role = ? AND estado_activo = 1" . $area);
        $stmt->execute([$rol]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array{correo:?string,nombre_completo:?string,notificar_por_correo:mixed}|false */
    public static function datosParaCorreo(PDO $pdo, int $idUsuario)
    {
        $stmt = $pdo->prepare("SELECT correo, nombre_completo, notificar_por_correo FROM users WHERE id = ?");
        $stmt->execute([$idUsuario]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
