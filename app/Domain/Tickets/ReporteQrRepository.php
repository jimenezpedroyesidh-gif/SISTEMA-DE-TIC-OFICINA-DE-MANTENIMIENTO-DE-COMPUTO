<?php
/** Solo SQL con consultas preparadas. */
class ReporteQrRepository
{
    /** La sala se resuelve SIEMPRE por el token del QR, nunca por un id secuencial en la URL. */
    public static function salaPorToken(PDO $pdo, string $token): ?array
    {
        $st = $pdo->prepare("SELECT id_sala, nombre_sala, dependencia FROM salas WHERE codigo_qr_token = ?");
        $st->execute([$token]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function codigoSeguimientoExiste(PDO $pdo, string $codigo): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM tickets WHERE codigo_seguimiento = ?");
        $st->execute([$codigo]);
        return (bool)$st->fetchColumn();
    }

    /**
     * Reporte sin sesión: sin usuario ni equipo (el equipo lo fija el beca/técnico al tomar el ticket).
     * Guarda nombre y correo institucional de quien reporta para poder contactarlo después.
     */
    public static function crearReporteAnonimo(PDO $pdo, array $sala, string $descripcion, ?string $ip, string $codigoSeguimiento, string $nombreContacto = '', string $correoContacto = ''): int
    {
        $pdo->prepare(
            "INSERT INTO tickets
                (user_id, nombre_dependencia, ubicacion, equipo, descripcion, id_sala, id_equipo, origen, ip_origen, codigo_seguimiento, nombre_contacto, correo_contacto)
             VALUES (NULL, NULL, ?, NULL, ?, ?, NULL, 'QR_ANONIMO', ?, ?, ?, ?)"
        )->execute([$sala['nombre_sala'], $descripcion, $sala['id_sala'], $ip, $codigoSeguimiento,
                    $nombreContacto !== '' ? $nombreContacto : null, $correoContacto !== '' ? strtolower($correoContacto) : null]);
        return (int)$pdo->lastInsertId();
    }

    /** Reporte de un profesor con sesión: mismo circuito que «Reportar daño» de su panel, origen QR_PROFESOR. */
    public static function crearReporteProfesor(PDO $pdo, int $idProfesor, array $sala, string $descripcion): int
    {
        $pdo->prepare(
            "INSERT INTO tickets_profesor (profesor_id, tipo_ticket, id_sala, sala_danio, descripcion_problema, origen)
             VALUES (?, 'danio', ?, ?, ?, 'QR_PROFESOR')"
        )->execute([$idProfesor, $sala['id_sala'], $sala['nombre_sala'], $descripcion]);
        return (int)$pdo->lastInsertId();
    }

    /** Datos mínimos de un reporte QR anónimo por su código de seguimiento (el detalle interno no se expone). */
    public static function reportePublicoPorCodigo(PDO $pdo, string $codigoNormalizado): ?array
    {
        $st = $pdo->prepare(
            "SELECT t.id, t.estado, t.descripcion, t.solucion, t.fecha_creacion, t.fecha_terminacion, t.nombre_dependencia, s.nombre_sala
             FROM tickets t
             LEFT JOIN salas s ON t.id_sala = s.id_sala
             WHERE t.codigo_seguimiento = ? AND t.origen = 'QR_ANONIMO'"
        );
        $st->execute([$codigoNormalizado]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
