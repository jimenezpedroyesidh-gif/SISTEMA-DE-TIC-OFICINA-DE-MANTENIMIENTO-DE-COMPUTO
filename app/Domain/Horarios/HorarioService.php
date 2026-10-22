<?php
/**
 * HorarioService — casos de uso del módulo Horarios (crear, editar, borrar,
 * excepciones «solo esta semana», vaciar periodo, permiso de edición para
 * secretaría y verificación de choques).
 *
 * Los textos son los mismos que mostraba public/horarios.php antes de extraer esta clase. El SQL está en
 * HorarioRepository.
 *
 * Cambios funcionales respecto a la página original (documentados en
 * claude/MODULO_SALAS_Y_HORARIOS.md):
 *  - Un id de sala o de persona que no existe devuelve un mensaje de validación.
 *  - Borrar un horario (o vaciar un periodo) con excepciones asociadas ya no
 *    falla por la clave foránea: se borran también sus excepciones.
 *  - Las excepciones y el vaciado de periodo ahora SÍ quedan en auditoría.
 *  - Las horas de una excepción «cambio de hora» se validan con validarHora().
 */
require_once __DIR__ . '/../../Support/validacion.php';

final class HorarioService
{
    public const TIPOS = [
        'CLASE' => 'Clase',
        'TURNO_BECA' => 'Turno de beca-trabajo',
        'TURNO_TECNICO' => 'Turno de técnico',
    ];
    public const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
    public const TIPOS_EXCEPCION = ['CANCELADO', 'CAMBIO_SALA', 'CAMBIO_HORA'];

    private static function ok(string $m): array { return ['ok' => true, 'mensaje' => $m]; }
    private static function error(string $m): array { return ['ok' => false, 'mensaje' => $m]; }

    // ───────────────────────── Permisos

    /**
     * Qué puede hacer este usuario en la pantalla de horarios.
     * Ver: admin, técnico, beca, profesor, secretaria. Escribir: admin/técnico
     * siempre, y secretaria solo con users.permiso_horarios_extra = 1.
     *
     * @return array{alcance:?array,tienePermisoSecretaria:bool,puedeVerTodo:bool,puedeModificar:bool}
     */
    public static function permisos(PDO $pdo, string $rol, int $idUsuario): array
    {
        $tiene = $rol === Rol::SECRETARIA && HorarioRepository::permisoSecretaria($pdo, $idUsuario);
        // Secretaria con permiso: alcance limitado a las salas que el admin le habilitó + los turnos de las
        // becas de la secretaría. null = sin límite (admin, técnico, vigilante).
        $alcance = null;
        if ($rol === Rol::SECRETARIA && $tiene) {
            $alcance = ['salas' => HorarioRepository::salasDeSecretaria($pdo, $idUsuario), 'becas' => HorarioRepository::becasDeSecretaria($pdo)];
        }
        return [
            'alcance' => $alcance,
            'tienePermisoSecretaria' => $tiene,
            // Todas las salas: admin y técnico siempre; la secretaria solo si el admin le activó el permiso.
            // El vigilante ve todas las salas (clases, préstamos y mantenimientos) pero nunca edita.
            'puedeVerTodo' => in_array($rol, [Rol::ADMIN, Rol::TECNICO, Rol::VIGILANTE], true) || $tiene,
            'puedeModificar' => in_array($rol, [Rol::ADMIN, Rol::TECNICO], true) || $tiene,
        ];
    }

    // ───────────────────────── Alcance (secretaría)

    /**
     * ¿Esta franja entra en el alcance? Sí si está en una sala habilitada, o si es el turno de una beca
     * de la secretaría. $alcance null = sin límite.
     */
    public static function dentroDeAlcance(?array $alcance, ?int $idSala, ?string $tipo, ?int $idUsuario): bool
    {
        if ($alcance === null) return true;
        if ($idSala !== null && in_array($idSala, $alcance['salas'], true)) return true;
        return $tipo === 'TURNO_BECA' && $idUsuario !== null && in_array($idUsuario, $alcance['becas'], true);
    }

    private static function horarioEnAlcance(PDO $pdo, ?array $alcance, int $idHorario): bool
    {
        if ($alcance === null) return true;
        $h = HorarioRepository::alcanceDeHorario($pdo, $idHorario);
        return $h !== null && self::dentroDeAlcance($alcance, $h['id_sala'] !== null ? (int)$h['id_sala'] : null, (string)$h['tipo'], $h['id_usuario'] !== null ? (int)$h['id_usuario'] : null);
    }

    private const FUERA_DE_ALCANCE = "Solo puedes crear o editar horarios de las salas que te habilitó el administrador o los turnos de las becas de la secretaría.";

    // ───────────────────────── Horarios recurrentes

    /**
     * Valida y normaliza los campos de crear/editar. Devuelve [datos, null] o [null, mensajeError].
     * @return array{0:?array,1:?string}
     */
    private static function normalizar(PDO $pdo, array $in, string $periodoActual, ?int $idHorario, bool $esEdicion): array
    {
        $idSala = !empty($in['id_sala']) ? (int)$in['id_sala'] : null;
        $idUsuario = !empty($in['id_usuario']) ? (int)$in['id_usuario'] : null;
        $tipo = (string)($in['tipo'] ?? '');
        $dia = (int)($in['dia_semana'] ?? 0);
        $horaInicio = validarHora($in['hora_inicio'] ?? '') ?? '';
        $horaFin = validarHora($in['hora_fin'] ?? '') ?? '';
        $detalle = trim((string)($in['detalle'] ?? ''));
        $periodo = trim((string)($in['periodo'] ?? '')) ?: $periodoActual;

        if (($esEdicion && !$idHorario) || !isset(self::TIPOS[$tipo]) || $dia < 1 || $dia > 7 || $horaInicio === '' || $horaFin === '') {
            return [null, "Completa tipo, día y horas."];
        }
        if (!HorarioPeriodo::valido($periodo)) {
            return [null, "Periodo académico inválido."];
        }
        if ($tipo === 'CLASE' && !$idSala) {
            return [null, "Una clase siempre necesita una sala."];
        }
        if ($horaFin <= $horaInicio) {
            return [null, "La hora de fin debe ser mayor a la hora de inicio."];
        }
        if ($idSala && !HorarioRepository::salaExiste($pdo, $idSala)) {
            return [null, "La sala seleccionada no existe."];
        }
        if ($idUsuario && !HorarioRepository::usuarioExiste($pdo, $idUsuario)) {
            return [null, "La persona seleccionada no existe."];
        }
        // Varias becas/técnicos pueden compartir horario y sala; lo que no puede pasar es que la misma
        // persona tenga dos turnos a la vez, ni que una sala tenga dos clases a la vez.
        $cruce = HorarioRepository::cruce($pdo, $tipo, $idSala, $idUsuario, $dia, $horaInicio, $horaFin, $idHorario, $periodo);
        if ($cruce === 'sala') {
            return [null, "Esa franja se cruza con otra clase ya registrada en la misma sala ese día (mismo periodo)."];
        }
        if ($cruce === 'persona') {
            return [null, "Esa franja se cruza con otro turno de la misma persona ese día (mismo periodo)."];
        }
        return [[
            'id_sala' => $idSala, 'id_usuario' => $idUsuario, 'tipo' => $tipo, 'dia' => $dia, 'periodo' => $periodo,
            'hora_inicio' => $horaInicio, 'hora_fin' => $horaFin, 'detalle' => ($detalle !== '' ? $detalle : null),
        ], null];
    }

    public static function crear(PDO $pdo, int $idActor, array $in, string $periodoActual, ?array $alcance = null): array
    {
        [$d, $err] = self::normalizar($pdo, $in, $periodoActual, null, false);
        if ($err !== null) return self::error($err);
        if (!self::dentroDeAlcance($alcance, $d['id_sala'], $d['tipo'], $d['id_usuario'])) return self::error(self::FUERA_DE_ALCANCE);
        $nuevoId = HorarioRepository::insertar($pdo, $d, $idActor);
        AuditoriaService::registrar($pdo, $idActor, 'horarios', $nuevoId, 'CREAR', "tipo={$d['tipo']} dia={$d['dia']} periodo={$d['periodo']} {$d['hora_inicio']}-{$d['hora_fin']}");
        MantenimientoAgenda::avisarCruces($pdo, $d['id_sala'], 'Nueva franja en el horario');
        return self::ok("Horario creado correctamente.");
    }

    public static function editar(PDO $pdo, int $idActor, int $idHorario, array $in, string $periodoActual, ?array $alcance = null): array
    {
        if (!self::horarioEnAlcance($pdo, $alcance, $idHorario)) return self::error(self::FUERA_DE_ALCANCE);
        [$d, $err] = self::normalizar($pdo, $in, $periodoActual, $idHorario, true);
        if ($err !== null) return self::error($err);
        if (!self::dentroDeAlcance($alcance, $d['id_sala'], $d['tipo'], $d['id_usuario'])) return self::error(self::FUERA_DE_ALCANCE);
        HorarioRepository::actualizar($pdo, $idHorario, $d);
        AuditoriaService::registrar($pdo, $idActor, 'horarios', $idHorario, 'EDITAR', "tipo={$d['tipo']} dia={$d['dia']} periodo={$d['periodo']} {$d['hora_inicio']}-{$d['hora_fin']}");
        MantenimientoAgenda::avisarCruces($pdo, $d['id_sala'], 'Cambio en el horario');
        return self::ok("Horario actualizado.");
    }

    /** Un id vacío o 0 no hace nada: devuelve null para que la página no muestre mensaje. */
    public static function borrar(PDO $pdo, int $idActor, int $idHorario, ?array $alcance = null): ?array
    {
        if (!$idHorario) return null;
        if (!self::horarioEnAlcance($pdo, $alcance, $idHorario)) return self::error(self::FUERA_DE_ALCANCE);
        HorarioRepository::borrar($pdo, $idHorario);
        AuditoriaService::registrar($pdo, $idActor, 'horarios', $idHorario, 'BORRAR');
        return self::ok("Horario eliminado.");
    }

    // ───────────────────────── Excepciones «solo esta semana»

    public static function guardarExcepcion(PDO $pdo, int $idActor, array $in, ?array $alcance = null): array
    {
        if (!self::horarioEnAlcance($pdo, $alcance, (int)($in['id_horario'] ?? 0))) return self::error(self::FUERA_DE_ALCANCE);
        $idHorario = (int)($in['id_horario'] ?? 0);
        $fecha = (string)($in['fecha'] ?? '');
        $tipo = (string)($in['tipo_excepcion'] ?? '');
        $nuevaSala = !empty($in['nueva_id_sala']) ? (int)$in['nueva_id_sala'] : null;
        $nuevaInicio = trim((string)($in['nueva_hora_inicio'] ?? ''));
        $nuevaFin = trim((string)($in['nueva_hora_fin'] ?? ''));
        $motivo = trim((string)($in['motivo'] ?? ''));

        $ref = HorarioRepository::buscarBasico($pdo, $idHorario);
        $fechaObj = DateTime::createFromFormat('Y-m-d', $fecha);

        if (!$ref) return self::error("Ese horario ya no existe.");
        if (!$fechaObj || $fechaObj->format('Y-m-d') !== $fecha) return self::error("Fecha inválida para la excepción.");
        if ((int)$fechaObj->format('N') !== (int)$ref['dia_semana']) return self::error("Esa fecha no corresponde al día de la semana de este horario.");
        if (!in_array($tipo, self::TIPOS_EXCEPCION, true)) return self::error("Selecciona qué tipo de cambio quieres hacer solo esta semana.");
        if ($tipo === 'CAMBIO_SALA' && !$nuevaSala) return self::error("Selecciona la sala nueva para esta semana.");
        if ($tipo === 'CAMBIO_SALA' && !HorarioRepository::salaExiste($pdo, $nuevaSala)) return self::error("La sala seleccionada no existe.");
        if ($tipo === 'CAMBIO_SALA' && $alcance !== null && !in_array($nuevaSala, $alcance['salas'], true)) return self::error("Solo puedes mover la franja a una sala que te habilitó el administrador.");
        if ($tipo === 'CAMBIO_HORA' && (validarHora($nuevaInicio) === null || validarHora($nuevaFin) === null || $nuevaFin <= $nuevaInicio)) {
            return self::error("Indica una hora de inicio y fin válidas (fin después de inicio) para esta semana.");
        }

        $creada = HorarioRepository::guardarExcepcion(
            $pdo, $idHorario, $fecha, $tipo,
            $tipo === 'CAMBIO_SALA' ? $nuevaSala : null,
            $tipo === 'CAMBIO_HORA' ? $nuevaInicio : null,
            $tipo === 'CAMBIO_HORA' ? $nuevaFin : null,
            $motivo !== '' ? $motivo : null,
            $idActor
        );
        AuditoriaService::registrar($pdo, $idActor, 'excepciones_horario', $idHorario, $creada ? 'CREAR' : 'EDITAR', "excepcion fecha=$fecha tipo=$tipo");
        // La clase se mueve a otra sala u otra hora solo esta semana: puede caer encima de un mantenimiento.
        MantenimientoAgenda::avisarCruces($pdo, $tipo === 'CAMBIO_SALA' ? $nuevaSala : (int)$ref['id_sala'], 'Cambio de horario de esta semana');
        return self::ok("Cambio guardado solo para esta semana ($fecha). El horario recurrente sigue igual las demás.");
    }

    public static function borrarExcepcion(PDO $pdo, int $idActor, int $idExcepcion, ?array $alcance = null): ?array
    {
        if (!$idExcepcion) return null;
        if ($alcance !== null) {
            $idH = HorarioRepository::horarioDeExcepcion($pdo, $idExcepcion);
            if ($idH === null || !self::horarioEnAlcance($pdo, $alcance, $idH)) return self::error(self::FUERA_DE_ALCANCE);
        }
        HorarioRepository::borrarExcepcion($pdo, $idExcepcion);
        AuditoriaService::registrar($pdo, $idActor, 'excepciones_horario', $idExcepcion, 'BORRAR');
        return self::ok("Excepción eliminada: esa fecha vuelve a mostrar el horario normal.");
    }

    // ───────────────────────── Vaciar periodo

    public static function vaciarPeriodo(PDO $pdo, int $idActor, string $periodo, string $confirmacion, ?array $alcance = null): array
    {
        if ($alcance !== null) return self::error("Vaciar el horario de todo el semestre es solo del técnico o el administrador.");
        $periodo = trim($periodo);
        if (!HorarioPeriodo::valido($periodo)) return self::error("Selecciona el periodo que quieres vaciar.");
        if (trim($confirmacion) !== 'BORRAR') return self::error("Para vaciar el horario debes escribir BORRAR (en mayúsculas) en el campo de confirmación.");
        $cantidad = HorarioRepository::borrarPorPeriodo($pdo, $periodo);
        AuditoriaService::registrar($pdo, $idActor, 'horarios', 0, 'BORRAR', "VACIAR_PERIODO periodo=$periodo franjas_borradas=$cantidad");
        return self::ok("Se vació el horario del periodo $periodo ($cantidad franjas eliminadas). Ya puedes cargar el horario nuevo del semestre.");
    }

    // ───────────────────────── Permiso de edición para secretaría (solo admin)

    /**
     * El admin puede dar a UNA secretaria el poder de editar horarios sin cambiarle el rol.
     * Se valida en servidor que el objetivo sea 'secretaria': nunca sirve para escalar a otro rol.
     * @return array{ok:bool,mensaje:string}
     */
    public static function cambiarPermisoSecretaria(PDO $pdo, string $rolActor, int $idActor, int $idObjetivo, int $otorgar): array
    {
        if ($rolActor !== Rol::ADMIN) {
            return ['ok' => false, 'mensaje' => 'No autorizado.'];
        }
        $otorgar = $otorgar === 1 ? 1 : 0;
        $rolObjetivo = HorarioRepository::rolDeUsuario($pdo, $idObjetivo);
        if (!$idObjetivo || $rolObjetivo !== Rol::SECRETARIA) {
            return ['ok' => false, 'mensaje' => "Ese usuario no existe o no tiene rol de secretaria."];
        }
        HorarioRepository::fijarPermisoSecretaria($pdo, $idObjetivo, $otorgar);
        AuditoriaService::registrar(
            $pdo, $idActor, 'users', $idObjetivo, 'EDITAR',
            $otorgar ? 'permiso_horarios_extra=1 (otorgado por admin)' : 'permiso_horarios_extra=0 (revocado por admin)'
        );
        return ['ok' => true, 'mensaje' => $otorgar
            ? "Permiso de edición de horarios otorgado a esa secretaria."
            : "Permiso de edición de horarios revocado."];
    }

    /**
     * El admin elige qué salas puede ver y editar una secretaria en Horarios.
     * @param int[] $idsSala
     */
    public static function guardarSalasSecretaria(PDO $pdo, string $rolActor, int $idActor, int $idObjetivo, array $idsSala): array
    {
        if ($rolActor !== Rol::ADMIN) return self::error('No autorizado.');
        if (!$idObjetivo || HorarioRepository::rolDeUsuario($pdo, $idObjetivo) !== Rol::SECRETARIA) {
            return self::error("Ese usuario no existe o no tiene rol de secretaria.");
        }
        $validas = [];
        foreach (array_unique(array_map('intval', $idsSala)) as $idSala) {
            if ($idSala > 0 && HorarioRepository::salaExiste($pdo, $idSala)) $validas[] = $idSala;
        }
        try {
            Database::transaccion(fn(PDO $pdo) => HorarioRepository::fijarSalasSecretaria($pdo, $idObjetivo, $validas, $idActor), $pdo);
        } catch (PDOException $e) {
            return self::error("Falta la tabla secretaria_salas: vuelve a importar database/tickets_db.sql.");
        }
        AuditoriaService::registrar($pdo, $idActor, 'users', $idObjetivo, 'EDITAR', 'salas de horarios de secretaria: ' . ($validas ? implode(',', $validas) : 'ninguna'));
        return self::ok(count($validas) . " sala(s) habilitada(s) para esa secretaria.");
    }

    // ───────────────────────── Choques (AJAX de mantenimientos_programados)

    /**
     * Eventos que ocupan esa sala a esa fecha/hora (horario recurrente con su
     * excepción aplicada, préstamos, otros mantenimientos). Formato que lee
     * public/assets/mantenimientos.js: {tipo, hora_inicio, hora_fin, detalle, usuario}.
     */
    public static function choques(PDO $pdo, int $idSala, string $fechaHora): array
    {
        $ts = $idSala && $fechaHora !== '' ? strtotime($fechaHora) : false;
        if (!$ts) return [];
        $choques = HorarioEventos::choques($pdo, $idSala, date('Y-m-d', $ts), date('H:i', $ts));
        return array_map(function ($c) {
            return [
                'tipo' => $c['titulo'],
                'hora_inicio' => $c['hora_inicio'],
                'hora_fin' => $c['hora_fin'],
                'detalle' => $c['detalle'],
                'usuario' => $c['usuario_nombre'] ?? $c['profesor_nombre'] ?? null,
            ];
        }, $choques);
    }
}
