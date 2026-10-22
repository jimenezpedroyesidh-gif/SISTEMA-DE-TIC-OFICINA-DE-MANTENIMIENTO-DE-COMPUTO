<?php
/**
 * Bandeja UNIFICADA del panel técnico: tickets normales (QR con o sin sesión, secretaría, técnico/beca) y tickets
 * de profesor de un mismo grupo (Departamento de Sistemas / otras dependencias) en UNA sola lista, más recientes
 * primero, partida en dos: pendientes y firmados (cerrados). Cada una se pagina aparte (5 por página, botones
 * numéricos como en un correo electrónico). Cada fila lleva '_tipo' = 'normal' | 'prof' para saber qué fila pintar.
 */
final class TicketBandeja
{
    /** Estados en los que el ticket ya terminó (firmado/cerrado) y no requiere acción. */
    public const ESTADOS_CERRADOS = [TicketWorkflow::APROBADO, TicketWorkflow::CERRADO, TicketWorkflow::DESCARTADO];

    /**
     * ¿El ticket ya está firmado/cerrado? Un «Solucionado» (o un traslado sin validar) sigue PENDIENTE aunque
     * tenga firmado = 1: falta la aprobación del técnico.
     */
    public static function estaFirmado(array $t): bool
    {
        if (in_array($t['estado'] ?? '', self::ESTADOS_CERRADOS, true)) return true;
        return !empty($t['firmado']) && !in_array($t['estado'] ?? '', [TicketWorkflow::SOLUCIONADO, TicketWorkflow::LISTO_SIN_VALIDAR], true);
    }

    /**
     * @return array{pendientes:array,firmados:array} cada uno con la forma de paginarArreglo()
     *         (datos, total, total_paginas, pagina)
     */
    public static function cargar(PDO $pdo, bool $sistemas, int $paginaPendientes, int $paginaFirmados): array
    {
        // Dos fases: misma bandeja, pero solo se cargan completas las filas de la página visible.
        return self::desdeListasLigeras(
            TicketPanelRepository::todosNormalesLigeros($pdo, $sistemas),
            TicketPanelRepository::todosProfesorLigeros($pdo, $sistemas),
            $paginaPendientes, $paginaFirmados,
            fn(array $ids) => TicketPanelRepository::normalesPorIds($pdo, $ids),
            fn(array $ids) => TicketPanelRepository::profesorPorIds($pdo, $ids)
        );
    }

    /**
     * Igual que desdeListas(), pero recibe filas LIGERAS (al menos id, fecha_creacion, estado, firmado), ordena, parte
     * en pendientes/firmados y pagina con ellas, y solo al final pide las filas completas de la página visible.
     * Mismo orden, mismos totales y mismas páginas que desdeListas() con las filas completas.
     *
     * @param callable(array<int>): array<int, array> $cargarNormales ids → [id => fila completa]
     * @param callable(array<int>): array<int, array> $cargarProfesor ids → [id => fila completa]
     */
    public static function desdeListasLigeras(array $normales, array $profesor, int $paginaPendientes, int $paginaFirmados, callable $cargarNormales, callable $cargarProfesor): array
    {
        $bandeja = self::desdeListas($normales, $profesor, $paginaPendientes, $paginaFirmados);
        $ids = ['normal' => [], 'prof' => []];
        foreach ($bandeja as $res) {
            foreach ($res['datos'] as $f) { $ids[$f['_tipo']][] = (int)$f['id']; }
        }
        $completas = ['normal' => $ids['normal'] ? $cargarNormales($ids['normal']) : [], 'prof' => $ids['prof'] ? $cargarProfesor($ids['prof']) : []];
        foreach ($bandeja as $k => $res) {
            $datos = [];
            foreach ($res['datos'] as $f) {
                $fila = $completas[$f['_tipo']][(int)$f['id']] ?? null;
                if ($fila !== null) { $fila['_tipo'] = $f['_tipo']; $datos[] = $fila; }
            }
            $bandeja[$k]['datos'] = $datos;
        }
        return $bandeja;
    }

    /**
     * Misma bandeja a partir de listas ya cargadas (panel admin): $normales y $profesor se etiquetan, se mezclan
     * por fecha y se parten en pendientes / firmados.
     */
    public static function desdeListas(array $normales, array $profesor, int $paginaPendientes, int $paginaFirmados): array
    {
        $filas = [];
        foreach ($normales as $t) { $t['_tipo'] = 'normal'; $filas[] = $t; }
        foreach ($profesor as $p) { $p['_tipo'] = 'prof'; $filas[] = $p; }
        usort($filas, fn($a, $b) => strcmp((string)$b['fecha_creacion'], (string)$a['fecha_creacion']) ?: ((int)$b['id'] <=> (int)$a['id']));
        return [
            'pendientes' => paginarArreglo(array_values(array_filter($filas, fn($f) => !self::estaFirmado($f))), $paginaPendientes),
            'firmados' => paginarArreglo(array_values(array_filter($filas, fn($f) => self::estaFirmado($f))), $paginaFirmados),
        ];
    }

    /** Filas visibles de un tipo ('normal' | 'prof') en las páginas actuales (para las precargas en bloque). */
    public static function visibles(array $bandejas, string $tipo): array
    {
        $out = [];
        foreach ($bandejas as $b) {
            foreach (['pendientes', 'firmados'] as $k) {
                foreach ($b[$k]['datos'] as $f) { if ($f['_tipo'] === $tipo) $out[] = $f; }
            }
        }
        return $out;
    }

    /** Texto «1–5 de 12» de una lista paginada. */
    public static function rango(array $res): string
    {
        if ($res['total'] === 0) return '0';
        $desde = ($res['pagina'] - 1) * ticketsPorPagina() + 1;
        return $desde . '–' . ($desde + count($res['datos']) - 1) . ' de ' . $res['total'];
    }
}
