<?php

/** Búsqueda de componentes por código de inventario o serial (buscador de celular y formularios). */

/**
 * Busca componentes por código de inventario o serial (búsqueda parcial,
 * pensada para el buscador grande desde celular). $soloDisponibles=true
 * limita a estado ACTIVO y sin equipo asignado (para elegir reemplazo).
 *
 * $idSalaContexto (opcional): cuando se pasa, el resultado se restringe a
 * lo que YA está instalado en esa sala/oficina, o a lo que está suelto en
 * bodega (sin equipo asignado). Así, al buscar un componente para un
 * ticket/mantenimiento de una sala puntual, no aparece el inventario
 * completo de otras salas. Si no se pasa (null), se busca en todo el
 * inventario (pantallas donde todavía no hay una sala elegida).
 */
function buscarComponentes(PDO $pdo, string $q, bool $soloDisponibles = false, int $limite = 15, ?int $idSalaContexto = null): array {
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $limite = max(1, $limite);

    // Ahora se busca con índice: · código de inventario que EMPIEZA por el texto (UFPS-0012…) · serial que
    // EMPIEZA por el texto (SN9F…) · código de inventario que TERMINA en el texto (…0231 → columna virtual
    // codigo_inventario_rev) Cada rama trae como máximo $limite filas ya ordenadas por su índice, así que
    // nunca recorre la tabla.
    $filtros = '';
    $paramsFiltro = [];
    if ($soloDisponibles) {
        // "Disponible para instalar": repuestos ACTIVOS o piezas EN_REVISION que se devuelven; sin equipo asignado.
        $filtros .= " AND c.estado IN ('ACTIVO','EN_REVISION') AND c.id_equipo_actual IS NULL";
    }
    if ($idSalaContexto) {
        // Solo lo instalado en esa sala, o lo que está suelto en bodega.
        $filtros .= " AND (c.id_equipo_actual IS NULL OR e.id_sala = ?)";
        $paramsFiltro[] = $idSalaContexto;
    }
    $select = "SELECT c.*, e.codigo AS equipo_codigo, s.nombre_sala
               FROM componentes c
               LEFT JOIN equipos e ON c.id_equipo_actual = e.id_equipo
               LEFT JOIN salas s ON e.id_sala = s.id_sala";
    $escapar = static fn(string $t): string => addcslashes($t, '\\%_');
    $prefijo = $escapar($q) . '%';
    $alReves = $escapar(implode('', array_reverse(mb_str_split($q)))) . '%';
    $lim = (int)$limite;

    $ramas = [
        ["($select WHERE c.codigo_inventario LIKE ?$filtros ORDER BY c.codigo_inventario LIMIT $lim)", $prefijo],
        ["($select WHERE c.serial LIKE ?$filtros ORDER BY c.serial LIMIT $lim)", $prefijo],
        ["($select WHERE c.codigo_inventario_rev LIKE ?$filtros ORDER BY c.codigo_inventario_rev LIMIT $lim)", $alReves],
    ];
    $params = [];
    foreach ($ramas as [, $patron]) {
        $params[] = $patron;
        array_push($params, ...$paramsFiltro);
    }
    $st = $pdo->prepare('SELECT * FROM (' . implode(' UNION ', array_column($ramas, 0)) . ") r ORDER BY r.codigo_inventario LIMIT $lim");
    $st->execute($params);
    $filas = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($filas) >= $limite || !componentesBusquedaLibrePermitida($pdo)) {
        return $filas;
    }

    // Inventario pequeño: también «contiene» en cualquier parte (comportamiento original).
    $like = '%' . $escapar($q) . '%';
    $sql = "$select WHERE (c.codigo_inventario LIKE ? OR c.serial LIKE ?)";
    $stmt = $pdo->prepare("$sql$filtros ORDER BY c.codigo_inventario LIMIT $lim");
    $stmt->execute(array_merge([$like, $like], $paramsFiltro));
    $porId = [];
    foreach (array_merge($filas, $stmt->fetchAll(PDO::FETCH_ASSOC)) as $f) {
        $porId[(int)$f['id_componente']] = $f;
    }
    usort($porId, fn($a, $b) => strcmp((string)$a['codigo_inventario'], (string)$b['codigo_inventario']));
    return array_slice($porId, 0, $limite);
}

/** Filas a partir de las cuales el buscador de componentes deja de usar «contiene» (recorrería la tabla). */
const COMPONENTES_UMBRAL_BUSQUEDA_LIBRE = 200000;

/**
 * ¿El inventario es lo bastante pequeño para la búsqueda «contiene» (LIKE '%texto%')? Usa el conteo
 * aproximado de information_schema (instantáneo, no recorre la tabla) y lo recuerda durante la petición.
 */
function componentesBusquedaLibrePermitida(PDO $pdo): bool {
    static $permitida = null;
    if ($permitida === null) {
        try {
            $filas = (int)$pdo->query("SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'componentes'")->fetchColumn();
        } catch (PDOException $e) {
            $filas = 0;
        }
        $permitida = $filas < COMPONENTES_UMBRAL_BUSQUEDA_LIBRE;
    }
    return $permitida;
}

/**
 * Igual que buscarComponentes(), pero para un id puntual — pensado para
 * cuando el QR leído es el enlace de ficha_componente.php (guarda el id,
 * no el código de inventario en texto). Aplica las mismas reglas de
 * "disponible"/sala para no dejar elegir algo que no debería verse desde
 * ese formulario.
 */
function buscarComponentePorId(PDO $pdo, int $idComponente, bool $soloDisponibles = false, ?int $idSalaContexto = null): ?array {
    $sql = "SELECT c.*, e.codigo AS equipo_codigo, s.nombre_sala
            FROM componentes c
            LEFT JOIN equipos e ON c.id_equipo_actual = e.id_equipo
            LEFT JOIN salas s ON e.id_sala = s.id_sala
            WHERE c.id_componente = ?";
    $params = [$idComponente];
    if ($soloDisponibles) {
        $sql .= " AND c.estado IN ('ACTIVO','EN_REVISION') AND c.id_equipo_actual IS NULL";
    }
    if ($idSalaContexto) {
        $sql .= " AND (c.id_equipo_actual IS NULL OR e.id_sala = ?)";
        $params[] = $idSalaContexto;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    return $fila ?: null;
}

