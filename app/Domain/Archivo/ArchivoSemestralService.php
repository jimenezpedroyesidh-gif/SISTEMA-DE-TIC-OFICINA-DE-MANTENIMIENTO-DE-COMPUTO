<?php
/**
 * Cierre de semestre: el ADMIN decide cuándo «comprimir» los tickets de un semestre.
 *
 * Reglas:
 *  - Solo se archivan tickets en estado final (Aprobado, Cerrado, Descartado). Los que no se han contestado,
 *    no se han solucionado o esperan aprobación final se quedan intactos en los paneles.
 *  - Tampoco se archiva un ticket del que cuelga algo abierto: mantenimiento sin terminar, incidente de
 *    seguridad sin cerrar o solicitud de baja pendiente.
 *  - De cada ticket queda un resumen compacto en `tickets_archivo` (sala, quién reportó, quién atendió,
 *    descripción y solución recortadas, fechas, tiempo de resolución, prioridad, calificación, equipos).
 *  - El historial de componentes y los movimientos de inventario NO se borran: `historial_componente`
 *    queda apuntando al resumen archivado y la ficha de cada equipo sigue mostrando sus tickets archivados.
 *  - Se borra el detalle operativo (comentarios, asignaciones, eventos, pausas, SLA, encuesta) y, si el admin
 *    lo marca, las fotos adjuntas (las que no son evidencia de un movimiento de inventario).
 *  - Todo en una transacción: si algo falla no queda nada a medias. Los archivos se borran del disco después del COMMIT.
 */
final class ArchivoSemestralService
{
    public const MAX_TEXTO = 1000;
    /** Días antes del fin del semestre en curso desde los que el admin ya puede cerrarlo. */
    public const DIAS_ANTES_FIN = 30;

    /** [desde, hastaExclusivo] en 'Y-m-d' del periodo AAAA-N. */
    public static function rango(string $periodo): array
    {
        [$anio, $mitad] = array_map('intval', explode('-', $periodo));
        return $mitad === 1 ? ["$anio-01-01", "$anio-07-01"] : ["$anio-07-01", ($anio + 1) . "-01-01"];
    }

    /** ¿Ya se puede cerrar este semestre? (terminó, o le faltan menos de DIAS_ANTES_FIN días). */
    public static function sePuedeCerrar(string $periodo, ?int $hoy = null): bool
    {
        if (!HorarioPeriodo::valido($periodo)) return false;
        $hoy = $hoy ?? time();
        [, $hasta] = self::rango($periodo);
        return strtotime($hasta) - $hoy <= self::DIAS_ANTES_FIN * 86400;
    }

    /** Texto que el admin debe escribir para confirmar (evita archivar por un clic accidental). */
    public static function textoConfirmacion(string $periodo): string
    {
        return 'ARCHIVAR ' . $periodo;
    }

    /** Vista previa de un periodo: cuántos se archivan, cuántos se quedan y por qué. */
    public static function vistaPrevia(PDO $pdo, string $periodo): array
    {
        [$desde, $hasta] = self::rango($periodo);
        $r = ['periodo' => $periodo, 'desde' => $desde, 'hasta' => $hasta, 'sePuede' => self::sePuedeCerrar($periodo)];
        foreach ([TablaTicket::NORMAL, TablaTicket::PROFESOR] as $tabla) {
            $cand = count(ArchivoSemestralRepository::candidatos($pdo, $tabla, $desde, $hasta));
            $term = ArchivoSemestralRepository::contarTerminados($pdo, $tabla, $desde, $hasta);
            $r[$tabla] = [
                'candidatos' => $cand,
                'retenidos' => $term - $cand, // terminados pero con algo abierto colgando
                'abiertos' => ArchivoSemestralRepository::contarAbiertos($pdo, $tabla, $desde, $hasta),
            ];
        }
        $r['total'] = $r['tickets']['candidatos'] + $r['tickets_profesor']['candidatos'];
        return $r;
    }

    /** Hay un semestre ya terminado con tickets listos para archivar (aviso en el panel del admin). */
    public static function avisoPendiente(PDO $pdo): ?array
    {
        $actual = HorarioPeriodo::actual();
        foreach (ArchivoSemestralRepository::resumenPorPeriodo($pdo) as $periodo => $c) {
            $periodo = (string)$periodo;
            if ($c['terminados'] > 0 && ($periodo < $actual || self::sePuedeCerrar($periodo))) {
                return ['periodo' => $periodo, 'terminados' => $c['terminados'], 'abiertos' => $c['abiertos']];
            }
        }
        return null;
    }

    private static function recortar(?string $t): ?string
    {
        $t = trim((string)$t);
        if ($t === '') return null;
        return mb_strlen($t) > self::MAX_TEXTO ? mb_substr($t, 0, self::MAX_TEXTO - 1) . '…' : $t;
    }

    /**
     * Archiva el semestre. Devuelve ['ok' => bool, 'mensaje' => string, 'lote' => ?int].
     */
    public static function archivar(PDO $pdo, int $idAdmin, string $periodo, string $confirmacion, bool $borrarAdjuntos): array
    {
        if (!HorarioPeriodo::valido($periodo)) {
            return ['ok' => false, 'mensaje' => 'Periodo inválido.', 'lote' => null];
        }
        if (!self::sePuedeCerrar($periodo)) {
            return ['ok' => false, 'mensaje' => "El semestre $periodo todavía no termina: se puede cerrar desde " . self::DIAS_ANTES_FIN . " días antes de su fin.", 'lote' => null];
        }
        if (trim($confirmacion) !== self::textoConfirmacion($periodo)) {
            return ['ok' => false, 'mensaje' => 'Escribe exactamente «' . self::textoConfirmacion($periodo) . '» para confirmar.', 'lote' => null];
        }

        [$desde, $hasta] = self::rango($periodo);
        $archivosABorrar = [];
        $n = [TablaTicket::NORMAL => 0, TablaTicket::PROFESOR => 0];
        $nAdj = 0;
        $bytes = 0;
        $idLote = null;

        try {
          Database::transaccion(function (PDO $pdo) use ($idAdmin, $periodo, $desde, $hasta, $borrarAdjuntos, &$archivosABorrar, &$n, &$nAdj, &$bytes, &$idLote): void {
            $idLote = ArchivoSemestralRepository::crearLote($pdo, $periodo, $desde, date('Y-m-d', strtotime($hasta . ' -1 day')), $idAdmin);
            foreach ([TablaTicket::NORMAL, TablaTicket::PROFESOR] as $tabla) {
                foreach (ArchivoSemestralRepository::candidatos($pdo, $tabla, $desde, $hasta) as $id) {
                    $t = $tabla === TablaTicket::NORMAL ? ArchivoSemestralRepository::ticketSolicitante($pdo, $id) : ArchivoSemestralRepository::ticketProfesor($pdo, $id);
                    if (!$t) continue;
                    $met = ArchivoSemestralRepository::metricas($pdo, $tabla, $id);
                    $activos = ArchivoSemestralRepository::activos($pdo, $tabla, $id);
                    if ($tabla === TablaTicket::NORMAL && !empty($t['id_equipo']) && !in_array((int)$t['id_equipo'], array_map(fn($a) => (int)$a['id_equipo'], $activos), true)) {
                        $activos[] = ['id_equipo' => (int)$t['id_equipo'], 'id_componente' => null, 'tipo_afectacion' => 'DAÑADO', 'equipo_codigo' => $t['equipo'] ?? null, 'componente_codigo' => null];
                    }
                    $codigos = array_values(array_filter(array_map(fn($a) => $a['equipo_codigo'] ?: $a['componente_codigo'], $activos)));

                    $creado = (string)$t['fecha_creacion'];
                    $cierre = $met['fecha_cierre_evento'] ?? ($t['fecha_terminacion'] ?? null) ?? ($met['fecha_solucion'] ?? null);
                    $fila = [
                        'id_lote' => $idLote,
                        'periodo' => $periodo,
                        'tabla_origen' => $tabla,
                        'id_original' => $id,
                        'tipo' => $tabla === TablaTicket::NORMAL
                            ? (string)$t['tipo_solicitud']
                            : ($t['tipo_ticket'] === 'danio' ? 'ERROR_SALA' : 'REQUERIMIENTO' . (!empty($t['subtipo_requerimiento']) ? ':' . $t['subtipo_requerimiento'] : '')),
                        'origen' => $t['origen'] ?? null,
                        'categoria' => $t['categoria'] ?? null,
                        'estado_final' => (string)$t['estado'],
                        'id_sala' => !empty($t['id_sala']) ? (int)$t['id_sala'] : null,
                        'sala_nombre' => $t['nombre_sala'] ?? ($tabla === TablaTicket::NORMAL ? $t['ubicacion'] : ($t['sala_danio'] ?? $t['sala_requerimiento'] ?? null)),
                        'solicitante' => $t['solicitante_nombre'] ?? ($t['nombre_contacto'] ?? ($tabla === TablaTicket::NORMAL && ($t['origen'] ?? '') === 'QR_ANONIMO' ? 'QR sin sesión' : null)),
                        'correo_contacto' => $tabla === TablaTicket::NORMAL ? ($t['correo_contacto'] ?? null) : ($t['solicitante_correo'] ?? null),
                        Rol::TECNICO => $t['tecnico_nombre'] ?? null,
                        'beca' => $t['beca_nombre'] ?? null,
                        'descripcion' => self::recortar($tabla === TablaTicket::NORMAL
                            ? $t['descripcion']
                            : implode(' · ', array_filter([$t['descripcion_problema'] ?? null, $t['descripcion_actividad'] ?? null, $t['motivo'] ?? null, $t['software'] ?? null, $t['programa'] ?? null]))),
                        'solucion' => self::recortar($tabla === TablaTicket::NORMAL ? ($t['solucion'] ?? null) : null),
                        'equipos_afectados' => $codigos ? mb_substr(implode(', ', $codigos), 0, 500) : null,
                        'prioridad' => $met['prioridad'],
                        'fecha_creacion' => $creado,
                        'fecha_primera_respuesta' => $met['fecha_primera_respuesta'],
                        'fecha_cierre' => $cierre,
                        'minutos_resolucion' => $cierre ? max(0, (int)round((strtotime((string)$cierre) - strtotime($creado)) / 60)) : null,
                        'reaperturas' => $met['reaperturas'],
                        'calificacion' => $met['calificacion'],
                        'total_comentarios' => min(65535, $met['total_comentarios']),
                        'total_adjuntos' => min(65535, $met['total_adjuntos']),
                        'id_programado_relacionado' => $met['id_programado'],
                        'codigo_seguimiento' => $tabla === TablaTicket::NORMAL ? ($t['codigo_seguimiento'] ?? null) : null,
                    ];
                    $idArchivo = ArchivoSemestralRepository::insertarArchivo($pdo, $fila);
                    foreach ($activos as $a) {
                        ArchivoSemestralRepository::insertarArchivoActivo($pdo, $idArchivo,
                            $a['id_equipo'] !== null ? (int)$a['id_equipo'] : null,
                            $a['id_componente'] !== null ? (int)$a['id_componente'] : null,
                            $a['tipo_afectacion'] ?? null);
                    }

                    $idsAdj = [];
                    if ($borrarAdjuntos) {
                        foreach (ArchivoSemestralRepository::adjuntosBorrables($pdo, $tabla, $id) as $adj) {
                            $idsAdj[] = (int)$adj['id'];
                            $archivosABorrar[] = basename((string)$adj['nombre_almacenado']);
                            $bytes += (int)$adj['tamano_bytes'];
                        }
                    }
                    $nAdj += count($idsAdj);
                    ArchivoSemestralRepository::eliminarTicket($pdo, $tabla, $id, $idArchivo, $idsAdj);
                    $n[$tabla]++;
                }
            }
            ArchivoSemestralRepository::cerrarLote($pdo, $idLote, $n['tickets'], $n['tickets_profesor'], $nAdj, $bytes);
            AuditoriaService::registrar($pdo, $idAdmin, 'tickets_archivo', $idLote, 'CREAR',
                "Cierre de semestre $periodo: {$n['tickets']} ticket(s) de solicitantes/QR y {$n['tickets_profesor']} de profesores archivados; $nAdj adjunto(s) borrados.");
          }, $pdo);
        } catch (Throwable $e) {
            RegistroErrores::registrar('Archivo semestral: falló el cierre de ' . $periodo, $e);
            return ['ok' => false, 'mensaje' => 'No se archivó nada: ocurrió un error y se deshicieron los cambios. Revisa el registro de errores.', 'lote' => null];
        }

        // Ya confirmado en la base: ahora sí se borran las fotos del disco (si falla una, solo queda el archivo huérfano).
        $carpeta = TicketAdjuntos::carpetaAdjuntos();
        foreach ($archivosABorrar as $nombre) {
            if ($nombre !== '' && is_file($carpeta . '/' . $nombre)) @unlink($carpeta . '/' . $nombre);
        }

        $total = $n['tickets'] + $n['tickets_profesor'];
        return [
            'ok' => true,
            'mensaje' => $total === 0
                ? "No había tickets terminados para archivar en $periodo."
                : "Semestre $periodo archivado: $total ticket(s) comprimidos" . ($nAdj ? ", $nAdj foto(s) borradas (" . round($bytes / 1048576, 1) . ' MB liberados)' : '') . '. Los tickets abiertos siguen en los paneles.',
            'lote' => $idLote,
        ];
    }
}
