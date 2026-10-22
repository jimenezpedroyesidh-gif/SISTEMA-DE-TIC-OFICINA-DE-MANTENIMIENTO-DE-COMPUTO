<?php
/**
 * Utilidades de presentación compartidas. Cargado desde bootstrap.php.
 *  - h(): escapa texto para HTML (la única función de escape del proyecto).
 *  - claseChipEstadoTicket(): clase CSS del chip de estado de un ticket.
 *  - claseChipHistorialTicket(): variante usada en la ficha de equipo.
 * Las funciones mantienen EXACTAMENTE las reglas que tenía cada copia.
 */

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}

/**
 * En espera → espera; En proceso → proceso; cualquier otro → solucionado.
 * Con $rechazadoComoDanio=true, "Rechazado" → danio (así lo pintaban las filas de admin
 * de profesor y la lista de mantenimientos de la beca; las demás filas lo pintan como
 * "solucionado": se conserva esa diferencia visual, no se unifica en este paso).
 */
function claseChipEstadoTicket(?string $estado, bool $rechazadoComoDanio = false): string
{
    if ($estado === TicketWorkflow::EN_ESPERA) return 'espera';
    if ($estado === TicketWorkflow::EN_PROCESO) return 'proceso';
    if ($rechazadoComoDanio && $estado === TicketWorkflow::RECHAZADO) return 'danio';
    return 'solucionado';
}

/** Variante de ficha_equipo.php (historial de tickets de un equipo/sala). */
function claseChipHistorialTicket(string $estado): string
{
    return match ($estado) {
        TicketWorkflow::SOLUCIONADO, TicketWorkflow::APROBADO, TicketWorkflow::CERRADO => 'solucionado',
        TicketWorkflow::EN_PROCESO, TicketWorkflow::LISTO_SIN_VALIDAR => 'proceso',
        TicketWorkflow::RECHAZADO, TicketWorkflow::DESCARTADO => 'danio',
        default => 'espera',
    };
}

/**
 * json_encode seguro para incrustar dentro de un <script> HTML: escapa
 * < > & ' " (como <...) para que un valor con "</script>" no pueda
 * cerrar el bloque (XSS almacenado). Usar en vez de json_encode() en vistas.
 */
function jsJson($valor): string {
    return json_encode($valor, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

/**
 * Plano de sala (mapa tipo «selección de sillas»): reparte los equipos de una sala en
 * frente (equipo docente, videobeam, parlantes) y los puestos de cómputo a cada lado del pasillo.
 *
 * Sin distribución guardada (reparto automático, igual que assets/plano_sala.js): orden natural del
 * código (PC2 antes que PC10), la primera mitad a la izquierda y el resto a la derecha.
 *
 * Con distribución ($distribucion = estructura de DistribucionSala, maquetada en distribucion_sala.php):
 * filas → mesas → puestos tal como están en el salón, cada puesto con su número y punto de red
 * (claves _num y _red en cada equipo), puestos vacíos (['_vacio' => true]), el puesto docente
 * adelante (en 'frente') o atrás ('fondo'), y los equipos que no tienen puesto en 'sinUbicar'.
 *
 * @param array  $equipos      filas con id, código y (opcional) descripción
 * @param string $campoCodigo  columna del código ('codigo' o 'equipo_codigo')
 * @param string $campoDesc    columna de la descripción
 * @param ?array $distribucion estructura guardada de la sala (null = reparto automático)
 * @return array{frente: array, izquierda: array, derecha: array, columnas: int, filas: ?array, fondo: array, sinUbicar: array, todos: array}
 */
function planoSala(array $equipos, string $campoCodigo = 'codigo', string $campoDesc = 'descripcion', ?array $distribucion = null, string $campoId = 'id_equipo'): array
{
    // Código corto a mostrar (_cod): sin el prefijo de la sala, salvo que choque con otro equipo de la lista.
    $cortos = array_count_values(array_map(fn($eq) => mb_strtolower(codigoEnSala((string)($eq[$campoCodigo] ?? ''))), $equipos));
    $frente = []; $puestos = [];
    foreach ($equipos as $eq) {
        $tipo = tipoPuestoSala((string)($eq[$campoCodigo] ?? ''), (string)($eq[$campoDesc] ?? ''));
        $eq['_tipo'] = $tipo;
        $corto = codigoEnSala((string)($eq[$campoCodigo] ?? ''));
        $eq['_cod'] = ($cortos[mb_strtolower($corto)] ?? 0) > 1 ? (string)($eq[$campoCodigo] ?? '') : $corto;
        if ($tipo === 'pc') { $puestos[] = $eq; } else { $frente[] = $eq; }
    }
    $orden = static fn($a, $b) => strnatcasecmp((string)($a[$campoCodigo] ?? ''), (string)($b[$campoCodigo] ?? ''));
    usort($puestos, $orden);
    usort($frente, $orden);

    if ($distribucion === null || empty($distribucion['filas'])) {
        $mitad = (int)ceil(count($puestos) / 2);
        $izq = array_slice($puestos, 0, $mitad);
        $der = array_slice($puestos, $mitad);
        return [
            'frente' => $frente, 'izquierda' => $izq, 'derecha' => $der,
            'columnas' => count($izq) <= 4 ? 2 : 3,
            'filas' => null, 'fondo' => [], 'sinUbicar' => [],
            'todos' => array_merge($frente, $izq, $der),
        ];
    }

    // ── Con distribución: cada equipo en su puesto real
    $porId = [];
    foreach (array_merge($frente, $puestos) as $eq) { $porId[(int)($eq[$campoId] ?? 0)] = $eq; }
    $ubicados = [];
    $tomar = static function (array $p, string $tipoVacio = 'pc') use (&$porId, &$ubicados): array {
        $id = (int)($p['id_equipo'] ?? 0);
        if ($id && isset($porId[$id]) && !isset($ubicados[$id])) {
            $ubicados[$id] = true;
            $eq = $porId[$id];
            if ($eq['_tipo'] === 'pc' && $tipoVacio === 'docente') $eq['_tipo'] = 'docente';
            return $eq + ['_num' => (string)($p['numero'] ?? ''), '_red' => (string)($p['red'] ?? '')];
        }
        return ['_vacio' => true, '_tipo' => $tipoVacio, '_num' => (string)($p['numero'] ?? ''), '_red' => (string)($p['red'] ?? '')];
    };

    $docente = $distribucion['docente'] ?? [];
    $puestoDocente = ($docente['ubicacion'] ?? 'ninguno') !== 'ninguno' ? $tomar($docente, 'docente') : null;

    $filas = []; $izq = []; $der = [];
    foreach ($distribucion['filas'] as $fila) {
        $lados = ['izq' => [], 'der' => []];
        $lado = 'izq';
        foreach ($fila['mesas'] ?? [] as $mesa) {
            $items = array_map(fn($p) => $tomar((array)$p), $mesa['puestos'] ?? []);
            $lados[$lado][] = ['puestos' => $items, 'pasillo' => !empty($mesa['pasillo']) && $lado === 'der'];
            foreach ($items as $it) { if (empty($it['_vacio'])) { if ($lado === 'izq') $izq[] = $it; else $der[] = $it; } }
            if (!empty($mesa['pasillo']) && $lado === 'izq') $lado = 'der';
        }
        $filas[] = $lados;
    }
    // Equipos de la lista que no tienen puesto: docente/videobeam/parlantes al frente (como siempre); PCs aparte.
    $frenteFinal = []; $sinUbicar = [];
    foreach ($frente as $eq) { if (!isset($ubicados[(int)($eq[$campoId] ?? 0)])) $frenteFinal[] = $eq; }
    foreach ($puestos as $eq) { if (!isset($ubicados[(int)($eq[$campoId] ?? 0)])) $sinUbicar[] = $eq; }
    $fondo = [];
    if ($puestoDocente !== null) {
        if (($docente['ubicacion'] ?? '') === 'atras') { $fondo[] = $puestoDocente; }
        elseif (($docente['lado'] ?? 'izq') === 'der') { $frenteFinal[] = $puestoDocente; }
        else { array_unshift($frenteFinal, $puestoDocente); }
    }
    $reales = static fn(array $l) => array_values(array_filter($l, fn($e) => empty($e['_vacio'])));
    return [
        'frente' => $frenteFinal, 'izquierda' => $izq, 'derecha' => $der,
        'columnas' => 2,
        'filas' => $filas, 'fondo' => $fondo, 'sinUbicar' => $sinUbicar,
        'todos' => array_merge($reales($frenteFinal), $izq, $der, $reales($fondo), $sinUbicar),
    ];
}

/**
 * Código a mostrar dentro del plano: sin el prefijo de la sala, que ya es obvio ahí
 * («SA402-PC07» → «PC07»). El código completo va en el title/aria-label del puesto.
 * La misma regla está en assets/plano_sala.js y assets/distribucion_sala.js.
 */
function codigoEnSala(string $codigo): string
{
    return preg_match('/^[A-Za-z]{1,5}\d{1,4}[-_ ]+(\S.*)$/u', $codigo, $m) ? $m[1] : $codigo;
}

/** Número del puesto (si la sala tiene distribución) como insignia del puesto. */
function numPuestoSala(array $eq): string
{
    $n = (string)($eq['_num'] ?? '');
    return $n === '' ? '' : '<span class="sala-puesto-num" title="Puesto n.º ' . h($n) . '">' . h($n) . '</span>';
}

/** Puesto sin equipo (distribución): se dibuja para que el salón se vea completo, con su número. */
function puestoVacioSala(array $p): string
{
    $doc = ($p['_tipo'] ?? '') === 'docente';
    $n = (string)($p['_num'] ?? '');
    return '<span class="sala-puesto sala-puesto-vacio" title="' . ($doc ? 'Puesto docente' : 'Puesto') . ($n !== '' ? ' n.º ' . h($n) : '') . ' sin equipo">'
        . ($n !== '' ? '<span class="sala-puesto-num">' . h($n) . '</span>' : '')
        . '<span class="sala-puesto-cod">' . ($doc ? 'Docente' : 'Vacío') . '</span></span>';
}

/**
 * Buscador estándar del sistema (una sola línea: lupa + campo + «Buscar», y una ✕ para limpiar).
 * Mismo aspecto en todas las pantallas; estilos .buscador* en css/06-componentes-globales.css.
 * Opciones: id, name ('q'), valor, placeholder, etiqueta (texto para lectores de pantalla),
 * limpiar (URL para quitar la búsqueda; solo se muestra con valor), boton ('Buscar'),
 * ocultos ([name => value] que se conservan), autofocus (bool), requerido (bool).
 */
function buscadorHtml(array $o): string
{
    $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    if (!function_exists('iconoSvg') && defined('LAYOUT_DIR')) require_once LAYOUT_DIR . '/iconos.php';
    $id = $o['id'] ?? 'q';
    $valor = (string)($o['valor'] ?? '');
    $html = '<form method="GET" class="buscador" role="search">';
    foreach (($o['ocultos'] ?? []) as $k => $v) {
        $html .= '<input type="hidden" name="' . $e($k) . '" value="' . $e($v) . '">';
    }
    $html .= '<label class="sr-only" for="' . $e($id) . '">' . $e($o['etiqueta'] ?? 'Buscar') . '</label>'
        . '<div class="buscador-campo">'
        . (function_exists('iconoSvg') ? iconoSvg('buscar', 'ico buscador-ico') : '')
        . '<input type="search" id="' . $e($id) . '" name="' . $e($o['name'] ?? 'q') . '" value="' . $e($valor) . '"'
        . ' placeholder="' . $e($o['placeholder'] ?? 'Buscar…') . '" autocomplete="off"' . (!empty($o['autofocus']) ? ' autofocus' : '') . (!empty($o['requerido']) ? ' required' : '') . '>'
        . ($valor !== '' && !empty($o['limpiar']) ? '<a class="buscador-limpiar" href="' . $e($o['limpiar']) . '" aria-label="Quitar búsqueda" title="Quitar búsqueda">&times;</a>' : '')
        . '</div>'
        . '<button type="submit" class="btn btn-sm buscador-btn">' . $e($o['boton'] ?? 'Buscar') . '</button>'
        . '</form>';
    return $html;
}

/**
 * Valor interno (enum de la base de datos) en texto legible: «EN_REVISION» → «En revisión»,
 * «todo_en_uno» → «Todo en uno». Los que no están en la lista se convierten quitando «_» y
 * dejando solo la primera letra en mayúscula.
 */
function etiquetaValor(?string $valor): string
{
    static $mapa = [
        'activo' => 'Activo', 'en_revision' => 'En revisión', 'defectuoso' => 'Defectuoso', 'dado_de_baja' => 'Dado de baja',
        'programado' => 'Programado', 'en_proceso' => 'En proceso', 'realizado' => 'Realizado', 'cancelado' => 'Cancelado',
        'pendiente' => 'Pendiente', 'tomado' => 'Tomado', 'incompleto' => 'Incompleto', 'excluido_traslado' => 'Trasladado',
        'enviado' => 'Enviado', 'error' => 'Con error', 'aprobada' => 'Aprobada', 'rechazada' => 'Rechazada', 'pendiente_aprobacion' => 'Pendiente de aprobación',
        'todo_en_uno' => 'Todo en uno', 'portatil' => 'Portátil', 'memoria_ram' => 'Memoria RAM', 'ram' => 'Memoria RAM', 'disco_duro' => 'Disco duro',
        'fuente_poder' => 'Fuente de poder', 'tarjeta_red' => 'Tarjeta de red', 'tarjeta_video' => 'Tarjeta de video', 'board' => 'Board', 'cpu' => 'CPU',
        'aire_acondicionado' => 'Aire acondicionado', 'iluminacion' => 'Iluminación', 'toma_electrica' => 'Tomas eléctricas',
        'otro_infraestructura' => 'Otra infraestructura', 'videobeam' => 'Videobeam', 'parlante' => 'Parlantes',
        'mantenimiento_preventivo' => 'Mantenimiento preventivo', 'mantenimiento_correctivo' => 'Mantenimiento correctivo',
        'instalacion_software' => 'Instalación de software', 'dependencia' => 'Dependencia', 'departamento' => 'Departamento',
        'plan_estudio' => 'Plan de estudio', 'plan_de_estudio' => 'Plan de estudio', 'facultad' => 'Facultad',
    ];
    $v = trim((string)$valor);
    if ($v === '') return '';
    $k = mb_strtolower($v);
    if (isset($mapa[$k])) return $mapa[$k];
    $t = mb_strtolower(str_replace('_', ' ', $v));
    return mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
}

/** Tipo de puesto según código/descripción: 'docente', 'videobeam', 'parlante' o 'pc'. */
function tipoPuestoSala(string $codigo, string $descripcion = ''): string
{
    $t = mb_strtolower($codigo . ' ' . $descripcion);
    if (preg_match('/video\s*beam|proyector|\bvb\d*\b/u', $t)) return 'videobeam';
    if (preg_match('/parlante|sonido|audio|bafle|altavoz/u', $t)) return 'parlante';
    if (preg_match('/docente|profesor|maestro/u', $t)) return 'docente';
    return 'pc';
}

/**
 * Ventana flotante (modal) para formularios: la página muestra su contenido principal (listas,
 * planos, calendarios) y los formularios se abren encima, sin empujar la vista hacia abajo.
 * Uso:  <?= vfAbrir('vf-nueva-sala', 'Nueva sala', 'Descripción opcional', $hayError) ?> …formulario… <?= vfCerrar() ?>
 * y un botón que la abre: <button type="button" class="btn" data-vf-abrir="vf-nueva-sala">Nueva sala</button>
 * $abierta = true la deja abierta al cargar (p. ej. el formulario volvió con un error de validación).
 * $tamano: 'md' (560px) o 'lg' (820px). En el celular ocupa toda la pantalla. Lógica en assets/shell.js.
 */
function vfAbrir(string $id, string $titulo, string $descripcion = '', bool $abierta = false, string $tamano = 'md'): string
{
    $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    return '<div class="vf" id="' . $e($id) . '" hidden role="dialog" aria-modal="true" aria-labelledby="' . $e($id) . '-t"'
        . ($abierta ? ' data-vf-abierta' : '') . '>'
        . '<div class="vf-caja ' . ($tamano === 'lg' ? 'vf-lg' : 'vf-md') . '">'
        . '<header class="vf-head"><div class="vf-titulos"><h2 class="vf-titulo" id="' . $e($id) . '-t">' . $e($titulo) . '</h2>'
        . ($descripcion !== '' ? '<p class="vf-sub">' . $e($descripcion) . '</p>' : '') . '</div>'
        . '<button type="button" class="vf-cerrar" data-vf-cerrar aria-label="Cerrar">&times;</button></header>'
        . '<div class="vf-cuerpo">';
}

function vfCerrar(): string
{
    return '</div></div></div>';
}

/**
 * Ruta de un archivo de public/ con ?v=<fecha de modificación>. Con esa versión en la URL el navegador lo guarda
 * en caché un año (public/assets/.htaccess) y baja el nuevo apenas cambia.
 */
function asset(string $ruta): string
{
    $archivo = dirname(__DIR__, 2) . '/public/' . $ruta;
    return $ruta . (is_file($archivo) ? '?v=' . filemtime($archivo) : '');
}

/** Hoja de estilos de la página: app.css (todo en un archivo) o, si quedó desactualizada, estilo.css con sus @import. */
function hojaDeEstilos(): string
{
    static $ruta = null;
    if ($ruta === null) {
        $dir = dirname(__DIR__, 2) . '/public/assets';
        $partes = glob("$dir/{estilo.css,tokens.css,css/*.css}", GLOB_BRACE) ?: [];
        $alDia = is_file("$dir/app.css") && filemtime("$dir/app.css") >= max(array_map('filemtime', $partes ?: [__FILE__]));
        $ruta = asset($alDia ? 'assets/app.css' : 'assets/estilo.css');
    }
    return $ruta;
}
