<?php
/**
 * HorarioCalendario — arma los datos de la grilla semanal de public/horarios.php:
 * qué horarios ve cada rol, la semana navegable, las excepciones aplicadas, los
 * mantenimientos y préstamos de la semana y las pestañas por sala.
 *
 * Es lógica de preparación de datos (sin HTML ni sesión): devuelve un arreglo con los mismos nombres de
 * variable que usa la vista (app/Presentation/Views/horarios/*.php).
 *
 * Reglas de visibilidad ($modoVista):
 *  - 'salas': admin y técnico (y la secretaria a quien el admin le activó el permiso) ven todas las salas,
 *    cada una en su pestaña, con préstamos y mantenimientos, y filtros de periodo/sala/tipo/día.
 *  - 'profesor': una sola semana con SUS clases (en cualquier sala), sus préstamos y los mantenimientos
 *    de sus salas. Al tocar un bloque se ve todo junto: materia, sala, horario y profesor.
 *  - 'beca_mantenimiento': una sola semana con su turno y los mantenimientos activos de todas las salas
 *    (sin buscar sala por sala).
 *  - 'beca_personal': becas de secretaría o redes: solo su propio turno.
 *  - 'sin_acceso': secretaria sin el permiso: aviso para pedirlo al admin.
 */
require_once __DIR__ . '/../../Support/validacion.php';

/** Minutos desde 00:00 de un «HH:MM» (la vista la usa para posicionar los bloques). */
function minutosDesdeHHMM(string $hhmm): int
{
    [$hh, $mm] = array_map('intval', explode(':', $hhmm));
    return $hh * 60 + $mm;
}

final class HorarioCalendario
{
    public const MESES = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
                          7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'];
    public const TIPOS_MANTENIMIENTO = ['MANTENIMIENTO_PREVENTIVO' => 'Mantenimiento preventivo', 'INSTALACION_SOFTWARE' => 'Instalación de software', 'OTRO' => 'Otro'];
    public const HORA_INICIO_GRID = 6;
    public const HORA_FIN_GRID = 22;
    /** id de la pestaña «General» (todas las salas juntas). 0 ya es «sin sala». */
    public const PESTANA_GENERAL = -1;

    public static function rangoSemanaTexto(DateTime $lunes, DateTime $sabado): string
    {
        $meses = self::MESES;
        $d1 = (int)$lunes->format('j'); $d2 = (int)$sabado->format('j');
        $m1 = (int)$lunes->format('n'); $m2 = (int)$sabado->format('n');
        $a1 = $lunes->format('Y'); $a2 = $sabado->format('Y');
        if ($a1 === $a2 && $m1 === $m2) return "$d1 - $d2 de {$meses[$m1]}, $a1";
        if ($a1 === $a2) return "$d1 de {$meses[$m1]} - $d2 de {$meses[$m2]}, $a1";
        return "$d1 de {$meses[$m1]} de $a1 - $d2 de {$meses[$m2]} de $a2";
    }

    /**
     * Agrupa los bloques de una sala que se cruzan el mismo día (p. ej. 3 becas de 6 a 10, o una beca
     * de 6 a 10 y otra de 8 a 12). Cada grupo se dibuja como UN recuadro que lista a cada persona con
     * su propio horario, en vez de pintar los bloques uno encima de otro.
     * @return array<int, array{dia:int, inicio:string, fin:string, items:array}>  ordenados por día y hora
     */
    public static function agruparSolapados(array $bloques): array
    {
        usort($bloques, fn($a, $b) => [$a['dia'], $a['hora_inicio'], $a['hora_fin']] <=> [$b['dia'], $b['hora_inicio'], $b['hora_fin']]);
        $grupos = [];
        foreach ($bloques as $b) {
            $ult = count($grupos) - 1;
            if ($ult >= 0 && $grupos[$ult]['dia'] === $b['dia'] && $b['hora_inicio'] < $grupos[$ult]['fin']) {
                $grupos[$ult]['items'][] = $b;
                if ($b['hora_fin'] > $grupos[$ult]['fin']) $grupos[$ult]['fin'] = $b['hora_fin'];
                continue;
            }
            $grupos[] = ['dia' => $b['dia'], 'inicio' => $b['hora_inicio'], 'fin' => $b['hora_fin'], 'items' => [$b]];
        }
        return $grupos;
    }

    /**
     * Alto de cada fila de la grilla: las horas con algo escrito conservan el alto normal y las horas vacías
     * quedan a la mitad, para no tener que bajar tanto. Devuelve el valor para style="grid-template-rows: …"
     * (fila 1 = encabezado de días; luego una fila por hora entre $horaIni y $horaFin).
     */
    public static function filasGrid(array $bloques, int $horaIni, int $horaFin): string
    {
        $ocupadas = [];
        foreach ($bloques as $b) {
            $ini = max(minutosDesdeHHMM((string)($b['hora_inicio'] ?? $b['inicio'])), $horaIni * 60);
            $fin = min(minutosDesdeHHMM((string)($b['hora_fin'] ?? $b['fin'])), $horaFin * 60);
            for ($h = intdiv($ini, 60); $h * 60 < $fin; $h++) $ocupadas[$h] = true;
        }
        $filas = ['var(--hg-fila-encabezado)'];
        for ($h = $horaIni; $h < $horaFin; $h++) {
            $filas[] = isset($ocupadas[$h]) ? 'var(--hg-fila)' : 'var(--hg-fila-vacia)';
        }
        return 'grid-template-rows: ' . implode(' ', $filas);
    }

    /** Enlace a otra semana conservando los filtros de la URL actual. */
    public static function linkSemana(array $get, string $lunesYmd): string
    {
        $get['semana'] = $lunesYmd;
        return '?' . http_build_query($get);
    }

    /**
     * @param array{rol:string,userId:int,puedeVerTodo:bool,puedeModificar:bool,periodoActual:string} $ctx
     * @param array $get  normalmente $_GET (ya normalizado por validacion.php)
     */
    /** Qué vista le corresponde a cada rol (ver la cabecera de la clase). */
    public static function modoVista(string $rol, bool $puedeVerTodo, ?string $areaBeca): string
    {
        if ($puedeVerTodo) return 'salas';
        if ($rol === 'profesor') return 'profesor';
        if ($rol === Rol::BECA) return ($areaBeca ?? AreaBeca::MANTENIMIENTO) === AreaBeca::MANTENIMIENTO ? 'beca_mantenimiento' : 'beca_personal';
        return 'sin_acceso';
    }

    public static function armar(PDO $pdo, array $ctx, array $get): array
    {
        $rol = $ctx['rol'];
        $modoVista = self::modoVista($rol, (bool)$ctx['puedeVerTodo'], $ctx['areaBeca'] ?? null);
        $userId = $ctx['userId'];
        $puedeVerTodo = $ctx['puedeVerTodo'];
        $puedeModificar = $ctx['puedeModificar'];
        $periodoActual = $ctx['periodoActual'];
        $tiposHorario = HorarioService::TIPOS;

        // ── Consultas de apoyo
        $salas = HorarioRepository::salas($pdo);
        $salasPorId = [];
        foreach ($salas as $s) { $salasPorId[(int)$s['id_sala']] = $s; }
        $profesores = HorarioRepository::profesores($pdo);
        $personalTurnos = HorarioRepository::personalTurnos($pdo);
        $alcance = $ctx['alcance'] ?? null;
        $salasPorSecretaria = [];
        if ($alcance !== null) {
            // Secretaria: en los formularios solo sus salas y, como personal, solo las becas de la secretaría.
            $salas = array_values(array_filter($salas, fn($s) => in_array((int)$s['id_sala'], $alcance['salas'], true)));
            $personalTurnos = array_values(array_filter($personalTurnos, fn($p) => in_array((int)$p['id'], $alcance['becas'], true)));
            if (!$alcance['salas']) $profesores = []; // sin salas no hay clases que pueda poner
        }
        if ($rol === Rol::ADMIN) {
            $salasPorSecretaria = HorarioRepository::salasPorSecretaria($pdo);
        }

        $secretarias = [];
        if ($rol === Rol::ADMIN) {
            $secretarias = HorarioRepository::secretarias($pdo);
        }

        // ── Listado según rol
        $filtroSala = (int)($get['sala'] ?? 0);
        $filtroTipo = validarEnum($get['tipo'] ?? '', array_keys($tiposHorario)) ?? '';
        $filtroDia = (int)($get['dia'] ?? 0);

        // admin/técnico/secretaria navegan el historial de periodos; beca y profesor siempre ven el actual.
        if ($puedeVerTodo) {
            $filtroPeriodo = $get['periodo'] ?? $periodoActual;
            if ($filtroPeriodo !== 'TODOS' && !HorarioPeriodo::valido($filtroPeriodo)) {
                $filtroPeriodo = $periodoActual;
            }
        } else {
            $filtroPeriodo = $periodoActual;
        }

        $salasProfesor = [];
        if ($modoVista === 'salas') {
            $horarios = HorarioRepository::listarTodos($pdo, $filtroPeriodo, $filtroSala, $filtroTipo, $filtroDia);
            if ($alcance !== null) {
                // Secretaria: solo lo de sus salas habilitadas y los turnos de las becas de la secretaría.
                $horarios = array_values(array_filter($horarios, fn($h) => HorarioService::dentroDeAlcance(
                    $alcance, $h['id_sala'] !== null ? (int)$h['id_sala'] : null, (string)$h['tipo'], $h['id_usuario'] !== null ? (int)$h['id_usuario'] : null)));
            }
            if ($rol === Rol::VIGILANTE) {
                // El guardia solo necesita saber cuándo está ocupada cada sala: clases (no turnos del personal).
                $horarios = array_values(array_filter($horarios, fn($h) => $h['tipo'] === 'CLASE' && !empty($h['id_sala'])));
            }
        } elseif ($modoVista === 'beca_mantenimiento' || $modoVista === 'beca_personal') {
            $horarios = HorarioRepository::listarTurnosBeca($pdo, $userId, $filtroPeriodo);
        } elseif ($modoVista === 'profesor') {
            // Solo sus clases: la vista las junta en una sola semana (sin pestañas por sala).
            $horarios = HorarioRepository::listarClasesProfesor($pdo, $userId, $filtroPeriodo);
            $salasProfesor = array_values(array_unique(array_filter(array_map(fn($h) => (int)$h['id_sala'], $horarios))));
        } else {
            $horarios = [];
        }

        // ── Semana mostrada (calendario navegable): ?semana=YYYY-MM-DD → lunes de ESA semana
        $hoy = new DateTime();
        $fechaRefSemana = false;
        if (validarFecha($get['semana'] ?? '') !== null) {
            $fechaRefSemana = DateTime::createFromFormat('Y-m-d', $get['semana']);
        }
        if (!$fechaRefSemana) {
            $fechaRefSemana = $hoy;
        }
        $diaRefISO = (int)$fechaRefSemana->format('N');
        $lunesSemana = (clone $fechaRefSemana)->modify('-' . ($diaRefISO - 1) . ' days')->setTime(0, 0, 0);
        $sabadoSemana = (clone $lunesSemana)->modify('+5 days')->setTime(23, 59, 59);

        $diaHoyISO = (int)$hoy->format('N');
        $lunesHoy = (clone $hoy)->modify('-' . ($diaHoyISO - 1) . ' days')->setTime(0, 0, 0);
        $esSemanaActual = $lunesSemana->format('Y-m-d') === $lunesHoy->format('Y-m-d');

        $textoRangoSemana = self::rangoSemanaTexto($lunesSemana, $sabadoSemana);
        $linkSemanaAnterior = self::linkSemana($get, (clone $lunesSemana)->modify('-7 days')->format('Y-m-d'));
        $linkSemanaSiguiente = self::linkSemana($get, (clone $lunesSemana)->modify('+7 days')->format('Y-m-d'));
        $linkSemanaHoy = self::linkSemana($get, $lunesHoy->format('Y-m-d'));

        // Mantenimientos de la semana: salas y beca de mantenimiento ven todas las salas; el profesor solo las
        // suyas; la beca de secretaría/redes y la secretaria sin permiso, ninguno.
        $limiteMant = $modoVista === 'profesor' ? $salasProfesor : (in_array($modoVista, ['salas', 'beca_mantenimiento'], true) ? null : []);
        if ($alcance !== null) $limiteMant = $alcance['salas'];
        $mantenimientosSemana = HorarioRepository::mantenimientosEntre(
            $pdo, $lunesSemana->format('Y-m-d H:i:s'), $sabadoSemana->format('Y-m-d H:i:s'), $limiteMant
        );
        $tiposMantenimiento = self::TIPOS_MANTENIMIENTO;

        // HorarioEventos::semana() ya resuelve la excepción puntual de cada horario+fecha.
        // Las excepciones solo aplican al periodo en curso (un semestre histórico no tiene fechas reales esta semana).
        $eventosSemanaTodos = HorarioEventos::semana($pdo, $lunesSemana);
        $eventosHorarioPorId = [];
        if ($filtroPeriodo === $periodoActual) {
            foreach ($eventosSemanaTodos as $e) {
                if ($e['origen'] === 'HORARIO') {
                    $eventosHorarioPorId[$e['id_origen']] = $e;
                }
            }
        }

        // ── Normaliza horarios recurrentes + mantenimientos + préstamos a «bloques»
        $bloques = [];
        foreach ($horarios as $h) {
            $idHorario = (int)$h['id_horario'];
            $idSalaBloque = $h['id_sala'] ? (int)$h['id_sala'] : 0;
            $idSalaBloqueRaw = $h['id_sala'] ? (int)$h['id_sala'] : null;
            $salaNombreBloque = $h['nombre_sala'] ?: 'Turnos generales (sin sala)';
            $salaAbrevBloque = $h['abreviatura'] ?: 'GEN';
            $horaInicioBloque = substr($h['hora_inicio'], 0, 5);
            $horaFinBloque = substr($h['hora_fin'], 0, 5);
            $notaExcepcion = null;
            $editableExcepcion = false;
            $idExcepcion = null;
            $excTipo = null;
            $excMotivo = null;

            if ($filtroPeriodo === $periodoActual) {
                $ev = $eventosHorarioPorId[$idHorario] ?? null;
                if ($ev === null) {
                    // Sin evento esta semana => excepción CANCELADO de esa fecha: no se dibuja (el recurrente sigue intacto).
                    continue;
                }
                if ($ev['nota_excepcion']) {
                    $notaExcepcion = $ev['nota_excepcion'];
                    $editableExcepcion = true;
                    $idExcepcion = $ev['id_excepcion'];
                    $excTipo = $ev['excepcion_tipo'];
                    $excMotivo = $ev['excepcion_motivo'];
                    if ($ev['id_sala'] !== $idSalaBloqueRaw) {
                        $idSalaBloqueRaw = $ev['id_sala'];
                        $idSalaBloque = $ev['id_sala'] ?: 0;
                        $salaInfoNueva = $ev['id_sala'] !== null ? ($salasPorId[$ev['id_sala']] ?? null) : null;
                        $salaNombreBloque = $salaInfoNueva['nombre_sala'] ?? 'Turnos generales (sin sala)';
                        $salaAbrevBloque = $salaInfoNueva['abreviatura'] ?? 'GEN';
                    }
                    $horaInicioBloque = $ev['hora_inicio'];
                    $horaFinBloque = $ev['hora_fin'];
                }
            }

            $bloques[] = [
                'origen' => 'horario',
                'id' => $idHorario,
                'id_sala' => $idSalaBloque, // 0 = pestaña «turnos generales»
                'id_sala_raw' => $idSalaBloqueRaw,
                'sala_nombre' => $salaNombreBloque,
                'sala_abrev' => $salaAbrevBloque,
                'tipo' => $h['tipo'],
                'dia' => (int)$h['dia_semana'],
                'periodo' => $h['periodo'] ?? $periodoActual,
                'hora_inicio' => $horaInicioBloque,
                'hora_fin' => $horaFinBloque,
                'detalle' => $h['detalle'],
                'usuario_nombre' => $h['usuario_nombre'] ?? null,
                'id_usuario' => $h['id_usuario'] ? (int)$h['id_usuario'] : null,
                'nota_excepcion' => $notaExcepcion,
                'editable_excepcion' => $editableExcepcion,
                'id_excepcion' => $idExcepcion,
                'excepcion_tipo' => $excTipo,
                'excepcion_motivo' => $excMotivo,
            ];
        }
        foreach ($mantenimientosSemana as $m) {
            $ts = strtotime($m['fecha_programada']);
            $bloques[] = [
                'estado' => $m['estado'],
                'responsable' => $m['responsable_nombre'] ?? null,
                'origen' => 'mantenimiento',
                'id' => (int)$m['id_programado'],
                'id_sala' => (int)$m['id_sala'],
                'id_sala_raw' => (int)$m['id_sala'],
                'sala_nombre' => $m['nombre_sala'],
                'sala_abrev' => $m['abreviatura'],
                'tipo' => 'MANTENIMIENTO',
                'dia' => (int)date('N', $ts),
                'hora_inicio' => date('H:i', $ts),
                'hora_fin' => date('H:i', strtotime('+1 hour', $ts)), // bloque visual de 1h (la tabla no guarda duración)
                'detalle' => ($tiposMantenimiento[$m['tipo']] ?? $m['tipo']) . ($m['descripcion'] ? ': ' . $m['descripcion'] : ''),
                'usuario_nombre' => null,
                'id_usuario' => null,
            ];
        }

        // ── Préstamos con fecha real (de los eventos ya calculados arriba)
        $prestamosSemana = array_values(array_filter($eventosSemanaTodos, fn($e) => $e['origen'] === 'PRESTAMO'));
        if ($filtroSala) {
            $prestamosSemana = array_values(array_filter($prestamosSemana, fn($e) => $e['id_sala'] === $filtroSala));
        }
        if ($alcance !== null) {
            $prestamosSemana = array_values(array_filter($prestamosSemana, fn($e) => $e['id_sala'] !== null && in_array((int)$e['id_sala'], $alcance['salas'], true)));
        }
        if ($modoVista === 'profesor') {
            // Solo sus propios préstamos.
            $prestamosSemana = array_values(array_filter($prestamosSemana, fn($e) => $e['profesor_id'] === $userId));
        } elseif ($modoVista !== 'salas') {
            $prestamosSemana = []; // las becas ven su turno (y los mantenimientos), no los préstamos
        }
        foreach ($prestamosSemana as $p) {
            $ts = strtotime($p['fecha']);
            $esMio = $rol === 'profesor' && $p['profesor_id'] === $userId;
            $mostrarDetalle = $puedeModificar || in_array($rol, [Rol::BECA, Rol::VIGILANTE], true) || $esMio; // el vigilante necesita saber a quién se le prestó
            $salaInfo = $p['id_sala'] !== null ? ($salasPorId[$p['id_sala']] ?? null) : null;
            $bloques[] = [
                'estado' => $p['estado'] ?? null,
                'origen' => 'prestamo',
                'id' => $p['id_origen'],
                'id_sala' => $p['id_sala'] ?? 0,
                'id_sala_raw' => $p['id_sala'],
                'sala_nombre' => $salaInfo['nombre_sala'] ?? '',
                'sala_abrev' => $salaInfo['abreviatura'] ?? '',
                'tipo' => 'PRESTAMO',
                'dia' => (int)date('N', $ts),
                'hora_inicio' => $p['hora_inicio'],
                'hora_fin' => $p['hora_fin'],
                'detalle' => $mostrarDetalle ? trim($p['titulo'] . ($p['detalle'] ? ': ' . $p['detalle'] : '')) : null,
                'usuario_nombre' => $mostrarDetalle ? $p['profesor_nombre'] : null,
                'id_usuario' => null,
            ];
        }

        // Fecha real de cada bloque en la semana mostrada y nombre del profesor en sus propias clases.
        // «editable»: franja recurrente que quien mira puede cambiar (la secretaria, solo dentro de su alcance).
        foreach ($bloques as &$bq) {
            $bq['editable'] = $puedeModificar && $bq['origen'] === 'horario'
                && HorarioService::dentroDeAlcance($alcance, $bq['id_sala_raw'] ?? null, (string)$bq['tipo'], $bq['id_usuario'] ?? null);
            $bq['fecha'] = (clone $lunesSemana)->modify('+' . ($bq['dia'] - 1) . ' days')->format('Y-m-d');
            if ($modoVista === 'profesor' && $bq['origen'] === 'horario' && empty($bq['usuario_nombre'])) $bq['usuario_nombre'] = $ctx['nombreUsuario'] ?? null;
        }
        unset($bq);
        usort($bloques, fn($a, $b) => [$a['dia'], $a['hora_inicio']] <=> [$b['dia'], $b['hora_inicio']]);
        $prestamosLista = array_values(array_filter($bloques, fn($b) => $b['origen'] === 'prestamo'));

        // ── Pestañas de sala: las salas presentes en los bloques, más «Turnos generales» si aplica
        $salasEnBloques = [];
        $hayGeneral = false;
        foreach ($bloques as $b) {
            if ($b['id_sala'] === 0) { $hayGeneral = true; continue; }
            $salasEnBloques[$b['id_sala']] = ['id' => $b['id_sala'], 'nombre' => $b['sala_nombre'], 'abrev' => $b['sala_abrev']];
        }
        ksort($salasEnBloques);
        $pestanasSala = array_values($salasEnBloques);
        if ($hayGeneral) {
            $pestanasSala[] = ['id' => 0, 'nombre' => 'Turnos generales (sin sala)', 'abrev' => 'Sin sala'];
        }
        // Primera pestaña: «General», todas las salas juntas en una sola grilla (cada bloque lleva su sala).
        // Las demás pestañas agrupan el horario de cada sala por separado.
        if (count($pestanasSala) > 1) {
            array_unshift($pestanasSala, ['id' => self::PESTANA_GENERAL, 'nombre' => 'General — todas las salas', 'abrev' => 'General']);
        }

        // Vigilante: lo de HOY que impide prestar una sala (mantenimientos, clases y préstamos), por sala.
        $hoySalas = [];
        if ($rol === Rol::VIGILANTE && $esSemanaActual) {
            $hoyYmd = $hoy->format('Y-m-d');
            foreach ($bloques as $b) {
                if (($b['fecha'] ?? '') !== $hoyYmd || $b['id_sala'] === 0) continue; // fecha: se calcula para todos los bloques más arriba
                $hoySalas[$b['id_sala']]['nombre'] = $b['sala_nombre'];
                $hoySalas[$b['id_sala']]['abrev'] = $b['sala_abrev'];
                $hoySalas[$b['id_sala']]['bloques'][] = $b;
                if ($b['origen'] === 'mantenimiento') $hoySalas[$b['id_sala']]['mantenimiento'] = true;
            }
            // Primero las salas con mantenimiento (no se prestan), luego por nombre.
            uasort($hoySalas, fn($a, $b) => [empty($a['mantenimiento']), $a['abrev']] <=> [empty($b['mantenimiento']), $b['abrev']]);
        }

        return [
            'modoVista' => $modoVista,
            'prestamosLista' => $prestamosLista,
            'salas' => $salas,
            'salasPorId' => $salasPorId,
            'profesores' => $profesores,
            'personalTurnos' => $personalTurnos,
            'secretarias' => $secretarias,
            'filtroSala' => $filtroSala,
            'filtroTipo' => $filtroTipo,
            'filtroDia' => $filtroDia,
            'filtroPeriodo' => $filtroPeriodo,
            'horaInicioGrid' => self::HORA_INICIO_GRID,
            'horaFinGrid' => self::HORA_FIN_GRID,
            'totalHoras' => self::HORA_FIN_GRID - self::HORA_INICIO_GRID,
            'lunesSemana' => $lunesSemana,
            'esSemanaActual' => $esSemanaActual,
            'diaHoyISO' => $diaHoyISO,
            'textoRangoSemana' => $textoRangoSemana,
            'linkSemanaAnterior' => $linkSemanaAnterior,
            'linkSemanaSiguiente' => $linkSemanaSiguiente,
            'linkSemanaHoy' => $linkSemanaHoy,
            'bloques' => $bloques,
            'pestanasSala' => $pestanasSala,
            'alcance' => $alcance,
            'salasPorSecretaria' => $salasPorSecretaria,
            'hoySalas' => $hoySalas,
            'tiposMantenimiento' => $tiposMantenimiento,
        ];
    }
}
