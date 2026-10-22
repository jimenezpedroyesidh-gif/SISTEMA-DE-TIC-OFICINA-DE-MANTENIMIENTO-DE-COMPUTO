<?php
/** Controlador de public/buscar_componente_ajax.php: admin/tecnico/beca_trabajo; params q, disponibles, sala, id. Ámbito global. */

header('Content-Type: application/json; charset=utf-8');
requireAuthJson([Rol::ADMIN, Rol::TECNICO, Rol::BECA]); // mismo control de acceso que el módulo de inventario

$q = validarTexto($_GET['q'] ?? '', 100) ?? '';
$soloDisponibles = ($_GET['disponibles'] ?? '') === '1';
$idSalaContexto = isset($_GET['sala']) && $_GET['sala'] !== '' ? (int)$_GET['sala'] : null;
$idBuscado = isset($_GET['id']) && $_GET['id'] !== '' ? (int)$_GET['id'] : null;

echo json_encode(ComponenteBusquedaService::buscar($pdo, $q, $soloDisponibles, $idSalaContexto, $idBuscado));
