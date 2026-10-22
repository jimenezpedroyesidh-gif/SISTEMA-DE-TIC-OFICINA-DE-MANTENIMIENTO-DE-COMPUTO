<?php
/**
 * Acuerdos de nivel de servicio (SLA) de la mesa de ayuda, todo en horas HÁBILES:
 *  - Prioridad = impacto × urgencia (matriz ITIL). Se asigna sola según el origen, el tipo y la categoría;
 *    el admin la ajusta por ticket.
 *  - Primera respuesta: primer cambio de estado, primera asignación o primer comentario del personal.
 *  - Solución: último paso a un estado resuelto (Solucionado / Listo sin validar / Aprobado / Cerrado).
 *    Volver de un estado resuelto a uno abierto cuenta como reapertura.
 *  - El tiempo «en espera del usuario» (pausas) no cuenta para la solución.
 *  - Semáforo de los abiertos: a tiempo · por vencer (umbral configurable) · vencido · en pausa.
 * Las horas exactas salen de ticket_eventos (disparadores de la base), así que valen para TODOS los
 * flujos (panel, QR, beca, técnico, profesor) sin tocar cada uno.
 */
final class SlaService
{
    public const PRIORIDADES = ['CRITICA' => 'Crítica', 'ALTA' => 'Alta', 'MEDIA' => 'Media', 'BAJA' => 'Baja'];
    public const TIPOS = ['INCIDENTE' => 'Incidente', 'REQUERIMIENTO' => 'Requerimiento'];
    public const IMPACTOS = [1 => 'Alto · varios usuarios o un salón completo', 2 => 'Medio · un grupo o un puesto de clase', 3 => 'Bajo · un usuario'];
    public const URGENCIAS = [1 => 'Alta · detiene la clase o el trabajo', 2 => 'Media · hay alternativa temporal', 3 => 'Baja · puede esperar'];

    /** Matriz impacto (fila) × urgencia (columna) → prioridad. */
    private const MATRIZ = [
        1 => [1 => 'CRITICA', 2 => 'ALTA', 3 => 'MEDIA'],
        2 => [1 => 'ALTA', 2 => 'MEDIA', 3 => 'BAJA'],
        3 => [1 => 'MEDIA', 2 => 'BAJA', 3 => 'BAJA'],
    ];

    /** Semáforo: etiqueta y clase de chip (clases literales para el lint de CSS). */
    public const SEMAFORO = [
        'a_tiempo'   => ['A tiempo', 'chip solucionado'],
        'por_vencer' => ['Por vencer', 'chip espera'],
        'vencido'    => ['Vencido', 'chip danio'],
        'pausa'      => ['En pausa', 'chip'],
        'cumplido'   => ['Cumplió', 'chip proceso'],
        'incumplido' => ['No cumplió', 'chip danio'],
        'excluido'   => [TicketWorkflow::DESCARTADO, 'chip'],
    ];

    /** Segundos entre revisiones automáticas (avisos de vencimiento y retenciones). */
    public const INTERVALO_REVISION = 300;

    public static function prioridadDe(int $impacto, int $urgencia): string
    {
        $i = min(3, max(1, $impacto));
        $u = min(3, max(1, $urgencia));
        return self::MATRIZ[$i][$u];
    }

    /**
     * Impacto y urgencia automáticos de un ticket nuevo. @return array{0:int,1:int}
     * Seguridad → crítico; daño en un salón (QR o profesor) → alto; red/infraestructura → alto;
     * otro incidente → media; requerimiento de profesor → media; otro requerimiento → baja.
     */
    public static function impactoUrgenciaAutomaticos(string $tabla, string $tipo, ?string $categoria, ?string $origen): array
    {
        if ($categoria === 'SEGURIDAD') return [1, 1];
        if ($tipo === 'INCIDENTE') {
            if (in_array($origen, ['QR_ANONIMO', 'QR_PROFESOR', 'PANEL_PROFESOR'], true) || $tabla === TablaTicket::PROFESOR) return [2, 1];
            if (in_array($categoria, ['RED', 'INFRAESTRUCTURA'], true)) return [1, 2];
            return [2, 2];
        }
        return $tabla === TablaTicket::PROFESOR ? [2, 2] : [3, 2];
    }

    /** Crea la fila de servicio de los tickets que no la tienen (prioridad automática). Devuelve cuántas. */
    public static function completarFaltantes(PDO $pdo): int
    {
        $n = 0;
        foreach (SlaRepository::sinDatosSla($pdo) as $t) {
            $tipo = in_array($t['tipo'], ['INCIDENTE', 'REQUERIMIENTO'], true) ? $t['tipo'] : 'INCIDENTE';
            [$imp, $urg] = self::impactoUrgenciaAutomaticos((string)$t['tabla'], $tipo, $t['categoria'] ?? null, $t['origen'] ?? null);
            SlaRepository::insertarSla($pdo, (string)$t['tabla'], (int)$t['id'], $tipo, $imp, $urg, self::prioridadDe($imp, $urg));
            $n++;
        }
        return $n;
    }

    /**
     * Tickets (con filtros de SlaRepository::tickets) ya medidos contra su SLA.
     * Cada fila suma: prioridad, politica, primera_respuesta, solucion, reaperturas, seg_respuesta, seg_solucion,
     * seg_pausa, en_pausa, venc_respuesta, venc_solucion, cumple_respuesta, cumple_solucion, consumo, semaforo,
     * asignaciones (técnicos distintos), encuesta.
     * @return list<array<string,mixed>>
     */
    public static function medir(PDO $pdo, array $filtros = [], ?DateTimeImmutable $ahora = null, ?CalendarioHabil $cal = null): array
    {
        self::completarFaltantes($pdo);
        $tickets = SlaRepository::tickets($pdo, $filtros);
        if (!$tickets) return [];
        $ahora = $ahora ?? new DateTimeImmutable('now');
        $cal = $cal ?? CalendarioHabil::desdeBase($pdo);
        $politicas = SlaRepository::politicas($pdo);
        $umbral = max(1, min(99, ConfigSistema::entero($pdo, 'sla_umbral_aviso'))) / 100;

        $ids = [];
        foreach ($tickets as $t) $ids[$t['tabla']][] = (int)$t['id'];
        $eventos = SlaRepository::eventos($pdo, $ids);
        $asign = SlaRepository::asignaciones($pdo, $ids);
        $coment = SlaRepository::comentariosPersonal($pdo, $ids);
        $pausas = SlaRepository::pausas($pdo, $ids);
        $encuestas = SlaRepository::encuestas($pdo, $ids);

        $out = [];
        foreach ($tickets as $t) {
            $k = $t['tabla'] . '#' . (int)$t['id'];
            $out[] = self::medirUno($t, $eventos[$k] ?? [], $asign[$k] ?? [], $coment[$k] ?? [], $pausas[$k] ?? [], $encuestas[$k][0] ?? null, $politicas, $cal, $ahora, $umbral);
        }
        return $out;
    }

    public static function medirUno(array $t, array $eventos, array $asign, array $coment, array $pausas, ?array $encuesta,
                                    array $politicas, CalendarioHabil $cal, DateTimeImmutable $ahora, float $umbral = 0.75): array
    {
        $tz = $ahora->getTimezone();
        $dt = static fn($s): ?DateTimeImmutable => $s ? new DateTimeImmutable((string)$s, $tz) : null;
        $creacion = $dt($t['fecha_creacion']) ?? $ahora;
        $estado = (string)$t['estado'];
        $tipo = in_array($t['tipo'] ?? '', ['INCIDENTE', 'REQUERIMIENTO'], true) ? $t['tipo'] : 'INCIDENTE';
        $prioridad = isset(self::PRIORIDADES[$t['prioridad'] ?? '']) ? (string)$t['prioridad'] : 'MEDIA';
        $pol = $politicas[$tipo][$prioridad] ?? ['respuesta' => 8.0, 'solucion' => 40.0];
        $resuelto = in_array($estado, SlaRepository::ESTADOS_RESUELTOS, true);
        $descartado = $estado === TicketWorkflow::DESCARTADO;

        // Primera respuesta: lo primero que hizo el personal con el ticket.
        $cands = [];
        foreach ($eventos as $e) {
            if ($e['estado_anterior'] !== null && $e['estado_nuevo'] !== TicketWorkflow::EN_ESPERA) { $cands[] = $dt($e['fecha']); break; }
        }
        if ($asign) $cands[] = $dt($asign[0]['fecha_asignado']);
        if ($coment) $cands[] = $dt($coment[0]['fecha_creacion']);
        $cands = array_filter($cands, static fn($d) => $d !== null && $d >= $creacion);
        $primeraRespuesta = $cands ? min($cands) : null;

        // Solución y reaperturas a partir de los cambios de estado.
        $primeraSolucion = null; $ultimaSolucion = null; $reaperturas = 0;
        foreach ($eventos as $e) {
            $nuevoRes = in_array($e['estado_nuevo'], SlaRepository::ESTADOS_RESUELTOS, true);
            $antesRes = $e['estado_anterior'] !== null && in_array($e['estado_anterior'], SlaRepository::ESTADOS_RESUELTOS, true);
            if ($nuevoRes && !$antesRes) {
                $f = $dt($e['fecha']);
                $primeraSolucion = $primeraSolucion ?? $f;
                $ultimaSolucion = $f;
            }
            if ($antesRes && !$nuevoRes && $e['estado_nuevo'] !== TicketWorkflow::DESCARTADO) $reaperturas++;
        }
        $solucion = null;
        if ($resuelto) {
            $solucion = $ultimaSolucion ?? ($dt($t['fecha_terminacion'] ?? null) ?? $dt($t['fecha_firmado'] ?? null)); // datos anteriores a los eventos
            if ($solucion !== null && $solucion < $creacion) $solucion = null;
            $primeraSolucion = $primeraSolucion ?? $solucion;
        }
        if ($primeraRespuesta === null && $resuelto && $solucion !== null) $primeraRespuesta = $solucion; // se resolvió sin paso intermedio

        // Pausas («en espera del usuario»): su tiempo hábil se descuenta de la solución.
        $finReloj = $solucion ?? $ahora;
        $segPausa = 0; $enPausa = false;
        foreach ($pausas as $p) {
            $ini = $dt($p['inicio']); $fin = $dt($p['fin']) ?? $ahora;
            if ($p['fin'] === null && !$resuelto && !$descartado) $enPausa = true;
            if ($ini === null) continue;
            $ini = max($ini, $creacion); $fin = min($fin, $finReloj);
            if ($fin > $ini) $segPausa += $cal->segundosEntre($ini, $fin);
        }

        $limResp = (int)round($pol['respuesta'] * 3600);
        $limSol = (int)round($pol['solucion'] * 3600);
        $segResp = $primeraRespuesta ? $cal->segundosEntre($creacion, $primeraRespuesta) : null;
        $segSol = max(0, $cal->segundosEntre($creacion, $finReloj) - $segPausa);
        $vencResp = $cal->sumar($creacion, $limResp);
        $vencSol = $cal->sumar($creacion, $limSol + $segPausa);

        $cumpleResp = null; $cumpleSol = null;
        if (!$descartado) {
            if ($segResp !== null) $cumpleResp = $segResp <= $limResp;
            elseif ($cal->segundosEntre($creacion, $ahora) > $limResp) $cumpleResp = false;
            if ($resuelto && $solucion !== null) $cumpleSol = $segSol <= $limSol;
            elseif (!$resuelto && $segSol > $limSol) $cumpleSol = false;
        }
        $consumo = $limSol > 0 ? $segSol / $limSol : 0.0;

        if ($descartado) $sem = 'excluido';
        elseif ($resuelto) $sem = $cumpleSol === false ? 'incumplido' : 'cumplido';
        elseif ($enPausa) $sem = 'pausa';
        elseif ($consumo >= 1) $sem = 'vencido';
        elseif ($consumo >= $umbral) $sem = 'por_vencer';
        else $sem = 'a_tiempo';

        $tecnicos = array_unique(array_map(static fn($a) => (int)$a['id_tecnico'], $asign));
        $fmt = static fn(?DateTimeImmutable $d): ?string => $d ? $d->format('Y-m-d H:i:s') : null;

        return $t + [
            'tipo_sla'          => $tipo,
            'prioridad_sla'     => $prioridad,
            'politica'          => $pol,
            'primera_respuesta' => $fmt($primeraRespuesta),
            'primera_solucion'  => $fmt($primeraSolucion),
            'solucion'          => $fmt($solucion),
            'reaperturas'       => $reaperturas,
            'seg_respuesta'     => $segResp,
            'seg_solucion'      => ($resuelto && $solucion !== null) || !$resuelto ? $segSol : null,
            'seg_pausa'         => $segPausa,
            'en_pausa'          => $enPausa,
            'venc_respuesta'    => $fmt($vencResp),
            'venc_solucion'     => $fmt($vencSol),
            'cumple_respuesta'  => $cumpleResp,
            'cumple_solucion'   => $cumpleSol,
            'consumo'           => $consumo,
            'semaforo'          => $sem,
            'resuelto'          => $resuelto,
            'descartado'        => $descartado,
            'n_tecnicos'        => count($tecnicos),
            'encuesta'          => $encuesta,
        ];
    }

    /** Guarda en ticket_sla las fechas calculadas (trazabilidad y consultas externas). */
    public static function sincronizar(PDO $pdo, ?array $medidos = null): array
    {
        $medidos = $medidos ?? self::medir($pdo);
        foreach ($medidos as $m) {
            if ((string)($m['sla_primera_respuesta'] ?? '') === (string)($m['primera_respuesta'] ?? '')
                && (string)($m['sla_fecha_solucion'] ?? '') === (string)($m['solucion'] ?? '')
                && (int)($m['sla_reaperturas'] ?? 0) === (int)$m['reaperturas']) continue;
            SlaRepository::guardarDerivados($pdo, (string)$m['tabla'], (int)$m['id'], $m['primera_respuesta'], $m['primera_solucion'], $m['solucion'], (int)$m['reaperturas']);
        }
        return $medidos;
    }

    /**
     * Avisos de vencimiento: al responsable (o a técnicos y becas de mantenimiento si nadie lo tiene) cuando
     * el ticket pasa el umbral, y además a los admin cuando vence. Solo tickets creados desde que se activó
     * el módulo (sla_inicio_avisos), para no llenar la campanita con tickets antiguos. Devuelve cuántos avisó.
     */
    public static function revisarAvisos(PDO $pdo, ?array $medidos = null): int
    {
        $inicio = ConfigSistema::texto($pdo, 'sla_inicio_avisos');
        if ($inicio === '') {
            ConfigSistema::guardar($pdo, 'sla_inicio_avisos', date('Y-m-d H:i:s'), null);
            return 0;
        }
        $medidos = $medidos ?? self::medir($pdo, ['abiertos' => true]);
        $n = 0;
        foreach ($medidos as $m) {
            if ($m['resuelto'] || $m['descartado'] || (string)$m['fecha_creacion'] < $inicio) continue;
            $ref = ($m['tabla'] === TablaTicket::NORMAL ? 'Ticket #' : 'Ticket de profesor #') . (int)$m['id'] . ($m['nombre_sala'] ? ' (' . $m['nombre_sala'] . ')' : '');
            $vence = date('d/m H:i', strtotime((string)$m['venc_solucion']));
            if ($m['semaforo'] === 'vencido' && empty($m['aviso_vencido'])) {
                self::avisar($pdo, $m, "SLA vencido: $ref superó el tiempo de solución (" . self::PRIORIDADES[$m['prioridad_sla']] . ').', true);
                SlaRepository::marcarAviso($pdo, (string)$m['tabla'], (int)$m['id'], 'aviso_vencido');
                SlaRepository::marcarAviso($pdo, (string)$m['tabla'], (int)$m['id'], 'aviso_por_vencer');
                $n++;
            } elseif ($m['semaforo'] === 'por_vencer' && empty($m['aviso_por_vencer'])) {
                self::avisar($pdo, $m, "Por vencer: $ref debe quedar solucionado antes de $vence.", false);
                SlaRepository::marcarAviso($pdo, (string)$m['tabla'], (int)$m['id'], 'aviso_por_vencer');
                $n++;
            }
        }
        return $n;
    }

    private static function avisar(PDO $pdo, array $m, string $mensaje, bool $tambienAdmins): void
    {
        $mensaje = mb_substr($mensaje, 0, 250);
        if (!empty($m['id_responsable'])) {
            NotificationService::crear($pdo, (int)$m['id_responsable'], 'TICKET_ESTADO', $mensaje, 'tickets.php');
        } else {
            NotificationService::notificarPorRol($pdo, Rol::TECNICO, 'TICKET_ESTADO', $mensaje, 'tickets.php');
            if ($m['tipo_sla'] === 'INCIDENTE') NotificationService::notificarPorRol($pdo, Rol::BECA, 'TICKET_ESTADO', $mensaje, 'tickets.php');
        }
        if ($tambienAdmins) NotificationService::notificarAdmins($pdo, 'SISTEMA', $mensaje, 'sla.php');
    }

    /**
     * Revisión periódica, a lo sumo cada INTERVALO_REVISION segundos: sincroniza fechas, envía avisos de
     * vencimiento y aplica la retención de IP de los reportes anónimos. La llama ShellData en cada página con
     * sesión (no hace falta un cron) y también tools/revision_programada.php. Nunca interrumpe la página.
     */
    public static function revisionPeriodica(PDO $pdo, bool $forzar = false): bool
    {
        try {
            $ultima = ConfigSistema::entero($pdo, 'sla_ultima_revision');
            if (!$forzar && time() - $ultima < self::INTERVALO_REVISION) return false;
            ConfigSistema::guardar($pdo, 'sla_ultima_revision', (string)time(), null);
            $medidos = self::sincronizar($pdo);
            self::revisarAvisos($pdo, array_values(array_filter($medidos, static fn($m) => !$m['resuelto'] && !$m['descartado'])));
            SeguridadService::aplicarRetencionIp($pdo);
            IncidenteService::desdeTickets($pdo);
            return true;
        } catch (Throwable $e) {
            RegistroErrores::registrar('SLA: falló la revisión periódica', $e);
            return false;
        }
    }

    /** Admin: cambia impacto/urgencia de un ticket (la prioridad sale de la matriz). */
    public static function ajustarPrioridad(PDO $pdo, string $tabla, int $id, int $impacto, int $urgencia, int $idAdmin): array
    {
        if (!TablaTicket::valida($tabla) || !isset(self::IMPACTOS[$impacto]) || !isset(self::URGENCIAS[$urgencia])) {
            return ['ok' => false, 'mensaje' => 'Datos de prioridad no válidos.'];
        }
        self::completarFaltantes($pdo);
        $prioridad = self::prioridadDe($impacto, $urgencia);
        if (!SlaRepository::ajustarPrioridad($pdo, $tabla, $id, $impacto, $urgencia, $prioridad, $idAdmin)) {
            return ['ok' => true, 'mensaje' => 'La prioridad ya era ' . self::PRIORIDADES[$prioridad] . '.'];
        }
        AuditoriaService::registrar($pdo, $idAdmin, $tabla, $id, 'EDITAR', "prioridad SLA -> $prioridad (impacto $impacto, urgencia $urgencia)");
        return ['ok' => true, 'mensaje' => "Prioridad del ticket #$id: " . self::PRIORIDADES[$prioridad] . '.'];
    }

    /** Admin: detiene el reloj («en espera del usuario») o lo reanuda. */
    public static function alternarPausa(PDO $pdo, string $tabla, int $id, string $motivo, int $idAdmin): array
    {
        if (!TablaTicket::valida($tabla)) return ['ok' => false, 'mensaje' => 'Ticket no válido.'];
        $t = SlaRepository::tickets($pdo, ['tabla' => $tabla, 'id' => $id])[0] ?? null;
        if ($t === null) return ['ok' => false, 'mensaje' => 'El ticket no existe.'];
        $abierta = SlaRepository::pausaAbierta($pdo, $tabla, $id);
        if ($abierta !== null) {
            SlaRepository::cerrarPausa($pdo, (int)$abierta['id_pausa']);
            AuditoriaService::registrar($pdo, $idAdmin, $tabla, $id, 'EDITAR', 'SLA: reloj reanudado');
            return ['ok' => true, 'mensaje' => "Se reanudó el reloj del ticket #$id."];
        }
        if (!in_array($t['estado'], SlaRepository::ESTADOS_ABIERTOS, true)) {
            return ['ok' => false, 'mensaje' => 'Solo se pausa un ticket abierto.'];
        }
        $motivo = trim($motivo);
        if ($motivo === '' || mb_strlen($motivo) > 255) return ['ok' => false, 'mensaje' => 'Escribe el motivo de la pausa (máximo 255 caracteres).'];
        SlaRepository::abrirPausa($pdo, $tabla, $id, $motivo, $idAdmin);
        AuditoriaService::registrar($pdo, $idAdmin, $tabla, $id, 'EDITAR', 'SLA: reloj en pausa — ' . $motivo);
        return ['ok' => true, 'mensaje' => "El reloj del ticket #$id quedó en pausa."];
    }

    /** Admin: guarda la tabla de acuerdos (horas hábiles de respuesta y solución). */
    public static function guardarPoliticas(PDO $pdo, array $entrada, int $idAdmin): array
    {
        $filas = [];
        foreach (self::TIPOS as $tipo => $_) {
            foreach (self::PRIORIDADES as $prio => $nombre) {
                $r = str_replace(',', '.', (string)($entrada[$tipo][$prio]['respuesta'] ?? ''));
                $s = str_replace(',', '.', (string)($entrada[$tipo][$prio]['solucion'] ?? ''));
                if (!is_numeric($r) || !is_numeric($s) || (float)$r <= 0 || (float)$s <= 0 || (float)$r > 9999 || (float)$s > 9999) {
                    return ['ok' => false, 'mensaje' => "Revisa las horas de «" . self::TIPOS[$tipo] . " · {$nombre}»: deben ser números mayores que cero."];
                }
                if ((float)$r > (float)$s) {
                    return ['ok' => false, 'mensaje' => "En «" . self::TIPOS[$tipo] . " · {$nombre}» la respuesta no puede tardar más que la solución."];
                }
                $filas[] = [$tipo, $prio, round((float)$r, 2), round((float)$s, 2)];
            }
        }
        foreach ($filas as [$tipo, $prio, $r, $s]) SlaRepository::guardarPolitica($pdo, $tipo, $prio, $r, $s);
        AuditoriaService::registrar($pdo, $idAdmin, 'sla_politicas', null, 'EDITAR', 'acuerdos de servicio actualizados');
        return ['ok' => true, 'mensaje' => 'Acuerdos de servicio guardados.'];
    }

    /** Admin: guarda el horario de atención y los umbrales. */
    public static function guardarHorario(PDO $pdo, array $entrada, int $idAdmin): array
    {
        $semana = [];
        $hay = false;
        foreach (CalendarioHabil::DIAS as $n => $nombre) {
            if (empty($entrada['dia'][$n]['activo'])) { $semana[(string)$n] = null; continue; }
            $ini = (string)($entrada['dia'][$n]['inicio'] ?? '');
            $fin = (string)($entrada['dia'][$n]['fin'] ?? '');
            $si = CalendarioHabil::segundosDeHora($ini);
            $sf = CalendarioHabil::segundosDeHora($fin);
            if ($si === null || $sf === null || $sf <= $si) return ['ok' => false, 'mensaje' => "Revisa el horario del $nombre: la hora de cierre debe ser posterior a la de apertura."];
            $semana[(string)$n] = [substr($ini, 0, 5), substr($fin, 0, 5)];
            $hay = true;
        }
        if (!$hay) return ['ok' => false, 'mensaje' => 'Marca al menos un día de atención.'];
        $umbral = (int)($entrada['umbral'] ?? 75);
        $meta = (int)($entrada['meta'] ?? 90);
        if ($umbral < 50 || $umbral > 95) return ['ok' => false, 'mensaje' => 'El aviso «por vencer» debe estar entre 50 % y 95 % del tiempo.'];
        if ($meta < 50 || $meta > 100) return ['ok' => false, 'mensaje' => 'La meta de cumplimiento debe estar entre 50 % y 100 %.'];
        if (!ConfigSistema::guardar($pdo, 'horario_semana', (string)json_encode($semana), $idAdmin)) {
            return ['ok' => false, 'mensaje' => 'Falta la tabla config_sistema: vuelve a importar database/tickets_db.sql.'];
        }
        ConfigSistema::guardar($pdo, 'sla_umbral_aviso', (string)$umbral, $idAdmin);
        ConfigSistema::guardar($pdo, 'sla_meta_cumplimiento', (string)$meta, $idAdmin);
        AuditoriaService::registrar($pdo, $idAdmin, 'config_sistema', null, 'EDITAR', 'horario de atención y umbrales del SLA');
        return ['ok' => true, 'mensaje' => 'Horario de atención guardado.'];
    }

    public static function agregarFestivo(PDO $pdo, string $fecha, string $descripcion, int $idAdmin): array
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        $descripcion = trim($descripcion);
        if (!$d || $d->format('Y-m-d') !== $fecha) return ['ok' => false, 'mensaje' => 'Elige una fecha válida.'];
        if ($descripcion === '' || mb_strlen($descripcion) > 120) return ['ok' => false, 'mensaje' => 'Escribe el motivo (máximo 120 caracteres).'];
        SlaRepository::agregarFestivo($pdo, $fecha, $descripcion);
        AuditoriaService::registrar($pdo, $idAdmin, 'dias_festivos', null, 'CREAR', "$fecha $descripcion");
        return ['ok' => true, 'mensaje' => 'Día no hábil agregado.'];
    }

    public static function cargarFestivosColombia(PDO $pdo, int $anio, int $idAdmin): array
    {
        if ($anio < 2000 || $anio > 2100) return ['ok' => false, 'mensaje' => 'Año no válido.'];
        $lista = CalendarioHabil::festivosColombia($anio);
        foreach ($lista as $f => $nombre) SlaRepository::agregarFestivo($pdo, $f, $nombre);
        AuditoriaService::registrar($pdo, $idAdmin, 'dias_festivos', null, 'CREAR', "festivos de Colombia $anio");
        return ['ok' => true, 'mensaje' => count($lista) . " festivos de Colombia $anio cargados."];
    }

    public static function quitarFestivo(PDO $pdo, string $fecha, int $idAdmin): array
    {
        if (!SlaRepository::quitarFestivo($pdo, $fecha)) return ['ok' => false, 'mensaje' => 'Esa fecha no estaba en la lista.'];
        AuditoriaService::registrar($pdo, $idAdmin, 'dias_festivos', null, 'BORRAR', $fecha);
        return ['ok' => true, 'mensaje' => 'Día no hábil quitado.'];
    }
}
