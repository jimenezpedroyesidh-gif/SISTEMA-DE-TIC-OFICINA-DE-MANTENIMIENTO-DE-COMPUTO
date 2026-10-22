<?php
/** Controlador de public/listar_modelos_ajax.php: admin/tecnico; ?id_fabricante= → modelos del fabricante. Ámbito global. */

header('Content-Type: application/json; charset=utf-8');
requireAuthJson([Rol::ADMIN, Rol::TECNICO], []); // mismo control de acceso que el módulo de inventario

$idFabricante = (int)($_GET['id_fabricante'] ?? 0);
echo json_encode($idFabricante ? ListadoAjaxRepository::modelosDeFabricante($pdo, $idFabricante) : []);
