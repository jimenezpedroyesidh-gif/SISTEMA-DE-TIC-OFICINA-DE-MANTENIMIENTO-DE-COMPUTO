<?php
/** Controlador de public/listar_equipos_ajax.php: cualquier sesión; ?id_sala= → equipos de la sala (+ ?plano=1: estado de cada uno; + ?distribucion=1: {equipos, distribucion}). Ámbito global. */

header('Content-Type: application/json; charset=utf-8');
requireAuthJson(null, []);

$idSala = (int)($_GET['id_sala'] ?? 0);
$equipos = $idSala ? ListadoAjaxRepository::equiposDeSala($pdo, $idSala) : [];
// ?plano=1 (plano de sala): agrega a cada equipo su estado real (operativo, piezas, traslados con quién aprobó).
if ($equipos && !empty($_GET['plano'])) {
    $estados = PlanoSalaService::estadoEquipos($pdo, array_column($equipos, 'id_equipo'), true);
    foreach ($equipos as &$eq) { $eq['estado'] = $estados[(int)$eq['id_equipo']] ?? null; }
    unset($eq);
}
// ?plano=1 también trae el videobeam/parlantes de la sala (componentes frente al tablero, marcables como afectados).
if ($idSala && !empty($_GET['plano'])) {
    foreach (PlanoSalaService::frentePorSalas($pdo, [$idSala], true)[$idSala] ?? [] as $c) {
        $equipos[] = ['id_componente' => $c['id_componente'], 'tipo' => $c['tipo'], 'codigo' => $c['nombre'], 'descripcion' => $c['codigo'], 'estado' => $c['estado']];
    }
}
// ?distribucion=1: respuesta {equipos, distribucion} con la maquetación guardada del salón (null si no hay).
if (!empty($_GET['distribucion'])) {
    echo json_encode(['equipos' => $equipos, 'distribucion' => $idSala ? DistribucionSalaRepository::deSala($pdo, $idSala) : null]);
    return;
}
echo json_encode($equipos);
