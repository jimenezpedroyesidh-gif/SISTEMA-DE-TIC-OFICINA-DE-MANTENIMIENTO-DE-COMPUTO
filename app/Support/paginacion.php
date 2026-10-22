<?php

/**
 * Ejecuta $sqlBase con LIMIT/OFFSET calculados a partir de $pagina, y
 * cuenta el total de filas que produciría sin el LIMIT (envolviendo la
 * consulta original en un COUNT(*) — funciona con cualquier SELECT, sin
 * tener que reescribir a mano el conteo en cada llamador).
 *
 * $sqlBase NO debe incluir su propio LIMIT. Si ya trae ORDER BY, pásalo
 * dentro de $sqlBase igual que siempre — este helper solo le agrega el
 * LIMIT/OFFSET al final.
 *
 * @return array{datos: array, total: int, total_paginas: int, pagina: int}
 */
function paginar(PDO $pdo, string $sqlBase, array $params, int $pagina, int $porPagina = 20): array {
    $pagina = max(1, $pagina);
    $porPagina = max(1, $porPagina);

    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM ($sqlBase) AS conteo_paginacion");
    $stmtTotal->execute($params);
    $total = (int)$stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int)ceil($total / $porPagina));
    $pagina = min($pagina, $totalPaginas);

    $offset = ($pagina - 1) * $porPagina;
    $stmt = $pdo->prepare($sqlBase . " LIMIT " . (int)$porPagina . " OFFSET " . (int)$offset);
    $stmt->execute($params);
    $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'datos' => $datos,
        'total' => $total,
        'total_paginas' => $totalPaginas,
        'pagina' => $pagina,
    ];
}

/**
 * Tamaño de página ÚNICO de todos los paneles de tickets: 5 por página, los más recientes primero. Cambiar
 * este número cambia todos los paneles a la vez.
 */
function ticketsPorPagina(): int {
    return 5;
}

/**
 * Paginación EN MEMORIA para listas que ya vienen filtradas en PHP
 * (p. ej. tickets.php). Misma forma de resultado que paginar().
 *
 * @return array{datos: array, total: int, total_paginas: int, pagina: int}
 */
function paginarArreglo(array $items, int $pagina, int $porPagina = 0): array {
    $porPagina = $porPagina > 0 ? $porPagina : ticketsPorPagina();
    $total = count($items);
    $totalPaginas = max(1, (int)ceil($total / $porPagina));
    $pagina = min(max(1, $pagina), $totalPaginas);
    return [
        'datos' => array_slice(array_values($items), ($pagina - 1) * $porPagina, $porPagina),
        'total' => $total,
        'total_paginas' => $totalPaginas,
        'pagina' => $pagina,
    ];
}

/**
 * Números de página a mostrar: todos si son pocos; si no, primera, última
 * y la vecindad de la actual, con null donde va "…".
 *
 * @return array<int|null>
 */
function paginasVisibles(int $actual, int $total, int $maximo = 7): array {
    if ($total <= $maximo) {
        return range(1, $total);
    }
    $set = [1, $total, $actual - 1, $actual, $actual + 1];
    if ($actual <= 3) { $set = array_merge($set, [2, 3, 4]); }
    if ($actual >= $total - 2) { $set = array_merge($set, [$total - 3, $total - 2, $total - 1]); }
    $set = array_values(array_unique(array_filter($set, fn($n) => $n >= 1 && $n <= $total)));
    sort($set);
    $out = [];
    $prev = 0;
    foreach ($set as $n) {
        if ($prev && $n - $prev > 1) { $out[] = null; }
        $out[] = $n;
        $prev = $n;
    }
    return $out;
}

/**
 * Controles de paginación como HTML listo para imprimir: « Anterior,
 * botones numerados 1 2 3 … hasta el último (la página actual resaltada) y
 * Siguiente ». $queryParamsExtra son los filtros activos que hay que
 * conservar al cambiar de página (ej. ['estado' => 'En proceso']).
 * $nombreParamPagina permite tener varios bloques paginados en la misma
 * URL sin que se pisen. $ancla (opcional): id del bloque al que volver
 * tras cambiar de página (para no perder la pestaña abierta).
 */
function renderControlesPaginacion(
    int $paginaActual,
    int $totalPaginas,
    array $queryParamsExtra = [],
    string $nombreParamPagina = 'pagina',
    string $ancla = ''
): string {
    if ($totalPaginas <= 1) {
        return '';
    }
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');

    // Se conservan los parámetros de OTROS bloques paginados que ya vienen
    // en la URL (p. ej. pagina_prof=2 al cambiar pagina_sistemas).
    $base = $queryParamsExtra;
    foreach ($_GET as $k => $v) {
        if (is_scalar($v) && !isset($base[$k]) && $k !== $nombreParamPagina) {
            $base[$k] = $v;
        }
    }
    $construirUrl = function (int $pagina) use ($base, $nombreParamPagina, $ancla) {
        $params = $base;
        $params[$nombreParamPagina] = $pagina;
        return '?' . http_build_query($params) . ($ancla !== '' ? '#' . $ancla : '');
    };

    $html = '<nav class="paginacion" aria-label="Paginación">';
    if ($paginaActual > 1) {
        $html .= '<a class="btn btn-secondary btn-sm pag-nav" href="' . $e($construirUrl($paginaActual - 1)) . '" aria-label="Página anterior">«</a>';
    }
    foreach (paginasVisibles($paginaActual, $totalPaginas) as $n) {
        if ($n === null) {
            $html .= '<span class="pag-puntos">…</span>';
        } elseif ($n === $paginaActual) {
            $html .= '<span class="btn btn-sm pag-actual" aria-current="page">' . $n . '</span>';
        } else {
            $html .= '<a class="btn btn-secondary btn-sm" href="' . $e($construirUrl($n)) . '">' . $n . '</a>';
        }
    }
    if ($paginaActual < $totalPaginas) {
        $html .= '<a class="btn btn-secondary btn-sm pag-nav" href="' . $e($construirUrl($paginaActual + 1)) . '" aria-label="Página siguiente">»</a>';
    }
    $html .= '</nav>';
    return $html;
}
