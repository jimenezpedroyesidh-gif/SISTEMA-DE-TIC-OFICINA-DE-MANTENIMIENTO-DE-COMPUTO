<?php
/**
 * Casos de uso de la revisión en bodega y de las solicitudes de baja. Devuelve ['error' => string, 'exito' =>
 * string] (uno de los dos vacío) como el resto de servicios de inventario.
 */

class BodegaService
{
    /** EN_REVISION → ACTIVO|DEFECTUOSO. «De baja» no se decide aquí: es una solicitud que aprueba el admin. */
    public static function revisar(PDO $pdo, int $idUsuario, int $idComponente, string $nuevoEstado, string $observacion): array
    {
        $r = ['error' => '', 'exito' => ''];
        if (!in_array($nuevoEstado, ['ACTIVO', 'DEFECTUOSO'], true)) {
            $r['error'] = "Estado destino inválido.";
            return $r;
        }
        $comp = BodegaRepository::enRevisionPorId($pdo, $idComponente);
        if (!$comp) {
            $r['error'] = "El componente ya no está en revisión (puede que otro usuario ya lo haya decidido).";
            return $r;
        }
        BodegaRepository::cambiarEstado($pdo, $idComponente, $nuevoEstado);
        InventarioService::registrarHistorial($pdo, $idComponente, 'REVISION', $idUsuario, null, null, 'EN_REVISION', $nuevoEstado, null, trim($observacion));
        $r['exito'] = "Componente {$comp['codigo_inventario']} pasó a {$nuevoEstado}.";
        return $r;
    }

    /** El técnico/admin escribe el concepto del daño; la baja solo queda PENDIENTE y se avisa a los admins. */
    public static function solicitarBaja(PDO $pdo, int $idUsuario, int $idComponente, string $concepto): array
    {
        $r = ['error' => '', 'exito' => ''];
        $concepto = trim($concepto);
        if ($concepto === '') {
            $r['error'] = "Escribe el concepto técnico del daño antes de solicitar la baja.";
            return $r;
        }
        $comp = BodegaRepository::candidatoBaja($pdo, $idComponente);
        if (!$comp) {
            $r['error'] = "Ese componente ya no está disponible para solicitar su baja.";
            return $r;
        }
        // ej. doble clic o POST directo) duplicaba la solicitud pendiente.
        if (BodegaRepository::tieneSolicitudPendiente($pdo, $idComponente)) {
            $r['error'] = "Ese componente ya tiene una solicitud de baja pendiente de aprobación.";
            return $r;
        }
        ComponenteBajas::solicitarBajaComponente($pdo, $idComponente, $concepto, $idUsuario);
        NotificationService::notificarAdmins($pdo, 'SISTEMA', "Nueva solicitud de baja para {$comp['codigo_inventario']} — pendiente de aprobación.", 'solicitudes_baja.php');
        $r['exito'] = "Se envió la solicitud de baja de {$comp['codigo_inventario']} al admin para su aprobación.";
        return $r;
    }

    /** El admin aprueba o rechaza; se notifica a quien la solicitó. */
    public static function resolverSolicitud(PDO $pdo, int $idAdmin, int $idSolicitud, bool $aprobar, string $observacion): array
    {
        $r = ['error' => '', 'exito' => ''];
        $observacion = trim($observacion);
        if (!ComponenteBajas::resolverSolicitudBaja($pdo, $idSolicitud, $aprobar, $idAdmin, $observacion !== '' ? $observacion : null)) {
            $r['error'] = "Esa solicitud ya no está pendiente (puede que otro admin ya la haya resuelto).";
            return $r;
        }
        $sol = BodegaRepository::solicitudPorId($pdo, $idSolicitud);
        if ($sol) {
            $codigo = BodegaRepository::codigoComponente($pdo, (int)$sol['id_componente']);
            $msg = $aprobar
                ? "Tu solicitud de baja para {$codigo} fue APROBADA."
                : "Tu solicitud de baja para {$codigo} fue RECHAZADA." . ($observacion !== '' ? " Motivo: {$observacion}" : '');
            NotificationService::crear($pdo, (int)$sol['id_solicito'], 'SISTEMA', $msg, 'ficha_componente.php?id=' . (int)$sol['id_componente']);
        }
        $r['exito'] = $aprobar ? "Baja aprobada — el componente queda marcado como DE_BAJA." : "Solicitud rechazada.";
        return $r;
    }
}
