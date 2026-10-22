<?php
/**
 * Parámetros del sistema que administra el admin (tabla config_sistema: clave → valor): horario hábil,
 * umbrales del SLA y opciones de seguridad. Cada clave tiene un valor por defecto: si la clave no se ha
 * guardado, se usa el predeterminado.
 * Una sola consulta por petición (caché en memoria).
 */
final class ConfigSistema
{
    /** Valores por defecto. El horario es JSON: día ISO (1 = lunes … 7 = domingo) => [inicio, fin] o null. */
    public const PREDETERMINADOS = [
        'horario_semana'               => '{"1":["07:00","18:00"],"2":["07:00","18:00"],"3":["07:00","18:00"],"4":["07:00","18:00"],"5":["07:00","18:00"],"6":["07:00","12:00"],"7":null}',
        'sla_umbral_aviso'             => '75',
        'sla_meta_cumplimiento'        => '90',
        'sla_inicio_avisos'            => '',
        'sla_ultima_revision'          => '0',
        'seg_forzar_https'             => '0',
        'seg_hsts'                     => '0',
        'seg_mfa_obligatorio'          => '0',
        'seg_retencion_bitacora_dias'  => '365',
        'seg_retencion_ip_dias'        => '180',
        'seg_qr_max_por_hora'          => '5',
        'seg_qr_captcha'               => '1',
        'seg_respaldos_conservar'      => '10',
        'priv_responsable'             => 'Universidad Francisco de Paula Santander — Departamento de Sistemas e Informática',
        'priv_correo'                  => '',
    ];

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /** @return array<string,string> todas las claves con su valor efectivo */
    public static function todos(PDO $pdo): array
    {
        if (self::$cache === null) {
            $guardados = [];
            foreach ($pdo->query('SELECT clave, valor FROM config_sistema')->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                $guardados[(string)$k] = (string)$v;
            }
            self::$cache = array_merge(self::PREDETERMINADOS, $guardados);
        }
        return self::$cache;
    }

    public static function texto(PDO $pdo, string $clave): string
    {
        return (string)(self::todos($pdo)[$clave] ?? (self::PREDETERMINADOS[$clave] ?? ''));
    }

    public static function entero(PDO $pdo, string $clave): int
    {
        return (int)self::texto($pdo, $clave);
    }

    public static function activo(PDO $pdo, string $clave): bool
    {
        return self::texto($pdo, $clave) === '1';
    }

    /** Guarda una clave (crea o reemplaza). Devuelve false si no se pudo guardar. */
    public static function guardar(PDO $pdo, string $clave, string $valor, ?int $idUsuario): bool
    {
        if (!preg_match('/^[a-z_]{3,60}$/', $clave)) {
            throw new InvalidArgumentException('Clave de configuración inválida');
        }
        try {
            $pdo->prepare('INSERT INTO config_sistema (clave, valor, actualizado_en, actualizado_por) VALUES (?, ?, NOW(), ?)
                           ON DUPLICATE KEY UPDATE valor = VALUES(valor), actualizado_en = NOW(), actualizado_por = VALUES(actualizado_por)')
                ->execute([$clave, $valor, $idUsuario]);
        } catch (PDOException $e) {
            return false;
        }
        if (self::$cache !== null) self::$cache[$clave] = $valor;
        return true;
    }

    /** Olvida la caché (pruebas o tras cambios hechos por otra conexión). */
    public static function reiniciar(): void
    {
        self::$cache = null;
    }
}
