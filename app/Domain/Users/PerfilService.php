<?php
/**
 * Foto de perfil de la cuenta propia. La validación del archivo (tamaño, contenido real con getimagesize,
 * nombre generado por el servidor) sigue en guardarFotoPerfil() de app/Support/ui_shell.php (el controlador
 * ya la carga); aquí solo se coordina con la base de datos y se borra el archivo anterior. Devuelve ['error'
 * => ?string, 'exito' => ?string].
 */

class PerfilService
{
    private const ERROR_BD = "No se pudo guardar la foto de perfil. Verifica que la base de datos esté actualizada (database/tickets_db.sql).";

    public static function subirFoto(PDO $pdo, int $idUsuario, ?array $archivo): array
    {
        if (empty($archivo) || ($archivo['error'] ?? null) === UPLOAD_ERR_NO_FILE) {
            return ['error' => 'Selecciona una imagen primero.', 'exito' => null];
        }
        $res = guardarFotoPerfil($archivo, $idUsuario);
        if (!$res['ok']) {
            return ['error' => $res['error'], 'exito' => null];
        }
        try {
            $anterior = UserRepository::fotoPerfil($pdo, $idUsuario);
            UserRepository::guardarFotoPerfil($pdo, $idUsuario, $res['nombre']);
            borrarFotoPerfilAnterior($anterior);
            return ['error' => null, 'exito' => 'Foto de perfil actualizada.'];
        } catch (PDOException $e) {
            borrarFotoPerfilAnterior($res['nombre']); // no se pudo guardar en BD: no dejar el archivo huérfano
            return ['error' => self::ERROR_BD, 'exito' => null];
        }
    }

    public static function quitarFoto(PDO $pdo, int $idUsuario): array
    {
        try {
            $anterior = UserRepository::fotoPerfil($pdo, $idUsuario);
            UserRepository::guardarFotoPerfil($pdo, $idUsuario, null);
            borrarFotoPerfilAnterior($anterior);
            return ['error' => null, 'exito' => 'Foto de perfil eliminada. Ahora se muestran tus iniciales.'];
        } catch (PDOException $e) {
            return ['error' => self::ERROR_BD, 'exito' => null];
        }
    }
}
