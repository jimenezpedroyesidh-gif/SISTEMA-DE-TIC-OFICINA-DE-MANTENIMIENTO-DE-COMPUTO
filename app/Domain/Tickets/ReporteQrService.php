<?php
/**
 * Casos de uso del reporte de daño por QR y de la consulta pública de estado. Requiere NotificationService,
 * TicketActivos y validarTexto() cargados (bootstrap + controlador).
 */

class ReporteQrService
{
    public const MAX_DESCRIPCION = 5000;
    public const MAX_INTENTOS_CONSULTA = 8;
    public const MAX_NOMBRE = 100;
    public const MAX_CORREO = 150;

    /**
     * Registra un reporte. Devuelve ['resultado' => 'error'|'duplicado'|'profesor'|'anonimo', ...]:
     *  - error: 'mensaje';  duplicado: 'estado' del ticket en curso;  profesor: 'id';  anonimo: 'id' y 'codigo'.
     * Si ya hay un daño en curso para la sala no se crea otro (evita llenar la bandeja con el mismo daño).
     * $idsEquipo: equipos afectados que se marcaron en el plano de la sala (opcional, con o sin sesión).
     * Se revalida que sean de esa sala y se guardan en ticket_activos del ticket creado.
     * $idsComponente: videobeam/parlantes de la sala marcados en el plano (mismo tratamiento).
     */
    public static function reportar(PDO $pdo, array $sala, string $descripcion, ?int $idProfesor, ?string $ip, array $idsEquipo = [], array $idsComponente = [], array $contacto = []): array
    {
        $descripcion = trim($descripcion);
        if ($descripcion === '') return ['resultado' => 'error', 'mensaje' => "Describe el problema antes de enviar."];
        // Sin sesión: nombre y correo institucional obligatorios (así el equipo de mantenimiento puede contactar al profesor).
        $nombreContacto = trim((string)($contacto['nombre'] ?? ''));
        $correoContacto = trim((string)($contacto['correo'] ?? ''));
        if ($idProfesor === null) {
            if (validarTexto($nombreContacto, self::MAX_NOMBRE, 3) === null) {
                return ['resultado' => 'error', 'mensaje' => "Escribe tu nombre completo."];
            }
            if (strlen($correoContacto) > self::MAX_CORREO || !correoInstitucionalValido($correoContacto)) {
                return ['resultado' => 'error', 'mensaje' => "Escribe tu correo institucional (@" . institucion('dominio_correo') . "): es la forma de contactarte sobre este reporte."];
            }
        }
        if (validarTexto($descripcion, self::MAX_DESCRIPCION) === null) {
            return ['resultado' => 'error', 'mensaje' => "La descripción admite máximo " . self::MAX_DESCRIPCION . " caracteres."];
        }
        $existente = TicketActivos::ticketDanioActivoExistente($pdo, (int)$sala['id_sala'], null);
        if ($existente) return ['resultado' => 'duplicado', 'estado' => $existente['estado']];

        if ($idProfesor !== null) {
            $id = ReporteQrRepository::crearReporteProfesor($pdo, $idProfesor, $sala, $descripcion);
            $validos = TicketActivos::equiposValidosDeSala($pdo, (int)$sala['id_sala'], $idsEquipo);
            $validosComp = PlanoSalaService::componentesFrenteValidos($pdo, (int)$sala['id_sala'], $idsComponente);
            if ($validos || $validosComp) TicketActivos::agregarActivosATicket($pdo, TablaTicket::PROFESOR, $id, $validos, $validosComp);
            NotificationService::notificarAdmins($pdo, 'REQUERIMIENTO', "Nuevo ticket de daño de profesor pendiente (ticket #$id, vía QR)", "tickets.php");
            // Quien atiende un daño (ir al salón) es beca_trabajo, no el admin.
            // Las becas solo atienden salas del Departamento de Sistemas: no se les avisa de otras dependencias.
            if (TicketConsultas::esDependenciaSistemas($sala['dependencia'] ?? null)) {
                NotificationService::notificarPorRol($pdo, Rol::BECA, 'TICKET_ESTADO', "Nuevo daño reportado en {$sala['nombre_sala']} (vía QR de profesor)", "tickets.php");
            }
            return ['resultado' => 'profesor', 'id' => $id];
        }

        // Código propio para quien no tiene cuenta: algo que anotar sin exponer el id secuencial (evita enumerar reportes).
        do {
            $codigo = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        } while (ReporteQrRepository::codigoSeguimientoExiste($pdo, $codigo));
        $id = ReporteQrRepository::crearReporteAnonimo($pdo, $sala, $descripcion, $ip, $codigo, $nombreContacto, $correoContacto);
        $validosAnon = TicketActivos::equiposValidosDeSala($pdo, (int)$sala['id_sala'], $idsEquipo);
        $validosCompAnon = PlanoSalaService::componentesFrenteValidos($pdo, (int)$sala['id_sala'], $idsComponente);
        if ($validosAnon || $validosCompAnon) TicketActivos::agregarActivosATicket($pdo, TablaTicket::NORMAL, $id, $validosAnon, $validosCompAnon);
        if (TicketConsultas::esDependenciaSistemas($sala['dependencia'] ?? null)) {
            NotificationService::notificarPorRol($pdo, Rol::BECA, 'TICKET_ESTADO', "Nuevo daño reportado en {$sala['nombre_sala']} (QR anónimo)", "tickets.php");
        }
        // Copia del código de seguimiento al correo (si config/smtp.php existe; si no, solo queda en pantalla).
        if (class_exists('Mailer')) {
            Mailer::enviar($correoContacto, $nombreContacto, "Reporte recibido — {$sala['nombre_sala']}",
                "Hola $nombreContacto,\n\nRecibimos tu reporte de daño en {$sala['nombre_sala']}.\n"
                . "Código de seguimiento: $codigo\n\nPuedes consultar el estado sin cuenta en «Consultar estado de mi reporte».\n"
                . "Si el equipo de mantenimiento necesita más información te escribirá a este correo.\n\n" . institucion('oficina') . " — " . institucion('sigla'));
        }
        return ['resultado' => 'anonimo', 'id' => $id, 'codigo' => $codigo];
    }

    /**
     * Consulta pública por código de seguimiento, con límite de intentos por sesión (8 por minuto) contra fuerza bruta.
     * $sesion es $_SESSION (se pasa por referencia). Devuelve ['codigo' => texto a mostrar, 'ticket' => fila|null, 'error' => string].
     */
    public static function consultar(PDO $pdo, string $codigo, array &$sesion): array
    {
        $r = ['codigo' => $codigo, 'ticket' => null, 'error' => ''];
        if ($codigo === '') return $r;

        $intentos = $sesion['consulta_estado_intentos'] ?? 0;
        if (time() - ($sesion['consulta_estado_ultimo'] ?? 0) > 60) $intentos = 0;
        if ($intentos >= self::MAX_INTENTOS_CONSULTA) {
            return ['codigo' => '', 'ticket' => null, 'error' => "Demasiados intentos. Espera un minuto antes de volver a intentar."];
        }
        $sesion['consulta_estado_intentos'] = $intentos + 1;
        $sesion['consulta_estado_ultimo'] = time();

        $normalizado = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo));
        if ($normalizado === '') { $r['error'] = "Ingresa un código válido."; return $r; }
        $r['ticket'] = ReporteQrRepository::reportePublicoPorCodigo($pdo, $normalizado)
            ?? ArchivoSemestralRepository::porCodigo($pdo, $normalizado); // reporte ya archivado en un cierre de semestre
        if (!$r['ticket']) $r['error'] = "No se encontró ningún reporte con ese código.";
        return $r;
    }

    /** Mensaje de alto nivel para el público (no expone quién lo atiende ni las piezas). */
    public static function descripcionEstadoPublico(string $estado): string
    {
        switch ($estado) {
            case TicketWorkflow::EN_ESPERA:   return 'Recibido — todavía nadie lo ha tomado.';
            case TicketWorkflow::EN_PROCESO:  return 'Un técnico o becario de mantenimiento ya lo está atendiendo.';
            case TicketWorkflow::SOLUCIONADO: return 'El trabajo ya se realizó — está pendiente de aprobación final.';
            case TicketWorkflow::APROBADO:
            case TicketWorkflow::CERRADO:     return 'Resuelto y cerrado.';
            case TicketWorkflow::RECHAZADO:   return 'Se detectó que quedó incompleto — se está corrigiendo.';
            default:            return $estado;
        }
    }
}
