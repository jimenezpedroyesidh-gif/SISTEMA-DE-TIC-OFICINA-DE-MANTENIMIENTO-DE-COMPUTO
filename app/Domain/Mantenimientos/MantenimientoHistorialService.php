<?php
/**
 * MantenimientoHistorialService — «Historial y análisis de mantenimiento».
 *
 * Consolida por sala todo lo ocurrido en un periodo (mantenimientos con su resultado, fallas reportadas por QR,
 * por profesores o por el personal, solicitudes de profesores, equipos intervenidos y trasladados, diagnósticos y
 * soluciones) y calcula: intervenciones por sala, fallas más frecuentes, equipos con más reincidencia y tiempos
 * promedio de atención y de resolución. Incluye los tickets ya archivados en el cierre de semestre.
 *
 * Con el informe del semestre se definen acciones correctivas (mantenimiento_acciones) para el semestre
 * siguiente, con responsable, prioridad y fecha objetivo; cada una se convierte en un mantenimiento de sala o en
 * una campaña, y queda HECHA sola cuando ese mantenimiento (o la campaña completa) termina como realizado.
 *
 * Tiempos en horas de calendario: atención = creación → primera respuesta; resolución = creación → solución.
 */
final class MantenimientoHistorialService
{
    public const CATEGORIAS = ['HARDWARE', 'RED', 'SOFTWARE', 'IMPRESORA', 'PROYECTOR', 'INFRAESTRUCTURA', 'SEGURIDAD', 'OTRO'];
    public const TIPOS_ACCION = [
        'REEMPLAZAR_EQUIPO' => 'Reemplazar equipos', 'ESTANDARIZAR_SOFTWARE' => 'Estandarizar software',
        'REVISAR_CONFIGURACION' => 'Revisar configuraciones', 'PREVENTIVO' => 'Mantenimiento preventivo más completo', 'OTRA' => 'Otra',
    ];
    public const PRIORIDADES = ['ALTA' => 'Alta', 'MEDIA' => 'Media', 'BAJA' => 'Baja'];
    public const ESTADOS_ACCION = ['PENDIENTE' => 'Pendiente', 'PROGRAMADA' => 'Programada', 'HECHA' => 'Hecha', 'DESCARTADA' => 'Descartada'];
    /** Tipo de mantenimiento con el que se programa cada acción. */
    private const TIPO_MANT_POR_ACCION = [
        'REEMPLAZAR_EQUIPO' => 'OTRO', 'ESTANDARIZAR_SOFTWARE' => 'INSTALACION_SOFTWARE',
        'REVISAR_CONFIGURACION' => 'MANTENIMIENTO_PREVENTIVO', 'PREVENTIVO' => 'MANTENIMIENTO_PREVENTIVO', 'OTRA' => 'OTRO',
    ];

    // ───────────────────────── Filtros

    /** [desde, hasta] ('Y-m-d') de un semestre AAAA-N (1 = ene-jun, 2 = jul-dic, igual que el cierre de semestre). */
    public static function rangoPeriodo(string $periodo): array
    {
        [$anio, $n] = array_map('intval', explode('-', $periodo));
        return $n === 1 ? ["$anio-01-01", "$anio-06-30"] : ["$anio-07-01", "$anio-12-31"];
    }

    /** Últimos semestres para el selector (el actual primero). */
    public static function periodosRecientes(int $cuantos = 6): array
    {
        $p = HorarioPeriodo::actual();
        $out = [];
        for ($i = 0; $i < $cuantos; $i++) {
            $out[] = $p;
            $p = HorarioPeriodo::anterior($p);
        }
        return $out;
    }

    public static function filtros(PDO $pdo, array $get): array
    {
        $periodo = (string)($get['periodo'] ?? '');
        $desde = (string)($get['desde'] ?? '');
        $hasta = (string)($get['hasta'] ?? '');
        $esRango = $periodo === 'rango' && strtotime($desde) && strtotime($hasta);
        if (!$esRango) {
            $periodo = HorarioPeriodo::valido($periodo) ? $periodo : HorarioPeriodo::actual();
            [$desde, $hasta] = self::rangoPeriodo($periodo);
        } else {
            $desde = date('Y-m-d', strtotime($desde));
            $hasta = date('Y-m-d', strtotime($hasta));
            if ($hasta < $desde) { [$desde, $hasta] = [$hasta, $desde]; }
        }
        $codigoEquipo = trim((string)($get['equipo'] ?? ''));
        $idEquipo = 0;
        $equipoNoExiste = false;
        if ($codigoEquipo !== '') {
            $eq = MantenimientoHistorialRepository::equipoPorCodigo($pdo, $codigoEquipo);
            $idEquipo = $eq ? (int)$eq['id_equipo'] : -1; // -1: no coincide nada
            $equipoNoExiste = !$eq;
        }
        $idResp = (int)($get['responsable'] ?? 0);
        $categoria = (string)($get['categoria'] ?? '');
        return [
            'periodo' => $esRango ? 'rango' : $periodo,
            // Semestre que se analiza para las acciones (con rango libre, el semestre de la fecha final).
            'periodo_analizado' => $esRango ? (date('Y', strtotime($hasta)) . '-' . (date('n', strtotime($hasta)) <= 6 ? '1' : '2')) : $periodo,
            'desde_fecha' => $desde, 'hasta_fecha' => $hasta,
            'desde' => $desde . ' 00:00:00', 'hasta' => $hasta . ' 23:59:59',
            'id_sala' => max(0, (int)($get['sala'] ?? 0)),
            'bloque' => trim((string)($get['bloque'] ?? '')),
            'equipo' => $codigoEquipo, 'id_equipo' => $idEquipo, 'equipo_no_existe' => $equipoNoExiste,
            'id_responsable' => $idResp > 0 ? $idResp : 0,
            'nombre_responsable' => $idResp > 0 ? MantenimientoHistorialRepository::nombreUsuario($pdo, $idResp) : '',
            'categoria' => in_array($categoria, self::CATEGORIAS, true) ? $categoria : '',
        ];
    }

    // ───────────────────────── Cálculo

    /** Texto del tipo de reporte («Reporte QR sin sesión», «Daño reportado por profesor», …). */
    public static function tipoIncidencia(array $i): string
    {
        $origen = (string)($i['origen'] ?? '');
        if ($i['tipo_ticket'] === 'requerimiento') {
            $sub = ['CAMBIO_SALA' => 'cambio de sala', 'INSTALACION_SOFTWARE' => 'instalación de software', 'PRESTAMO_SALA' => 'préstamo de sala', 'PRESTAMO_EQUIPO' => 'préstamo de equipo'];
            return 'Solicitud de profesor' . (!empty($i['subtipo']) ? ': ' . ($sub[$i['subtipo']] ?? mb_strtolower(etiquetaValor((string)$i['subtipo']))) : '');
        }
        if ($i['tipo_ticket'] === 'danio') {
            return $origen === 'QR_PROFESOR' ? 'Reporte QR de profesor' : 'Daño reportado por profesor';
        }
        return [
            'QR_ANONIMO' => 'Reporte QR sin sesión', 'PANEL_INTERNO' => 'Solicitud de secretaría o dependencia',
            'BECA_DIRECTO' => 'Daño registrado por el personal', 'TECNICO_DIRECTO' => 'Daño registrado por el personal',
        ][$origen] ?? 'Reporte';
    }

    private static function segundos(?string $desde, ?string $hasta): ?int
    {
        if (!$desde || !$hasta) {
            return null;
        }
        $d = strtotime($hasta) - strtotime($desde);
        return $d >= 0 ? $d : null;
    }

    private static function promedio(array $v): ?int
    {
        $v = array_values(array_filter($v, fn($x) => $x !== null));
        return $v ? (int)round(array_sum($v) / count($v)) : null;
    }

    /** Acción que se sugiere según la causa principal de una sala. */
    public static function accionSugerida(?string $categoria, bool $hayReincidentes): string
    {
        return match ($categoria) {
            'SOFTWARE' => 'ESTANDARIZAR_SOFTWARE',
            'RED', 'SEGURIDAD' => 'REVISAR_CONFIGURACION',
            'HARDWARE', 'PROYECTOR', 'IMPRESORA' => $hayReincidentes ? 'REEMPLAZAR_EQUIPO' : 'PREVENTIVO',
            default => 'PREVENTIVO',
        };
    }

    public static function calcular(PDO $pdo, array $f): array
    {
        $incidencias = MantenimientoHistorialRepository::incidencias($pdo, $f);
        $equiposInc = MantenimientoHistorialRepository::equiposDeIncidencias($pdo, $incidencias);
        $mants = MantenimientoHistorialRepository::mantenimientos($pdo, $f);
        $problemas = MantenimientoHistorialRepository::equiposProblemaEnMantenimientos($pdo, $f);
        $traslados = MantenimientoHistorialRepository::traslados($pdo, $f);
        $salasCat = array_column(MantenimientoHistorialRepository::salas($pdo), null, 'id_sala');

        $porSala = [];
        $fila = function (int $idSala) use (&$porSala, $salasCat) {
            if (!isset($porSala[$idSala])) {
                $s = $salasCat[$idSala] ?? ['nombre_sala' => 'Sala #' . $idSala, 'abreviatura' => '', 'bloque' => null];
                $porSala[$idSala] = ['id_sala' => $idSala, 'nombre_sala' => $s['nombre_sala'], 'abreviatura' => $s['abreviatura'], 'bloque' => $s['bloque'],
                    'mantenimientos' => 0, 'fallas' => 0, 'solicitudes' => 0, 'equipos_revisados' => 0, 'traslados' => 0,
                    'categorias' => [], 'resolucion' => [], 'equipos' => []];
            }
        };

        $atencion = $resolucion = [];
        $fallasPorCategoria = $porTipo = [];
        $reinc = [];
        $equiposAfectados = [];
        $nFallas = $nSolicitudes = 0;
        $timeline = [];
        foreach ($incidencias as $i) {
            $k = $i['fuente'] . '#' . (int)$i['id'];
            $esSolicitud = $i['tipo_ticket'] === 'requerimiento';
            $tA = self::segundos($i['fecha_creacion'], $i['fecha_respuesta']);
            $tR = self::segundos($i['fecha_creacion'], $i['fecha_solucion']);
            $atencion[] = $tA;
            $resolucion[] = $tR;
            $tipo = self::tipoIncidencia($i);
            $porTipo[$tipo] = ($porTipo[$tipo] ?? 0) + 1;
            $eqs = $equiposInc[$k] ?? [];
            if ($esSolicitud) {
                $nSolicitudes++;
            } else {
                $nFallas++;
                $cat = $i['categoria'] ?: 'SIN_CLASIFICAR';
                $fallasPorCategoria[$cat] = ($fallasPorCategoria[$cat] ?? 0) + 1;
                foreach ($eqs as $e) {
                    $reinc[$e['id_equipo']] ??= ['codigo' => $e['codigo'], 'id_sala' => $e['id_sala'], 'incidencias' => 0, 'incompletos' => 0, 'traslados' => 0];
                    $reinc[$e['id_equipo']]['incidencias']++;
                }
            }
            foreach ($eqs as $e) { $equiposAfectados[$e['id_equipo']] = true; }
            if (!empty($i['id_sala'])) {
                $fila((int)$i['id_sala']);
                $r = &$porSala[(int)$i['id_sala']];
                $esSolicitud ? $r['solicitudes']++ : $r['fallas']++;
                if (!$esSolicitud && $i['categoria']) { $r['categorias'][$i['categoria']] = ($r['categorias'][$i['categoria']] ?? 0) + 1; }
                $r['resolucion'][] = $tR;
                foreach ($eqs as $e) { $r['equipos'][$e['id_equipo']] = $e['codigo']; }
                unset($r);
            }
            $timeline[] = [
                'fecha' => $i['fecha_creacion'], 'clase' => $esSolicitud ? 'solicitud' : 'falla', 'titulo' => $tipo . ' #' . (int)$i['id'] . ($i['fuente'] === 'archivo' ? ' (archivado)' : ''),
                'sala' => $i['nombre_sala'], 'estado' => $i['estado'], 'categoria' => $i['categoria'],
                'detalle' => (string)$i['descripcion'], 'solucion' => (string)($i['solucion'] ?? ''),
                'responsable' => trim(implode(' · ', array_filter([$i['tecnico'] ?? null, $i['beca'] ?? null]))),
                'equipos' => implode(', ', array_column($eqs, 'codigo')), 'atencion' => $tA, 'resolucion' => $tR,
            ];
        }

        $mantRealizados = 0;
        $mantResueltos = 0;
        $ejecucion = [];
        foreach ($mants as $m) {
            $fila((int)$m['id_sala']);
            if ($m['estado'] === 'REALIZADO') {
                $mantRealizados++;
                $mantResueltos += $m['resultado'] === 'RESUELTO' ? 1 : 0;
                $porSala[(int)$m['id_sala']]['mantenimientos']++;
                $porSala[(int)$m['id_sala']]['equipos_revisados'] += (int)$m['equipos_trabajados'];
                $ejecucion[] = self::segundos($m['fecha_programada'], $m['fecha_realizado']);
            }
            $timeline[] = [
                'fecha' => $m['fecha_programada'], 'clase' => 'mantenimiento',
                'titulo' => etiquetaValor((string)$m['tipo']) . ' #' . (int)$m['id_programado'] . (!empty($m['id_campana']) ? ' (campaña)' : ''),
                'sala' => $m['nombre_sala'], 'estado' => $m['estado'] . ($m['resultado'] ? ' · ' . (MantenimientoService::RESULTADOS[$m['resultado']] ?? $m['resultado']) : ''),
                'categoria' => null, 'detalle' => (string)$m['descripcion'], 'solucion' => (string)($m['observaciones_cierre'] ?? ''),
                'responsable' => (string)($m['responsable'] ?? ''),
                'equipos' => (int)$m['equipos_trabajados'] . ' equipos trabajados' . ((int)$m['equipos_trasladados'] ? ', ' . (int)$m['equipos_trasladados'] . ' trasladados' : ''),
                'atencion' => null, 'resolucion' => null,
            ];
        }
        foreach ($problemas as $p) {
            $reinc[$p['id_equipo']] ??= ['codigo' => $p['codigo'], 'id_sala' => $p['id_sala'], 'incidencias' => 0, 'incompletos' => 0, 'traslados' => 0];
            $reinc[$p['id_equipo']][$p['estado'] === 'INCOMPLETO' ? 'incompletos' : 'traslados'] += (int)$p['n'];
        }
        foreach ($traslados as $t) {
            if (!empty($t['id_sala_origen'])) {
                $fila((int)$t['id_sala_origen']);
                $porSala[(int)$t['id_sala_origen']]['traslados']++;
            }
            $timeline[] = [
                'fecha' => $t['fecha_solicitado'], 'clase' => 'traslado',
                'titulo' => 'Traslado de ' . ($t['componente_codigo'] ? etiquetaValor((string)$t['componente_tipo']) . ' ' . $t['componente_codigo'] : 'equipo ' . $t['equipo_codigo']),
                'sala' => trim(($t['sala_origen'] ?? '¿?') . ' → ' . ($t['sala_destino'] ?? '¿?')), 'estado' => $t['fecha_ejecutado'] ? 'Ejecutado' : 'Solicitado',
                'categoria' => null, 'detalle' => (string)$t['motivo'], 'solucion' => '', 'responsable' => (string)($t['solicito'] ?? ''),
                'equipos' => (string)($t['equipo_codigo'] ?? ''), 'atencion' => null, 'resolucion' => null,
            ];
        }

        // Salas: total, causa principal y acción sugerida (informe del semestre).
        $reincidentesPorSala = [];
        foreach ($reinc as $id => $r) {
            $reinc[$id]['total'] = $r['incidencias'] + $r['incompletos'] + $r['traslados'];
            if ($reinc[$id]['total'] >= 2 && $r['id_sala']) { $reincidentesPorSala[(int)$r['id_sala']][] = $r['codigo']; }
        }
        foreach ($porSala as $id => $r) {
            $cats = (array)$r['categorias'];
            arsort($cats);
            $causa = $cats ? (string)array_key_first($cats) : null;
            $porSala[$id]['total'] = $r['mantenimientos'] + $r['fallas'] + $r['solicitudes'];
            $porSala[$id]['causa_principal'] = $causa;
            $porSala[$id]['causa_veces'] = $cats ? (int)reset($cats) : 0;
            $porSala[$id]['prom_resolucion'] = self::promedio($r['resolucion']);
            $porSala[$id]['equipos_con_incidencias'] = count($r['equipos']);
            $porSala[$id]['reincidentes'] = $reincidentesPorSala[$id] ?? [];
            $porSala[$id]['accion_sugerida'] = self::accionSugerida($causa, !empty($reincidentesPorSala[$id]));
            unset($porSala[$id]['categorias'], $porSala[$id]['resolucion'], $porSala[$id]['equipos']);
        }
        usort($porSala, fn($a, $b) => [$b['total'], $b['fallas']] <=> [$a['total'], $a['fallas']]);

        $reincidentes = array_values(array_filter($reinc, fn($r) => $r['total'] >= 2));
        usort($reincidentes, fn($a, $b) => $b['total'] <=> $a['total'] ?: strcmp((string)$a['codigo'], (string)$b['codigo']));
        foreach ($reincidentes as &$r) { $r['nombre_sala'] = $salasCat[(int)$r['id_sala']]['nombre_sala'] ?? '—'; }
        unset($r);

        arsort($fallasPorCategoria);
        arsort($porTipo);
        usort($timeline, fn($a, $b) => strcmp((string)$b['fecha'], (string)$a['fecha']));

        return [
            'kpi' => [
                'intervenciones' => $mantRealizados + $nFallas + $nSolicitudes,
                'mant_realizados' => $mantRealizados, 'mant_programados' => count(array_filter($mants, fn($m) => $m['estado'] !== 'CANCELADO')),
                'mant_resueltos' => $mantResueltos, 'fallas' => $nFallas, 'solicitudes' => $nSolicitudes,
                'equipos_afectados' => count($equiposAfectados), 'traslados' => count($traslados),
                'prom_atencion' => self::promedio($atencion), 'prom_resolucion' => self::promedio($resolucion),
                'prom_ejecucion' => self::promedio($ejecucion),
                'n_atencion' => count(array_filter($atencion, fn($x) => $x !== null)), 'n_resolucion' => count(array_filter($resolucion, fn($x) => $x !== null)),
            ],
            'porSala' => $porSala,
            'fallasPorCategoria' => $fallasPorCategoria,
            'porTipo' => $porTipo,
            'reincidentes' => array_slice($reincidentes, 0, 15),
            'timeline' => $timeline, // completo (el CSV lo usa entero; la pantalla muestra los 300 más recientes)
            'timelineTotal' => count($timeline),
        ];
    }

    /** Detalle consolidado en CSV (Excel), para verificar las cifras contra los registros. */
    public static function csv(array $d): string
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, ['Fecha', 'Registro', 'Sala', 'Estado', 'Categoría', 'Responsable', 'Equipos', 'Descripción', 'Diagnóstico / solución', 'Atención (h)', 'Resolución (h)'], ';');
        $horas = static fn(?int $s): string => $s === null ? '' : number_format($s / 3600, 1, ',', '');
        foreach ($d['timeline'] as $t) {
            fputcsv($h, [$t['fecha'], $t['titulo'], $t['sala'], $t['estado'], $t['categoria'] ? etiquetaValor($t['categoria']) : '', $t['responsable'],
                $t['equipos'], $t['detalle'], $t['solucion'], $horas($t['atencion']), $horas($t['resolucion'])], ';');
        }
        rewind($h);
        return (string)stream_get_contents($h);
    }

    // ───────────────────────── Acciones correctivas

    private static function r(string $error = '', string $exito = ''): array
    {
        return ['error' => $error, 'exito' => $exito];
    }

    public static function crearAccion(PDO $pdo, int $userId, array $in): array
    {
        $periodoOrigen = (string)($in['periodo_origen'] ?? '');
        if (!HorarioPeriodo::valido($periodoOrigen)) {
            return self::r('El semestre analizado no es válido.');
        }
        $periodoDestino = (string)($in['periodo_destino'] ?? '');
        if (!HorarioPeriodo::valido($periodoDestino)) {
            $periodoDestino = HorarioPeriodo::siguiente($periodoOrigen);
        }
        $tipo = (string)($in['tipo_accion'] ?? '');
        $causa = trim((string)($in['causa'] ?? ''));
        $prioridad = (string)($in['prioridad'] ?? 'MEDIA');
        $fecha = (string)($in['fecha_objetivo'] ?? '');
        $idSala = !empty($in['id_sala']) ? (int)$in['id_sala'] : null;
        $idResp = !empty($in['id_responsable']) ? (int)$in['id_responsable'] : null;
        $codigoEquipo = trim((string)($in['equipo'] ?? ''));
        if (!isset(self::TIPOS_ACCION[$tipo]) || $causa === '' || !isset(self::PRIORIDADES[$prioridad])) {
            return self::r('Completa el tipo de acción, la causa y la prioridad.');
        }
        $dt = DateTime::createFromFormat('Y-m-d', $fecha);
        if (!$dt || $dt->format('Y-m-d') !== $fecha) {
            return self::r('La fecha objetivo no es válida.');
        }
        if ($idSala && !MantenimientoRepository::salaExiste($pdo, $idSala)) {
            return self::r('La sala elegida no existe.');
        }
        $idEquipo = null;
        if ($codigoEquipo !== '') {
            $eq = MantenimientoHistorialRepository::equipoPorCodigo($pdo, $codigoEquipo);
            if (!$eq) {
                return self::r("No existe un equipo con el código «{$codigoEquipo}».");
            }
            $idEquipo = (int)$eq['id_equipo'];
            $idSala = $idSala ?: (int)$eq['id_sala'];
        }
        if ($idResp && !in_array(MantenimientoRepository::rolDeUsuario($pdo, $idResp), [Rol::TECNICO, Rol::ADMIN], true)) {
            $idResp = null;
        }
        $id = MantenimientoHistorialRepository::insertarAccion($pdo, [
            'periodo_origen' => $periodoOrigen, 'periodo_destino' => $periodoDestino, 'id_sala' => $idSala, 'id_equipo' => $idEquipo,
            'tipo_accion' => $tipo, 'causa' => mb_substr($causa, 0, 255), 'descripcion' => trim((string)($in['descripcion'] ?? '')) ?: null,
            'prioridad' => $prioridad, 'id_responsable' => $idResp, 'fecha_objetivo' => $fecha, 'id_creador' => $userId,
        ]);
        AuditoriaService::registrar($pdo, $userId, 'mantenimiento_acciones', $id, 'CREAR', "$tipo para $periodoDestino");
        if ($idResp && $idResp !== $userId) {
            NotificationService::crear($pdo, $idResp, 'MANTENIMIENTO', "Tienes una acción correctiva para el semestre $periodoDestino: " . self::TIPOS_ACCION[$tipo] . '.', 'historial_mantenimiento.php?vista=acciones&destino=' . $periodoDestino);
        }
        return self::r('', 'Acción correctiva registrada para el semestre ' . $periodoDestino . '.');
    }

    public static function cambiarEstadoAccion(PDO $pdo, int $userId, int $id, string $estado): array
    {
        if (!in_array($estado, ['HECHA', 'DESCARTADA'], true)) {
            return self::r('Estado no válido.');
        }
        if (MantenimientoHistorialRepository::cambiarEstadoAccion($pdo, $id, $estado, ['PENDIENTE', 'PROGRAMADA']) === 0) {
            return self::r('Esa acción ya estaba cerrada.');
        }
        AuditoriaService::registrar($pdo, $userId, 'mantenimiento_acciones', $id, 'EDITAR', "estado=$estado");
        return self::r('', $estado === 'HECHA' ? 'Acción marcada como hecha.' : 'Acción descartada.');
    }

    /**
     * Datos para abrir el formulario de programar (o de campaña) desde una acción pendiente.
     * null si la acción no existe o ya no está pendiente.
     */
    public static function prefillDesdeAccion(PDO $pdo, int $idAccion): ?array
    {
        $a = $idAccion ? MantenimientoHistorialRepository::accion($pdo, $idAccion) : false;
        if (!$a || $a['estado'] !== 'PENDIENTE') {
            return null;
        }
        return [
            'id_accion' => (int)$a['id_accion'],
            'id_sala' => $a['id_sala'] !== null ? (int)$a['id_sala'] : 0,
            'id_equipo' => $a['id_equipo'] !== null ? (int)$a['id_equipo'] : 0,
            'tipo' => self::TIPO_MANT_POR_ACCION[$a['tipo_accion']] ?? 'OTRO',
            'nombre' => self::TIPOS_ACCION[$a['tipo_accion']] . ' (' . $a['periodo_destino'] . ')',
            'descripcion' => 'Acción correctiva #' . (int)$a['id_accion'] . ' (' . self::TIPOS_ACCION[$a['tipo_accion']] . '). Causa: ' . $a['causa'] . ($a['descripcion'] ? "\n" . $a['descripcion'] : ''),
            'fecha_objetivo' => (string)$a['fecha_objetivo'],
            'id_responsable' => $a['id_responsable'] !== null ? (int)$a['id_responsable'] : 0,
        ];
    }
}
