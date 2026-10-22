<?php
/**
 * Creación de tickets del profesor (daño de sala y requerimientos: cambio de sala, instalación de software,
 * préstamos).
 */

require_once __DIR__ . '/../../Support/paginacion.php';
require_once __DIR__ . '/../../Support/validacion.php'; // validarTexto()

final class TicketProfesorCreacionService
{
    public const SUBTIPOS_REQUERIMIENTO = ['CAMBIO_SALA', 'INSTALACION_SOFTWARE', 'PRESTAMO_SALA', 'PRESTAMO_EQUIPO'];

    public const DURACIONES_PRESTAMO = ['1_HORA' => '1 hora', '2_HORAS' => '2 horas', 'TODO_DIA' => 'Todo el día'];

    private static function fechaValida(string $f): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $f);
        return $d !== false && $d->format('Y-m-d') === $f;
    }

    /**
     * Crea un ticket desde el panel del profesor: daño de sala o requerimiento (cambio de sala,
     * instalación de software, préstamo de sala/equipo). Valida con los mismos mensajes que el
     * formulario tenía en línea; la sala, el horario y los equipos se revalidan en el servidor
     * (nunca se confía en los ids ni nombres del navegador).
     *
     * @param array $in Datos del formulario (normalmente $_POST).
     * @return array{ok:bool,id:?int,error:?string}
     */
    public static function crearTicketProfesor(PDO $pdo, int $idProfesor, array $in, ?array $salasPermitidas = null): array
    {
        $err = static fn(string $m): array => ['ok' => false, 'id' => null, 'error' => $m];
        try {
            // $salasPermitidas (filas id_sala/nombre_sala): el admin-docente solo pide para las salas de su dependencia.
            $salas = array_column($salasPermitidas ?? TicketRepository::salasParaSelect($pdo), 'nombre_sala', 'id_sala');
            $tipo = (string)($in['tipo_ticket'] ?? '');

            if ($tipo === 'requerimiento') {
                $subtipo = (string)($in['subtipo_requerimiento'] ?? '');
                if (!in_array($subtipo, self::SUBTIPOS_REQUERIMIENTO, true)) {
                    return $err('Selecciona qué tipo de requerimiento necesitas.');
                }
                if ($subtipo === 'CAMBIO_SALA') {
                    return self::crearCambioSala($pdo, $idProfesor, $in, $salas);
                }
                if ($subtipo === 'INSTALACION_SOFTWARE') {
                    return self::crearInstalacionSoftware($pdo, $idProfesor, $in, $salas);
                }
                return self::crearPrestamo($pdo, $idProfesor, $in, $subtipo, $salas);
            }
            return self::crearDanio($pdo, $idProfesor, $in, $salas);
        } catch (Throwable $e) {
            RegistroErrores::registrar('Error creando ticket de profesor', $e);
            return $err('No se pudo crear el ticket. Revisa el log de PHP para conocer el error de base de datos.');
        }
    }

    /** Inserta, audita y (tras confirmar) avisa a los admins. */
    private static function guardarTicketProfesor(PDO $pdo, int $idProfesor, array $fila, string $avisoAdmins, ?callable $extra = null, ?callable $despues = null): array
    {
        $id = Database::transaccion(function (PDO $pdo) use ($idProfesor, $fila, $extra) {
            $id = TicketRepository::insertarProfesor($pdo, $fila);
            if ($extra) { $extra($id); }
            AuditoriaService::registrar($pdo, $idProfesor, TablaTicket::PROFESOR, $id, 'CREAR', 'ticket creado por profesor (' . ($fila['subtipo_requerimiento'] ?? $fila['tipo_ticket']) . ')');
            return $id;
        }, $pdo);
        // Los avisos van DESPUÉS de confirmar: si fallan, el ticket ya existe y no se muestra como no creado.
        try {
            NotificationService::notificarAdmins($pdo, 'REQUERIMIENTO', str_replace('{id}', (string)$id, $avisoAdmins), 'tickets.php');
            if ($despues) { $despues($id); }
            if (in_array($fila['subtipo_requerimiento'] ?? '', ['PRESTAMO_SALA', 'PRESTAMO_EQUIPO'], true)) {
                MantenimientoAgenda::avisarCruces($pdo, isset($fila['id_sala']) ? (int)$fila['id_sala'] : null, "Nuevo préstamo #$id");
            }
        } catch (Throwable $e) {
            RegistroErrores::registrar('Aviso de ticket de profesor #' . $id, $e);
        }
        return ['ok' => true, 'id' => $id, 'error' => null];
    }

    private static function crearCambioSala(PDO $pdo, int $idProfesor, array $in, array $salas): array
    {
        $err = static fn(string $m): array => ['ok' => false, 'id' => null, 'error' => $m];
        // IDOR: el horario elegido debe pertenecer a este profesor.
        $propios = [];
        foreach (TicketRepository::horariosDeClaseDelProfesor($pdo, $idProfesor) as $h) { $propios[(int)$h['id_horario']] = $h; }
        $idHorario = !empty($in['id_horario_referencia']) ? (int)$in['id_horario_referencia'] : null;
        $horario = $idHorario ? ($propios[$idHorario] ?? null) : null;
        $motivo = trim((string)($in['motivo'] ?? ''));
        $idDestino = !empty($in['id_sala_destino']) ? (int)$in['id_sala_destino'] : null;

        if (!$horario) {
            return $err('Selecciona el horario actual (la clase y la sala que deseas cambiar).');
        }
        if ($motivo === '') {
            return $err('Indica el motivo del cambio de sala.');
        }
        if (!$idDestino || !isset($salas[$idDestino])) {
            return $err('Selecciona la sala a la que deseas cambiarte.');
        }
        if ((int)$horario['id_sala'] === $idDestino) {
            return $err('La sala destino debe ser diferente a tu sala actual.');
        }
        return self::guardarTicketProfesor($pdo, $idProfesor, [
            'profesor_id' => $idProfesor, 'tipo_ticket' => 'requerimiento', 'subtipo_requerimiento' => 'CAMBIO_SALA',
            'id_horario_referencia' => $idHorario, 'id_sala_actual' => (int)$horario['id_sala'], 'sala_actual_nombre' => $horario['nombre_sala'],
            'id_sala' => $idDestino, 'sala_requerimiento' => $salas[$idDestino], 'motivo' => $motivo,
        ], 'Nuevo requerimiento de cambio de sala pendiente (ticket #{id})');
    }

    private static function crearInstalacionSoftware(PDO $pdo, int $idProfesor, array $in, array $salas): array
    {
        $err = static fn(string $m): array => ['ok' => false, 'id' => null, 'error' => $m];
        $idSala = !empty($in['id_sala_software']) ? (int)$in['id_sala_software'] : null;
        $software = trim((string)($in['software'] ?? ''));
        $descripcion = trim((string)($in['descripcion_software'] ?? ''));

        if (!$idSala || !isset($salas[$idSala])) {
            return $err('Selecciona la sala o laboratorio donde se instalará el software.');
        }
        if ($software === '') {
            return $err('Indica el nombre del software o aplicativo.');
        }
        if ($descripcion === '') {
            return $err('Describe la versión, arquitectura o especificaciones requeridas.');
        }
        if (validarTexto($software, 150) === null) {
            return $err('El nombre del software admite máximo 150 caracteres.');
        }
        return self::guardarTicketProfesor($pdo, $idProfesor, [
            'profesor_id' => $idProfesor, 'tipo_ticket' => 'requerimiento', 'subtipo_requerimiento' => 'INSTALACION_SOFTWARE',
            'id_sala' => $idSala, 'sala_requerimiento' => $salas[$idSala], 'programa' => $software, 'descripcion_actividad' => $descripcion,
        ], 'Nuevo requerimiento de instalación de software pendiente (ticket #{id})');
    }

    private static function crearPrestamo(PDO $pdo, int $idProfesor, array $in, string $subtipo, array $salas): array
    {
        $err = static fn(string $m): array => ['ok' => false, 'id' => null, 'error' => $m];
        $motivo = trim((string)($in['motivo_prestamo'] ?? ''));
        $cantidad = isset($in['cantidad_alumnos']) && $in['cantidad_alumnos'] !== '' ? (int)$in['cantidad_alumnos'] : null;
        $paraQue = trim((string)($in['para_que'] ?? ''));
        $idSala = !empty($in['id_sala_prestamo']) ? (int)$in['id_sala_prestamo'] : null;
        $fechaReq = (string)($in['fecha_requerida'] ?? '');
        $duracion = (string)($in['duracion_prestamo'] ?? '');
        $fechaDev = (string)($in['fecha_devolucion_prevista'] ?? '');

        if ($motivo === '') {
            return $err('Indica el motivo u objeto del préstamo.');
        }
        if ($cantidad === null || $cantidad < 1) {
            return $err('Indica la cantidad de alumnos involucrados.');
        }
        if ($paraQue === '') {
            return $err('Indica para qué actividad se usará el préstamo.');
        }
        if (!$idSala || !isset($salas[$idSala])) {
            return $err('Selecciona la sala o ubicación requerida.');
        }
        if ($fechaReq === '') {
            return $err('Indica la fecha en la que necesitas el préstamo.');
        }
        if (!isset(self::DURACIONES_PRESTAMO[$duracion])) {
            return $err('Selecciona la duración del préstamo (1 hora, 2 horas o todo el día).');
        }
        if ($fechaDev === '') {
            return $err('Los préstamos necesitan una fecha de devolución.');
        }
        if (!self::fechaValida($fechaReq)) {
            return $err('La fecha requerida no es válida.');
        }
        if (!self::fechaValida($fechaDev)) {
            return $err('La fecha de devolución no es válida.');
        }
        if ($fechaDev < $fechaReq) {
            return $err('La fecha de devolución no puede ser anterior a la fecha requerida.');
        }
        return self::guardarTicketProfesor($pdo, $idProfesor, [
            'profesor_id' => $idProfesor, 'tipo_ticket' => 'requerimiento', 'subtipo_requerimiento' => $subtipo,
            'motivo' => $motivo, 'cantidad_alumnos' => $cantidad, 'para_que' => $paraQue,
            'id_sala' => $idSala, 'sala_requerimiento' => $salas[$idSala],
            'fecha_requerida' => $fechaReq, 'duracion_prestamo' => $duracion, 'fecha_devolucion_prevista' => $fechaDev,
        ], 'Nuevo requerimiento de préstamo pendiente (ticket #{id})');
    }

    private static function crearDanio(PDO $pdo, int $idProfesor, array $in, array $salas): array
    {
        $err = static fn(string $m): array => ['ok' => false, 'id' => null, 'error' => $m];
        $idSala = !empty($in['id_sala_danio']) ? (int)$in['id_sala_danio'] : null;
        // Otro reporte (profesor, QR anónimo o este mismo docente) ya en curso para la sala: no se duplica.
        $existente = $idSala ? TicketActivos::ticketDanioActivoExistente($pdo, $idSala, null) : null;
        // Equipos afectados: OPCIONALES; equiposValidosDeSala() revalida que pertenezcan de verdad a la sala.
        $idsEquipo = TicketActivos::equiposValidosDeSala($pdo, $idSala ?? 0, (array)($in['ids_equipo_danio'] ?? []));
        // Videobeam/parlantes de la sala marcados en el plano (componentes frente al tablero), también revalidados.
        $idsComponente = PlanoSalaService::componentesFrenteValidos($pdo, $idSala ?? 0, (array)($in['ids_componente_danio'] ?? []));
        $descripcion = trim((string)($in['descripcion_problema'] ?? ''));

        if (!$idSala || !isset($salas[$idSala])) {
            return $err('Selecciona la sala donde ocurrió el problema.');
        }
        if ($existente) {
            return $err("Ya existe un reporte de daño en curso para esa sala (estado: {$existente['estado']}). El equipo de mantenimiento ya lo tiene identificado — no es necesario reportarlo de nuevo.");
        }
        if ($descripcion === '') {
            return $err('Describe el problema.');
        }
        $nombreSala = $salas[$idSala];
        return self::guardarTicketProfesor($pdo, $idProfesor, [
            'profesor_id' => $idProfesor, 'tipo_ticket' => 'danio', 'id_sala' => $idSala, 'sala_danio' => $nombreSala, 'descripcion_problema' => $descripcion,
        ], 'Nuevo ticket de daño de profesor pendiente (ticket #{id})',
            static function (int $id) use ($pdo, $idsEquipo, $idsComponente): void {
                if (!empty($idsEquipo) || !empty($idsComponente)) { TicketActivos::agregarActivosATicket($pdo, TablaTicket::PROFESOR, $id, $idsEquipo, $idsComponente); }
            },
            // Un daño reportado desde el panel del profesor también avisa a beca_trabajo, que es quien va al salón.
            static function (int $id) use ($pdo, $nombreSala): void {
                if (TicketConsultas::esTicketDeSistemas($pdo, TablaTicket::PROFESOR, $id)) { // las becas solo atienden el Departamento
                    NotificationService::notificarPorRol($pdo, Rol::BECA, 'TICKET_ESTADO', "Nuevo daño reportado en {$nombreSala} (panel de profesor)", 'tickets.php');
                }
            });
    }
}
