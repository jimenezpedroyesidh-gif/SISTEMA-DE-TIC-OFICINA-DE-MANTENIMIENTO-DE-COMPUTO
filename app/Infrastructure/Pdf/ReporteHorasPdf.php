<?php
/**
 * PDF simple del «Seguimiento de horas» de una beca trabajo (una página o más por beca).
 * Reporte interno del sistema: logo, datos de la beca, tabla por día y total. Solo dibuja:
 * los datos los prepara SeguimientoHorasService.
 */
require_once dirname(__DIR__, 3) . '/vendor/fpdf/fpdf.php';

class ReporteHorasPdf extends FPDF
{
    public string $nombre = '';
    public string $codigo = '';
    public string $dependencia = '';
    public string $periodo = '';

    /** Anchos de columna (suman 190 mm = ancho útil de A4 con márgenes de 10 mm). */
    private const COLS = ['FECHA' => 24, 'LLEGADA' => 22, 'SALIDA' => 22, 'HORAS' => 16, 'ACTIVIDAD REALIZADA' => 106];

    private static function rutaLogo(): string { return dirname(__DIR__, 3) . '/public/logo.png'; }

    private static function t(string $s): string
    {
        return function_exists('mb_convert_encoding') ? (string)mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8') : $s;
    }

    function Header()
    {
        $y = 10;
        if (is_file(self::rutaLogo())) {
            $this->Image(self::rutaLogo(), 10, $y, 16, 16);
        }
        $this->SetXY(30, $y + 1);
        $this->SetFont('Arial', 'B', 13);
        $this->Cell(170, 7, self::t('Seguimiento de horas - Beca trabajo'), 0, 2);
        $this->SetFont('Arial', '', 9);
        $this->Cell(170, 5, self::t(institucion('sistema') . ' ' . institucion('sigla') . ' · Periodo: ' . $this->periodo), 0, 1);

        $this->SetXY(10, $y + 20);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(22, 6, 'Nombre:', 1);
        $this->SetFont('Arial', '', 8);
        $this->Cell(88, 6, self::t($this->nombre), 1);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(16, 6, self::t('Código:'), 1);
        $this->SetFont('Arial', '', 8);
        $this->Cell(64, 6, self::t($this->codigo), 1, 1);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(22, 6, 'Dependencia:', 1);
        $this->SetFont('Arial', '', 8);
        $this->Cell(168, 6, self::t($this->dependencia !== '' ? $this->dependencia : '-'), 1, 1);
        $this->Ln(3);

        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(230, 230, 230);
        foreach (self::COLS as $titulo => $w) {
            $this->Cell($w, 7, self::t($titulo), 1, 0, 'C', true);
        }
        $this->Ln();
    }

    function Footer()
    {
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 7);
        $this->Cell(0, 8, self::t('Generado el ' . date('d/m/Y H:i') . ' · Página ' . $this->PageNo() . ' de {nb}'), 0, 0, 'C');
    }

    /** Una fila por día; si no cabe, salta de página (el encabezado se repite solo). */
    function filaDia(array $d): void
    {
        $wAct = self::COLS['ACTIVIDAD REALIZADA'];
        $this->SetFont('Arial', '', 8);
        $texto = self::t($d['actividad'] ?: '-');
        $lineas = max(1, (int)ceil($this->GetStringWidth($texto) / ($wAct - 4)));
        $alto = max(7, $lineas * 4 + 2);
        if ($this->GetY() + $alto > 275) {
            $this->AddPage();
        }
        $y = $this->GetY();
        $this->Cell(self::COLS['FECHA'], $alto, date('d/m/Y', strtotime($d['fecha'])), 1, 0, 'C');
        $this->Cell(self::COLS['LLEGADA'], $alto, $d['hora_llegada'] ? substr((string)$d['hora_llegada'], 0, 5) : '-', 1, 0, 'C');
        $this->Cell(self::COLS['SALIDA'], $alto, $d['hora_salida'] ? substr((string)$d['hora_salida'], 0, 5) : '-', 1, 0, 'C');
        $this->Cell(self::COLS['HORAS'], $alto, $d['horas'] !== null ? (string)$d['horas'] : '-', 1, 0, 'C');
        $x = $this->GetX();
        $this->MultiCell($wAct, $alto / $lineas, $texto, 1, 'L');
        $this->SetXY($x + $wAct, $y);
        $this->SetY($y + $alto);
    }

    function sinRegistros(): void
    {
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(190, 8, self::t('Sin registros de asistencia en este periodo.'), 1, 1, 'C');
    }

    function total(float $horas): void
    {
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(190, 8, self::t('Total de horas del periodo: ' . round($horas, 2)), 0, 1, 'R');
    }
}
