<?php
/**
 * Casos de uso del «Seguimiento de horas» de las becas trabajo.
 *
 * Reglas:
 *  - Solo las becas trabajo tienen horas. Técnico, secretaria, profesor, solicitante y admin NO tienen
 *    seguimiento propio (el sistema de asistencia es para las becas).
 *  - La beca ve siempre las suyas.
 *  - El admin ve las de todas las becas (y no tiene horas propias).
 *  - Cualquier otro rol (incluida una beca) ve las de todas solo si el admin le activó el permiso
 *    «Ver horas de todas las becas» (UserPermissions::VER_HORAS_BECAS) desde Usuarios.
 *
 * La asistencia viene de PostgreSQL (solo lectura) mediante PostgresConnection.php; sin Postgres las
 * funciones devuelven vacío y la vista muestra «Sin registros».
 */
require_once dirname(__DIR__, 2) . '/Infrastructure/Database/PostgresConnection.php';

class SeguimientoHorasService
{
    public const MESES = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

    /**
     * Qué puede ver el usuario de la sesión.
     * propio = tiene horas propias (solo beca_trabajo); todas = ve las de todas las becas (admin o permiso).
     * @return array{propio:bool,todas:bool,entra:bool}
     */
    public static function acceso(PDO $pdo, int $idUsuario, string $rol): array
    {
        $propio = $rol === Rol::BECA;
        $todas = $rol === Rol::ADMIN || ($rol !== '' && UserPermissions::tiene($pdo, $idUsuario, UserPermissions::VER_HORAS_BECAS));
        return ['propio' => $propio, 'todas' => $todas, 'entra' => $propio || $todas];
    }

    /** Rango máximo de un reporte por fechas (un año). */
    public const MAX_DIAS_RANGO = 366;

    /**
     * Periodo consultado. Por defecto un mes calendario (?mes, ?anio); si llegan ?desde y ?hasta válidos
     * (Y-m-d, desde <= hasta, máximo un año) el reporte cubre exactamente ese rango de días.
     * Devuelve [anio, mes, inicio, fin, rango(bool), etiqueta, query, error].
     */
    public static function periodo($anioGet, $mesGet, $desdeGet = null, $hastaGet = null): array
    {
        $anio = validarEntero($anioGet, 2000, 2100, (int)date('Y'));
        $mes = validarEntero($mesGet, 1, 12, (int)date('n'));
        $ini = sprintf('%04d-%02d-01', $anio, $mes);
        $base = ['anio' => $anio, 'mes' => $mes, 'inicio' => $ini, 'fin' => date('Y-m-t', strtotime($ini)), 'rango' => false,
                 'etiqueta' => self::MESES[$mes] . ' ' . $anio, 'query' => 'mes=' . $mes . '&anio=' . $anio, 'error' => null];

        $desde = validarFecha((string)($desdeGet ?? ''));
        $hasta = validarFecha((string)($hastaGet ?? ''));
        if ($desdeGet === null && $hastaGet === null) return $base;
        if (($desdeGet ?? '') === '' && ($hastaGet ?? '') === '') return $base;
        if ($desde === null || $hasta === null) {
            return ['error' => 'Escribe las dos fechas (desde y hasta).'] + $base;
        }
        if ($desde > $hasta) {
            return ['error' => 'La fecha «desde» debe ser anterior o igual a «hasta».'] + $base;
        }
        if ((strtotime($hasta) - strtotime($desde)) / 86400 + 1 > self::MAX_DIAS_RANGO) {
            return ['error' => 'El rango máximo es de un año.'] + $base;
        }
        return [
            'anio' => (int)substr($desde, 0, 4), 'mes' => (int)substr($desde, 5, 2),
            'inicio' => $desde, 'fin' => $hasta, 'rango' => true,
            'etiqueta' => 'del ' . date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)),
            'query' => 'desde=' . $desde . '&hasta=' . $hasta, 'error' => null,
        ];
    }

    /** Días con horas de un código en el periodo + total. */
    public static function seguimientoDe(?string $codigo, string $ini, string $fin): array
    {
        if ($codigo === null || $codigo === '') return ['dias' => [], 'total' => 0.0];
        $dias = construirSeguimientoDiario(registrosAsistenciaPorCodigo($codigo, $ini, $fin));
        $total = 0.0;
        foreach ($dias as $d) { if ($d['horas'] !== null) $total += $d['horas']; }
        return ['dias' => $dias, 'total' => round($total, 2)];
    }

    /**
     * Datos de la página.
     *  - becas: listado de todas las becas con sus horas del mes (solo si ve todas).
     *  - becaConsultada: la beca cuyo detalle se muestra: ?ver_id (si ve todas, y solo becas), o la propia
     *    si es beca; null para admin/otros roles mientras no elijan una (solo ven el listado).
     */
    public static function consultaMensual(PDO $pdo, int $idSesion, array $acceso, $verId, array $periodo): array
    {
        $becas = [];
        if ($acceso['todas']) {
            $becas = SeguimientoHorasRepository::becas($pdo);
            foreach ($becas as &$b) {
                $b['horas_mes'] = null;
                if (!empty($b['codigo'])) {
                    $dias = construirSeguimientoDiario(registrosAsistenciaPorCodigo($b['codigo'], $periodo['inicio'], $periodo['fin']));
                    $total = 0.0; $hay = false;
                    foreach ($dias as $d) { if ($d['horas'] !== null) { $total += $d['horas']; $hay = true; } }
                    $b['horas_mes'] = $hay ? round($total, 2) : null;
                }
            }
            unset($b);
        }

        $consultada = null;
        if ($acceso['todas'] && $verId !== null && $verId !== '') {
            $consultada = SeguimientoHorasRepository::becaPorId($pdo, (int)$verId);
        }
        if ($consultada === null && $acceso['propio']) {
            $consultada = SeguimientoHorasRepository::becaPorId($pdo, $idSesion);
        }

        $seg = $consultada ? self::seguimientoDe($consultada['codigo'] ?? null, $periodo['inicio'], $periodo['fin']) : ['dias' => [], 'total' => 0.0];
        return [
            'becas' => $becas,
            'becaConsultada' => $consultada,
            'esPropia' => $consultada !== null && (int)$consultada['id'] === $idSesion,
            'seguimiento' => $seg['dias'],
            'totalHoras' => $seg['total'],
        ];
    }

    /**
     * Becas que salen en el PDF. Quien ve todas: ?todas=1 → todas las becas con código; ?codigo → esa beca
     * (solo códigos de becas); sin nada → la propia si es beca. Solo propio: siempre la propia (se ignora
     * ?codigo, así no se descarga el de otra). Devuelve ['error' => texto|null, 'becas' => [...]].
     */
    public static function becasParaPdf(PDO $pdo, int $idSesion, array $acceso, bool $todas, string $codigoPedido): array
    {
        if ($acceso['todas'] && $todas) {
            $lista = SeguimientoHorasRepository::becasConCodigo($pdo);
        } elseif ($acceso['todas'] && $codigoPedido !== '') {
            $b = SeguimientoHorasRepository::becaPorCodigo($pdo, $codigoPedido);
            if (!$b) return ['error' => 'No existe ninguna beca trabajo con ese código.', 'becas' => []];
            $lista = [$b];
        } elseif ($acceso['propio']) {
            $yo = SeguimientoHorasRepository::becaPorId($pdo, $idSesion);
            if (empty($yo['codigo'])) return ['error' => 'No tienes código institucional registrado en tu perfil.', 'becas' => []];
            $lista = [$yo];
        } else {
            return ['error' => 'Falta el código de la beca a consultar.', 'becas' => []];
        }
        if (empty($lista)) return ['error' => 'No hay becas con código para generar el PDF.', 'becas' => []];
        return ['error' => null, 'becas' => $lista];
    }

    public static function nombreArchivoPdf(array $becas, int $mes, int $anio, ?array $periodo = null): string
    {
        $sufijo = ($periodo !== null && !empty($periodo['rango'])) ? $periodo['inicio'] . '_a_' . $periodo['fin'] : $mes . '_' . $anio;
        return count($becas) === 1
            ? 'Horas_' . preg_replace('/[^A-Za-z0-9_]/', '_', (string)($becas[0]['nombre_completo'] ?? '')) . '_' . $sufijo . '.pdf'
            : 'Horas_Becas_Trabajo_' . $sufijo . '.pdf';
    }
}
