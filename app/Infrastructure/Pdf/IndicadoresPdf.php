<?php
/**
 * PDF del tablero de indicadores de la mesa de ayuda: resumen, cumplimiento por prioridad, desempeño por
 * responsable, pendientes por antigüedad y disponibilidad de equipos. Solo dibuja: los datos los prepara
 * IndicadoresService::calcular().
 */
require_once dirname(__DIR__, 3) . '/vendor/fpdf/fpdf.php';

class IndicadoresPdf extends FPDF
{
    public string $periodo = '';
    public string $filtro = '';

    private static function t(string $s): string
    {
        $s = strtr($s, ['≥' => '>=', '≤' => '<=', '—' => '-', '–' => '-', '«' => '"', '»' => '"']);
        return function_exists('mb_convert_encoding') ? (string)mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8') : $s;
    }

    function Header()
    {
        $logo = dirname(__DIR__, 3) . '/public/logo.png';
        if (is_file($logo)) $this->Image($logo, 10, 10, 14, 14);
        $this->SetXY(28, 10);
        $this->SetFont('Arial', 'B', 13);
        $this->Cell(172, 7, self::t('Indicadores de la mesa de ayuda'), 0, 2);
        $this->SetFont('Arial', '', 9);
        $this->Cell(172, 5, self::t(institucion('sistema') . ' ' . institucion('sigla') . ' · ' . $this->periodo), 0, 2);
        if ($this->filtro !== '') $this->Cell(172, 5, self::t($this->filtro), 0, 2);
        $this->SetY(30);
    }

    function Footer()
    {
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 7);
        $this->Cell(0, 8, self::t('Tiempos en horas hábiles según el horario de atención · Generado el ' . date('d/m/Y H:i') . ' · Página ' . $this->PageNo() . ' de {nb}'), 0, 0, 'C');
    }

    /** Tabla simple con encabezado gris. $anchos suman 190. */
    function tabla(string $titulo, array $encabezado, array $anchos, array $filas): void
    {
        if ($this->GetY() > 250) $this->AddPage();
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(190, 7, self::t($titulo), 0, 1);
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(235, 235, 235);
        foreach ($encabezado as $i => $h) $this->Cell($anchos[$i], 6, self::t($h), 1, 0, 'L', true);
        $this->Ln();
        $this->SetFont('Arial', '', 8);
        if (!$filas) {
            $this->Cell(190, 6, self::t('Sin datos en el periodo.'), 1, 1);
        }
        foreach ($filas as $f) {
            if ($this->GetY() > 275) $this->AddPage();
            foreach ($f as $i => $v) {
                $txt = self::t((string)$v);
                while ($this->GetStringWidth($txt) > $anchos[$i] - 2 && strlen($txt) > 3) $txt = substr($txt, 0, -4) . '...';
                $this->Cell($anchos[$i], 6, $txt, 1);
            }
            $this->Ln();
        }
        $this->Ln(4);
    }

    /** Arma el documento completo. */
    public static function generar(array $d, string $filtro): self
    {
        $pdf = new self('P', 'mm', 'A4');
        $pdf->AliasNbPages();
        $pdf->SetAutoPageBreak(false);
        $pdf->periodo = 'Del ' . date('d/m/Y', strtotime($d['filtros']['desde'])) . ' al ' . date('d/m/Y', strtotime($d['filtros']['hasta']));
        $pdf->filtro = $filtro;
        $pdf->AddPage();

        $pdf->tabla('Resumen', ['Indicador', 'Valor', 'Referencia'], [70, 30, 90], IndicadoresService::resumen($d));
        $filas = [];
        foreach ($d['prioridades'] as $p => $x) {
            $filas[] = [SlaService::PRIORIDADES[$p], $x['n'], $x['resueltos'], IndicadoresService::textoPct($x['cumplimiento']), CalendarioHabil::textoDuracion($x['prom_solucion'])];
        }
        $pdf->tabla('Cumplimiento por prioridad', ['Prioridad', 'Tickets', 'Resueltos', 'Cumplimiento', 'Solución promedio'], [40, 30, 30, 45, 45], $filas);
        $filas = [];
        foreach ($d['tecnicos'] as $x) {
            $filas[] = [$x['nombre'], $x['asignados'], $x['resueltos'], CalendarioHabil::textoDuracion($x['prom_solucion']), IndicadoresService::textoPct($x['cumplimiento']), $x['satisfaccion'] === null ? '-' : number_format($x['satisfaccion'], 1, ',', '')];
        }
        $pdf->tabla('Desempeño por responsable', ['Responsable', 'Tickets', 'Resueltos', 'Solución prom.', 'Cumplimiento', 'Satisfacción'], [60, 22, 22, 30, 30, 26], $filas);
        $filas = [];
        foreach (IndicadoresService::BUCKETS as $b => $nombre) $filas[] = [$nombre, $d['antiguedad'][$b]];
        $pdf->tabla('Pendientes de hoy por antigüedad', ['Antigüedad', 'Tickets'], [140, 50], $filas);
        $filas = [];
        foreach ($d['disponibilidad'] as $x) {
            $filas[] = [$x['nombre_sala'], $x['operativos'] . ' de ' . $x['total'], IndicadoresService::textoPct($x['total'] > 0 ? round($x['operativos'] * 100 / $x['total'], 1) : null)];
        }
        $pdf->tabla('Equipos operativos por sala', ['Sala', 'Operativos', 'Disponibilidad'], [100, 45, 45], $filas);
        $filas = [];
        foreach ($d['reincidentes'] as $x) $filas[] = [$x['codigo'], (string)$x['nombre_sala'], $x['incidentes']];
        $pdf->tabla('Equipos reincidentes (2 o más incidentes)', ['Equipo', 'Sala', 'Incidentes'], [60, 90, 40], $filas);
        return $pdf;
    }
}
