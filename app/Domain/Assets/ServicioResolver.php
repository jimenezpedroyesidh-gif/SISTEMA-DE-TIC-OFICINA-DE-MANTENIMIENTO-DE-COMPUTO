<?php
/**
 * ServicioResolver
 * ================
 * Resuelve "el equipo o la sala tiene su propia dependencia dueña" y
 * hace el servicio responsable configurable: el sistema lo resuelve
 * solo, mirando la configuración del activo, en vez de tener el
 * servicio fijo a "Sistemas".
 *
 * Esto se apoya en las tablas unidades_organizacionales / salas_unidades
 * / users.id_unidad / unidades_organizacionales.es_administradora_sistema,
 * pensadas para usarse "cuando gestionar_salas.php, register.php y
 * ficha_equipo.php se migren a usar id_unidad". Ningún archivo PHP las
 * consulta todavía fuera de esta clase.
 *
 * Esta clase es SOLO LECTURA: no modifica gestionar_salas.php, ni
 * ficha_equipo.php, ni ningún flujo de escritura existente. Hoy, con
 * una sola unidad marcada `es_administradora_sistema=1` (Sistemas),
 * siempre va a devolver esa misma unidad — el comportamiento observable
 * no cambia en nada. El valor real aparece el día que se registre una
 * segunda unidad administradora (ej. Redes) y se enlacen salas/equipos
 * a ella vía `salas_unidades`: en ese momento, código que use este
 * resolver empieza a enrutar distinto sin que haya que tocarlo.
 */

final class ServicioResolver
{
    /**
     * Dependencia(s) dueña(s) de una sala: las unidades organizacionales
     * enlazadas a ella en `salas_unidades` (puede ser más de una, ej. un
     * Plan de Estudios que la usa para clases + Sistemas que la
     * administra).
     *
     * @return array<int, array{id_unidad:int, nombre:string, tipo:string, es_administradora_sistema:bool}>
     */
    public static function dependenciasDeSala(PDO $pdo, int $idSala): array
    {
        $stmt = $pdo->prepare(
            "SELECT u.id_unidad, u.nombre, u.tipo, u.es_administradora_sistema
             FROM salas_unidades su
             JOIN unidades_organizacionales u ON u.id_unidad = su.id_unidad
             WHERE su.id_sala = ?"
        );
        $stmt->execute([$idSala]);
        return array_map(
            fn($f) => [
                'id_unidad' => (int)$f['id_unidad'],
                'nombre' => (string)$f['nombre'],
                'tipo' => (string)$f['tipo'],
                'es_administradora_sistema' => (bool)$f['es_administradora_sistema'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * Dependencia dueña de un equipo puntual: se resuelve vía la sala en
     * la que vive el equipo (`equipos.id_sala`), no vía el usuario que
     * reporta. Este es exactamente el caso que menciona el documento:
     * "un PC de Control Interno" — la persona que escanea el QR no elige
     * a quién avisar, el sistema lo deduce del equipo.
     */
    public static function dependenciasDeEquipo(PDO $pdo, int $idEquipo): array
    {
        $stmt = $pdo->prepare("SELECT id_sala FROM equipos WHERE id_equipo = ?");
        $stmt->execute([$idEquipo]);
        $idSala = $stmt->fetchColumn();
        if (!$idSala) {
            return [];
        }
        return self::dependenciasDeSala($pdo, (int)$idSala);
    }

    /**
     * El/los servicio(s) responsable(s) de resolver un ticket sobre esta
     * sala o equipo: la(s) unidad(es) marcada(s)
     * `es_administradora_sistema = 1` entre las dependencias
     * encontradas. Si ninguna dependencia enlazada es administradora
     * (caso raro hoy, pero posible si una sala solo se enlazó a un Plan
     * de Estudios), cae de vuelta a preguntar por LA unidad
     * administradora global — así nunca se queda un reporte sin
     * servicio asignado.
     *
     * @return array{id_unidad:int, nombre:string}|null
     */
    public static function servicioResponsable(PDO $pdo, ?int $idSala, ?int $idEquipo): ?array
    {
        $dependencias = [];
        if ($idEquipo) {
            $dependencias = self::dependenciasDeEquipo($pdo, $idEquipo);
        } elseif ($idSala) {
            $dependencias = self::dependenciasDeSala($pdo, $idSala);
        }

        foreach ($dependencias as $dep) {
            if ($dep['es_administradora_sistema']) {
                return ['id_unidad' => $dep['id_unidad'], 'nombre' => $dep['nombre']];
            }
        }

        // Nadie enlazado es administrador de sistema todavía: fallback a
        // la única unidad administradora conocida (hoy: Sistemas).
        $stmt = $pdo->query(
            "SELECT id_unidad, nombre FROM unidades_organizacionales
             WHERE es_administradora_sistema = 1 LIMIT 1"
        );
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        return $fila ? ['id_unidad' => (int)$fila['id_unidad'], 'nombre' => (string)$fila['nombre']] : null;
    }
}
