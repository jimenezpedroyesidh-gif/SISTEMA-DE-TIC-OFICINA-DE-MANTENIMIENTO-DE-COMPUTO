<?php

/**
 * Casos de uso de auditoría. Equivale (sin cambiar comportamiento) a las
 * antiguas funciones sueltas registrarAuditoria() y obtenerHistorialObjeto(),
 * que siguen existiendo como fachada en app/Support/auditoria.php.
 */
final class AuditoriaService
{
    /**
     * Registra una acción CREAR/EDITAR/BORRAR. Nunca debe tumbar la operación
     * principal: un PDOException se escribe en el log y se ignora.
     *
     * @param array<string, array{0:mixed,1:mixed}> $cambios campo => [antes, después]
     */
    public static function registrar(PDO $pdo, ?int $idUsuario, string $tabla, ?int $idRegistro, string $accion, string $detalle = '', array $cambios = []): void
    {
        try {
            $idAuditoria = AuditoriaRepository::insertar($pdo, $idUsuario, $tabla, $idRegistro, $accion, $detalle);

            if (!empty($cambios)) {
                $filas = [];
                foreach ($cambios as $campo => $par) {
                    [$antes, $despues] = $par;
                    // No vale la pena registrar un "cambio" donde no cambió nada.
                    if ((string)$antes === (string)$despues) continue;
                    $filas[] = [$campo, $antes, $despues];
                }
                AuditoriaRepository::insertarCampos($pdo, $idAuditoria, $filas);
            }
        } catch (PDOException $e) {
            RegistroErrores::registrar("Error registrando auditoría", $e);
        }
    }

    /**
     * Historial detallado (encabezado + campos cambiados) de un registro,
     * del más reciente al más antiguo.
     *
     * @return array<int, array{fecha_hora:string, usuario:?string, accion:string, detalle:string, cambios: array}>
     */
    public static function historialObjeto(PDO $pdo, string $tabla, int $idRegistro): array
    {
        $acciones = AuditoriaRepository::accionesDeRegistro($pdo, $tabla, $idRegistro);
        if (!$acciones) return [];

        $campos = AuditoriaRepository::camposDeAcciones($pdo, array_column($acciones, 'id_auditoria'));
        $porAccion = [];
        foreach ($campos as $fila) {
            $porAccion[$fila['id_auditoria']][] = $fila;
        }
        foreach ($acciones as &$accion) {
            $accion['cambios'] = $porAccion[$accion['id_auditoria']] ?? [];
        }
        unset($accion);
        return $acciones;
    }
}
