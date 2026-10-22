<?php
/** Adjuntos de tickets/mantenimientos: carpeta, MIME permitidos y guardado seguro. */
final class TicketAdjuntos
{
    // ===================== ticket_adjuntos =====================

    /**
     * Carpeta real de almacenamiento. Vive junto al resto del proyecto
     * (igual que todo lo demás aquí, no hay una carpeta "pública" separada)
     * pero el acceso directo por URL está bloqueado por
     * `storage/adjuntos/.htaccess` (Require all denied) — el único camino
     * real para bajar un archivo es descargar_adjunto.php, que sí valida
     * sesión y permisos antes de servirlo.
     */
    public static function carpetaAdjuntos(): string {
        return __DIR__ . '/../../../storage/adjuntos';
    }

    /** Tipos y tamaño máximo permitidos — nunca ejecutables ni scripts. */
    public const ADJUNTOS_MIME_PERMITIDOS = [
        'image/jpeg', 'image/png', 'image/webp', 'application/pdf',
    ];
    public const ADJUNTOS_TAMANO_MAXIMO = 8 * 1024 * 1024;

    /**
     * Guarda en disco (con nombre aleatorio, nunca el original) y registra
     * en `ticket_adjuntos` un archivo subido vía $_FILES. Devuelve el id
     * del adjunto, o null si el archivo no pasó las validaciones — nunca
     * lanza una excepción por un archivo inválido, para no tumbar el flujo
     * completo del ticket por un adjunto opcional.
     *
     * @param array $archivo uno de los elementos de $_FILES (ya subido)
     */
    public static function guardarAdjunto(
        PDO $pdo,
        array $archivo,
        int $idUsuario,
        ?string $tablaTicket = null,
        ?int $idTicket = null,
        ?int $idMovimiento = null,
        ?int $idProgramado = null
    ): ?int {
        if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null; // nada subido, o error de subida — no es fatal
        }
        if ($archivo['size'] > self::ADJUNTOS_TAMANO_MAXIMO) {
            return null;
        }
        // Nunca confiar en el mime que manda el navegador — se detecta del
        // contenido real del archivo.
        $mime = mime_content_type($archivo['tmp_name']);
        if (!in_array($mime, self::ADJUNTOS_MIME_PERMITIDOS, true)) {
            return null;
        }
        // Debe ir a exactamente uno de los tres destinos (igual que el CHECK
        // de la tabla: ticket, movimiento de inventario, o mantenimiento
        // programado — nunca ninguno ni varios a la vez).
        $destinos = (int)($idTicket !== null) + (int)($idMovimiento !== null) + (int)($idProgramado !== null);
        if ($destinos !== 1) {
            return null;
        }

        $carpeta = self::carpetaAdjuntos();
        if (!is_dir($carpeta) && !mkdir($carpeta, 0750, true) && !is_dir($carpeta)) {
            error_log("No se pudo crear la carpeta de adjuntos: $carpeta");
            return null;
        }

        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
        $nombreAlmacenado = bin2hex(random_bytes(16)) . '.' . $extension;
        $rutaDestino = $carpeta . '/' . $nombreAlmacenado;

        if (!move_uploaded_file($archivo['tmp_name'], $rutaDestino)) {
            error_log("No se pudo mover el adjunto subido a $rutaDestino");
            return null;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO ticket_adjuntos
                (tabla_ticket, id_ticket, id_movimiento, id_programado, nombre_original, nombre_almacenado, mime_type, tamano_bytes, id_usuario_subio)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $tablaTicket, $idTicket, $idMovimiento, $idProgramado,
            basename($archivo['name']), $nombreAlmacenado, $mime, $archivo['size'], $idUsuario,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Fotos de evidencia general de un mantenimiento programado (subidas al
     * marcarlo REALIZADO) — una o varias, sin importar cuántos equipos
     * abarque, a diferencia de los adjuntos de ticket que son 1 a 1.
     */
    public static function adjuntosDeMantenimiento(PDO $pdo, int $idProgramado): array {
        $stmt = $pdo->prepare(
            "SELECT id, nombre_original, mime_type, tamano_bytes, fecha_subida
             FROM ticket_adjuntos WHERE id_programado = ? ORDER BY fecha_subida DESC"
        );
        $stmt->execute([$idProgramado]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
