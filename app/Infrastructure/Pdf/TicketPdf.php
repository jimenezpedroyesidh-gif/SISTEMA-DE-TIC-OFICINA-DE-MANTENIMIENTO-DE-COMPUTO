<?php
/**
 * PDF «Informe Técnico de Soporte» de un ticket. Solo dibuja: los datos los prepara TicketPdfService. Ambos
 * terminan con la conversación con el solicitante y las fotos de evidencia del trabajo (constancia). El
 * reporte de horas de las becas tiene su propia clase (ReporteHorasPdf).
 */
require_once dirname(__DIR__, 3) . '/vendor/fpdf/fpdf.php';
require_once dirname(__DIR__, 2) . '/Support/marca.php';

class TicketPdf extends FPDF
{
    /** Logo institucional: public/logo.png. */
    private static function rutaLogo(): string { return dirname(__DIR__, 3) . '/public/logo.png'; }

    function Header()
    {
        $this->Image(self::rutaLogo(), 10, 8, 25);
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, utf8_decode('UNIVERSIDAD FRANCISCO DE PAULA SANTANDER'), 0, 1, 'C');
        $this->SetFont('Arial', '', 11);
        $this->Cell(0, 10, utf8_decode('Informe Técnico de Soporte - Departamento de Sistemas'), 0, 1, 'C');
        $this->Ln(5);
        $this->SetDrawColor(...marcaRgb());
        $this->SetLineWidth(1);
        $this->Line(10, 35, 200, 35);
        $this->Ln(10);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, utf8_decode('Página ') . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    /** Fila «etiqueta: valor». Un valor null se imprime como $siNulo. */
    function Field($label, $value, string $siNulo = '')
    {
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(45, 8, utf8_decode($label), 0, 0);
        $this->SetFont('Arial', '', 10);
        $this->MultiCell(0, 8, utf8_decode((string)($value ?? $siNulo)));
        $this->Ln(2);
    }

    private function firma(?string $tecnico, ?string $fecha): void
    {
        $this->Ln(10);
        $this->SetFont('Arial', 'I', 10);
        $this->MultiCell(0, 8, utf8_decode('Firmado electrónicamente por ' . $tecnico . ' el ' . $fecha));
    }

    private function cierre(): void
    {
        $this->Ln(15);
        $this->SetDrawColor(...marcaRgb());
        $this->SetLineWidth(0.7);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(8);
        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 8, utf8_decode('Sistema de Tickets - ' . institucion('sigla')), 0, 1, 'C');
    }

    /** Título de una sección (Conversación, Evidencia…). */
    private function seccion(string $titulo, float $espacioMinimo = 30): void
    {
        if ($this->GetY() + $espacioMinimo > 280) $this->AddPage();
        $this->Ln(4);
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(...marcaRgb());
        $this->Cell(0, 8, utf8_decode($titulo), 0, 1);
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(220, 220, 220);
        $this->SetLineWidth(0.3);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(2);
    }

    /**
     * Conversación con quien reportó (constancia): fecha, autor, rol y texto; las reiteraciones se marcan.
     * @param array<int,array> $mensajes TicketConversacion::deTicket()
     */
    private function conversacion(array $mensajes): void
    {
        if (!$mensajes) return;
        $this->seccion('Conversación con el solicitante (' . count($mensajes) . ' mensaje' . (count($mensajes) === 1 ? '' : 's') . ')');
        foreach ($mensajes as $m) {
            $quien = $m['autor'] . ($m['es_personal'] ? ' (soporte)' : ' (solicitante)');
            $fecha = date('d/m/Y H:i', strtotime((string)$m['fecha_creacion']));
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(0, 5, utf8_decode($fecha . ' - ' . $quien . (!empty($m['es_reiteracion']) ? ' - REITERACIÓN' : '')), 0, 1);
            $this->SetFont('Arial', '', 9);
            $this->MultiCell(0, 5, utf8_decode((string)$m['comentario']));
            $this->Ln(1.5);
        }
    }

    /**
     * Fotos de evidencia del trabajo realizado, dos por fila (cada una en un recuadro de 90 x 68 mm).
     * @param array<int,array> $fotos TicketEvidencia::deTicket()
     */
    private function evidencias(array $fotos): void
    {
        if (!$fotos) return;
        $this->seccion('Evidencia fotográfica del trabajo realizado (' . count($fotos) . ')', 90); // título y primera fila de fotos juntos
        $anchoCaja = 92.0; $altoCaja = 68.0; $col = 0;
        foreach ($fotos as $f) {
            $ruta = TicketEvidencia::ruta($f);
            $info = $ruta ? @getimagesize($ruta) : false;
            if (!$ruta || !$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) continue;
            if ($col === 0 && $this->GetY() + $altoCaja + 8 > 280) $this->AddPage();
            $x = 10 + $col * ($anchoCaja + 6);
            $y = $this->GetY();
            $escala = min($anchoCaja / $info[0], $altoCaja / $info[1]);
            $w = $info[0] * $escala; $h = $info[1] * $escala;
            try {
                $this->Image($ruta, $x + ($anchoCaja - $w) / 2, $y + ($altoCaja - $h) / 2, $w, $h, $info[2] === IMAGETYPE_PNG ? 'PNG' : 'JPG');
            } catch (Throwable $e) {
                continue; // una foto dañada no impide generar el PDF
            }
            $this->SetXY($x, $y + $altoCaja + 1);
            $this->SetFont('Arial', 'I', 7);
            $this->Cell($anchoCaja, 4, utf8_decode(date('d/m/Y H:i', strtotime((string)$f['fecha_subida'])) . ' - ' . ($f['subio'] ?? '')), 0, 0, 'C');
            if ($col === 1) { $this->SetXY(10, $y + $altoCaja + 7); $col = 0; } else { $this->SetXY(10, $y); $col = 1; }
        }
        if ($col === 1) $this->SetY($this->GetY() + $altoCaja + 7);
    }

    /** Solución + conversación + evidencia, comunes a los dos tipos de ticket. */
    private function anexos(array $extra): void
    {
        $this->conversacion($extra['conversacion'] ?? []);
        $this->evidencias($extra['fotos'] ?? []);
    }

    private static function nuevo(): self
    {
        $pdf = new self();
        $pdf->AliasNbPages();
        $pdf->AddPage();
        return $pdf;
    }

    /** Ticket normal (tabla tickets). $equipos = texto de «Equipos afectados» ('' = no se imprime la fila). */
    public static function ticket(array $t, string $equipos, array $extra = []): self
    {
        $pdf = self::nuevo();
        $pdf->Field('Solicitante:', $t['solicitante'] ?? ('Anónimo (QR)' . ($t['nombre_sala'] ? ' — ' . $t['nombre_sala'] : '')));
        $pdf->Field('Dependencia:', $t['nombre_dependencia']);
        $pdf->Field('Ubicación:', $t['ubicacion']);
        if (!empty($t['radicado_datarsoft'])) $pdf->Field('Radicado DatArSoft:', $t['radicado_datarsoft']);
        $pdf->Field('Equipo:', $t['equipo'] ?: '-');
        if ($equipos !== '') $pdf->Field('Equipos afectados:', $equipos);
        $pdf->Field('Marca/Modelo:', $t['marca_modelo']);
        $pdf->Field('Inventario/Serial:', $t['numero_inventario']);
        $pdf->Field('Descripción:', $t['descripcion']);
        $pdf->Field('Solución:', $t['solucion']);
        $pdf->Field('Técnico:', $t['tecnico'] ?? '-');
        $pdf->Field('Estado:', $t['estado']);
        $pdf->anexos($extra);
        if ($t['firmado']) $pdf->firma($t['tecnico'], $t['fecha_terminacion']);
        $pdf->cierre();
        return $pdf;
    }

    /** Ticket de profesor (tabla tickets_profesor). */
    public static function ticketProfesor(array $t, string $equipos, array $extra = []): self
    {
        $pdf = self::nuevo();
        $f = fn($l, $v) => $pdf->Field($l, $v, '-');
        $f('Profesor:', $t['profesor']);
        $f('Tipo:', ucfirst($t['tipo_ticket']));

        $duraciones = TicketProfesorCreacionService::DURACIONES_PRESTAMO;
        if ($t['tipo_ticket'] === 'requerimiento') {
            if (!empty($t['subtipo_requerimiento'])) $f('Subtipo:', str_replace('_', ' ', $t['subtipo_requerimiento']));
            if ($t['subtipo_requerimiento'] === 'CAMBIO_SALA') {
                $f('Sala actual:', $t['sala_actual_nombre']);
                $f('Sala destino:', $t['sala_requerimiento']);
                $f('Motivo:', $t['motivo']);
            } elseif (in_array($t['subtipo_requerimiento'], ['PRESTAMO_SALA', 'PRESTAMO_EQUIPO'], true)) {
                $f('Sala / objeto:', $t['sala_requerimiento']);
                $f('Motivo / objeto:', $t['motivo']);
                $f('Cantidad de alumnos:', $t['cantidad_alumnos']);
                $f('Para qué:', $t['para_que']);
                $f('Duración:', $duraciones[$t['duracion_prestamo']] ?? $t['duracion_prestamo']);
                $f('Fecha requerida:', $t['fecha_requerida']);
                $f('Devolución prevista:', $t['fecha_devolucion_prevista']);
            } else {
                // INSTALACION_SOFTWARE u otro subtipo futuro (programa = software, descripcion_actividad = detalle).
                $f('Programa:', $t['programa']);
                $f('Sala:', $t['sala_requerimiento']);
                $f('Fecha requerida:', $t['fecha_requerida']);
                $f('Actividad:', $t['descripcion_actividad']);
            }
        } else {
            $f('Sala:', $t['sala_danio']);
            $f('Salón:', $t['salon']);
            $f('Problema:', $t['descripcion_problema']);
            // Equipos afectados: del checklist opcional del profesor o agregados después por el beca/técnico (ticket_activos).
            if ($equipos !== '') $f('Equipos afectados:', $equipos);
        }

        if (!empty($t['solucion'])) $f('Solución:', $t['solucion']);
        if (!empty($t['fecha_solucion'])) $f('Fecha de solución:', $t['fecha_solucion']);
        $f('Técnico:', $t['tecnico'] ?? '-');
        $f('Estado:', $t['estado']);
        $pdf->anexos($extra);
        if ($t['firmado']) $pdf->firma($t['tecnico'], $t['fecha_firmado'] ?: ($t['fecha_solucion'] ?? null));
        $pdf->cierre();
        return $pdf;
    }
}
