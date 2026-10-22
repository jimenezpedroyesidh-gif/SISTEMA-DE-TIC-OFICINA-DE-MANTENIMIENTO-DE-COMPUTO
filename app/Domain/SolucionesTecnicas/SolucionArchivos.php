<?php
/**
 * Archivos privados de las soluciones técnicas, en storage/soluciones/ (bloqueada por .htaccess):
 *  - Archivos de versión (scripts, instaladores, configuración, documentación): extensión en lista blanca, huella
 *    SHA-256 calculada al subir y revisada antes de cada descarga (descargar_solucion.php).
 *  - Evidencias (capturas y fotos): solo imagen o PDF detectado por contenido; las fotos se re-codifican sin datos
 *    GPS con el mismo optimizador de la evidencia de tickets (TicketEvidencia::optimizar).
 * En disco se guardan con nombre aleatorio y extensión .dat: aunque el servidor quedara mal configurado, nada de
 * lo subido se puede ejecutar ni abrir por URL. No escribe en la base: devuelve los datos para que el servicio los
 * registre dentro de su transacción.
 */
final class SolucionArchivos
{
    /** Tamaño máximo de un archivo de versión (además manda upload_max_filesize/post_max_size de php.ini). */
    public const TAMANO_MAXIMO = 64 * 1024 * 1024;

    /** Archivos por versión (en un solo envío). */
    public const MAX_ARCHIVOS_VERSION = 10;

    /** Extensiones permitidas en una versión → categoría con que se registran. */
    public const EXTENSIONES = [
        'ps1' => 'SCRIPT', 'psm1' => 'SCRIPT', 'psd1' => 'SCRIPT', 'bat' => 'SCRIPT', 'cmd' => 'SCRIPT', 'vbs' => 'SCRIPT',
        'py' => 'SCRIPT', 'sh' => 'SCRIPT',
        'msi' => 'INSTALADOR', 'msix' => 'INSTALADOR', 'exe' => 'INSTALADOR', 'zip' => 'INSTALADOR', '7z' => 'INSTALADOR', 'cab' => 'INSTALADOR',
        'reg' => 'CONFIGURACION', 'pol' => 'CONFIGURACION', 'admx' => 'CONFIGURACION', 'adml' => 'CONFIGURACION', 'inf' => 'CONFIGURACION',
        'xml' => 'CONFIGURACION', 'json' => 'CONFIGURACION', 'ini' => 'CONFIGURACION', 'cfg' => 'CONFIGURACION', 'conf' => 'CONFIGURACION',
        'yml' => 'CONFIGURACION', 'yaml' => 'CONFIGURACION',
        'pdf' => 'DOCUMENTACION', 'docx' => 'DOCUMENTACION', 'xlsx' => 'DOCUMENTACION', 'odt' => 'DOCUMENTACION', 'md' => 'DOCUMENTACION',
        'txt' => 'DOCUMENTACION', 'png' => 'DOCUMENTACION', 'jpg' => 'DOCUMENTACION', 'jpeg' => 'DOCUMENTACION',
    ];

    public const CATEGORIAS = [
        'SCRIPT' => 'Script', 'INSTALADOR' => 'Instalador o paquete', 'CONFIGURACION' => 'Configuración',
        'DOCUMENTACION' => 'Documentación', 'OTRO' => 'Otro',
    ];

    /** Evidencias: fotos o PDF (el MIME se detecta del contenido, nunca del navegador). */
    public const MIME_EVIDENCIA = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    /** Evidencias por envío (un celular puede elegir varias fotos a la vez). */
    public const MAX_EVIDENCIAS = 6;

    public static function carpeta(): string
    {
        return dirname(__DIR__, 3) . '/storage/soluciones';
    }

    /** Ruta en disco de un archivo guardado; null si ya no existe. basename() impide salir de la carpeta. */
    public static function ruta(string $nombreAlmacenado): ?string
    {
        $ruta = self::carpeta() . '/' . basename($nombreAlmacenado);
        return is_file($ruta) ? $ruta : null;
    }

    /** Nombre original apto para mostrar y para la cabecera de descarga (sin rutas, comillas ni caracteres de control). */
    public static function nombreLimpio(string $nombre): string
    {
        $nombre = basename(str_replace('\\', '/', $nombre));
        $nombre = (string)preg_replace('/["\\\\\x00-\x1f\x7f]/', '_', $nombre);
        return mb_substr($nombre !== '' ? $nombre : 'archivo', 0, 255);
    }

    /**
     * Valida TODOS los archivos de una versión antes de mover ninguno; si uno falla no se guarda nada.
     * @return array{error:string, datos:array<int,array{categoria:string,nombre_original:string,nombre_almacenado:string,tamano_bytes:int,sha256:string}>}
     */
    public static function guardarArchivosVersion(?array $files): array
    {
        $lista = array_values(array_filter(TicketEvidencia::normalizarFiles($files), fn($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
        if (!$lista) {
            return ['error' => 'Adjunta al menos un archivo (script, instalador, configuración o documento).', 'datos' => []];
        }
        if (count($lista) > self::MAX_ARCHIVOS_VERSION) {
            return ['error' => 'Una versión admite hasta ' . self::MAX_ARCHIVOS_VERSION . ' archivos por envío (comprime el resto en un .zip).', 'datos' => []];
        }
        $validos = [];
        foreach ($lista as $f) {
            $nombre = self::nombreLimpio((string)($f['name'] ?? ''));
            $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
            $tmp = (string)($f['tmp_name'] ?? '');
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                return ['error' => "«{$nombre}» no llegó completo: revisa que no supere el tamaño máximo de subida del servidor.", 'datos' => []];
            }
            if (!isset(self::EXTENSIONES[$ext])) {
                return ['error' => "«{$nombre}»: tipo de archivo no permitido. Se aceptan: " . implode(', ', array_keys(self::EXTENSIONES)) . '.', 'datos' => []];
            }
            $tamano = (int)filesize($tmp);
            if ($tamano <= 0 || $tamano > self::TAMANO_MAXIMO) {
                return ['error' => "«{$nombre}» está vacío o supera " . (self::TAMANO_MAXIMO / 1048576) . ' MB.', 'datos' => []];
            }
            $validos[] = ['tmp' => $tmp, 'categoria' => self::EXTENSIONES[$ext], 'nombre_original' => $nombre, 'tamano_bytes' => $tamano, 'sha256' => (string)hash_file('sha256', $tmp)];
        }

        $guardados = [];
        foreach ($validos as $v) {
            $almacenado = self::mover($v['tmp']);
            if ($almacenado === null) {
                self::borrar(array_column($guardados, 'nombre_almacenado'));
                return ['error' => "No se pudo guardar «{$v['nombre_original']}» en el servidor. Intenta de nuevo.", 'datos' => []];
            }
            unset($v['tmp']);
            $guardados[] = $v + ['nombre_almacenado' => $almacenado];
        }
        return ['error' => '', 'datos' => $guardados];
    }

    /**
     * Guarda las evidencias válidas (foto o PDF). Las fotos se re-codifican a JPEG sin metadatos.
     * @return array{guardadas:array<int,array{nombre_original:string,nombre_almacenado:string,mime_type:string,tamano_bytes:int}>, rechazadas:int}
     */
    public static function guardarEvidencias(?array $files): array
    {
        $guardadas = [];
        $rechazadas = 0;
        foreach (array_slice(TicketEvidencia::normalizarFiles($files), 0, self::MAX_EVIDENCIAS) as $f) {
            $error = $f['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $tmp = (string)($f['tmp_name'] ?? '');
            $mime = $error === UPLOAD_ERR_OK && is_uploaded_file($tmp) ? (string)mime_content_type($tmp) : '';
            if (!in_array($mime, self::MIME_EVIDENCIA, true) || (int)filesize($tmp) > TicketAdjuntos::ADJUNTOS_TAMANO_MAXIMO) {
                $rechazadas++;
                continue;
            }
            if ($mime !== 'application/pdf') {
                TicketEvidencia::optimizar($tmp, $mime);
                clearstatcache(true, $tmp);
                $mime = (string)mime_content_type($tmp);
            }
            $almacenado = self::mover($tmp);
            if ($almacenado === null) {
                $rechazadas++;
                continue;
            }
            $guardadas[] = ['nombre_original' => self::nombreLimpio((string)($f['name'] ?? '')), 'nombre_almacenado' => $almacenado, 'mime_type' => $mime, 'tamano_bytes' => (int)filesize(self::carpeta() . '/' . $almacenado)];
        }
        return ['guardadas' => $guardadas, 'rechazadas' => $rechazadas];
    }

    /** Quita del disco archivos que no llegaron a registrarse (la transacción de la base falló). */
    public static function borrar(array $nombresAlmacenados): void
    {
        foreach ($nombresAlmacenados as $n) {
            $ruta = self::ruta((string)$n);
            if ($ruta !== null) {
                @unlink($ruta);
            }
        }
    }

    /** Mueve el archivo subido a la carpeta privada con un nombre aleatorio; null si falla. */
    private static function mover(string $tmp): ?string
    {
        $carpeta = self::carpeta();
        if (!is_dir($carpeta) && !mkdir($carpeta, 0750, true) && !is_dir($carpeta)) {
            error_log("No se pudo crear la carpeta de soluciones técnicas: $carpeta");
            return null;
        }
        $nombre = bin2hex(random_bytes(16)) . '.dat';
        if (!move_uploaded_file($tmp, $carpeta . '/' . $nombre)) {
            error_log("No se pudo mover el archivo subido a $carpeta/$nombre");
            return null;
        }
        return $nombre;
    }
}
