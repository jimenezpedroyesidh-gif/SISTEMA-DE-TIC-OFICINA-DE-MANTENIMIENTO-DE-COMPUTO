<?php
/**
 * Conexión de SOLO LECTURA a la base PostgreSQL del sistema de asistencia
 * facial (DPTO_asistencia_v2.py). El sistema de tickets NUNCA escribe en
 * esta base — solo consulta estado/registros.
 *
 * Los datos de conexión van en config/postgres.php (plantilla: postgres.example.php).
 *
 * Las funciones de este archivo cruzan por `codigo` (users.codigo en MySQL == usuarios.codigo / estado.codigo
 * / registros.codigo en Postgres), no por cédula.
 */

function getConexionPG() {
    return Database::asistencia();
}

/**
 * Devuelve un array [codigo => 'ACTIVO'|'INACTIVO'] para el conjunto de
 * códigos dado, consultando la tabla `estado` de la base de asistencia.
 * Si Postgres no responde, devuelve un array vacío (el panel debe seguir
 * funcionando, simplemente sin ese dato).
 *
 * @param string[] $codigos
 * @return array<string,string>
 */
function estadoAsistenciaPorCodigos(array $codigos): array {
    if (empty($codigos)) return [];

    $pdoPg = getConexionPG();
    if (!$pdoPg) return [];

    $placeholders = implode(',', array_fill(0, count($codigos), '?'));
    $stmt = $pdoPg->prepare(
        "SELECT codigo, estado FROM estado WHERE codigo IN ($placeholders)"
    );
    $stmt->execute($codigos);

    $resultado = [];
    foreach ($stmt->fetchAll() as $fila) {
        $resultado[$fila['codigo']] = strtoupper((string)$fila['estado']);
    }
    return $resultado;
}

/**
 * Registros crudos de asistencia (entrada/salida/actividad) de un código
 * puntual, dentro de un rango de fechas inclusivo. Ordenados por fecha y
 * hora para poder emparejar entrada/salida por día en construirSeguimientoDiario().
 *
 * @return array<int,array{fecha:string,hora:string,tipo:string,actividad:?string}>
 */
function registrosAsistenciaPorCodigo(string $codigo, string $fechaInicio, string $fechaFin): array {
    $pdoPg = getConexionPG();
    if (!$pdoPg) return [];

    $stmt = $pdoPg->prepare(
        "SELECT fecha, hora, tipo, actividad
         FROM registros
         WHERE codigo = ? AND fecha BETWEEN ? AND ?
         ORDER BY fecha ASC, hora ASC"
    );
    $stmt->execute([$codigo, $fechaInicio, $fechaFin]);
    return $stmt->fetchAll();
}

/**
 * Agrupa los registros crudos de un código en un resumen por día: hora de
 * llegada (primer registro de tipo entrada), hora de salida (último
 * registro de tipo salida), cantidad de horas entre ambas, y las
 * actividades reportadas ese día (concatenadas si hay varias).
 *
 * Asume que `registros.tipo` distingue entrada/salida por texto (se
 * compara sin distinguir mayúsculas — revisa contra los valores reales
 * que graba DPTO_asistencia_v2.py y ajusta $tiposEntrada/$tiposSalida
 * abajo si usan otro texto).
 *
 * @return array<int,array{fecha:string,hora_llegada:?string,hora_salida:?string,horas:?float,actividad:string}>
 */
function construirSeguimientoDiario(array $registros): array {
    $tiposEntrada = ['entrada', 'llegada', 'in'];
    $tiposSalida  = ['salida', 'out'];

    $porDia = [];
    foreach ($registros as $r) {
        $fecha = $r['fecha'];
        if (!isset($porDia[$fecha])) {
            $porDia[$fecha] = ['fecha' => $fecha, 'hora_llegada' => null, 'hora_salida' => null, 'actividades' => []];
        }
        $tipo = strtolower(trim((string)$r['tipo']));
        if (in_array($tipo, $tiposEntrada, true) && $porDia[$fecha]['hora_llegada'] === null) {
            $porDia[$fecha]['hora_llegada'] = $r['hora'];
        }
        if (in_array($tipo, $tiposSalida, true)) {
            $porDia[$fecha]['hora_salida'] = $r['hora']; // se queda con la última salida del día
        }
        $actividad = trim((string)($r['actividad'] ?? ''));
        if ($actividad !== '' && !in_array($actividad, $porDia[$fecha]['actividades'], true)) {
            $porDia[$fecha]['actividades'][] = $actividad;
        }
    }

    $resultado = [];
    foreach ($porDia as $dia) {
        $horas = null;
        if ($dia['hora_llegada'] && $dia['hora_salida']) {
            $segundos = strtotime($dia['fecha'] . ' ' . $dia['hora_salida']) - strtotime($dia['fecha'] . ' ' . $dia['hora_llegada']);
            if ($segundos > 0) {
                $horas = round($segundos / 3600, 2);
            }
        }
        $resultado[] = [
            'fecha'        => $dia['fecha'],
            'hora_llegada' => $dia['hora_llegada'],
            'hora_salida'  => $dia['hora_salida'],
            'horas'        => $horas,
            'actividad'    => implode(' / ', $dia['actividades']),
        ];
    }
    usort($resultado, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));
    return $resultado;
}
