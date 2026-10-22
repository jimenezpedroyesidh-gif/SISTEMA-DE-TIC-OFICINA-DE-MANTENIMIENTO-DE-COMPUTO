<?php
/**
 * Distribución física de un salón (maquetación del plano). Reglas puras, sin SQL.
 *
 * Estructura (se guarda como JSON en sala_distribucion.estructura):
 *   docente: {ubicacion: adelante|atras|ninguno, lado: izq|der, id_equipo: ?int, numero: string, red: string}
 *   filas:   [ {mesas: [ {pasillo: bool, puestos: [ {id_equipo: ?int, numero: string, red: string} ]} ]} ]
 *
 *  - Las filas van del frente (tablero) hacia el fondo; las mesas de izquierda a derecha.
 *  - «pasillo» en una mesa = hay pasillo a su derecha. El primer pasillo de cada fila parte el
 *    salón en lado izquierdo / derecho (en el celular se ve un lado a la vez).
 *  - Cada mesa puede tener distinta cantidad de puestos (p. ej. mesas de 4 con una de 3, o mesas de 3).
 *  - Cada puesto lleva su NÚMERO visible (el que se pega en el equipo), el equipo de inventario que
 *    está ahí y el PUNTO DE RED de la pared/canaleta al que se conecta.
 */
final class DistribucionSala
{
    public const MAX_FILAS = 20;
    public const MAX_MESAS = 10;
    public const MAX_PUESTOS = 8;
    public const MAX_NUMERO = 10;
    public const MAX_RED = 30;
    public const UBICACIONES_DOCENTE = ['adelante', 'atras', 'ninguno'];
    public const ORDENES = ['filas', 'zigzag', 'fondo'];

    /**
     * Limpia y valida una estructura recibida (p. ej. del editor). $idsValidos: equipos que pertenecen
     * a la sala. Devuelve ['estructura' => array, 'errores' => string[]].
     */
    public static function normalizar(array $e, array $idsValidos, array $codigos = []): array
    {
        $validos = array_flip(array_map('intval', $idsValidos));
        $errores = [];
        $usados = [];   // id_equipo => descripción del puesto donde ya está
        $numeros = [];  // número => descripción del puesto
        $texto = static fn($v, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$v)), 0, $max);

        $revisarPuesto = function (array $p, string $donde) use ($validos, &$usados, &$numeros, &$errores, $texto, $codigos): array {
            $id = isset($p['id_equipo']) && $p['id_equipo'] !== '' && $p['id_equipo'] !== null ? (int)$p['id_equipo'] : null;
            if ($id !== null && !isset($validos[$id])) {
                $errores[] = "El equipo del puesto $donde no pertenece a esta sala.";
                $id = null;
            }
            if ($id !== null) {
                if (isset($usados[$id])) {
                    $errores[] = 'El equipo ' . ($codigos[$id] ?? "#$id") . " está en dos puestos ({$usados[$id]} y $donde).";
                } else {
                    $usados[$id] = $donde;
                }
            }
            $numero = $texto($p['numero'] ?? '', self::MAX_NUMERO);
            if ($numero !== '') {
                $clave = mb_strtolower($numero);
                if (isset($numeros[$clave])) {
                    $errores[] = "El número «{$numero}» se repite ({$numeros[$clave]} y $donde).";
                } else {
                    $numeros[$clave] = $donde;
                }
            }
            return ['id_equipo' => $id, 'numero' => $numero, 'red' => $texto($p['red'] ?? '', self::MAX_RED)];
        };

        $d = is_array($e['docente'] ?? null) ? $e['docente'] : [];
        $ubic = in_array($d['ubicacion'] ?? '', self::UBICACIONES_DOCENTE, true) ? $d['ubicacion'] : 'adelante';
        $docente = ['ubicacion' => $ubic, 'lado' => ($d['lado'] ?? '') === 'der' ? 'der' : 'izq', 'id_equipo' => null, 'numero' => '', 'red' => ''];
        if ($ubic !== 'ninguno') {
            $docente = array_merge($docente, $revisarPuesto($d, 'del docente'));
        }

        $filas = [];
        $filasIn = is_array($e['filas'] ?? null) ? array_values($e['filas']) : [];
        if (count($filasIn) > self::MAX_FILAS) {
            $errores[] = 'Máximo ' . self::MAX_FILAS . ' filas por salón.';
            $filasIn = array_slice($filasIn, 0, self::MAX_FILAS);
        }
        foreach ($filasIn as $iF => $f) {
            $mesasIn = is_array($f['mesas'] ?? null) ? array_values($f['mesas']) : [];
            if (count($mesasIn) > self::MAX_MESAS) {
                $errores[] = 'Fila ' . ($iF + 1) . ': máximo ' . self::MAX_MESAS . ' mesas.';
                $mesasIn = array_slice($mesasIn, 0, self::MAX_MESAS);
            }
            $mesas = [];
            foreach ($mesasIn as $iM => $m) {
                $puestosIn = is_array($m['puestos'] ?? null) ? array_values($m['puestos']) : [];
                if (!$puestosIn) continue; // una mesa sin puestos no se dibuja
                if (count($puestosIn) > self::MAX_PUESTOS) {
                    $errores[] = 'Fila ' . ($iF + 1) . ', mesa ' . ($iM + 1) . ': máximo ' . self::MAX_PUESTOS . ' puestos por mesa.';
                    $puestosIn = array_slice($puestosIn, 0, self::MAX_PUESTOS);
                }
                $puestos = [];
                foreach ($puestosIn as $iP => $p) {
                    $puestos[] = $revisarPuesto(is_array($p) ? $p : [], 'F' . ($iF + 1) . '-M' . ($iM + 1) . '-' . ($iP + 1));
                }
                $mesas[] = ['pasillo' => !empty($m['pasillo']), 'puestos' => $puestos];
            }
            if ($mesas) {
                $mesas[count($mesas) - 1]['pasillo'] = false; // no hay pasillo después de la última mesa
                $filas[] = ['mesas' => $mesas];
            }
        }
        return ['estructura' => ['docente' => $docente, 'filas' => $filas], 'errores' => array_values(array_unique($errores))];
    }

    /** Estructura vacía con N filas iguales (plantilla rápida del editor). $pasilloTras: mesa (1..) tras la que va el pasillo; 0 = sin pasillo. */
    public static function plantilla(int $filas, int $mesas, int $puestos, int $pasilloTras = 0, string $docente = 'adelante'): array
    {
        $filas = max(1, min(self::MAX_FILAS, $filas));
        $mesas = max(1, min(self::MAX_MESAS, $mesas));
        $puestos = max(1, min(self::MAX_PUESTOS, $puestos));
        $fila = ['mesas' => []];
        for ($m = 1; $m <= $mesas; $m++) {
            $fila['mesas'][] = ['pasillo' => $m === $pasilloTras && $m < $mesas, 'puestos' => array_fill(0, $puestos, ['id_equipo' => null, 'numero' => '', 'red' => ''])];
        }
        return [
            'docente' => ['ubicacion' => in_array($docente, self::UBICACIONES_DOCENTE, true) ? $docente : 'adelante', 'lado' => 'izq', 'id_equipo' => null, 'numero' => '', 'red' => ''],
            'filas' => array_fill(0, $filas, $fila),
        ];
    }

    /**
     * Posiciones de los puestos en orden de recorrido: [[fila, mesa, puesto], ...].
     *  - filas:  del frente al fondo, cada fila de izquierda a derecha.
     *  - zigzag: como «filas», pero las filas pares van de derecha a izquierda (cableado en serpentina).
     *  - fondo:  del fondo al frente, de izquierda a derecha.
     */
    public static function recorrido(array $e, string $orden = 'filas'): array
    {
        $out = [];
        $filas = $e['filas'] ?? [];
        $indices = array_keys($filas);
        if ($orden === 'fondo') $indices = array_reverse($indices);
        foreach ($indices as $n => $iF) {
            $fila = [];
            foreach ($filas[$iF]['mesas'] as $iM => $m) {
                foreach (array_keys($m['puestos']) as $iP) $fila[] = [$iF, $iM, $iP];
            }
            if ($orden === 'zigzag' && $n % 2 === 1) $fila = array_reverse($fila);
            array_push($out, ...$fila);
        }
        return $out;
    }

    /** Numera los puestos 1, 2, 3… en el orden elegido (con prefijo opcional, p. ej. «PC»). */
    public static function numerar(array $e, string $orden = 'filas', int $inicio = 1, string $prefijo = ''): array
    {
        $n = $inicio;
        foreach (self::recorrido($e, $orden) as [$f, $m, $p]) {
            $e['filas'][$f]['mesas'][$m]['puestos'][$p]['numero'] = mb_substr($prefijo . $n++, 0, self::MAX_NUMERO);
        }
        return $e;
    }

    /** Pone los equipos dados (ya ordenados) en los puestos vacíos, en orden de recorrido. Los que no quepan quedan sin ubicar. */
    public static function autoAsignar(array $e, array $idsEquipo, string $orden = 'filas'): array
    {
        $ocupados = array_flip(self::idsUbicados($e));
        $pendientes = array_values(array_filter(array_map('intval', $idsEquipo), fn($id) => !isset($ocupados[$id])));
        foreach (self::recorrido($e, $orden) as [$f, $m, $p]) {
            if (!$pendientes) break;
            if ($e['filas'][$f]['mesas'][$m]['puestos'][$p]['id_equipo'] === null) {
                $e['filas'][$f]['mesas'][$m]['puestos'][$p]['id_equipo'] = array_shift($pendientes);
            }
        }
        return $e;
    }

    /** Ids de equipo que ya tienen puesto (incluido el docente). */
    public static function idsUbicados(array $e): array
    {
        $ids = [];
        if (!empty($e['docente']['id_equipo'])) $ids[] = (int)$e['docente']['id_equipo'];
        foreach ($e['filas'] ?? [] as $f) foreach ($f['mesas'] as $m) foreach ($m['puestos'] as $p) {
            if ($p['id_equipo'] !== null) $ids[] = (int)$p['id_equipo'];
        }
        return $ids;
    }

    /**
     * Distribución inicial a partir de lo que hoy muestra el plano automático: dos lados con pasillo
     * al medio, una mesa por lado y fila (2 o 3 puestos), equipos en orden de código y numerados 1, 2, 3…
     * $pcs: ids de los equipos de cómputo en orden natural de código; $idDocente: equipo docente si se detectó.
     */
    public static function inicial(array $pcs, ?int $idDocente = null): array
    {
        $n = count($pcs);
        $mitad = (int)ceil($n / 2);
        $cols = $mitad <= 4 ? 2 : 3;
        $filas = max(1, (int)ceil($mitad / $cols));
        $e = self::plantilla($filas, $n > 1 ? 2 : 1, $cols, $n > 1 ? 1 : 0);
        $e = self::autoAsignar($e, $pcs); // en orden de recorrido: el número del puesto sigue el orden de los códigos
        $e['docente']['id_equipo'] = $idDocente;
        $e['docente']['numero'] = $idDocente ? 'D' : '';
        return self::numerar($e);
    }

    /** Dónde está un equipo en la distribución: ['numero', 'red', 'fila', 'mesa', 'docente'] o null si no tiene puesto. */
    public static function puestoDeEquipo(array $e, int $idEquipo): ?array
    {
        $d = $e['docente'] ?? [];
        if (($d['ubicacion'] ?? 'ninguno') !== 'ninguno' && (int)($d['id_equipo'] ?? 0) === $idEquipo) {
            return ['numero' => (string)($d['numero'] ?? ''), 'red' => (string)($d['red'] ?? ''), 'fila' => null, 'mesa' => null, 'docente' => true];
        }
        foreach ($e['filas'] ?? [] as $iF => $f) foreach ($f['mesas'] ?? [] as $iM => $m) foreach ($m['puestos'] ?? [] as $p) {
            if ((int)($p['id_equipo'] ?? 0) === $idEquipo) {
                return ['numero' => (string)($p['numero'] ?? ''), 'red' => (string)($p['red'] ?? ''), 'fila' => $iF + 1, 'mesa' => $iM + 1, 'docente' => false];
            }
        }
        return null;
    }
}
