<?php
/**
 * Calendario hábil para medir el SLA: solo cuenta el horario de atención configurado por el admin
 * (por día de la semana) y no cuenta los festivos. Sin SQL salvo desdeBase(): el resto es aritmética
 * pura, fácil de probar. También calcula los festivos de Colombia (Ley 51 de 1983, «Ley Emiliani»).
 */
final class CalendarioHabil
{
    public const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    /** @var array<int,array{0:int,1:int}> día ISO => [segundo de inicio, segundo de fin] dentro del día */
    private array $semana = [];
    /** @var array<string,bool> fechas Y-m-d no hábiles */
    private array $festivos;
    /** @var array<string,int> caché de segundos hábiles por fecha */
    private array $cacheDia = [];

    /**
     * @param array<int|string,mixed> $semana   día => ['07:00','18:00'] o null (no se atiende)
     * @param array<int|string,mixed> $festivos lista de 'Y-m-d' (o claves 'Y-m-d')
     */
    public function __construct(array $semana, array $festivos = [])
    {
        foreach ($semana as $dia => $rango) {
            $dia = (int)$dia;
            if ($dia < 1 || $dia > 7 || !is_array($rango) || count($rango) !== 2) continue;
            $ini = self::segundosDeHora((string)$rango[0]);
            $fin = self::segundosDeHora((string)$rango[1]);
            if ($ini !== null && $fin !== null && $fin > $ini) $this->semana[$dia] = [$ini, $fin];
        }
        $this->festivos = [];
        foreach ($festivos as $k => $v) {
            $f = is_string($k) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $k) ? $k : (string)$v;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) $this->festivos[$f] = true;
        }
    }

    /** Calendario con el horario y los festivos guardados por el admin. */
    public static function desdeBase(PDO $pdo): self
    {
        $festivos = [];
        try {
            $festivos = $pdo->query('SELECT fecha FROM dias_festivos')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $festivos = [];
        }
        return new self(self::semanaDeConfig(ConfigSistema::texto($pdo, 'horario_semana')), $festivos);
    }

    /** @return array<int,array{0:string,1:string}|null> el horario guardado (JSON) en forma de arreglo */
    public static function semanaDeConfig(string $json): array
    {
        $d = json_decode($json, true);
        if (!is_array($d)) $d = json_decode(ConfigSistema::PREDETERMINADOS['horario_semana'], true);
        $out = [];
        foreach (self::DIAS as $n => $_) {
            $r = $d[(string)$n] ?? ($d[$n] ?? null);
            $out[$n] = is_array($r) && count($r) === 2 ? [(string)$r[0], (string)$r[1]] : null;
        }
        return $out;
    }

    /** 'HH:MM' → segundos desde medianoche (null si no es una hora válida). */
    public static function segundosDeHora(string $hora): ?int
    {
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($hora), $m)) {
            return trim($hora) === '24:00' ? 86400 : null;
        }
        return (int)$m[1] * 3600 + (int)$m[2] * 60;
    }

    public function hayHorario(): bool
    {
        return $this->semana !== [];
    }

    public function esFestivo(string $fecha): bool
    {
        return isset($this->festivos[$fecha]);
    }

    /** Segundos hábiles de un día completo (0 si es festivo o no se atiende). */
    private function segundosDia(DateTimeImmutable $dia): int
    {
        $f = $dia->format('Y-m-d');
        if (!isset($this->cacheDia[$f])) {
            $r = $this->semana[(int)$dia->format('N')] ?? null;
            $this->cacheDia[$f] = ($r === null || $this->esFestivo($f)) ? 0 : $r[1] - $r[0];
        }
        return $this->cacheDia[$f];
    }

    /** Segundos hábiles entre dos instantes (0 si $b <= $a). Sin horario configurado cuenta tiempo corrido. */
    public function segundosEntre(DateTimeInterface $a, DateTimeInterface $b): int
    {
        $ta = $a->getTimestamp();
        $tb = $b->getTimestamp();
        if ($tb <= $ta) return 0;
        if (!$this->hayHorario()) return $tb - $ta;

        $tz = $a->getTimezone();
        $dia = (new DateTimeImmutable('@' . $ta))->setTimezone($tz)->setTime(0, 0);
        $fin = (new DateTimeImmutable('@' . $tb))->setTimezone($tz)->setTime(0, 0);
        $total = 0;
        while ($dia <= $fin) {
            $r = $this->semana[(int)$dia->format('N')] ?? null;
            if ($r !== null && !$this->esFestivo($dia->format('Y-m-d'))) {
                $base = $dia->getTimestamp();
                $desde = max($ta, $base + $r[0]);
                $hasta = min($tb, $base + $r[1]);
                if ($hasta > $desde) $total += $hasta - $desde;
            }
            $dia = $dia->modify('+1 day');
        }
        return $total;
    }

    /** Instante en que se cumplen $segundos hábiles contados desde $inicio. */
    public function sumar(DateTimeInterface $inicio, int $segundos): DateTimeImmutable
    {
        $ini = DateTimeImmutable::createFromInterface($inicio);
        if ($segundos <= 0) return $ini;
        if (!$this->hayHorario()) return $ini->modify('+' . $segundos . ' seconds');

        $dia = $ini->setTime(0, 0);
        $t = $ini->getTimestamp();
        for ($i = 0; $i < 3700; $i++) { // tope de ~10 años: evita un ciclo infinito con datos absurdos
            if ($this->segundosDia($dia) > 0) {
                $r = $this->semana[(int)$dia->format('N')];
                $base = $dia->getTimestamp();
                $desde = max($t, $base + $r[0]);
                $hasta = $base + $r[1];
                if ($hasta > $desde) {
                    if ($hasta - $desde >= $segundos) return (new DateTimeImmutable('@' . ($desde + $segundos)))->setTimezone($ini->getTimezone());
                    $segundos -= $hasta - $desde;
                }
            }
            $dia = $dia->modify('+1 day');
        }
        return $ini->modify('+' . $segundos . ' seconds');
    }

    /**
     * Festivos de Colombia de un año: fijos, trasladables al lunes siguiente (Ley Emiliani) y los que
     * dependen de la Pascua. @return array<string,string> 'Y-m-d' => nombre
     */
    public static function festivosColombia(int $anio): array
    {
        $tz = new DateTimeZone('America/Bogota');
        $f = static fn(int $m, int $d): DateTimeImmutable => new DateTimeImmutable(sprintf('%04d-%02d-%02d', $anio, $m, $d), $tz);
        $lunes = static fn(DateTimeImmutable $d): DateTimeImmutable => (int)$d->format('N') === 1 ? $d : $d->modify('next monday');

        // Domingo de Pascua (algoritmo anónimo gregoriano, sin depender de la extensión calendar).
        $a = $anio % 19; $b = intdiv($anio, 100); $c = $anio % 100; $d = intdiv($b, 4); $e = $b % 4;
        $g = intdiv(8 * $b + 13, 25); $h = (19 * $a + $b - $d - $g + 15) % 30; $j = intdiv($c, 4); $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $j - $h - $k) % 7; $m = intdiv($a + 11 * $h + 19 * $l, 433);
        $mes = intdiv($h + $l - 7 * $m + 90, 25); $diaP = ($h + $l - 7 * $m + 33 * $mes + 19) % 32;
        $pascua = $f($mes, $diaP);

        $lista = [
            [$f(1, 1), 'Año Nuevo'],
            [$lunes($f(1, 6)), 'Día de los Reyes Magos'],
            [$lunes($f(3, 19)), 'Día de San José'],
            [$pascua->modify('-3 days'), 'Jueves Santo'],
            [$pascua->modify('-2 days'), 'Viernes Santo'],
            [$f(5, 1), 'Día del Trabajo'],
            [$pascua->modify('+43 days'), 'Ascensión del Señor'],
            [$pascua->modify('+64 days'), 'Corpus Christi'],
            [$pascua->modify('+71 days'), 'Sagrado Corazón de Jesús'],
            [$lunes($f(6, 29)), 'San Pedro y San Pablo'],
            [$f(7, 20), 'Día de la Independencia'],
            [$f(8, 7), 'Batalla de Boyacá'],
            [$lunes($f(8, 15)), 'Asunción de la Virgen'],
            [$lunes($f(10, 12)), 'Día de la Raza'],
            [$lunes($f(11, 1)), 'Todos los Santos'],
            [$lunes($f(11, 11)), 'Independencia de Cartagena'],
            [$f(12, 8), 'Inmaculada Concepción'],
            [$f(12, 25), 'Navidad'],
        ];
        $out = [];
        foreach ($lista as [$fecha, $nombre]) $out[$fecha->format('Y-m-d')] = $nombre;
        ksort($out);
        return $out;
    }

    /** Duración legible en horas hábiles: «3,5 h» / «45 min». */
    public static function textoDuracion(?int $segundos): string
    {
        if ($segundos === null) return '—';
        if ($segundos < 3600) return max(0, (int)round($segundos / 60)) . ' min';
        return number_format($segundos / 3600, 1, ',', '.') . ' h';
    }
}
