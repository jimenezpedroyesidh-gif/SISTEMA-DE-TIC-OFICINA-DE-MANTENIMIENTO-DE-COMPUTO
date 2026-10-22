<?php
/**
 * Persistencia del «Seguimiento de horas»: solo lectura de `users`, con consultas preparadas.
 * Las horas son SOLO de las becas trabajo: toda búsqueda de «a quién se consulta» se limita a role = 'beca_trabajo'.
 */
class SeguimientoHorasRepository
{
    private const COLS = 'id, nombre_completo, codigo, dependencia, role';

    public static function becaPorId(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare("SELECT " . self::COLS . " FROM users WHERE id = ? AND role = 'beca_trabajo'");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function becaPorCodigo(PDO $pdo, string $codigo): ?array
    {
        $st = $pdo->prepare("SELECT " . self::COLS . " FROM users WHERE codigo = ? AND role = 'beca_trabajo'");
        $st->execute([$codigo]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Todas las becas trabajo (listado de quien puede ver a todas). */
    public static function becas(PDO $pdo): array
    {
        return $pdo->query("SELECT " . self::COLS . " FROM users WHERE role = 'beca_trabajo' ORDER BY nombre_completo")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Becas trabajo con código institucional (PDF «todas»: sin código no hay cruce con la asistencia). */
    public static function becasConCodigo(PDO $pdo): array
    {
        return $pdo->query("SELECT " . self::COLS . " FROM users WHERE role = 'beca_trabajo' AND codigo IS NOT NULL AND codigo <> '' ORDER BY nombre_completo")->fetchAll(PDO::FETCH_ASSOC);
    }
}
