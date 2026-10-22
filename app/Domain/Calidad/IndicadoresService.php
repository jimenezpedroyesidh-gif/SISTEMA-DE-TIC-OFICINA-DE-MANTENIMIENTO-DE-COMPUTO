<?php
/**
 * Indicadores de la mesa de ayuda (tablero del admin), con filtros de fechas, dependencia y sala:
 * volumen, primera respuesta, solución en horas hábiles, cumplimiento del SLA, solución en el primer
 * contacto (FCR), reapertura, satisfacción, antigüedad de pendientes, preventivos, disponibilidad de
 * equipos y reincidentes. También arma la exportación (CSV para Excel y datos del PDF).
 */
final class IndicadoresService
{
    public const BUCKETS = ['menos_1' => 'Menos de 1 día hábil', '1_3' => 'De 1 a 3 días hábiles', '3_5' => 'De 3 a 5 días hábiles', 'mas_5' => 'Más de 5 días hábiles'];
    public const CATEGORIAS = ['HARDWARE' => 'Hardware', 'RED' => 'Red', 'SOFTWARE' => 'Software', 'IMPRESORA' => 'Impresora', 'PROYECTOR' => 'Proyector',
                               'INFRAESTRUCTURA' => 'Infraestructura', 'OTRO' => 'Otro', 'SEGURIDAD' => 'Incidente de seguridad', '' => 'Sin clasificar'];

    /** Filtros normalizados desde la URL (por defecto: lo que va del mes). */
    public static function filtros(array $get): array
    {
        $fecha = static function ($v, string $def): string {
            $v = (string)$v;
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            return ($d && $d->format('Y-m-d') === $v) ? $v : $def;
        };
        $desde = $fecha($get['desde'] ?? '', date('Y-m-01'));
        $hasta = $fecha($get['hasta'] ?? '', date('Y-m-d'));
        if ($hasta < $desde) [$desde, $hasta] = [$hasta, $desde];
        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'id_sala' => max(0, (int)($get['sala'] ?? 0)),
            'dependencia' => mb_substr(trim((string)($get['dependencia'] ?? '')), 0, 100),
        ];
    }

    private static function pct(int $parte, int $total): ?float
    {
        return $total > 0 ? round($parte * 100 / $total, 1) : null;
    }

    private static function promedio(array $v): ?int
    {
        return $v ? (int)round(array_sum($v) / count($v)) : null;
    }

    private static function mediana(array $v): ?int
    {
        if (!$v) return null;
        sort($v);
        $n = count($v);
        return (int)($n % 2 ? $v[intdiv($n, 2)] : round(($v[$n / 2 - 1] + $v[$n / 2]) / 2));
    }

    /** Calcula todo el tablero. */
    public static function calcular(PDO $pdo, array $f, ?DateTimeImmutable $ahora = null): array
    {
        $cal = CalendarioHabil::desdeBase($pdo);
        $base = ['id_sala' => $f['id_sala'] ?: null, 'dependencia' => $f['dependencia']];
        $tickets = SlaService::medir($pdo, $base + ['desde' => $f['desde'], 'hasta' => $f['hasta']], $ahora, $cal);
        $abiertosHoy = SlaService::medir($pdo, $base + ['abiertos' => true], $ahora, $cal);
        $meta = ConfigSistema::entero($pdo, 'sla_meta_cumplimiento');

        $k = ['recibidos' => count($tickets), 'resueltos' => 0, 'abiertos' => 0, 'descartados' => 0, 'incidentes' => 0, 'requerimientos' => 0];
        $resp = []; $sol = []; $okResp = 0; $nResp = 0; $okSol = 0; $nSol = 0; $fcr = 0; $reab = 0;
        $notas = []; $porPrio = []; $porTec = []; $porCat = []; $porSala = []; $idsIncidentes = [];
        foreach (SlaService::PRIORIDADES as $p => $_) $porPrio[$p] = ['n' => 0, 'resueltos' => 0, 'ok' => 0, 'medidos' => 0, 'seg' => []];

        foreach ($tickets as $t) {
            $k[$t['tipo_sla'] === 'INCIDENTE' ? 'incidentes' : 'requerimientos']++;
            if ($t['descartado']) { $k['descartados']++; continue; }
            $t['resuelto'] ? $k['resueltos']++ : $k['abiertos']++;
            $pp = &$porPrio[$t['prioridad_sla']];
            $pp['n']++;
            if ($t['seg_respuesta'] !== null) $resp[] = $t['seg_respuesta'];
            if ($t['cumple_respuesta'] !== null) { $nResp++; if ($t['cumple_respuesta']) $okResp++; }
            if ($t['cumple_solucion'] !== null) {
                $nSol++; $pp['medidos']++;
                if ($t['cumple_solucion']) { $okSol++; $pp['ok']++; }
            }
            if ($t['resuelto'] && $t['seg_solucion'] !== null) {
                $sol[] = $t['seg_solucion'];
                $pp['resueltos']++;
                $pp['seg'][] = $t['seg_solucion'];
                if ((int)$t['reaperturas'] === 0 && $t['n_tecnicos'] <= 1) $fcr++;
                if ((int)$t['reaperturas'] > 0) $reab++;
            }
            unset($pp);
            if ($t['encuesta']) $notas[] = (int)$t['encuesta']['calificacion'];

            $cat = (string)($t['categoria'] ?? '');
            $porCat[$cat] = ($porCat[$cat] ?? 0) + 1;
            if ($t['nombre_sala']) $porSala[$t['nombre_sala']] = ($porSala[$t['nombre_sala']] ?? 0) + 1;
            if ($t['tipo_sla'] === 'INCIDENTE') $idsIncidentes[$t['tabla']][] = (int)$t['id'];

            $nombre = $t['responsable'] ?: 'Sin responsable';
            $pt = $porTec[$nombre] ?? ['nombre' => $nombre, 'asignados' => 0, 'resueltos' => 0, 'seg' => [], 'ok' => 0, 'medidos' => 0, 'notas' => []];
            $pt['asignados']++;
            if ($t['resuelto'] && $t['seg_solucion'] !== null) { $pt['resueltos']++; $pt['seg'][] = $t['seg_solucion']; }
            if ($t['cumple_solucion'] !== null) { $pt['medidos']++; if ($t['cumple_solucion']) $pt['ok']++; }
            if ($t['encuesta']) $pt['notas'][] = (int)$t['encuesta']['calificacion'];
            $porTec[$nombre] = $pt;
        }

        $resueltosMedidos = count($sol);
        $k += [
            'prom_respuesta' => self::promedio($resp), 'mediana_respuesta' => self::mediana($resp),
            'prom_solucion' => self::promedio($sol), 'mediana_solucion' => self::mediana($sol),
            'cumpl_respuesta' => self::pct($okResp, $nResp), 'n_respuesta' => $nResp,
            'cumpl_solucion' => self::pct($okSol, $nSol), 'n_solucion' => $nSol,
            'fcr' => self::pct($fcr, $resueltosMedidos), 'reapertura' => self::pct($reab, $resueltosMedidos),
            'satisfaccion' => $notas ? round(array_sum($notas) / count($notas), 2) : null, 'n_encuestas' => count($notas),
            'pct_satisfechos' => self::pct(count(array_filter($notas, static fn($n) => $n >= 4)), count($notas)),
            'tasa_encuesta' => self::pct(count($notas), $k['resueltos']),
        ];

        $prioridades = [];
        foreach ($porPrio as $p => $d) {
            $prioridades[$p] = ['n' => $d['n'], 'resueltos' => $d['resueltos'], 'cumplimiento' => self::pct($d['ok'], $d['medidos']), 'prom_solucion' => self::promedio($d['seg'])];
        }
        $tecnicos = [];
        foreach ($porTec as $d) {
            $tecnicos[] = ['nombre' => $d['nombre'], 'asignados' => $d['asignados'], 'resueltos' => $d['resueltos'], 'prom_solucion' => self::promedio($d['seg']),
                           'cumplimiento' => self::pct($d['ok'], $d['medidos']), 'satisfaccion' => $d['notas'] ? round(array_sum($d['notas']) / count($d['notas']), 1) : null];
        }
        usort($tecnicos, static fn($a, $b) => [$b['resueltos'], $a['nombre']] <=> [$a['resueltos'], $b['nombre']]);
        arsort($porCat);
        arsort($porSala);

        // Antigüedad de los pendientes de hoy (en días hábiles según el horario configurado).
        $antig = array_fill_keys(array_keys(self::BUCKETS), 0);
        $ahoraR = $ahora ?? new DateTimeImmutable('now');
        $segDia = self::segundosDiaHabilPromedio($pdo);
        foreach ($abiertosHoy as $t) {
            $dias = $cal->segundosEntre(new DateTimeImmutable((string)$t['fecha_creacion']), $ahoraR) / $segDia;
            $antig[$dias < 1 ? 'menos_1' : ($dias < 3 ? '1_3' : ($dias < 5 ? '3_5' : 'mas_5'))]++;
        }
        $criticos = array_values(array_filter($abiertosHoy, static fn($t) => in_array($t['semaforo'], ['vencido', 'por_vencer'], true)));
        usort($criticos, static fn($a, $b) => $b['consumo'] <=> $a['consumo']);

        $prev = IndicadoresRepository::preventivos($pdo, $f['desde'], $f['hasta'], $f['id_sala'] ?: null, $f['dependencia']);
        $prev['cumplimiento'] = self::pct($prev['realizados'], $prev['programados']);
        $prev['a_tiempo_pct'] = self::pct($prev['a_tiempo'], $prev['programados']);
        $disp = IndicadoresRepository::disponibilidad($pdo, $f['id_sala'] ?: null, $f['dependencia']);
        $totEq = array_sum(array_column($disp, 'total'));
        $totOp = array_sum(array_column($disp, 'operativos'));

        return [
            'filtros' => $f, 'meta' => $meta, 'kpi' => $k, 'prioridades' => $prioridades, 'tecnicos' => $tecnicos,
            'categorias' => $porCat, 'salas' => array_slice($porSala, 0, 10, true), 'antiguedad' => $antig, 'pendientes' => count($abiertosHoy),
            'criticos' => array_slice($criticos, 0, 10), 'preventivos' => $prev,
            'disponibilidad' => $disp, 'disponibilidad_total' => self::pct((int)$totOp, (int)$totEq),
            'reincidentes' => IndicadoresRepository::reincidentes($pdo, $idsIncidentes), 'tickets' => $tickets,
        ];
    }

    /** Duración promedio de una jornada hábil (para expresar la antigüedad en días hábiles). */
    private static function segundosDiaHabilPromedio(PDO $pdo): int
    {
        $sem = CalendarioHabil::semanaDeConfig(ConfigSistema::texto($pdo, 'horario_semana'));
        $tot = 0; $n = 0;
        foreach ($sem as $r) {
            if ($r === null) continue;
            $tot += (int)CalendarioHabil::segundosDeHora($r[1]) - (int)CalendarioHabil::segundosDeHora($r[0]);
            $n++;
        }
        return $n > 0 && $tot > 0 ? (int)($tot / $n) : 86400;
    }

    /** Texto de un porcentaje (o «—»). */
    public static function textoPct(?float $v): string
    {
        return $v === null ? '—' : number_format($v, 1, ',', '.') . ' %';
    }

    /** Filas del resumen (las mismas en CSV y PDF). @return list<array{0:string,1:string,2:string}> indicador, valor, referencia */
    public static function resumen(array $d): array
    {
        $k = $d['kpi'];
        $h = static fn(?int $s): string => CalendarioHabil::textoDuracion($s);
        $meta = '≥ ' . $d['meta'] . ' %';
        return [
            ['Tickets recibidos', (string)$k['recibidos'], $k['incidentes'] . ' incidentes · ' . $k['requerimientos'] . ' requerimientos'],
            ['Tickets resueltos', (string)$k['resueltos'], $k['abiertos'] . ' abiertos · ' . $k['descartados'] . ' descartados'],
            ['Primera respuesta (promedio)', $h($k['prom_respuesta']), 'Mediana ' . $h($k['mediana_respuesta']) . ' · horas hábiles'],
            ['Tiempo de solución (promedio)', $h($k['prom_solucion']), 'Mediana ' . $h($k['mediana_solucion']) . ' · sin pausas'],
            ['Cumplimiento SLA de respuesta', self::textoPct($k['cumpl_respuesta']), 'Meta ' . $meta . ' · ' . $k['n_respuesta'] . ' medidos'],
            ['Cumplimiento SLA de solución', self::textoPct($k['cumpl_solucion']), 'Meta ' . $meta . ' · ' . $k['n_solucion'] . ' medidos'],
            ['Solución en el primer contacto (FCR)', self::textoPct($k['fcr']), 'Sin reaperturas y un solo responsable'],
            ['Tasa de reapertura', self::textoPct($k['reapertura']), 'Ideal < 5 %'],
            ['Satisfacción (1 a 5)', $k['satisfaccion'] === null ? '—' : number_format($k['satisfaccion'], 2, ',', '.'), self::textoPct($k['pct_satisfechos']) . ' satisfechos · ' . $k['n_encuestas'] . ' encuestas'],
            ['Respuesta a la encuesta', self::textoPct($k['tasa_encuesta']), 'Encuestas / tickets resueltos'],
            ['Preventivos realizados', self::textoPct($d['preventivos']['cumplimiento']), $d['preventivos']['realizados'] . ' de ' . $d['preventivos']['programados'] . ' programados · ' . self::textoPct($d['preventivos']['a_tiempo_pct']) . ' a tiempo'],
            ['Equipos operativos', self::textoPct($d['disponibilidad_total']), 'Foto de hoy en las salas filtradas'],
            ['Pendientes hoy', (string)$d['pendientes'], count($d['criticos']) . ' vencidos o por vencer'],
        ];
    }

    /** CSV para Excel (UTF-8 con BOM y punto y coma): resumen + detalle de cada ticket del periodo. */
    public static function csv(array $d): string
    {
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        $fila = static function (array $c) use ($f): void { fputcsv($f, $c, ';', '"', '\\'); };
        $fila(['Indicadores de la mesa de ayuda', 'Del ' . $d['filtros']['desde'] . ' al ' . $d['filtros']['hasta']]);
        $fila([]);
        $fila(['Indicador', 'Valor', 'Referencia']);
        foreach (self::resumen($d) as $r) $fila($r);
        $fila([]);
        $fila(['Prioridad', 'Tickets', 'Resueltos', 'Cumplimiento solución', 'Solución promedio']);
        foreach ($d['prioridades'] as $p => $x) $fila([SlaService::PRIORIDADES[$p], $x['n'], $x['resueltos'], self::textoPct($x['cumplimiento']), CalendarioHabil::textoDuracion($x['prom_solucion'])]);
        $fila([]);
        $fila(['Ticket', 'Tipo', 'Prioridad', 'Categoría', 'Sala', 'Dependencia', 'Responsable', 'Estado', 'Creado', 'Primera respuesta',
               'Solución', 'Respuesta (h hábiles)', 'Solución (h hábiles)', 'Pausa (h)', 'Reaperturas', 'Cumple respuesta', 'Cumple solución', 'Semáforo', 'Satisfacción']);
        $sn = static fn($b): string => $b === null ? '' : ($b ? 'Sí' : 'No');
        $hh = static fn($s): string => $s === null ? '' : number_format($s / 3600, 2, ',', '');
        foreach ($d['tickets'] as $t) {
            $fila([($t['tabla'] === 'tickets' ? 'T-' : 'P-') . $t['id'], SlaService::TIPOS[$t['tipo_sla']], SlaService::PRIORIDADES[$t['prioridad_sla']],
                   self::CATEGORIAS[(string)($t['categoria'] ?? '')] ?? (string)$t['categoria'], (string)$t['nombre_sala'], (string)$t['dependencia'],
                   (string)($t['responsable'] ?? ''), (string)$t['estado'], (string)$t['fecha_creacion'], (string)$t['primera_respuesta'], (string)$t['solucion'],
                   $hh($t['seg_respuesta']), $hh($t['seg_solucion']), $hh($t['seg_pausa']), (int)$t['reaperturas'], $sn($t['cumple_respuesta']), $sn($t['cumple_solucion']),
                   SlaService::SEMAFORO[$t['semaforo']][0], $t['encuesta'] ? (string)$t['encuesta']['calificacion'] : '']);
        }
        rewind($f);
        $out = (string)stream_get_contents($f);
        fclose($f);
        return $out;
    }
}
