<?php
/**
 * MantenimientoAgenda — disponibilidad de salas y personal para programar mantenimientos.
 *
 * No tiene datos propios: lee el calendario real de la sala con HorarioEventos::semana() (clases con sus
 * excepciones, turnos, préstamos y otros mantenimientos) y los mantenimientos activos del responsable.
 *
 * Reglas:
 *  - Un mantenimiento ocupa [inicio, inicio + duracion_minutos).
 *  - Choca con cualquier evento de esa sala que se cruce con ese intervalo, y con otro mantenimiento
 *    activo del mismo responsable (aunque sea en otra sala: una persona no está en dos sitios).
 *  - Para buscar un espacio libre solo se usa la franja de la grilla de horarios: lunes a sábado, 06:00-22:00,
 *    en pasos de 30 minutos.
 */
final class MantenimientoAgenda
{
    public const ESTADOS_ACTIVOS = ['PENDIENTE', 'PROGRAMADO', 'REPROGRAMADO', 'EN_PROCESO'];
    public const HORA_APERTURA = '06:00';
    public const HORA_CIERRE = '22:00';
    public const PASO_MINUTOS = 30;
    public const DIAS_BUSQUEDA = 90;
    public const DURACION_MIN = 15;
    public const DURACION_MAX = 960;

    /** Duración válida en minutos (fuera de rango → 60). */
    public static function duracion($valor): int
    {
        $m = (int)$valor;
        return ($m >= self::DURACION_MIN && $m <= self::DURACION_MAX) ? $m : 60;
    }

    /** @var array<string, array> eventos por "idSala|lunes" (una petición) */
    private static array $cacheSemanas = [];

    /** Olvida lo leído (después de crear o mover un mantenimiento en la misma petición). */
    public static function limpiarCache(): void
    {
        self::$cacheSemanas = [];
        self::$cacheResponsable = [];
    }

    /** Eventos del calendario de esa sala en una fecha 'Y-m-d' (cacheado por semana). */
    private static function eventosDelDia(PDO $pdo, int $idSala, string $fecha): array
    {
        $d = new DateTime($fecha);
        $lunes = (clone $d)->modify('-' . ((int)$d->format('N') - 1) . ' days')->setTime(0, 0, 0);
        $k = $idSala . '|' . $lunes->format('Y-m-d');
        if (!isset(self::$cacheSemanas[$k])) {
            self::$cacheSemanas[$k] = HorarioEventos::semana($pdo, $lunes, $idSala);
        }
        return array_values(array_filter(self::$cacheSemanas[$k], fn($e) => $e['fecha'] === $fecha));
    }

    /** @var array<string, array> mantenimientos activos por "idResponsable|fecha" (una petición) */
    private static array $cacheResponsable = [];

    /** Mantenimientos activos del responsable que se cruzan con [inicio, fin). Una consulta por día. */
    private static function ocupacionResponsable(PDO $pdo, int $idResponsable, DateTimeInterface $inicio, DateTimeInterface $fin, ?int $excluir): array
    {
        $fecha = $inicio->format('Y-m-d');
        $k = $idResponsable . '|' . $fecha;
        if (!isset(self::$cacheResponsable[$k])) {
            $enIn = implode(',', array_fill(0, count(self::ESTADOS_ACTIVOS), '?'));
            // Ventana amplia (día anterior a día siguiente) para no perder uno que empieza antes y sigue.
            $st = $pdo->prepare(
                "SELECT m.id_programado, m.fecha_programada, m.duracion_minutos, s.nombre_sala
                 FROM mantenimientos_programados m JOIN salas s ON s.id_sala = m.id_sala
                 WHERE m.id_responsable = ? AND m.estado IN ($enIn)
                   AND m.fecha_programada BETWEEN DATE_SUB(?, INTERVAL 1 DAY) AND DATE_ADD(?, INTERVAL 2 DAY)"
            );
            $st->execute([$idResponsable, ...self::ESTADOS_ACTIVOS, $fecha, $fecha]);
            self::$cacheResponsable[$k] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        $ini = $inicio->getTimestamp();
        $fi = $fin->getTimestamp();
        $out = [];
        foreach (self::$cacheResponsable[$k] as $m) {
            $ts = strtotime((string)$m['fecha_programada']);
            $te = $ts + 60 * (int)$m['duracion_minutos'];
            if ((int)$m['id_programado'] === (int)$excluir || !($ts < $fi && $ini < $te)) {
                continue;
            }
            $out[] = [
                'origen' => 'RESPONSABLE', 'id_origen' => (int)$m['id_programado'],
                'fecha' => date('Y-m-d', $ts), 'hora_inicio' => date('H:i', $ts), 'hora_fin' => date('H:i', $te),
                'titulo' => 'El responsable ya tiene el mantenimiento #' . (int)$m['id_programado'] . ' en ' . $m['nombre_sala'],
            ];
        }
        return $out;
    }

    /**
     * Todo lo que impide usar la sala en [inicio, inicio + minutos). Lista vacía = libre.
     * $excluirIdProgramado: el propio mantenimiento (al reprogramar o revisar conflictos).
     */
    public static function choques(PDO $pdo, int $idSala, DateTimeInterface $inicio, int $minutos, ?int $excluirIdProgramado = null, ?int $idResponsable = null): array
    {
        $fin = (new DateTime($inicio->format('Y-m-d H:i:s')))->modify("+$minutos minutes");
        $fecha = $inicio->format('Y-m-d');
        $hi = $inicio->format('H:i');
        // Si termina otro día, para la sala basta comparar hasta el fin del día de inicio.
        $hf = $fin->format('Y-m-d') === $fecha ? $fin->format('H:i') : '24:00';
        $choques = [];
        foreach (self::eventosDelDia($pdo, $idSala, $fecha) as $e) {
            if ($e['origen'] === 'MANTENIMIENTO' && $excluirIdProgramado && (int)$e['id_origen'] === $excluirIdProgramado) {
                continue;
            }
            if ($e['hora_inicio'] < $hf && $hi < $e['hora_fin']) {
                $choques[] = $e;
            }
        }
        if ($idResponsable) {
            foreach (self::ocupacionResponsable($pdo, $idResponsable, $inicio, $fin, $excluirIdProgramado) as $e) {
                // El mantenimiento de la misma sala ya salió arriba.
                if (!array_filter($choques, fn($c) => $c['origen'] === 'MANTENIMIENTO' && (int)$c['id_origen'] === $e['id_origen'])) {
                    $choques[] = $e;
                }
            }
        }
        return $choques;
    }

    /**
     * Primer espacio libre de la sala desde $desde (redondeado al siguiente paso de 30 min), de lunes a sábado
     * entre 06:00 y 22:00, sin cruzarse con nada de la sala ni con otro mantenimiento del responsable.
     * null si no hay ninguno en los próximos DIAS_BUSQUEDA días.
     */
    public static function primerHueco(PDO $pdo, int $idSala, DateTimeInterface $desde, int $minutos, ?int $idResponsable = null, ?int $excluirIdProgramado = null): ?DateTime
    {
        $t = new DateTime($desde->format('Y-m-d H:i:00'));
        $resto = ((int)$t->format('i')) % self::PASO_MINUTOS;
        if ($resto) {
            $t->modify('+' . (self::PASO_MINUTOS - $resto) . ' minutes');
        }
        $limite = (clone $t)->modify('+' . self::DIAS_BUSQUEDA . ' days');
        [$ha, $ma] = array_map('intval', explode(':', self::HORA_APERTURA));
        [$hc, $mc] = array_map('intval', explode(':', self::HORA_CIERRE));
        while ($t < $limite) {
            $apertura = (clone $t)->setTime($ha, $ma);
            $ultimoInicio = (clone $t)->setTime($hc, $mc)->modify("-$minutos minutes");
            if ((int)$t->format('N') === 7 || $t > $ultimoInicio) {
                $t = (clone $t)->modify('+1 day')->setTime($ha, $ma);
                continue;
            }
            if ($t < $apertura) {
                $t = $apertura;
            }
            if (!self::choques($pdo, $idSala, $t, $minutos, $excluirIdProgramado, $idResponsable)) {
                return $t;
            }
            $t = (clone $t)->modify('+' . self::PASO_MINUTOS . ' minutes');
        }
        return null;
    }

    /**
     * Conflictos de los mantenimientos que todavía no empiezan (préstamo, clase u otro mantenimiento que se
     * registró después y se cruza). @return array<int, array> id_programado => primer choque
     */
    public static function conflictos(PDO $pdo, array $mantenimientos): array
    {
        $out = [];
        $hoy = date('Y-m-d');
        foreach ($mantenimientos as $m) {
            if (!in_array($m['estado'], ['PENDIENTE', 'PROGRAMADO', 'REPROGRAMADO'], true)) {
                continue;
            }
            $inicio = new DateTime((string)$m['fecha_programada']);
            if ($inicio->format('Y-m-d') < $hoy) {
                continue;
            }
            $c = self::choques($pdo, (int)$m['id_sala'], $inicio, self::duracion($m['duracion_minutos'] ?? 60), (int)$m['id_programado'], !empty($m['id_responsable']) ? (int)$m['id_responsable'] : null);
            if ($c) {
                $out[(int)$m['id_programado']] = $c[0];
            }
        }
        return $out;
    }

    /**
     * Después de registrar algo que ocupa una sala (préstamo, clase, cambio de una semana), avisa a quien coordina
     * cada mantenimiento de esa sala que todavía no empieza y que ahora se cruza. Devuelve cuántos avisos envió.
     */
    public static function avisarCruces(PDO $pdo, ?int $idSala, string $queCambio): int
    {
        if (!$idSala) {
            return 0;
        }
        self::limpiarCache();
        $st = $pdo->prepare("SELECT id_programado, id_sala, fecha_programada, duracion_minutos, estado, id_responsable, id_creador
                             FROM mantenimientos_programados
                             WHERE id_sala = ? AND estado IN ('PENDIENTE','PROGRAMADO','REPROGRAMADO') AND fecha_programada >= CURDATE()
                             ORDER BY fecha_programada LIMIT 60");
        $st->execute([$idSala]);
        $mants = array_column($st->fetchAll(PDO::FETCH_ASSOC), null, 'id_programado');
        $n = 0;
        foreach (self::conflictos($pdo, $mants) as $id => $c) {
            $m = $mants[$id];
            $aviso = (int)($m['id_responsable'] ?: $m['id_creador']);
            NotificationService::crear($pdo, $aviso, 'MANTENIMIENTO',
                "$queCambio: el mantenimiento #$id del " . date('d/m H:i', strtotime((string)$m['fecha_programada'])) . ' ahora se cruza con ' . self::texto($c) . '. Reprográmalo.',
                'mantenimientos_programados.php?sala=' . (int)$m['id_sala']);
            $n++;
        }
        return $n;
    }

    /** Texto corto de un choque para mensajes ("préstamo de sala de 06:00 a 08:00"). */
    public static function texto(array $c): string
    {
        return lcfirst((string)$c['titulo']) . ' de ' . substr((string)$c['hora_inicio'], 0, 5) . ' a ' . substr((string)$c['hora_fin'], 0, 5);
    }
}
