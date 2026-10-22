<?php
/**
 * Include reutilizable: dos campos de búsqueda grandes (saliente/entrante)
 * pensados para usarse desde el celular. Se incluye dentro de un <form>
 * ya existente; NO abre su propio <form>.
 *
 * Variable opcional $prefix (string) para generar IDs únicos cuando este
 * widget se repite varias veces en una misma página (p. ej. una fila por
 * ticket en tickets.php). Si no se define, queda vacío.
 *
 * Variable opcional $requerido (bool, default true): si es false, los
 * campos ocultos no llevan `required` — para los casos donde la propuesta
 * de cambio es opcional (p. ej. el técnico resolviendo un ticket normal).
 *
 * Variable opcional $soloSaliente (bool, default false): oculta el campo
 * "entrante" — para el traslado a bodega/oficina de mantenimiento, donde
 * solo interesa registrar qué pieza se retira (nada la reemplaza todavía;
 * eso se resuelve después con un ticket de "Cambio de pieza puntual" que
 * la traiga de vuelta ya reparada o con un repuesto).
 *
 * Variable opcional $idSalaContexto (int|null): cuando la página YA sabe
 * a qué sala pertenece la búsqueda (una fila de ticket, un equipo de un
 * mantenimiento programado), pásala aquí para que solo aparezcan
 * componentes de ESA sala/oficina o sueltos en bodega — no el inventario
 * completo de otras salas.
 *
 * Variable opcional $salaSelectorId (string|null): alternativa a
 * $idSalaContexto para formularios donde la sala se elige con un <select>
 * que puede cambiar antes de buscar (ej. create_ticket_beca.php). Se le
 * pasa el id del <select> y el widget lee su valor actual en cada
 * búsqueda. Si ninguno de los dos se define, la búsqueda no filtra por
 * sala (comportamiento de siempre).
 */
$prefix = $prefix ?? '';
$requerido = $requerido ?? true;                        // ¿Es obligatorio elegir el componente SALIENTE?
$requeridoEntrante = $requeridoEntrante ?? $requerido;   // ¿Y el ENTRANTE?
$soloSaliente = $soloSaliente ?? false;
$nombreSaliente = $nombreSaliente ?? 'id_componente_saliente';
$nombreEntrante = $nombreEntrante ?? 'id_componente_entrante';
$attrRequerido = $requerido ? 'required' : '';
$attrRequeridoEntrante = $requeridoEntrante ? 'required' : '';
$idSalaContexto = $idSalaContexto ?? null;
$salaSelectorId = $salaSelectorId ?? null;
?>
<div class="field" data-sala-fija="<?= $idSalaContexto !== null ? (int)$idSalaContexto : '' ?>" data-sala-selector="<?= $salaSelectorId ? htmlspecialchars($salaSelectorId, ENT_QUOTES, 'UTF-8') : '' ?>">
  <label for="<?= $prefix ?>buscar_saliente">Componente que se retira (saliente)</label>
  <div class="comp-search-row">
    <div class="buscador-campo">
    <?= function_exists('iconoSvg') ? iconoSvg('buscar', 'ico buscador-ico') : '' ?>
    <input type="search" id="<?= $prefix ?>buscar_saliente" placeholder="Código de inventario o serial (ej: INV-0231)" autocomplete="off"
           data-comp-buscar data-comp-prefix="<?= htmlspecialchars((string)$prefix, ENT_QUOTES, "UTF-8") ?>" data-comp-campo="saliente" data-comp-solo-disp="0">
    </div>
    <button type="button" class="btn btn-ghost btn-sm comp-qr-btn" data-comp-leer data-comp-prefix="<?= htmlspecialchars((string)$prefix, ENT_QUOTES, "UTF-8") ?>" data-comp-campo="saliente" data-comp-solo-disp="0">Escanear</button>
  </div>
  <div id="<?= $prefix ?>resultados_saliente" class="comp-search-results"></div>
  <input type="hidden" id="<?= $prefix ?>id_componente_saliente" name="<?= $nombreSaliente ?>" <?= $attrRequerido ?>>
  <p class="hint" id="<?= $prefix ?>seleccionado_saliente"></p>
</div>

<?php if (!$soloSaliente): ?>
<div class="field" data-sala-fija="<?= $idSalaContexto !== null ? (int)$idSalaContexto : '' ?>" data-sala-selector="<?= $salaSelectorId ? htmlspecialchars($salaSelectorId, ENT_QUOTES, 'UTF-8') : '' ?>">
  <label for="<?= $prefix ?>buscar_entrante">Componente de reemplazo (entrante, disponible en bodega)</label>
  <div class="comp-search-row">
    <div class="buscador-campo">
    <?= function_exists('iconoSvg') ? iconoSvg('buscar', 'ico buscador-ico') : '' ?>
    <input type="search" id="<?= $prefix ?>buscar_entrante" placeholder="Código de inventario o serial (ej: INV-0450)" autocomplete="off"
           data-comp-buscar data-comp-prefix="<?= htmlspecialchars((string)$prefix, ENT_QUOTES, "UTF-8") ?>" data-comp-campo="entrante" data-comp-solo-disp="1">
    </div>
    <button type="button" class="btn btn-ghost btn-sm comp-qr-btn" data-comp-leer data-comp-prefix="<?= htmlspecialchars((string)$prefix, ENT_QUOTES, "UTF-8") ?>" data-comp-campo="entrante" data-comp-solo-disp="1">Escanear</button>
  </div>
  <div id="<?= $prefix ?>resultados_entrante" class="comp-search-results"></div>
  <input type="hidden" id="<?= $prefix ?>id_componente_entrante" name="<?= $nombreEntrante ?>" <?= $attrRequeridoEntrante ?>>
  <p class="hint" id="<?= $prefix ?>seleccionado_entrante"></p>
</div>
<?php endif; ?>

<?php /* Modal compartido del lector de cámara (uno solo para toda la página,
     sin importar cuántas veces se repita este widget). */ ?>
<?php
// Evita que el modal se repita cuando el widget se incluye varias veces
// en la misma página (una fila por ticket/equipo). define() sí persiste
// entre includes repetidos dentro del mismo request (a diferencia de una
// variable normal, que se reinicia cada vez que se vuelve a incluir).
if (!defined('COMP_QR_MODAL_IMPRESO')):
    define('COMP_QR_MODAL_IMPRESO', true);
?>
<div id="compQrModal" class="comp-qr-modal" hidden>
  <div class="comp-qr-modal-box">
    <div class="comp-qr-modal-head">
      <strong>Leer código de inventario</strong>
      <button type="button" class="btn btn-ghost btn-sm" data-comp-cerrar>✕</button>
    </div>
    <div id="compQrReader"></div>
    <p class="hint" id="compQrEstado">Apunta la cámara al código pegado en el componente.</p>
  </div>
</div>

<script src="<?= h(asset('assets/componentes_widget.js')) ?>"></script>
<?php endif; ?>
<?php unset($soloSaliente, $nombreSaliente, $nombreEntrante, $requeridoEntrante); /* que no se hereden a la próxima inclusión del widget en la misma página */ ?>
