<?php
/**
 * MantenimientoService — casos de uso de «Mantenimientos programados».
 *
 * Cada método devuelve ['error' => string, 'exito' => string] (y a veces 'http' => código). No genera HTML ni
 * lee $_POST/$_SESSION/$_FILES: el Controller le pasa los datos ya extraídos.
 */
require_once __DIR__ . '/../../Support/lib_inventario.php';

final class MantenimientoService
{
    public const TIPOS = ['MANTENIMIENTO_PREVENTIVO', 'INSTALACION_SOFTWARE', 'OTRO'];
    public const ALCANCES = ['TODOS', 'COMPUTO', 'SELECCION', 'VIDEOBEAM'];
    /** Alcance => [título, explicación] para el formulario y el listado. */
    public const ALCANCE_TEXTO = [
        'TODOS'     => ['Toda la sala', 'Equipos de cómputo, videobeam y parlantes.'],
        'COMPUTO'   => ['Solo equipos de cómputo', 'Todos los computadores de la sala, sin videobeam.'],
        'SELECCION' => ['Ciertos equipos', 'Eliges qué equipos de la sala se van a revisar.'],
        'VIDEOBEAM' => ['Solo el videobeam', 'El videobeam de la sala, sin checklist de equipos.'],
    ];
    public const ESTADOS_CIERRE = ['EN_PROCESO', 'REALIZADO', 'CANCELADO'];
    /** Estados en curso (todo lo que no está cerrado). PENDIENTE = espera a la sala anterior de su campaña. */
    public const ESTADOS_ACTIVOS = MantenimientoAgenda::ESTADOS_ACTIVOS;
    /** Duraciones que ofrecen los formularios (minutos => texto). El servidor acepta de 15 a 960. */
    public const DURACIONES = [30 => '30 minutos', 60 => '1 hora', 90 => '1 hora y media', 120 => '2 horas', 180 => '3 horas', 240 => '4 horas', 360 => '6 horas', 480 => '8 horas (jornada)'];
    public const RESULTADOS = ['RESUELTO' => 'Resuelto', 'PARCIAL' => 'Resuelto en parte', 'NO_RESUELTO' => 'No resuelto'];
    private const MSG_BLOQUEADA = 'Esta sala está en espera: en la campaña hay que terminar la sala anterior antes de empezar esta.';
    public const ORIGENES_ELEVABLES = ['QR_ANONIMO', 'BECA_DIRECTO', 'TECNICO_DIRECTO'];

    private const MSG_NO_TOMADO = 'Debes tomar este ticket antes de poder elevarlo a mantenimiento.';
    private const MSG_TICKET_FIRMADO = 'Este ticket ya fue marcado como Solucionado/Firmado — no se puede elevar a mantenimiento un ticket que ya está resuelto.';
    private const MSG_TICKET_PROF_SOLUCIONADO = 'Este ticket de profesor ya fue marcado como Solucionado/Firmado — no se puede elevar a mantenimiento un ticket que ya está resuelto.';

    private static function r(string $error = '', string $exito = '', ?int $http = null): array
    {
        return ['error' => $error, 'exito' => $exito, 'http' => $http];
    }

    // ───────────────────────── Permisos por rol (los usa el Controller)

    public static function permisos(string $rol): array
    {
        return [
            // Solo el técnico programa (igual que aprobar/rechazar «Daño/error»); el admin supervisa.
            'puedeProgramar'     => $rol === Rol::TECNICO,
            'puedeVerCompleto'   => in_array($rol, [Rol::ADMIN, Rol::TECNICO], true),
            // Tomar/asignar/liberar/marcar equipos: técnico y beca. El admin solo supervisa (ve el plano, no opera).
            'puedeMarcarEquipos' => in_array($rol, [Rol::TECNICO, Rol::BECA], true),
        ];
    }

    // ───────────────────────── Prefill «Elevar a mantenimiento de sala»

    /**
     * Revalida en servidor el ticket de origen (nunca se confía en el GET). Solo para el técnico.
     * @return array{error:string,idSala:int,idTicketOrigen:int,idTicketProfesorOrigen:int,yaElevadoA:?int}
     */
    public static function prefill(PDO $pdo, int $userId, int $idTicketElevar, int $idTicketProfesorElevar): array
    {
        $out = ['error' => '', 'idSala' => 0, 'idTicketOrigen' => 0, 'idTicketProfesorOrigen' => 0, 'yaElevadoA' => null];

        if ($idTicketElevar) {
            $t = MantenimientoRepository::ticketParaElevar($pdo, $idTicketElevar);
            if ($t && (int)($t['tecnico_id'] ?? 0) !== $userId) {
                $out['error'] = self::MSG_NO_TOMADO;
            } elseif ($t && !empty($t['firmado'])) {
                $out['error'] = self::MSG_TICKET_FIRMADO;
            } elseif ($t && !empty($t['id_sala']) && in_array($t['origen'], self::ORIGENES_ELEVABLES, true)) {
                $existente = MantenimientoRepository::programadoDeTicket($pdo, $idTicketElevar);
                if ($existente) {
                    $out['yaElevadoA'] = $existente;
                } else {
                    $out['idSala'] = (int)$t['id_sala'];
                    $out['idTicketOrigen'] = $idTicketElevar;
                }
            }
        }

        if ($idTicketProfesorElevar) {
            $tp = MantenimientoRepository::ticketProfesorParaElevar($pdo, $idTicketProfesorElevar);
            if ($tp && (int)($tp['tecnico_id'] ?? 0) !== $userId) {
                $out['error'] = self::MSG_NO_TOMADO;
                $tp = null; // corta el resto de la validación
            }
            $esElegible = $tp && (
                $tp['tipo_ticket'] === 'danio'
                || ($tp['tipo_ticket'] === 'requerimiento' && $tp['subtipo_requerimiento'] === 'INSTALACION_SOFTWARE')
                || ($tp['tipo_ticket'] === 'requerimiento' && $tp['subtipo_requerimiento'] === 'PRESTAMO_SALA' && !empty($tp['software_especifico']))
            );
            // `firmado=1` es la señal definitiva de cierre (al aprobar queda 'Aprobado', no 'Solucionado').
            $yaSolucionado = $tp && (in_array($tp['estado'], [TicketWorkflow::SOLUCIONADO, TicketWorkflow::CERRADO], true) || !empty($tp['firmado']));
            if ($tp && $yaSolucionado) {
                $out['error'] = self::MSG_TICKET_PROF_SOLUCIONADO;
            } elseif ($tp && !$tp['id_sala']) {
                $out['error'] = 'Este ticket no tiene una sala real asociada (viene de antes de que existiera el selector de sala) — no se puede elevar automáticamente. Regístralo de nuevo o asígnale una sala manualmente en la base de datos.';
            } elseif ($esElegible && !empty($tp['id_sala'])) {
                $existente = MantenimientoRepository::programadoDeTicketProfesor($pdo, $idTicketProfesorElevar);
                if ($existente) {
                    $out['yaElevadoA'] = $existente;
                } else {
                    $out['idSala'] = (int)$tp['id_sala'];
                    $out['idTicketProfesorOrigen'] = $idTicketProfesorElevar;
                }
            }
        }
        return $out;
    }

    // ───────────────────────── Programar (solo técnico)

    /**
     * @param array $in claves: id_sala, tipo, alcance_equipo, ids_equipo[] (con alcance SELECCION), descripcion, enlace_referencia,
     *                  fecha_programada, id_responsable, confirmar_choque, confirmar_sin_equipos,
     *                  id_ticket_origen, id_ticket_profesor_origen (valores crudos del formulario)
     */
    public static function programar(PDO $pdo, int $userId, array $in): array
    {
        $idSala = (int)($in['id_sala'] ?? 0);
        $tipo = (string)($in['tipo'] ?? '');
        $alcance = (string)($in['alcance_equipo'] ?? 'TODOS');
        $idsPedidos = array_values(array_unique(array_filter(array_map('intval', (array)($in['ids_equipo'] ?? [])))));
        if (!empty($in['id_equipo'])) {            // un solo equipo (enlaces antiguos): es una selección de uno
            $alcance = 'SELECCION';
            $idsPedidos = [(int)$in['id_equipo']];
        }
        $descripcion = trim((string)($in['descripcion'] ?? ''));
        $enlace = trim((string)($in['enlace_referencia'] ?? ''));
        $enlaceInvalido = false;
        if ($enlace !== '' && !filter_var($enlace, FILTER_VALIDATE_URL)) {
            $enlace = null;
            $enlaceInvalido = true;
        } elseif ($enlace === '') {
            $enlace = null;
        }
        $fecha = (string)($in['fecha_programada'] ?? '');
        $idResponsable = !empty($in['id_responsable']) ? (int)$in['id_responsable'] : null;
        // El coordinador general solo puede ser técnico; un id manipulado de beca se descarta.
        if ($idResponsable !== null && MantenimientoRepository::rolDeUsuario($pdo, $idResponsable) !== Rol::TECNICO) {
            $idResponsable = null;
        }
        $confirmaChoque = ($in['confirmar_choque'] ?? '') === '1';
        $confirmaSinEquipos = ($in['confirmar_sin_equipos'] ?? '') === '1';
        $duracion = MantenimientoAgenda::duracion($in['duracion_minutos'] ?? 60);
        $actividades = trim((string)($in['actividades'] ?? ''));
        $vinculosPedidos = self::leerVinculos($in['vinculos'] ?? []);
        // Acción correctiva del análisis de semestre que se está programando (solo si sigue pendiente).
        $accion = !empty($in['id_accion_origen']) ? MantenimientoHistorialService::prefillDesdeAccion($pdo, (int)$in['id_accion_origen']) : null;

        // Ticket de origen: revalidado igual que el prefill (el campo oculto no basta).
        $idTicketOrigenPost = !empty($in['id_ticket_origen']) ? (int)$in['id_ticket_origen'] : null;
        $idTicketOrigen = null;
        $noTomado = $firmado = false;
        if ($idTicketOrigenPost) {
            $tv = MantenimientoRepository::ticketParaProgramar($pdo, $idTicketOrigenPost);
            if ($tv && (int)($tv['tecnico_id'] ?? 0) !== $userId) {
                $noTomado = true;
            } elseif ($tv && !empty($tv['firmado'])) {
                $firmado = true;
            } elseif ($tv && in_array($tv['origen'], self::ORIGENES_ELEVABLES, true)) {
                $idTicketOrigen = $idTicketOrigenPost;
            }
        }
        $idTicketProfesorOrigenPost = !empty($in['id_ticket_profesor_origen']) ? (int)$in['id_ticket_profesor_origen'] : null;
        $idTicketProfesorOrigen = null;
        $profNoTomado = $profSolucionado = false;
        if ($idTicketProfesorOrigenPost) {
            $tvp = MantenimientoRepository::ticketProfesorParaProgramar($pdo, $idTicketProfesorOrigenPost);
            $elegible = $tvp && (
                $tvp['tipo_ticket'] === 'danio'
                || ($tvp['tipo_ticket'] === 'requerimiento' && $tvp['subtipo_requerimiento'] === 'INSTALACION_SOFTWARE')
            );
            if ($tvp && (int)($tvp['tecnico_id'] ?? 0) !== $userId) {
                $profNoTomado = true;
            } elseif ($tvp && (in_array($tvp['estado'], [TicketWorkflow::SOLUCIONADO, TicketWorkflow::CERRADO], true) || !empty($tvp['firmado']))) {
                $profSolucionado = true;
            } elseif ($tvp && $elegible && !empty($tvp['id_sala'])) {
                $idTicketProfesorOrigen = $idTicketProfesorOrigenPost;
            }
        }

        if (!in_array($alcance, self::ALCANCES, true)) {
            $alcance = 'TODOS';
        }
        $esSoftware = $tipo === 'INSTALACION_SOFTWARE';
        // No tiene sentido instalar software en un videobeam: toda la sala o el videobeam pasan a «solo cómputo».
        if ($esSoftware && $alcance !== 'SELECCION') {
            $alcance = 'COMPUTO';
        }
        $idsEquipo = $alcance === 'SELECCION' && $idSala ? MantenimientoRepository::equiposValidosDeSala($pdo, $idSala, $idsPedidos) : [];
        $equipoIncompatible = $esSoftware && $idsEquipo
            && count(MantenimientoRepository::equiposValidosDeSala($pdo, $idSala, $idsEquipo, true)) !== count($idsEquipo);
        $idEquipo = count($idsEquipo) === 1 ? $idsEquipo[0] : null;
        $totalEquiposSala = !$idSala ? 0 : ($alcance === 'SELECCION' ? count($idsEquipo) : MantenimientoRepository::contarEquiposAlcance($pdo, $idSala, $alcance));

        if ($noTomado || $profNoTomado) {
            return self::r(self::MSG_NO_TOMADO);
        }
        if ($profSolucionado) {
            return self::r(self::MSG_TICKET_PROF_SOLUCIONADO);
        }
        if ($firmado) {
            return self::r(self::MSG_TICKET_FIRMADO);
        }
        if (!$idSala || !in_array($tipo, self::TIPOS, true) || $descripcion === '' || $fecha === '') {
            return self::r('Completa sala, tipo, descripción y fecha programada.');
        }
        if ($enlaceInvalido) {
            return self::r('El enlace de referencia no es una URL válida (debe empezar por http:// o https://).');
        }
        if (!MantenimientoRepository::salaExiste($pdo, $idSala)) {
            return self::r('La sala seleccionada no existe.');
        }
        if ($alcance === 'SELECCION' && !$idsPedidos) {
            return self::r('Elige al menos un equipo de la sala, o cambia el alcance a «Toda la sala».');
        }
        if ($alcance === 'SELECCION' && count($idsEquipo) !== count($idsPedidos)) {
            return self::r('Uno de los equipos elegidos no existe o no es de esta sala.');
        }
        $ts = strtotime($fecha);
        if (!$ts) {
            return self::r('La fecha programada no es válida.');
        }
        if ($equipoIncompatible) {
            return self::r('No se puede programar una instalación de software sobre estos equipos: alguno no tiene torre, portátil o todo en uno (por ejemplo, es un videobeam). Elige solo equipos de cómputo.');
        }
        if ($totalEquiposSala === 0 && $alcance === 'VIDEOBEAM') {
            return self::r('Esta sala no tiene ningún videobeam registrado: regístralo en la sala (frente al tablero) antes de programar su mantenimiento.');
        }
        if ($totalEquiposSala === 0 && !$confirmaSinEquipos) {
            return self::r('Esta sala no tiene ningún equipo registrado en el inventario (gestionar_salas.php). Si programas el mantenimiento así, la checklist quedará vacía y ningún beca podrá sumarse a marcar equipos. Registra los equipos de la sala primero, o marca la casilla de confirmación si de verdad quieres continuar sin checklist.');
        }
        if ($idTicketOrigen && ($previo = MantenimientoRepository::programadoDeTicket($pdo, $idTicketOrigen)) > 0) {
            return self::r("Este ticket ya fue elevado al mantenimiento #{$previo} — un ticket solo puede escalarse una vez.");
        }
        if ($idTicketProfesorOrigen && ($previo = MantenimientoRepository::programadoDeTicketProfesor($pdo, $idTicketProfesorOrigen)) > 0) {
            return self::r("Este ticket de profesor ya fue elevado al mantenimiento #{$previo} — solo se puede escalar una vez.");
        }

        // Reportes vinculados: solo los que este técnico puede atender en esa sala (revalidado, nunca se confía en el form).
        $permitidos = [];
        foreach (MantenimientoRepository::ticketsVinculables($pdo, $userId, $idSala) as $v) {
            $permitidos[$v['tabla'] . ':' . (int)$v['id']] = true;
        }
        unset($permitidos['tickets:' . (int)$idTicketOrigen], $permitidos['tickets_profesor:' . (int)$idTicketProfesorOrigen]);
        foreach ($vinculosPedidos as $clave) {
            if (empty($permitidos[$clave])) {
                return self::r('Uno de los reportes que quieres vincular ya no se puede atender en este mantenimiento (cambió de estado, es de otra sala o lo atiende otro técnico).');
            }
        }

        // Advertencia no bloqueante: la sala (o el coordinador) está ocupada en algún momento de la duración estimada.
        $choques = MantenimientoAgenda::choques($pdo, $idSala, new DateTime(date('Y-m-d H:i:s', $ts)), $duracion, null, $idResponsable);
        if (!empty($choques) && !$confirmaChoque) {
            $c = $choques[0];
            return self::r('Esa sala tiene ' . MantenimientoAgenda::texto($c) . '. Marca la casilla de confirmación si deseas programar igual.');
        }

        $idNuevo = Database::transaccion(function (PDO $pdo) use ($idSala, $idEquipo, $idsEquipo, $tipo, $alcance, $descripcion, $actividades, $enlace, $fecha, $duracion, $idResponsable, $userId, $idTicketOrigen, $idTicketProfesorOrigen, $vinculosPedidos, $accion) {
            $id = MantenimientoRepository::insertar($pdo, $idSala, $idEquipo, $tipo, $alcance, $descripcion, $enlace, $fecha, $idResponsable, $userId, $idTicketOrigen, $idTicketProfesorOrigen, $duracion, $actividades !== '' ? $actividades : null);
            MantenimientoRepository::generarChecklist($pdo, $id, $idSala, $alcance, $idsEquipo);
            foreach ($vinculosPedidos as $clave) {
                [$tabla, $idT] = explode(':', $clave);
                MantenimientoRepository::vincular($pdo, $id, $tabla, (int)$idT, $userId);
            }
            if ($accion) {
                MantenimientoHistorialRepository::enlazarAccion($pdo, $accion['id_accion'], $id);
            }
            return $id;
        }, $pdo, 'programar_mant');
        MantenimientoAgenda::limpiarCache();

        AuditoriaService::registrar($pdo, $userId, 'mantenimientos_programados', $idNuevo, 'CREAR',
            $idTicketOrigen ? "elevado desde ticket #$idTicketOrigen" : ($idTicketProfesorOrigen ? "elevado desde ticket de profesor #$idTicketProfesorOrigen" : ''));
        foreach ($vinculosPedidos as $clave) {
            [$tabla, $idT] = explode(':', $clave);
            $dueno = $tabla === TablaTicket::NORMAL ? MantenimientoRepository::duenoTicket($pdo, (int)$idT) : MantenimientoRepository::duenoTicketProfesor($pdo, (int)$idT);
            if ($dueno) {
                NotificationService::crear($pdo, (int)$dueno, 'MANTENIMIENTO',
                    "Tu reporte #{$idT} se atenderá en el mantenimiento de sala programado para el " . date('d/m/Y H:i', $ts) . '.', 'tickets.php');
            }
        }

        // Notificación de ARRANQUE al dueño del ticket de origen (distinta de la del cierre).
        if ($idTicketProfesorOrigen && ($dueno = MantenimientoRepository::duenoTicketProfesor($pdo, $idTicketProfesorOrigen))) {
            NotificationService::crear($pdo, (int)$dueno, 'MANTENIMIENTO',
                "Se programó un mantenimiento general para la sala de tu ticket #{$idTicketProfesorOrigen}.", 'tickets.php');
        }
        if ($idTicketOrigen && ($dueno = MantenimientoRepository::duenoTicket($pdo, $idTicketOrigen))) {
            NotificationService::crear($pdo, (int)$dueno, 'MANTENIMIENTO',
                "Se programó un mantenimiento general para la sala de tu ticket #{$idTicketOrigen}.", 'tickets.php');
        }
        return self::r('', 'Mantenimiento programado correctamente.');
    }

    /** "tickets:12" / "tickets_profesor:7" sin repetidos; ignora lo que no tenga esa forma. */
    private static function leerVinculos($valor): array
    {
        $out = [];
        foreach ((array)$valor as $v) {
            if (is_string($v) && preg_match('/^(tickets|tickets_profesor):([1-9]\d*)$/', $v)) {
                $out[$v] = true;
            }
        }
        return array_keys($out);
    }

    // ───────────────────────── Cambiar estado del mantenimiento (admin/técnico)

    /** ¿Este archivo subido cuenta como evidencia? (mismas reglas que guardarAdjunto). */
    public static function evidenciaValida(array $f): bool
    {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return false;
        }
        if (($f['size'] ?? 0) > TicketAdjuntos::ADJUNTOS_TAMANO_MAXIMO) {
            return false;
        }
        $mime = @mime_content_type($f['tmp_name'] ?? '');
        return $mime !== false && in_array($mime, TicketAdjuntos::ADJUNTOS_MIME_PERMITIDOS, true);
    }

    /** @param array $archivos lista de archivos de $_FILES['evidencia'] ya normalizada (uno por elemento) */
    public static function cambiarEstado(PDO $pdo, int $userId, string $rol, int $id, string $nuevoEstado, string $observaciones, array $archivos, string $resultado = ''): array
    {
        $m = $id ? MantenimientoRepository::buscar($pdo, $id) : false;
        // Solo se actualiza lo que sigue en curso (un mantenimiento cerrado no se vuelve a cerrar).
        $puedeEjecutar = $m && in_array($rol, [Rol::ADMIN, Rol::TECNICO], true) && in_array($m['estado'], self::ESTADOS_ACTIVOS, true);
        $resultado = isset(self::RESULTADOS[$resultado]) ? $resultado : null;

        // Regla dura: no se cierra con un equipo pendiente/en proceso/incompleto (se valida aquí, no solo en la UI).
        $checklistIncompleta = $m && $nuevoEstado === 'REALIZADO' && MantenimientoRepository::contarChecklistPendiente($pdo, $id) > 0;
        // Evidencia fotográfica obligatoria para Realizado.
        $evidencias = array_values(array_filter($archivos, [self::class, 'evidenciaValida']));
        $sinEvidencia = $m && $nuevoEstado === 'REALIZADO' && count($evidencias) === 0;

        if (!$m || !$puedeEjecutar || !in_array($nuevoEstado, self::ESTADOS_CIERRE, true)) {
            return self::r('No puedes actualizar este mantenimiento.');
        }
        if ($m['estado'] === 'PENDIENTE' && $nuevoEstado !== 'CANCELADO') {
            return self::r(self::MSG_BLOQUEADA);
        }
        if ($checklistIncompleta) {
            return self::r('No puedes marcar este mantenimiento como Realizado: todavía hay equipos de la sala pendientes, en proceso o rechazados en la checklist. Corrige o completa esos equipos primero.');
        }
        if ($sinEvidencia) {
            return self::r('Adjunta al menos una foto de evidencia (mostrando los equipos ya con el mantenimiento hecho) antes de marcar como Realizado.');
        }

        if ($nuevoEstado === 'REALIZADO' || $nuevoEstado === 'CANCELADO') {
            MantenimientoRepository::cerrar($pdo, $id, $nuevoEstado, $observaciones, $userId, $nuevoEstado === 'REALIZADO' ? $resultado : null);
            if ($nuevoEstado === 'REALIZADO') {
                foreach ($evidencias as $archivo) {
                    TicketAdjuntos::guardarAdjunto($pdo, $archivo, $userId, null, null, null, $id);
                }
            }
            if (!empty($m['id_ticket_origen'])) {
                self::cerrarOrigen($pdo, $m, $id, $nuevoEstado, false);
            }
            if (!empty($m['id_ticket_profesor_origen'])) {
                self::cerrarOrigen($pdo, $m, $id, $nuevoEstado, true);
            }
            // Reportes vinculados: mismo trato que el ticket de origen (es la misma intervención).
            foreach (MantenimientoRepository::vinculosDe($pdo, [$id])[$id] ?? [] as $v) {
                $mv = $m;
                $esProf = $v['tabla_ticket'] === TablaTicket::PROFESOR;
                $mv[$esProf ? 'id_ticket_profesor_origen' : 'id_ticket_origen'] = (int)$v['id_ticket'];
                self::cerrarOrigen($pdo, $mv, $id, $nuevoEstado, $esProf, false);
            }
            // Campaña: libera la siguiente sala (si es secuencial) y la da por finalizada si ya no queda nada.
            if (!empty($m['id_campana'])) {
                MantenimientoCampanaService::alCerrarIntervencion($pdo, (int)$m['id_campana'], $userId);
            } elseif ($nuevoEstado === 'REALIZADO') {
                MantenimientoHistorialRepository::marcarHechasPorMantenimiento($pdo, [$id]); // acción correctiva cumplida
            } else {
                MantenimientoHistorialRepository::liberarAccionesPorMantenimiento($pdo, [$id]);
            }
        } else { // EN_PROCESO
            MantenimientoRepository::marcarEnProceso($pdo, $id, $nuevoEstado, $userId);
        }
        AuditoriaService::registrar($pdo, $userId, 'mantenimientos_programados', $id, 'EDITAR', "estado=$nuevoEstado");
        return self::r('', 'Mantenimiento actualizado.');
    }

    /** Avisa al dueño del ticket de origen y, si quedó REALIZADO, lo cierra y avisa a los participantes. */
    private static function cerrarOrigen(PDO $pdo, array $m, int $id, string $nuevoEstado, bool $esProfesor, bool $avisarParticipantes = true): void
    {
        $idOrigen = (int)($esProfesor ? $m['id_ticket_profesor_origen'] : $m['id_ticket_origen']);
        $dueno = $esProfesor ? MantenimientoRepository::duenoTicketProfesor($pdo, $idOrigen) : MantenimientoRepository::duenoTicket($pdo, $idOrigen);
        if ($dueno) {
            $etiqueta = $nuevoEstado === 'REALIZADO' ? 'Probado y resuelto' : 'Cancelado';
            NotificationService::crear($pdo, (int)$dueno, 'MANTENIMIENTO',
                "El mantenimiento de tu ticket #{$idOrigen} quedó $etiqueta",
                TicketConsultas::enlaceTicketParaUsuario($pdo, (int)$dueno, ($esProfesor ? 'ticket_profesor_pdf.php' : 'ticket_pdf.php') . "?id={$idOrigen}"));
        }
        if ($nuevoEstado !== 'REALIZADO') {
            return;
        }
        // El ticket que originó el mantenimiento refleja el cierre (no se escribe `archivado=1`: esa columna no
        // existe en el esquema).
        if ($esProfesor) {
            MantenimientoRepository::cerrarTicketProfesorOrigen($pdo, $idOrigen);
        } else {
            MantenimientoRepository::cerrarTicketOrigen($pdo, $idOrigen);
        }
        if (!$avisarParticipantes) {
            return;
        }
        // Participantes: quien dejó marca en la checklist, el creador y el responsable.
        $ids = MantenimientoRepository::participantesChecklist($pdo, $id);
        if (!empty($m['id_creador'])) { $ids[] = $m['id_creador']; }
        if (!empty($m['id_responsable'])) { $ids[] = $m['id_responsable']; }
        $texto = $esProfesor
            ? "El mantenimiento general de la sala (ticket de profesor #{$idOrigen}) quedó Cerrado definitivamente."
            : "El mantenimiento general de la sala (ticket #{$idOrigen}) quedó Cerrado definitivamente.";
        foreach (array_unique(array_filter($ids)) as $idParticipante) {
            NotificationService::crear($pdo, (int)$idParticipante, 'MANTENIMIENTO', $texto, 'mantenimientos_programados.php');
        }
    }

    // ───────────────────────── Disponibilidad (formulario de programar)

    /**
     * Para el formulario: qué se cruza con [fecha, fecha + duración) y, si $buscar, el primer espacio libre
     * desde esa fecha (o desde ahora). Respuesta con los nombres que lee mantenimientos.js.
     */
    public static function disponibilidad(PDO $pdo, int $idSala, string $fecha, int $duracion, ?int $idResponsable, bool $buscar): array
    {
        if (!$idSala || !MantenimientoRepository::salaExiste($pdo, $idSala)) {
            return ['choques' => [], 'sugerencia' => null];
        }
        $duracion = MantenimientoAgenda::duracion($duracion);
        $ts = $fecha !== '' ? strtotime($fecha) : false;
        $choques = [];
        if ($ts) {
            foreach (MantenimientoAgenda::choques($pdo, $idSala, new DateTime(date('Y-m-d H:i:00', $ts)), $duracion, null, $idResponsable) as $c) {
                $choques[] = ['texto' => MantenimientoAgenda::texto($c), 'hora_inicio' => $c['hora_inicio'], 'hora_fin' => $c['hora_fin']];
            }
        }
        $sugerencia = null;
        if ($buscar) {
            $desde = ($ts && $ts > time()) ? new DateTime(date('Y-m-d H:i:00', $ts)) : new DateTime('+30 minutes');
            $h = MantenimientoAgenda::primerHueco($pdo, $idSala, $desde, $duracion, $idResponsable);
            $sugerencia = $h ? $h->format('Y-m-d\TH:i') : null;
        }
        return ['choques' => $choques, 'sugerencia' => $sugerencia];
    }

    // ───────────────────────── Reprogramar (solo técnico)

    /**
     * Mueve un mantenimiento que todavía no empieza a otra fecha y guarda el cambio en el historial.
     * $nuevaFecha vacía = primer espacio libre de la sala (y del coordinador) desde ahora.
     * Si la fecha elegida choca, avisa igual que al programar (se puede confirmar).
     */
    public static function reprogramar(PDO $pdo, int $userId, int $id, string $nuevaFecha, string $motivo, bool $confirmaChoque): array
    {
        $m = $id ? MantenimientoRepository::buscar($pdo, $id) : false;
        if (!$m || !in_array($m['estado'], ['PENDIENTE', 'PROGRAMADO', 'REPROGRAMADO'], true)) {
            return self::r('Solo se puede reprogramar un mantenimiento que todavía no ha empezado.');
        }
        $duracion = MantenimientoAgenda::duracion($m['duracion_minutos'] ?? 60);
        $idResp = !empty($m['id_responsable']) ? (int)$m['id_responsable'] : null;
        $actual = new DateTime((string)$m['fecha_programada']);
        // ¿Lo movemos porque algo se le cruzó? (préstamo, clase u otro mantenimiento registrado después)
        $teniaConflicto = (bool)MantenimientoAgenda::choques($pdo, (int)$m['id_sala'], $actual, $duracion, $id, $idResp);

        if ($nuevaFecha === '') {
            $nueva = MantenimientoAgenda::primerHueco($pdo, (int)$m['id_sala'], new DateTime('+30 minutes'), $duracion, $idResp, $id);
            if (!$nueva) {
                return self::r('No hay un espacio libre en esa sala en los próximos ' . MantenimientoAgenda::DIAS_BUSQUEDA . ' días. Elige la fecha a mano.');
            }
        } else {
            $ts = strtotime($nuevaFecha);
            if (!$ts) {
                return self::r('La fecha nueva no es válida.');
            }
            $nueva = new DateTime(date('Y-m-d H:i:00', $ts));
            $choques = MantenimientoAgenda::choques($pdo, (int)$m['id_sala'], $nueva, $duracion, $id, $idResp);
            if ($choques && !$confirmaChoque) {
                return self::r('Esa sala tiene ' . MantenimientoAgenda::texto($choques[0]) . '. Marca la casilla de confirmación si deseas reprogramar igual.');
            }
        }
        if ($nueva->format('Y-m-d H:i') === $actual->format('Y-m-d H:i')) {
            return self::r('La fecha nueva es la misma que ya tenía.');
        }
        self::moverFecha($pdo, $m, $nueva, $teniaConflicto ? 'CONFLICTO' : 'MANUAL', $motivo, $userId);
        return self::r('', 'Mantenimiento reprogramado para el ' . $nueva->format('d/m/Y H:i') . '. El cambio quedó en su historial.');
    }

    /**
     * Cambia la fecha, deja el registro en mantenimiento_reprogramaciones y avisa a quien lo coordina y a
     * quienes ya tomaron equipos. Un PENDIENTE (bloqueado por la campaña) sigue PENDIENTE.
     * Lo usa también la campaña al liberar la siguiente sala.
     */
    public static function moverFecha(PDO $pdo, array $m, DateTime $nueva, string $tipo, string $motivo, ?int $userId): void
    {
        $id = (int)$m['id_programado'];
        $estado = $m['estado'] === 'PENDIENTE' ? 'PENDIENTE' : 'REPROGRAMADO';
        Database::transaccion(function (PDO $pdo) use ($m, $id, $nueva, $tipo, $motivo, $userId, $estado) {
            MantenimientoRepository::registrarReprogramacion($pdo, $id, (string)$m['fecha_programada'], $nueva->format('Y-m-d H:i:s'), $tipo, $motivo !== '' ? mb_substr($motivo, 0, 255) : null, $userId);
            MantenimientoRepository::cambiarFecha($pdo, $id, $nueva->format('Y-m-d H:i:s'), $estado);
            return true;
        }, $pdo, 'reprogramar_mant');
        MantenimientoAgenda::limpiarCache();
        if ($userId) {
            AuditoriaService::registrar($pdo, $userId, 'mantenimientos_programados', $id, 'EDITAR', "reprogramado ($tipo) a " . $nueva->format('Y-m-d H:i'));
        }
        $avisar = MantenimientoRepository::participantesChecklist($pdo, $id);
        $avisar[] = $m['id_responsable'] ?? null;
        $texto = "El mantenimiento #$id de la sala se movió al " . $nueva->format('d/m/Y H:i') . ($tipo === 'CONFLICTO' ? ' por un cruce con la agenda de la sala.' : '.');
        foreach (array_unique(array_filter(array_map('intval', $avisar))) as $idU) {
            if ($idU !== $userId) {
                NotificationService::crear($pdo, $idU, 'MANTENIMIENTO', $texto, 'mantenimientos_programados.php');
            }
        }
    }

    // ───────────────────────── Checklist por equipo

    /** El técnico asigna un equipo directamente a un técnico o beca (el admin no asigna: solo supervisa). */
    public static function asignarEquipo(PDO $pdo, int $userId, int $idMantEquipo, int $idProgramado, int $idAsignar): array
    {
        if (MantenimientoRepository::estadoDe($pdo, $idProgramado) === 'PENDIENTE') {
            return self::r(self::MSG_BLOQUEADA);
        }
        $nombre = $idAsignar ? MantenimientoRepository::nombreStaff($pdo, $idAsignar) : false;
        if (!$idMantEquipo || !$idProgramado || !$idAsignar || !$nombre) {
            return self::r('Selecciona un equipo y un becario/técnico válido para asignar.');
        }
        if (MantenimientoRepository::asignarEquipo($pdo, $idMantEquipo, $idProgramado, $idAsignar, $userId) === 0) {
            return self::r('Ese equipo ya quedó Realizado, o no existe — no se puede reasignar.');
        }
        AuditoriaService::registrar($pdo, $userId, 'mantenimiento_equipos', $idMantEquipo, 'EDITAR', "asignado a usuario #$idAsignar");
        NotificationService::crear($pdo, $idAsignar, 'MANTENIMIENTO', "El técnico te asignó un equipo en el mantenimiento #$idProgramado.", 'mantenimientos_programados.php');
        return self::r('', "Equipo asignado a $nombre.");
    }

    public static function tomarEquipo(PDO $pdo, int $userId, int $idMantEquipo, int $idProgramado): array
    {
        if (MantenimientoRepository::estadoDe($pdo, $idProgramado) === 'PENDIENTE') {
            return self::r(self::MSG_BLOQUEADA);
        }
        if (MantenimientoRepository::tomarEquipo($pdo, $idMantEquipo, $idProgramado, $userId) === 0) {
            return self::r('Ese equipo ya fue tomado por otra persona, o no existe.');
        }
        AuditoriaService::registrar($pdo, $userId, 'mantenimiento_equipos', $idMantEquipo, 'EDITAR', 'estado=TOMADO');
        // Avisa al técnico responsable (o al creador) de que un beca se sumó.
        $prog = MantenimientoRepository::responsables($pdo, $idProgramado);
        if ($prog) {
            $aviso = !empty($prog['id_responsable']) ? (int)$prog['id_responsable'] : (int)$prog['id_creador'];
            if ($aviso && $aviso !== $userId) {
                NotificationService::crear($pdo, $aviso, 'MANTENIMIENTO', "Se sumó un becario y tomó un equipo del mantenimiento #$idProgramado.", 'mantenimientos_programados.php');
            }
        }
        return self::r('', 'Tomaste este equipo. Ya puedes trabajar en él.');
    }

    /** Libera un TOMADO: su dueño, o el técnico. Sin mensaje si no había nada que liberar. */
    public static function liberarEquipo(PDO $pdo, int $userId, string $rol, int $idMantEquipo): array
    {
        $soloDueno = $rol === Rol::TECNICO ? null : $userId;
        if (MantenimientoRepository::liberarEquipo($pdo, $idMantEquipo, $soloDueno) === 0) {
            return self::r();
        }
        AuditoriaService::registrar($pdo, $userId, 'mantenimiento_equipos', $idMantEquipo, 'EDITAR', 'liberado a PENDIENTE');
        return self::r('', 'Equipo liberado.');
    }

    public static function marcarEquipo(PDO $pdo, int $userId, string $rol, int $idMantEquipo, int $idProgramado, string $nuevoEstado, string $obs, ?int $idCompSaliente, ?int $idCompEntrante): array
    {
        if ($nuevoEstado === 'INCOMPLETO' && $obs === '') {
            return self::r('Si marcas incompleto, deja una observación de qué falta.');
        }
        if (!$idMantEquipo || !$idProgramado || !in_array($nuevoEstado, ['REALIZADO', 'INCOMPLETO'], true)) {
            return self::r('Datos incompletos para marcar el equipo.');
        }
        if (MantenimientoRepository::estadoDe($pdo, $idProgramado) === 'PENDIENTE') {
            return self::r(self::MSG_BLOQUEADA);
        }
        // Un beca solo marca lo que él mismo tomó; el técnico puede marcar cualquiera como respaldo (el admin no opera equipos).
        $eq = MantenimientoRepository::filaChecklist($pdo, $idMantEquipo, $idProgramado);
        $puede = $eq && ($rol === Rol::TECNICO || (int)$eq['id_usuario_asignado'] === $userId);
        if (!$puede) {
            return self::r($eq && !empty($eq['id_usuario_asignado'])
                ? 'Este equipo ya fue tomado por otro becario — solo quien lo tomó (o el técnico) puede marcarlo.'
                : 'Primero debes tomar este equipo antes de marcarlo.', '', 403);
        }
        if (MantenimientoRepository::marcarEquipo($pdo, $idMantEquipo, $idProgramado, $nuevoEstado, $userId, $obs !== '' ? $obs : null, $idCompSaliente, $idCompEntrante) === 0) {
            return self::r('Ese equipo ya fue marcado por otra persona, o no existe.');
        }
        AuditoriaService::registrar($pdo, $userId, 'mantenimiento_equipos', $idMantEquipo, 'EDITAR', "estado=$nuevoEstado");

        // Avisos al técnico responsable (o al creador): cada cambio, y cuando queda el último equipo.
        $prog = MantenimientoRepository::responsables($pdo, $idProgramado);
        $aviso = null;
        if ($prog) {
            $aviso = !empty($prog['id_responsable']) ? (int)$prog['id_responsable'] : (int)$prog['id_creador'];
        }
        if ($aviso && $aviso !== $userId) {
            NotificationService::crear($pdo, $aviso, 'MANTENIMIENTO', "Se marcó un equipo como $nuevoEstado en el mantenimiento #$idProgramado.", 'mantenimientos_programados.php');
        }
        if ($aviso && MantenimientoRepository::contarChecklistPendiente($pdo, $idProgramado) === 0) {
            NotificationService::crear($pdo, $aviso, 'MANTENIMIENTO', "Todos los equipos del mantenimiento #$idProgramado ya quedaron realizados — puedes hacer la revisión final.", 'mantenimientos_programados.php');
        }
        return self::r('', 'Equipo actualizado en la checklist.');
    }

    /** Revertir una marca a PENDIENTE (solo técnico; el Controller ya filtró el rol). */
    public static function revertirEquipo(PDO $pdo, int $userId, int $idMantEquipo): array
    {
        if (!$idMantEquipo) {
            return self::r();
        }
        MantenimientoRepository::revertirEquipo($pdo, $idMantEquipo, $userId);
        AuditoriaService::registrar($pdo, $userId, 'mantenimiento_equipos', $idMantEquipo, 'EDITAR', 'revertido a PENDIENTE');
        return self::r('', 'Marca revertida.');
    }

    /** Aprueba el cambio de pieza propuesto en una fila (solo técnico) y lo aplica al inventario. */
    public static function aprobarPieza(PDO $pdo, int $userId, int $idMantEquipo, int $idProgramado): array
    {
        $me = MantenimientoRepository::filaChecklistCompleta($pdo, $idMantEquipo, $idProgramado);
        if (!$me || (empty($me['id_componente_saliente']) && empty($me['id_componente_entrante'])) || $me['estado'] === 'TRASLADO_PENDIENTE') {
            return self::r(); // un traslado a bodega se aprueba con aprobarTraslado()
        }
        try {
            InventarioService::aprobarCambioPiezaMantenimiento($pdo, $me, $userId, $idProgramado);
            AuditoriaService::registrar($pdo, $userId, 'mantenimiento_equipos', $idMantEquipo, 'EDITAR', 'cambio de pieza aprobado');
            return self::r('', 'Cambio de pieza aprobado y aplicado al inventario.');
        } catch (CambioPiezaNoAplicable $e) {
            return self::r($e->getMessage()); // no es técnico, o ya se aplicó / las piezas se movieron
        } catch (Throwable $e) {
            return self::r('No se pudo aplicar el cambio de pieza.');
        }
    }

    /**
     * Traslado a la oficina de mantenimiento (bodega) desde la checklist: el equipo no se pudo arreglar en la sala.
     * Quien lo tomó (o el técnico) elige la pieza que se lleva (la torre, normalmente) y explica por qué; la fila
     * queda TRASLADO_PENDIENTE (cuenta como pendiente: el mantenimiento no se puede cerrar) hasta que el técnico apruebe.
     */
    public static function proponerTraslado(PDO $pdo, int $userId, string $rol, int $idMantEquipo, int $idProgramado, string $motivo, ?int $idComponente): array
    {
        if (!$idMantEquipo || !$idProgramado) {
            return self::r('Datos incompletos para el traslado.');
        }
        if ($motivo === '') {
            return self::r('Explica por qué se lleva a la oficina de mantenimiento (qué falla tiene).');
        }
        if (!$idComponente) {
            return self::r('Elige la pieza que se lleva a la oficina de mantenimiento (bodega).');
        }
        $eq = MantenimientoRepository::filaChecklist($pdo, $idMantEquipo, $idProgramado);
        if (!$eq || !($rol === Rol::TECNICO || (int)$eq['id_usuario_asignado'] === $userId)) {
            return self::r('Primero debes tomar este equipo.', '', 403);
        }
        if (!MantenimientoRepository::componenteEstaEnEquipoDeFila($pdo, $idComponente, $idMantEquipo)) {
            return self::r('Esa pieza no está instalada en este equipo.');
        }
        if (MantenimientoRepository::proponerTraslado($pdo, $idMantEquipo, $idProgramado, $userId, $motivo, $idComponente) === 0) {
            return self::r('El equipo debe estar tomado (y no marcado todavía) para proponer el traslado.');
        }
        AuditoriaService::registrar($pdo, $userId, 'mantenimiento_equipos', $idMantEquipo, 'EDITAR', "traslado a bodega propuesto (componente #$idComponente)");
        $prog = MantenimientoRepository::responsables($pdo, $idProgramado);
        $aviso = $prog ? (!empty($prog['id_responsable']) ? (int)$prog['id_responsable'] : (int)$prog['id_creador']) : 0;
        if ($aviso && $aviso !== $userId) {
            NotificationService::crear($pdo, $aviso, 'MANTENIMIENTO', "Se propone trasladar un equipo a la oficina de mantenimiento (mantenimiento #$idProgramado) — falta tu aprobación.", 'mantenimientos_programados.php');
        }
        return self::r('', 'Traslado propuesto: el técnico debe aprobarlo para mover la pieza a bodega.');
    }

    /**
     * El técnico aprueba el traslado: la pieza sale del equipo a la bodega (EN_REVISION, con historial y movimiento;
     * si es la torre/todo en uno el equipo queda no operativo) y la fila sale del mantenimiento (EXCLUIDO_TRASLADO).
     * Todo o nada.
     */
    public static function aprobarTraslado(PDO $pdo, int $userId, int $idMantEquipo, int $idProgramado): array
    {
        $me = MantenimientoRepository::filaChecklistCompleta($pdo, $idMantEquipo, $idProgramado);
        if (!$me || $me['estado'] !== 'TRASLADO_PENDIENTE' || empty($me['id_componente_saliente'])) {
            return self::r('Ese traslado ya no está pendiente.');
        }
        try {
            Database::transaccion(function (PDO $pdo) use ($me, $userId, $idMantEquipo, $idProgramado) {
                $sinEntrante = $me;
                $sinEntrante['id_componente_entrante'] = null;
                InventarioService::aprobarCambioPiezaMantenimiento($pdo, $sinEntrante, $userId, $idProgramado);
                MantenimientoRepository::cerrarTrasladoAprobado($pdo, $idMantEquipo, $userId);
                return true;
            }, $pdo, 'aprobar_traslado_mant');
        } catch (CambioPiezaNoAplicable $e) {
            return self::r($e->getMessage());
        } catch (Throwable $e) {
            return self::r('No se pudo mover la pieza a bodega; el traslado NO quedó aprobado.');
        }
        AuditoriaService::registrar($pdo, $userId, 'mantenimiento_equipos', $idMantEquipo, 'EDITAR', 'traslado a bodega aprobado');
        if (!empty($me['id_usuario_asignado']) && (int)$me['id_usuario_asignado'] !== $userId) {
            NotificationService::crear($pdo, (int)$me['id_usuario_asignado'], 'MANTENIMIENTO', "El técnico aprobó el traslado a bodega del equipo (mantenimiento #$idProgramado).", 'mantenimientos_programados.php');
        }
        return self::r('', 'Traslado aprobado: la pieza quedó en la oficina de mantenimiento (bodega) y el equipo salió de este mantenimiento.');
    }

    /** El técnico rechaza el traslado: el equipo vuelve a quien lo tenía, para resolverlo en la sala. */
    public static function rechazarTraslado(PDO $pdo, int $userId, int $idMantEquipo, int $idProgramado, string $motivo): array
    {
        $me = MantenimientoRepository::filaChecklistCompleta($pdo, $idMantEquipo, $idProgramado);
        if (!$me || MantenimientoRepository::rechazarTraslado($pdo, $idMantEquipo, $idProgramado, $userId, $motivo) === 0) {
            return self::r('Ese traslado ya no está pendiente.');
        }
        AuditoriaService::registrar($pdo, $userId, 'mantenimiento_equipos', $idMantEquipo, 'EDITAR', 'traslado a bodega rechazado');
        if (!empty($me['id_usuario_asignado']) && (int)$me['id_usuario_asignado'] !== $userId) {
            NotificationService::crear($pdo, (int)$me['id_usuario_asignado'], 'MANTENIMIENTO', "El técnico rechazó el traslado a bodega (mantenimiento #$idProgramado): revisa el equipo en la sala.", 'mantenimientos_programados.php');
        }
        return self::r('', 'Traslado rechazado: el equipo vuelve a quien lo tenía.');
    }

    // ───────────────────────── Datos de la pantalla

    /** Clase de chip por estado del mantenimiento (la usan el listado y la campaña). */
    public const CHIP_ESTADO = ['PENDIENTE' => 'incompleto', 'PROGRAMADO' => 'espera', 'REPROGRAMADO' => 'espera', 'EN_PROCESO' => 'proceso', 'REALIZADO' => 'solucionado', 'CANCELADO' => 'danio'];
    /** Chip de cada estado de un equipo de la checklist (también lo usan las soluciones técnicas). */
    public const CHIP_EQUIPO = ['PENDIENTE' => 'espera', 'TOMADO' => 'proceso', 'REALIZADO' => 'solucionado', 'INCOMPLETO' => 'incompleto', 'EXCLUIDO_TRASLADO' => 'espera', 'TRASLADO_PENDIENTE' => 'proceso'];

    /**
     * Lo que se muestra frente al tablero según el alcance: VIDEOBEAM solo el videobeam de la sala; COMPUTO
     * (incluida la instalación de software) nada; TODOS (mantenimiento general de sala) todo.
     */
    public static function frenteSegunAlcance(array $frente, ?string $alcance): array
    {
        return match ($alcance ?? 'TODOS') {
            'VIDEOBEAM' => array_values(array_filter($frente, fn($c) => $c['tipo'] === 'videobeam')),
            'COMPUTO', 'SELECCION' => [],
            default     => $frente,
        };
    }

    public static function datosPantalla(PDO $pdo, int $userId, bool $puedeVerCompleto, int $filtroSala, string $filtroEstado, int $paginaHistorial = 1, bool $puedeProgramar = false, int $filtroCampana = 0): array
    {
        $salas = MantenimientoRepository::salas($pdo);
        $responsablesPosibles = MantenimientoRepository::tecnicosYBecas($pdo);
        // Coordinador general: un beca nunca puede cerrar/cancelar, así que solo se ofrecen técnicos.
        $coordinadoresPosibles = array_values(array_filter($responsablesPosibles, fn($r) => $r['role'] === Rol::TECNICO));

        $listado = MantenimientoRepository::listarConHistorialPaginado($pdo, $puedeVerCompleto ? null : $userId, $filtroSala, $filtroEstado, $paginaHistorial, MantenimientoRepository::HISTORIAL_POR_PAGINA, $filtroCampana);
        $mantenimientos = $listado['datos'];
        $ids = array_map(fn($m) => (int)$m['id_programado'], $mantenimientos);
        $evidencias = [];
        foreach ($mantenimientos as $m) {
            if ($m['estado'] === 'REALIZADO') {
                $evidencias[(int)$m['id_programado']] = TicketAdjuntos::adjuntosDeMantenimiento($pdo, (int)$m['id_programado']);
            }
        }
        $checklists = MantenimientoRepository::checklistDe($pdo, $ids);
        // Estado real de cada equipo del plano (operativo, piezas retiradas, traslados con quién aprobó).
        $idsEquipo = [];
        foreach ($checklists as $filas) { foreach ($filas as $f) { $idsEquipo[] = (int)$f['id_equipo']; } }
        return [
            'salas'                  => $salas,
            'responsablesPosibles'   => $responsablesPosibles,
            'coordinadoresPosibles'  => $coordinadoresPosibles,
            'mantenimientos'         => $mantenimientos,
            'paginacionHistorial'    => $listado,
            'checklistPorProgramado' => $checklists,
            'estadoEquipos'          => PlanoSalaService::estadoEquipos($pdo, $idsEquipo, true),
            // Videobeam/parlantes de cada sala: se ven frente al tablero del plano (informativo, no van en la checklist).
            'frentePorSala'          => PlanoSalaService::frentePorSalas($pdo, array_map(fn($m) => (int)$m['id_sala'], $mantenimientos), true),
            // Distribución física guardada de cada sala (filas, mesas, número de puesto y punto de red).
            'distribucionPorSala'    => DistribucionSalaRepository::porSalas($pdo, array_map(fn($m) => (int)$m['id_sala'], $mantenimientos)),
            'adjuntosPorProgramado'  => $evidencias,
            'chipEstado'             => self::CHIP_ESTADO,
            'duraciones'             => self::DURACIONES,
            'resultados'             => self::RESULTADOS,
            // Préstamo/clase/otro mantenimiento que se cruzó después de programar (se ofrece reprogramar).
            'conflictosPorProgramado' => MantenimientoAgenda::conflictos($pdo, $mantenimientos),
            'vinculosPorProgramado'  => MantenimientoRepository::vinculosDe($pdo, $ids),
            'reprogramacionesPorProgramado' => MantenimientoRepository::reprogramacionesDe($pdo, $ids),
            // Reportes abiertos que el técnico puede atender junto con el mantenimiento (formulario de programar).
            'ticketsVinculables'     => $puedeProgramar ? MantenimientoRepository::ticketsVinculables($pdo, $userId, null) : [],
            'chipEquipo'             => self::CHIP_EQUIPO,
        ];
    }
}
