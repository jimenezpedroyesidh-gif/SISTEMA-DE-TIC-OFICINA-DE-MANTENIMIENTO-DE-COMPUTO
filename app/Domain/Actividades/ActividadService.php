<?php
/**
 * Tablero de actividades de la secretaría (actividades.php): pegatinas en tres columnas (Por hacer · En curso
 * · Hecho). La secretaria crea, edita, asigna y borra; la beca mueve sus pegatinas (y al mover una sin
 * asignar, la toma). Avisos: a la beca cuando le asignan una y a quien la creó cuando queda hecha.
 */
final class ActividadService
{
    public const ESTADOS = ['POR_HACER' => 'Por hacer', 'EN_CURSO' => 'En curso', 'HECHO' => 'Hecho'];
    public const COLORES = ['amarillo' => 'Amarillo', 'azul' => 'Azul', 'verde' => 'Verde', 'rosa' => 'Rosa', 'lila' => 'Lila'];

    private static function r(string $error = '', string $exito = ''): array
    {
        return ['error' => $error, 'exito' => $exito];
    }

    /** ¿Quién usa el tablero? secretaria = gestiona; beca = la beca de secretaría (mueve las suyas). */
    public static function permisos(string $rol, ?string $areaBeca): array
    {
        $esBeca = $rol === Rol::BECA && $areaBeca === AreaBeca::SECRETARIA;
        return ['ver' => $rol === Rol::SECRETARIA || $esBeca, 'gestionar' => $rol === Rol::SECRETARIA, 'esBeca' => $esBeca];
    }

    /** Valida los campos del formulario. Devuelve [datos, error]. */
    private static function datos(PDO $pdo, array $p): array
    {
        $titulo = mb_substr(trim((string)($p['titulo'] ?? '')), 0, 120);
        if ($titulo === '') return [null, 'Escribe un título para la actividad.'];
        $color = array_key_exists((string)($p['color'] ?? ''), self::COLORES) ? (string)$p['color'] : 'amarillo';
        $idAsig = !empty($p['id_asignado']) ? (int)$p['id_asignado'] : null;
        if ($idAsig !== null && !in_array($idAsig, array_map('intval', array_column(AreaBeca::becas($pdo, AreaBeca::SECRETARIA), 'id')), true)) {
            return [null, 'Solo se puede asignar a una beca del área de secretaría.'];
        }
        $fecha = trim((string)($p['fecha_limite'] ?? ''));
        if ($fecha !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) return [null, 'La fecha límite no es válida.'];
        return [['titulo' => $titulo, 'detalle' => mb_substr(trim((string)($p['detalle'] ?? '')), 0, 2000), 'color' => $color,
                 'id_asignado' => $idAsig, 'fecha_limite' => $fecha !== '' ? $fecha : null], ''];
    }

    public static function crear(PDO $pdo, int $idSecretaria, array $p): array
    {
        [$d, $err] = self::datos($pdo, $p);
        if ($err !== '') return self::r($err);
        $id = ActividadRepository::crear($pdo, $d, $idSecretaria);
        if ($d['id_asignado']) {
            NotificationService::crear($pdo, $d['id_asignado'], 'SISTEMA', 'Nueva actividad para ti: ' . mb_substr($d['titulo'], 0, 150), 'actividades.php#act-' . $id);
        }
        return self::r('', 'Actividad «' . $d['titulo'] . '» agregada al tablero.');
    }

    public static function editar(PDO $pdo, int $idSecretaria, int $id, array $p): array
    {
        $antes = ActividadRepository::porId($pdo, $id);
        if (!$antes) return self::r('La actividad ya no existe.');
        [$d, $err] = self::datos($pdo, $p);
        if ($err !== '') return self::r($err);
        ActividadRepository::actualizar($pdo, $id, $d);
        if ($d['id_asignado'] && (int)$d['id_asignado'] !== (int)$antes['id_asignado']) {
            NotificationService::crear($pdo, $d['id_asignado'], 'SISTEMA', 'Te asignaron la actividad: ' . mb_substr($d['titulo'], 0, 150), 'actividades.php#act-' . $id);
        }
        return self::r('', 'Actividad actualizada.');
    }

    public static function borrar(PDO $pdo, int $id): array
    {
        if (!ActividadRepository::porId($pdo, $id)) return self::r('La actividad ya no existe.');
        ActividadRepository::borrar($pdo, $id);
        return self::r('', 'Actividad eliminada del tablero.');
    }

    /**
     * Mover a otra columna. La secretaria mueve cualquiera; la beca solo las suyas o las sin asignar
     * (al moverla, la toma). Al quedar «Hecho», se avisa a quien la creó.
     */
    public static function mover(PDO $pdo, int $idUsuario, bool $gestiona, int $id, string $estado): array
    {
        if (!array_key_exists($estado, self::ESTADOS)) return self::r('Estado no válido.');
        $a = ActividadRepository::porId($pdo, $id);
        if (!$a) return self::r('La actividad ya no existe.');
        $asignado = $a['id_asignado'] !== null ? (int)$a['id_asignado'] : null;
        if (!$gestiona) {
            if ($asignado !== null && $asignado !== $idUsuario) return self::r('Esa actividad es de otra persona.');
            $asignado = $idUsuario; // la toma
        }
        ActividadRepository::mover($pdo, $id, $estado, $asignado);
        if ($estado === 'HECHO' && $a['estado'] !== 'HECHO' && (int)$a['id_creador'] !== $idUsuario) {
            NotificationService::crear($pdo, (int)$a['id_creador'], 'SISTEMA', 'Actividad terminada: ' . mb_substr((string)$a['titulo'], 0, 150), 'actividades.php#act-' . $id);
        }
        return self::r('', 'Movida a «' . self::ESTADOS[$estado] . '».');
    }
}
