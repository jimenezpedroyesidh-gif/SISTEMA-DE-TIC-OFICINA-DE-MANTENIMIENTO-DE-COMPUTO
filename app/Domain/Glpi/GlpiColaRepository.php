<?php
/** Persistencia de la cola de envíos a GLPI (`glpi_cola`) y de los vínculos componente/sala ↔ GLPI. */
final class GlpiColaRepository
{
    public static function insertar(PDO $pdo, string $tipo, int $idComponente, ?int $idSalaDestino, ?int $idMovimiento, ?int $idUsuario): int
    {
        $pdo->prepare("INSERT INTO glpi_cola (tipo, id_componente, id_sala_destino, id_movimiento, id_usuario) VALUES (?, ?, ?, ?, ?)")
            ->execute([$tipo, $idComponente, $idSalaDestino, $idMovimiento, $idUsuario]);
        return (int)$pdo->lastInsertId();
    }

    /** Pendientes (y con error si $conErrores), más antiguos primero: así un alta va antes que su movimiento. */
    public static function porProcesar(PDO $pdo, bool $conErrores, int $limite): array
    {
        $estados = $conErrores ? "'PENDIENTE','ERROR'" : "'PENDIENTE'";
        return $pdo->query("SELECT * FROM glpi_cola WHERE estado IN ($estados) ORDER BY id LIMIT " . (int)$limite)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function marcarEnviado(PDO $pdo, int $id, ?string $itemtype, ?int $glpiId, ?string $nota = null): void
    {
        $pdo->prepare("UPDATE glpi_cola SET estado='ENVIADO', intentos=intentos+1, enviado_en=NOW(), glpi_itemtype=?, glpi_id=?, ultimo_error=? WHERE id=?")
            ->execute([$itemtype, $glpiId, $nota, $id]);
    }

    public static function marcarError(PDO $pdo, int $id, string $error): void
    {
        $pdo->prepare("UPDATE glpi_cola SET estado='ERROR', intentos=intentos+1, ultimo_error=? WHERE id=?")
            ->execute([mb_substr($error, 0, 255), $id]);
    }

    /** Últimos movimientos de la cola con datos del componente, para la vista del admin. */
    public static function ultimos(PDO $pdo, int $limite = 50): array
    {
        try {
            return $pdo->query("SELECT q.*, c.codigo_inventario, c.serial, c.tipo AS tipo_componente, s.nombre_sala AS sala_destino
                                FROM glpi_cola q
                                JOIN componentes c ON c.id_componente = q.id_componente
                                LEFT JOIN salas s ON s.id_sala = q.id_sala_destino
                                ORDER BY q.id DESC LIMIT " . (int)$limite)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    /** Conteo por estado: ['PENDIENTE'=>n, 'ENVIADO'=>n, 'ERROR'=>n]. */
    public static function conteo(PDO $pdo): array
    {
        $out = ['PENDIENTE' => 0, 'ENVIADO' => 0, 'ERROR' => 0];
        try {
            foreach ($pdo->query("SELECT estado, COUNT(*) n FROM glpi_cola GROUP BY estado")->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $out[$f['estado']] = (int)$f['n'];
            }
        } catch (PDOException $e) {
        }
        return $out;
    }

    /** Componente con la sala donde está (por su equipo o directa) y su vínculo GLPI. */
    public static function componente(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare("SELECT c.*, COALESCE(e.id_sala, c.id_sala_directa) AS id_sala_actual
                             FROM componentes c LEFT JOIN equipos e ON e.id_equipo = c.id_equipo_actual
                             WHERE c.id_componente = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function componentesDeEquipo(PDO $pdo, int $idEquipo): array
    {
        $st = $pdo->prepare("SELECT id_componente, tipo FROM componentes WHERE id_equipo_actual = ?");
        $st->execute([$idEquipo]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function tipoComponente(PDO $pdo, int $id): ?string
    {
        $st = $pdo->prepare("SELECT tipo FROM componentes WHERE id_componente = ?");
        $st->execute([$id]);
        $t = $st->fetchColumn();
        return $t === false ? null : (string)$t;
    }

    public static function vincularComponente(PDO $pdo, int $id, string $itemtype, int $glpiId): void
    {
        $pdo->prepare("UPDATE componentes SET glpi_itemtype = ?, glpi_id = ? WHERE id_componente = ?")->execute([$itemtype, $glpiId, $id]);
    }

    /** Componentes sin vínculo con GLPI: id_componente, serial. */
    public static function sinVinculo(PDO $pdo): array
    {
        return $pdo->query("SELECT id_componente, serial FROM componentes WHERE glpi_id IS NULL AND serial <> ''")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** serial => id_componente de todos los componentes locales (para marcar «ya está en el sistema»). */
    public static function serialesLocales(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query("SELECT id_componente, serial FROM componentes")->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $out[strtoupper(trim((string)$f['serial']))] = (int)$f['id_componente'];
        }
        return $out;
    }

    public static function salas(PDO $pdo): array
    {
        try {
            return $pdo->query("SELECT id_sala, nombre_sala, dependencia, glpi_location_id FROM salas ORDER BY nombre_sala")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    public static function ubicacionGlpiDeSala(PDO $pdo, int $idSala): array
    {
        $st = $pdo->prepare("SELECT nombre_sala, glpi_location_id FROM salas WHERE id_sala = ?");
        $st->execute([$idSala]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: ['nombre_sala' => "#$idSala", 'glpi_location_id' => null];
    }

    public static function vincularSala(PDO $pdo, int $idSala, ?int $idUbicacion): void
    {
        $pdo->prepare("UPDATE salas SET glpi_location_id = ? WHERE id_sala = ?")->execute([$idUbicacion, $idSala]);
    }
}
