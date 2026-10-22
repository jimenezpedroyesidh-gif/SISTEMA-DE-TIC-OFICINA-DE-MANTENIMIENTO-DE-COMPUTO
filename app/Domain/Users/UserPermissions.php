<?php
/**
 * UserPermissions — permisos puntuales por usuario que el admin activa o
 * desactiva con un clic desde Usuarios. Se guardan como columnas TINYINT en
 * `users`. Si falta la columna en la base, se degrada a "sin permiso" en vez
 * de romper la página.
 *
 * Cada permiso dice a qué roles se le puede dar (lista blanca): el admin no
 * puede usar esto para darle a alguien un permiso que no aplica a su rol, y
 * nadie puede escalar a admin por aquí.
 *
 *   permiso_horarios_extra        → (secretaria) ver los horarios de todas las salas y crear/editar/borrar
 *   permiso_ver_tickets_sistemas  → (secretaria) ver todos los tickets del Departamento de Sistemas
 *   permiso_ver_horas_becas       → (cualquier rol menos admin) ver las horas de TODAS las becas trabajo
 *                                   en «Seguimiento de horas». El admin ya las ve siempre.
 *   permiso_ver_tickets_externos  → (técnico) ver y atender tickets de salas/dependencias FUERA del
 *                                   Departamento de Sistemas e Informática. Apagado por defecto: el equipo
 *                                   trabaja solo con el Departamento. El admin siempre los ve (supervisa).
 */


final class UserPermissions
{
    public const HORARIOS = 'permiso_horarios_extra';
    public const VER_TICKETS_SISTEMAS = 'permiso_ver_tickets_sistemas';
    public const VER_HORAS_BECAS = 'permiso_ver_horas_becas';
    public const VER_TICKETS_EXTERNOS = 'permiso_ver_tickets_externos';

    /** permiso => [etiqueta, roles a los que el admin se lo puede dar] */
    public const PERMISOS = [
        self::HORARIOS => [
            'etiqueta' => 'Ver y editar horarios de salas',
            'roles'    => [Rol::SECRETARIA],
        ],
        self::VER_TICKETS_SISTEMAS => [
            'etiqueta' => 'Ver todos los tickets de Sistemas',
            'roles'    => [Rol::SECRETARIA],
        ],
        self::VER_HORAS_BECAS => [
            'etiqueta' => 'Ver horas de todas las becas',
            'roles'    => [Rol::TECNICO, Rol::SECRETARIA, Rol::PROFESOR, Rol::SOLICITANTE, Rol::BECA],
        ],
        self::VER_TICKETS_EXTERNOS => [
            'etiqueta' => 'Ver y atender tickets de otras dependencias',
            'roles'    => [Rol::TECNICO],
        ],
    ];

    /** Permisos que se le pueden dar a un rol: permiso => etiqueta. */
    public static function aplicablesA(string $rol): array
    {
        $out = [];
        foreach (self::PERMISOS as $permiso => $def) {
            if (in_array($rol, $def['roles'], true)) {
                $out[$permiso] = $def['etiqueta'];
            }
        }
        return $out;
    }

    public static function tiene(PDO $pdo, int $idUsuario, string $permiso): bool
    {
        if (!isset(self::PERMISOS[$permiso])) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("SELECT role, `$permiso` FROM users WHERE id = ?");
            $stmt->execute([$idUsuario]);
            $f = $stmt->fetch(PDO::FETCH_NUM);
        } catch (PDOException $e) {
            return false; // falta la columna en la base
        }
        // Si el usuario cambió a un rol al que no aplica, el valor guardado no cuenta.
        return $f !== false && in_array($f[0], self::PERMISOS[$permiso]['roles'], true) && (int)$f[1] === 1;
    }

    /**
     * Valores de los permisos de una lista de usuarios, en pocas consultas.
     * Solo trae los permisos que aplican al rol de cada usuario.
     * @return array<int,array<string,int>> id => [permiso => 0|1]
     */
    public static function de(PDO $pdo, array $idsUsuario): array
    {
        $ids = array_values(array_unique(array_map('intval', $idsUsuario)));
        if ($ids === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id, role FROM users WHERE id IN ($marcas)");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $out[(int)$u['id']] = array_fill_keys(array_keys(self::aplicablesA((string)$u['role'])), 0);
        }
        foreach (array_keys(self::PERMISOS) as $permiso) {
            try {
                $st = $pdo->prepare("SELECT id, `$permiso` AS v FROM users WHERE id IN ($marcas)");
                $st->execute($ids);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
                    if (isset($out[(int)$f['id']]) && array_key_exists($permiso, $out[(int)$f['id']])) {
                        $out[(int)$f['id']][$permiso] = (int)$f['v'];
                    }
                }
            } catch (PDOException $e) {
                // columna ausente: queda en 0
            }
        }
        return $out;
    }

    /**
     * Invierte un permiso de un usuario (activado ⇄ desactivado). Solo aplica a
     * permisos de la lista blanca y a usuarios cuyo rol admite ese permiso.
     *
     * @return array{ok:bool,valor:?int,mensaje:string}
     */
    public static function alternar(PDO $pdo, int $idAdmin, int $idUsuario, string $permiso): array
    {
        if (!isset(self::PERMISOS[$permiso])) {
            return ['ok' => false, 'valor' => null, 'mensaje' => 'Permiso no válido.'];
        }
        $def = self::PERMISOS[$permiso];
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$idUsuario]);
        $rol = $stmt->fetchColumn();
        if ($rol === false || !in_array($rol, $def['roles'], true)) {
            return ['ok' => false, 'valor' => null, 'mensaje' => 'Ese usuario no existe o su rol no admite el permiso «' . $def['etiqueta'] . '».'];
        }
        try {
            $pdo->prepare("UPDATE users SET `$permiso` = 1 - `$permiso` WHERE id = ?")->execute([$idUsuario]);
        } catch (PDOException $e) {
            return ['ok' => false, 'valor' => null, 'mensaje' => 'Falta la columna del permiso en la base: vuelve a importar database/tickets_db.sql.'];
        }
        $valor = self::tiene($pdo, $idUsuario, $permiso) ? 1 : 0;
        AuditoriaService::registrar($pdo, $idAdmin, 'users', $idUsuario, 'EDITAR',
            $def['etiqueta'] . ($valor ? ': activado' : ': desactivado'));
        return ['ok' => true, 'valor' => $valor,
                'mensaje' => $def['etiqueta'] . ($valor ? ' activado.' : ' desactivado.')];
    }
}
