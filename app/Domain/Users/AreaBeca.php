<?php
/**
 * Área de trabajo de una beca trabajo del Departamento de Sistemas (users.area_beca). Solo el admin
 * la asigna (Usuarios → editar). Define qué ve la beca:
 *  - mantenimiento (o sin asignar, como estaba antes): reportes de daño de sala, bodega, checklist…
 *  - secretaria: no ve tickets; solo Tablero de actividades, Mi turno y Mis horas.
 *  - redes: reservada para el futuro (se muestra deshabilitada en el formulario).
 */
final class AreaBeca
{
    public const MANTENIMIENTO = 'mantenimiento';
    public const SECRETARIA = 'secretaria';
    public const REDES = 'redes';
    /** Áreas que el admin puede asignar hoy. */
    public const DISPONIBLES = [self::MANTENIMIENTO, self::SECRETARIA];
    public const ETIQUETAS = [
        self::MANTENIMIENTO => 'Oficina de mantenimiento',
        self::SECRETARIA => 'Secretaría',
        self::REDES => 'Redes',
    ];
    /** Páginas que puede abrir una beca de secretaría (todo lo demás la lleva a su tablero). */
    public const PAGINAS_SECRETARIA = ['actividades.php', 'horarios.php', 'seguimiento_horas.php', 'seguimiento_horas_pdf.php',
        'perfil.php', 'cambiar_clave.php', 'logout.php', 'notificacion_marcar_leida.php', 'mis_notificaciones.php', 'verificacion.php'];

    /** Área efectiva de un usuario beca (sin asignar = mantenimiento, como hasta ahora). */
    public static function de(PDO $pdo, int $idUsuario): string
    {
        $st = $pdo->prepare('SELECT area_beca FROM users WHERE id = ?');
        $st->execute([$idUsuario]);
        $a = (string)($st->fetchColumn() ?: '');
        return isset(self::ETIQUETAS[$a]) ? $a : self::MANTENIMIENTO;
    }

    public static function etiqueta(?string $area): string
    {
        return self::ETIQUETAS[$area ?: self::MANTENIMIENTO] ?? self::ETIQUETAS[self::MANTENIMIENTO];
    }

    /** null = valor válido para guardar; string = mensaje de error. */
    public static function validar(string $area): ?string
    {
        if ($area === self::REDES) return 'El área de Redes todavía no está habilitada.';
        return in_array($area, self::DISPONIBLES, true) ? null : 'Área de la beca no válida.';
    }

    public static function guardar(PDO $pdo, int $idUsuario, ?string $area): void
    {
        $pdo->prepare('UPDATE users SET area_beca = ? WHERE id = ?')->execute([$area, $idUsuario]);
    }

    /**
     * Condición SQL «la beca atiende tickets» (área mantenimiento o sin asignar) para filtrar listas de
     * becas asignables y notificaciones. $alias: alias de la tabla users en la consulta ('' = sin alias).
     */
    public static function condicionMantenimientoSQL(PDO $pdo, string $alias = ''): string
    {
        $col = ($alias !== '' ? $alias . '.' : '') . 'area_beca';
        return "COALESCE($col, 'mantenimiento') = 'mantenimiento'";
    }

    /** Becas activas por área (para el tablero de la secretaría). */
    public static function becas(PDO $pdo, string $area): array
    {
        $st = $pdo->prepare("SELECT id, nombre_completo FROM users WHERE role = 'beca_trabajo' AND estado_activo = 1 AND area_beca = ? ORDER BY nombre_completo");
        $st->execute([$area]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
