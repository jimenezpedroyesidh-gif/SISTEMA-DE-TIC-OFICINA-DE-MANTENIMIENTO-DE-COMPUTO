<?php
/**
 * UserRepository — SQL (solo consultas preparadas) de la administración de usuarios
 * (public/usuarios.php y public/register.php). Sin reglas de negocio ni HTML.
 * Los permisos de secretaria viven en UserPermissions y el bloqueo de login en LoginThrottle.
 */
final class UserRepository
{
    private const COLUMNAS_LISTADO = "id, codigo, correo, nombre_completo, cargo, %s role, estado_activo, password_temporal, online";

    /** Todos los usuarios por nombre. */
    public static function listar(PDO $pdo): array
    {
        return $pdo->query("SELECT " . sprintf(self::COLUMNAS_LISTADO, 'dependencia, area_beca,') . " FROM users ORDER BY nombre_completo ASC")->fetchAll();
    }

    /** ids de usuarios con el login bloqueado (id => posición). */
    public static function idsBloqueados(PDO $pdo): array
    {
        return array_flip($pdo->query("SELECT id_usuario FROM usuario_bloqueo WHERE bloqueado = 1")->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function porId(PDO $pdo, int $id): ?array
    {
        $s = $pdo->prepare("SELECT * FROM users WHERE id=?");
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** Alterna estado_activo (1 ↔ 0). */
    public static function alternarEstado(PDO $pdo, int $id): void
    {
        $pdo->prepare("UPDATE users SET estado_activo = 1 - estado_activo WHERE id=?")->execute([$id]);
    }

    /** Unidades organizacionales para el desplegable de dependencia. */
    public static function unidades(PDO $pdo): array
    {
        return $pdo->query("SELECT id_unidad, nombre, tipo FROM unidades_organizacionales ORDER BY tipo, nombre")->fetchAll();
    }

    /** ¿Otro usuario (distinto de $excluirId) ya usa esa cédula, código o correo? */
    public static function existeDuplicado(PDO $pdo, string $codigo, string $correo, int $excluirId): bool
    {
        $s = $pdo->prepare("SELECT id FROM users WHERE (codigo=? OR correo=?) AND id != ?");
        $s->execute([$codigo, $correo, $excluirId]);
        return (bool)$s->fetch();
    }

    /** Actualiza los datos de un usuario. @throws PDOException */
    public static function actualizar(PDO $pdo, int $id, array $d): void
    {
        $pdo->prepare("UPDATE users SET codigo=?, correo=?, nombre_completo=?, cargo=?, dependencia=?, id_unidad=?, role=?, estado_activo=? WHERE id=?")
            ->execute([$d['codigo'], $d['correo'], $d['nombre_completo'], $d['cargo'], $d['dependencia'], $d['id_unidad'], $d['role'], $d['estado_activo'], $id]);
    }

    /** Alta con clave temporal ya hasheada. @return int id nuevo @throws PDOException */
    public static function insertar(PDO $pdo, array $d, string $hash): int
    {
        $pdo->prepare("INSERT INTO users (codigo, correo, password, password_temporal, role, nombre_completo, cargo, dependencia, id_unidad, estado_activo) VALUES (?,?,?,1,?,?,?,?,?,?)")
            ->execute([$d['codigo'], $d['correo'], $hash, $d['role'], $d['nombre_completo'], $d['cargo'], $d['dependencia'], $d['id_unidad'], $d['estado_activo']]);
        return (int)$pdo->lastInsertId();
    }

    /** Guarda una clave (hash) temporal: la persona deberá cambiarla al entrar. */
    public static function guardarClaveTemporal(PDO $pdo, int $id, string $hash): void
    {
        $pdo->prepare("UPDATE users SET password=?, password_temporal=1 WHERE id=?")->execute([$hash, $id]);
    }

    // Cuenta propia: login, logout, cambio de clave y foto de perfil

    /** Usuario por correo (único), o null. Se usa en el login para contar los fallos de esa cuenta. */
    public static function porCorreo(PDO $pdo, string $correo): ?array
    {
        $s = $pdo->prepare("SELECT * FROM users WHERE correo=?");
        $s->execute([$correo]);
        return $s->fetch() ?: null;
    }

    /** Datos del usuario para el header/sidebar/perfil. */
    public static function datosShell(PDO $pdo, int $userId): ?array
    {
        $sql = "
            SELECT u.id, u.codigo, u.correo, u.role, u.nombre_completo,
                   u.cargo, u.foto_perfil, u.dependencia, u.id_unidad,
                   un.nombre AS unidad_nombre, un.tipo AS unidad_tipo
            FROM users u
            LEFT JOIN unidades_organizacionales un ON u.id_unidad = un.id_unidad
            WHERE u.id = ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function marcarOffline(PDO $pdo, int $id): void
    {
        $pdo->prepare("UPDATE users SET online = 0 WHERE id = ?")->execute([$id]);
    }

    public static function claveHash(PDO $pdo, int $id): string
    {
        $s = $pdo->prepare("SELECT password FROM users WHERE id=?");
        $s->execute([$id]);
        return (string)$s->fetchColumn();
    }

    /** Guarda la clave definitiva elegida por la persona (y quita la marca de clave temporal). */
    public static function guardarClavePropia(PDO $pdo, int $id, string $hash): void
    {
        $pdo->prepare("UPDATE users SET password=?, password_temporal=0 WHERE id=?")->execute([$hash, $id]);
    }

    public static function fotoPerfil(PDO $pdo, int $id): ?string
    {
        $s = $pdo->prepare("SELECT foto_perfil FROM users WHERE id=?");
        $s->execute([$id]);
        $f = $s->fetchColumn();
        return $f ? (string)$f : null;
    }

    public static function guardarFotoPerfil(PDO $pdo, int $id, ?string $nombre): void
    {
        $pdo->prepare("UPDATE users SET foto_perfil=? WHERE id=?")->execute([$nombre, $id]);
    }
}
