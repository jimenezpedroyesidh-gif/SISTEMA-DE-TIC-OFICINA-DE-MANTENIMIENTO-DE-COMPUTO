<?php
/**
 * Evidencia fotográfica del trabajo realizado y regla de cierre «explicar + foto».
 *
 * Qué tickets la exigen al pasar a «Solucionado» (TIPOS_CON_EVIDENCIA):
 *   - tickets_profesor de daño/error de sala (panel del profesor o QR con sesión),
 *   - tickets_profesor de requerimiento de instalación de software,
 *   - tickets reportados por QR sin sesión (tickets.origen = QR_ANONIMO).
 * Préstamos y cambios de sala no la piden: se cierran con «Confirmar devolución» / la excepción de horario.
 *
 * Las fotos se guardan con el mecanismo único de adjuntos (ticket_adjuntos, categoria = 'EVIDENCIA'), se
 * re-codifican a JPEG (máx. 1600 px, sin metadatos EXIF/GPS del celular) y se imprimen en el PDF del ticket.
 */
final class TicketEvidencia
{
    /** Mínimo de caracteres de la explicación de la solución. */
    public const MIN_SOLUCION = 15;

    /** Fotos por envío (un celular puede elegir varias a la vez). */
    public const MAX_FOTOS_POR_ENVIO = 6;

    /** Lado mayor de la foto guardada (px). */
    private const LADO_MAXIMO = 1600;

    /** Formatos de foto aceptados (el MIME se detecta del contenido, nunca del navegador). */
    public const MIME_FOTO = ['image/jpeg', 'image/png', 'image/webp'];

    /** ¿Este ticket exige explicación + foto para quedar «Solucionado»? */
    public static function exige(string $tabla, array $t): bool
    {
        if ($tabla === TablaTicket::PROFESOR) {
            return ($t['tipo_ticket'] ?? '') === 'danio'
                || (($t['tipo_ticket'] ?? '') === 'requerimiento' && ($t['subtipo_requerimiento'] ?? '') === 'INSTALACION_SOFTWARE');
        }
        return $tabla === TablaTicket::NORMAL && ($t['origen'] ?? '') === 'QR_ANONIMO';
    }

    /**
     * Qué falta para poder marcar el ticket como Solucionado; null = nada (o el ticket no la exige).
     * $solucion es el texto que llega en el mismo formulario.
     */
    public static function faltante(PDO $pdo, string $tabla, array $t, ?string $solucion): ?string
    {
        if (!self::exige($tabla, $t)) {
            return null;
        }
        $faltas = [];
        if (mb_strlen(trim((string)$solucion)) < self::MIN_SOLUCION) {
            $faltas[] = 'explica qué hiciste (mínimo ' . self::MIN_SOLUCION . ' caracteres)';
        }
        if (self::contar($pdo, $tabla, (int)$t['id']) === 0) {
            $faltas[] = 'adjunta al menos una foto del trabajo realizado';
        }
        return $faltas ? 'Para marcarlo como Solucionado: ' . implode(' y ', $faltas) . '.' : null;
    }

    public static function contar(PDO $pdo, string $tabla, int $idTicket): int
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM ticket_adjuntos WHERE tabla_ticket = ? AND id_ticket = ? AND categoria = 'EVIDENCIA'");
        $st->execute([$tabla, $idTicket]);
        return (int)$st->fetchColumn();
    }

    /**
     * Fotos de evidencia de varios tickets en una sola consulta.
     * @param int[] $ids
     * @return array<int,array<int,array>> id_ticket => fotos (más antigua primero)
     */
    public static function deTickets(PDO $pdo, string $tabla, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !TablaTicket::valida($tabla)) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare(
            "SELECT a.id, a.id_ticket, a.id_usuario_subio, a.nombre_original, a.nombre_almacenado, a.mime_type, a.fecha_subida, u.nombre_completo AS subio
             FROM ticket_adjuntos a LEFT JOIN users u ON u.id = a.id_usuario_subio
             WHERE a.tabla_ticket = ? AND a.categoria = 'EVIDENCIA' AND a.id_ticket IN ($marcas)
             ORDER BY a.fecha_subida, a.id"
        );
        $st->execute(array_merge([$tabla], $ids));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $out[(int)$f['id_ticket']][] = $f;
        }
        return $out;
    }

    /** Fotos de un ticket (para el PDF). */
    public static function deTicket(PDO $pdo, string $tabla, int $idTicket): array
    {
        return self::deTickets($pdo, $tabla, [$idTicket])[$idTicket] ?? [];
    }

    /** Ruta en disco de una foto (para el PDF); null si ya no existe. */
    public static function ruta(array $foto): ?string
    {
        $ruta = TicketAdjuntos::carpetaAdjuntos() . '/' . basename((string)$foto['nombre_almacenado']);
        return is_file($ruta) ? $ruta : null;
    }

    /**
     * Guarda las fotos subidas en $_FILES[campo] (input multiple). Solo quien ejecuta el ticket
     * (técnico que lo tomó o beca asignada/ejecutora) y mientras no esté cerrado.
     * @return array{ok:bool,guardadas:int,mensaje:string}
     */
    public static function subir(PDO $pdo, string $tabla, int $idTicket, int $idUsuario, ?array $archivos): array
    {
        $r = fn(bool $ok, int $n, string $m) => ['ok' => $ok, 'guardadas' => $n, 'mensaje' => $m];
        if (!TablaTicket::valida($tabla)) {
            return $r(false, 0, 'Ticket no válido.');
        }
        $t = TicketRepository::crudo($pdo, $tabla, $idTicket);
        if (!$t || !TicketConsultas::becaEsEjecutora($t, $idUsuario)) {
            return $r(false, 0, 'Solo quien atiende el ticket puede subir evidencia.');
        }
        if (in_array($t['estado'], TicketConversacion::ESTADOS_CERRADOS, true)) {
            return $r(false, 0, 'El ticket ya está cerrado.');
        }
        $lista = self::normalizarFiles($archivos);
        if (!$lista) {
            return $r(true, 0, '');
        }
        $guardadas = 0;
        $rechazadas = 0;
        foreach (array_slice($lista, 0, self::MAX_FOTOS_POR_ENVIO) as $archivo) {
            if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$archivo['tmp_name'])) {
                if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $rechazadas++;
                continue;
            }
            $mime = mime_content_type($archivo['tmp_name']) ?: '';
            if (!in_array($mime, self::MIME_FOTO, true)) {
                $rechazadas++;
                continue;
            }
            self::optimizar((string)$archivo['tmp_name'], $mime);
            $archivo['size'] = filesize($archivo['tmp_name']);
            $id = TicketAdjuntos::guardarAdjunto($pdo, $archivo, $idUsuario, $tabla, $idTicket);
            if ($id) {
                $pdo->prepare("UPDATE ticket_adjuntos SET categoria = 'EVIDENCIA' WHERE id = ?")->execute([$id]);
                $guardadas++;
            } else {
                $rechazadas++;
            }
        }
        if ($guardadas) {
            AuditoriaService::registrar($pdo, $idUsuario, $tabla, $idTicket, 'EDITAR', "evidencia fotográfica: $guardadas foto(s)");
        }
        $msg = $rechazadas ? "$rechazadas archivo(s) no se guardaron (solo fotos JPG, PNG o WEBP de hasta 8 MB)." : '';
        return $r($rechazadas === 0 || $guardadas > 0, $guardadas, $msg);
    }

    /** Quien la subió puede quitar una foto mientras el ticket no esté cerrado. */
    public static function quitar(PDO $pdo, int $idAdjunto, int $idUsuario): bool
    {
        $st = $pdo->prepare("SELECT * FROM ticket_adjuntos WHERE id = ? AND categoria = 'EVIDENCIA' AND id_usuario_subio = ?");
        $st->execute([$idAdjunto, $idUsuario]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a || !TablaTicket::valida($a['tabla_ticket'])) {
            return false;
        }
        $t = TicketRepository::crudo($pdo, $a['tabla_ticket'], (int)$a['id_ticket']);
        if (!$t || in_array($t['estado'], TicketConversacion::ESTADOS_CERRADOS, true) || $t['estado'] === TicketWorkflow::SOLUCIONADO) {
            return false;
        }
        $pdo->prepare("DELETE FROM ticket_adjuntos WHERE id = ?")->execute([$idAdjunto]);
        $ruta = self::ruta($a);
        if ($ruta) @unlink($ruta);
        return true;
    }

    /** $_FILES['x'] con «multiple» viene por columnas; lo convierte en una lista de archivos. También lo usan las soluciones técnicas. */
    public static function normalizarFiles(?array $f): array
    {
        if (!$f || !isset($f['name'])) {
            return [];
        }
        if (!is_array($f['name'])) {
            return [$f];
        }
        $lista = [];
        foreach (array_keys($f['name']) as $i) {
            $lista[] = [
                'name' => $f['name'][$i], 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '',
                'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $f['size'][$i] ?? 0,
            ];
        }
        return $lista;
    }

    /**
     * Re-codifica la foto a JPEG (máx. LADO_MAXIMO px, calidad 82) sobre el mismo archivo temporal:
     * quita metadatos (ubicación GPS del celular), corrige la orientación y deja fotos livianas que el
     * PDF (FPDF) sí puede imprimir. Sin GD se deja el archivo tal cual.
     */
    public static function optimizar(string $ruta, string $mime): void
    {
        if (!function_exists('imagecreatefromstring')) {
            return;
        }
        $img = @imagecreatefromstring((string)file_get_contents($ruta));
        if (!$img) {
            return;
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($ruta);
            $giro = [3 => 180, 6 => -90, 8 => 90][(int)($exif['Orientation'] ?? 1)] ?? 0;
            if ($giro) {
                $rotada = imagerotate($img, $giro, 0);
                if ($rotada) { imagedestroy($img); $img = $rotada; }
            }
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $escala = min(1, self::LADO_MAXIMO / max($w, $h));
        $nw = max(1, (int)round($w * $escala));
        $nh = max(1, (int)round($h * $escala));
        $lienzo = imagecreatetruecolor($nw, $nh);
        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255)); // fondo blanco para PNG con transparencia
        imagecopyresampled($lienzo, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagejpeg($lienzo, $ruta, 82);
        imagedestroy($img);
        imagedestroy($lienzo);
        clearstatcache(true, $ruta);
    }
}
