<?php
/**
 * Helpers compartidos del "shell" visual (header + sidebar) unificado.
 *
 * No consulta nada que no exista ya en la base de datos real: usa
 * `users` (nombre_completo, cargo, correo, role, codigo) y la
 * dependencia/departamento vía `unidades_organizacionales` (id_unidad).
 * No hay columna de foto de perfil todavía — por eso el avatar se
 * dibuja siempre con iniciales, nunca con una <img> inventada.
 *
 * Lo carga bootstrap.php para todas las páginas.
 */

require_once __DIR__ . '/ui.php'; // h()

/**
 * Devuelve los datos reales del usuario en sesión para el header/sidebar
 * y el perfil. Cachea en una variable estática por request para no
 * repetir la consulta si header y sidebar se incluyen por separado.
 */
function obtenerUsuarioActualShell(PDO $pdo, int $userId): ?array {
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];

    $usuario = UserRepository::datosShell($pdo, $userId);
    $cache[$userId] = $usuario;
    return $usuario;
}

/** Iniciales para el avatar (ej. "Ana María Rojas" -> "AR"). */
function inicialesUsuario(?string $nombreCompleto): string {
    $nombreCompleto = trim((string)$nombreCompleto);
    if ($nombreCompleto === '') return '?';
    $partes = preg_split('/\s+/', $nombreCompleto);
    $ini = mb_substr($partes[0], 0, 1);
    if (count($partes) > 1) $ini .= mb_substr(end($partes), 0, 1);
    return mb_strtoupper($ini);
}

/** Etiqueta legible del rol para mostrar en el header/sidebar. */
function etiquetaRol(?string $role): string {
    $mapa = [
        Rol::ADMIN        => 'Administrador',
        Rol::TECNICO      => 'Técnico',
        Rol::PROFESOR     => 'Profesor',
        Rol::BECA => 'Beca trabajo',
        Rol::SOLICITANTE  => 'Solicitante',
        Rol::SECRETARIA   => 'Secretaria',
        Rol::VIGILANTE    => 'Vigilante',
        Rol::VISUALIZACION => 'Pantalla (TV)',
    ];
    return $mapa[$role] ?? h($role);
}

/** Carpeta real (en disco) donde viven las fotos de perfil ya validadas. */
function carpetaFotosPerfil(): string {
    return dirname(__DIR__, 2) . '/public/assets/uploads/perfiles';
}

/** Ruta pública (relativa, para usar en <img src>) de una foto de perfil. */
function urlFotoPerfil(string $nombreArchivo): string {
    return 'assets/uploads/perfiles/' . rawurlencode($nombreArchivo);
}

/**
 * Valida y guarda la foto de perfil subida en $_FILES['foto_perfil'].
 * Nunca confía en la extensión ni el "type" que manda el navegador:
 * revisa el contenido real con getimagesize() y genera un nombre de
 * archivo propio (nada de usar el nombre original). Devuelve
 * ['ok' => bool, 'nombre' => string|null, 'error' => string|null].
 */
function guardarFotoPerfil(array $archivo, int $idUsuario): array {
    if (!isset($archivo['error']) || $archivo['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'nombre' => null, 'error' => 'No se pudo subir el archivo (inténtalo de nuevo).'];
    }

    $tamanoMaximo = 2 * 1024 * 1024;
    if ($archivo['size'] > $tamanoMaximo) {
        return ['ok' => false, 'nombre' => null, 'error' => 'La imagen no puede pesar más de 2 MB.'];
    }

    // getimagesize() abre el archivo y verifica que sea una imagen real
    // (no un .php ni cualquier otra cosa disfrazada con extensión .jpg).
    $info = @getimagesize($archivo['tmp_name']);
    if ($info === false) {
        return ['ok' => false, 'nombre' => null, 'error' => 'El archivo no es una imagen válida.'];
    }

    $extensionesPermitidas = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    $mimeReal = $info['mime'];
    if (!isset($extensionesPermitidas[$mimeReal])) {
        return ['ok' => false, 'nombre' => null, 'error' => 'Solo se permiten imágenes JPG, PNG o WEBP.'];
    }

    $extension = $extensionesPermitidas[$mimeReal];
    // Nombre 100% generado por el servidor — nunca el nombre original.
    $nombreArchivo = 'u' . $idUsuario . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    $rutaDestino = carpetaFotosPerfil() . '/' . $nombreArchivo;

    if (!is_dir(carpetaFotosPerfil())) {
        @mkdir(carpetaFotosPerfil(), 0755, true);
    }

    if (!move_uploaded_file($archivo['tmp_name'], $rutaDestino)) {
        return ['ok' => false, 'nombre' => null, 'error' => 'No se pudo guardar la imagen en el servidor.'];
    }
    @chmod($rutaDestino, 0644);

    return ['ok' => true, 'nombre' => $nombreArchivo, 'error' => null];
}

/** Borra (best-effort) el archivo de foto de perfil anterior de un usuario. */
function borrarFotoPerfilAnterior(?string $nombreArchivo): void {
    if (!$nombreArchivo) return;
    $ruta = carpetaFotosPerfil() . '/' . basename($nombreArchivo);
    if (is_file($ruta)) {
        @unlink($ruta);
    }
}
