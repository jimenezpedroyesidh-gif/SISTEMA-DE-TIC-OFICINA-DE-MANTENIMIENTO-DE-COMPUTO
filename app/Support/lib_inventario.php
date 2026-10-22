<?php
/**
 * Catálogo de marcas/modelos e infraestructura por sala (ficha de equipo y componentes).
 * Carga también inventario.php (búsqueda de componentes).
 */
require_once __DIR__ . '/inventario.php';

/**
 * Catálogo de marcas para el <select> de fabricante.
 */
function listarFabricantes(PDO $pdo): array {
    return $pdo->query("SELECT id_fabricante, nombre FROM fabricantes ORDER BY nombre")
               ->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Resuelve la marca elegida en un POST del formulario de componente/infra.
 * Si el <select name="id_fabricante"> viene en "__nueva__", crea (o
 * reutiliza si ya existía por su nombre) la fila en `fabricantes` a partir
 * de $_POST['marca_nueva']. Devuelve [id_fabricante, nombre] o [null, null]
 * si no se seleccionó ninguna marca.
 */
function resolverFabricanteDesdePost(PDO $pdo, array $post): array {
    $idFabricante = $post['id_fabricante'] ?? '';

    if ($idFabricante === '__nueva__') {
        $nombre = trim($post['marca_nueva'] ?? '');
        if ($nombre === '') {
            return [null, null];
        }
        $stmt = $pdo->prepare("SELECT id_fabricante FROM fabricantes WHERE nombre = ?");
        $stmt->execute([$nombre]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            $stmt = $pdo->prepare("INSERT INTO fabricantes (nombre) VALUES (?)");
            $stmt->execute([$nombre]);
            $id = (int)$pdo->lastInsertId();
        }
        return [(int)$id, $nombre];
    }

    if ($idFabricante === '') {
        return [null, null];
    }

    $idFabricante = (int)$idFabricante;
    $stmt = $pdo->prepare("SELECT nombre FROM fabricantes WHERE id_fabricante = ?");
    $stmt->execute([$idFabricante]);
    $nombre = $stmt->fetchColumn();
    return $nombre !== false ? [$idFabricante, $nombre] : [null, null];
}

/**
 * Igual que resolverFabricanteDesdePost() pero para `modelos_activo`.
 * El <select name="id_modelo"> se llena por AJAX en cascada según la marca
 * (ver listar_modelos_ajax.php / assets/catalogo_marca_modelo.js), e incluye
 * una opción "__nuevo__" para dar de alta un modelo nuevo a partir de
 * $_POST['modelo_nuevo'], asociado a $idFabricante.
 */
function resolverModeloDesdePost(PDO $pdo, array $post, ?int $idFabricante): array {
    $idModelo = $post['id_modelo'] ?? '';

    if ($idModelo === '__nuevo__') {
        $nombre = trim($post['modelo_nuevo'] ?? '');
        if ($nombre === '') {
            return [null, null];
        }
        $stmt = $pdo->prepare(
            "SELECT id_modelo FROM modelos_activo
             WHERE nombre = ? AND ((? IS NULL AND id_fabricante IS NULL) OR id_fabricante = ?)"
        );
        $stmt->execute([$nombre, $idFabricante, $idFabricante]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            $stmt = $pdo->prepare("INSERT INTO modelos_activo (id_fabricante, nombre) VALUES (?, ?)");
            $stmt->execute([$idFabricante, $nombre]);
            $id = (int)$pdo->lastInsertId();
        }
        return [(int)$id, $nombre];
    }

    if ($idModelo === '') {
        return [null, null];
    }

    $idModelo = (int)$idModelo;
    $stmt = $pdo->prepare("SELECT nombre FROM modelos_activo WHERE id_modelo = ?");
    $stmt->execute([$idModelo]);
    $nombre = $stmt->fetchColumn();
    return $nombre !== false ? [$idModelo, $nombre] : [null, null];
}

/**
 * Devuelve las columnas que usa la plantilla de ficha_equipo.php al
 * listar infraestructura por sala: id_componente, estado, tipo,
 * codigo_inventario, marca, modelo.
 */
function listarInfraestructuraPorSala(PDO $pdo, int $idSala): array {
    $stmt = $pdo->prepare(
        "SELECT id_componente, estado, tipo, codigo_inventario, marca, modelo
         FROM componentes
         WHERE id_sala_directa = ?
         ORDER BY tipo, codigo_inventario"
    );
    $stmt->execute([$idSala]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
