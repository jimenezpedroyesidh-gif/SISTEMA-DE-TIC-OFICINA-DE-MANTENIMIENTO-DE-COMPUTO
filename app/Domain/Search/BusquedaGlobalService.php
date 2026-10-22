<?php
/**
 * Buscador global: decide qué puede encontrar cada rol y arma la respuesta JSON de public/buscar_global.php.
 *  - admin/tecnico/secretaria: tickets y usuarios de todos.
 *  - beca_trabajo: solo tickets que le corresponden (TicketConsultas); sin usuarios.
 *  - profesor: sus tickets de profesor y los normales que creó. solicitante: solo sus tickets normales.
 *  - componentes: admin/tecnico/beca_trabajo (buscarComponentes de app/Support/inventario.php).
 */
require_once dirname(__DIR__, 2) . '/Support/inventario.php';

final class BusquedaGlobalService
{
    private const ROLES_AMPLIOS = [Rol::ADMIN, Rol::TECNICO, Rol::SECRETARIA];

    public static function vacio(): array
    {
        return ['tickets' => [], 'usuarios' => [], 'componentes' => []];
    }

    public static function buscar(PDO $pdo, string $rol, int $idUsuario, string $q): array
    {
        if ($q === '' || mb_strlen($q) < 2) {
            return self::vacio();
        }
        $amplio = in_array($rol, self::ROLES_AMPLIOS, true);
        $esBeca = $rol === Rol::BECA;

        // Un solicitante o profesor SOLO encuentra sus propios tickets (anti-IDOR).
        $alcance = $amplio ? 'todos' : ($esBeca ? 'beca' : 'propios');
        $tickets = BusquedaGlobalRepository::tickets($pdo, $q, $alcance, $idUsuario);

        if ($rol === 'profesor' || $amplio || $esBeca) {
            $alcanceProf = $rol === 'profesor' ? 'propios' : ($esBeca ? 'beca' : 'todos');
            $tickets = array_merge($tickets, BusquedaGlobalRepository::ticketsProfesor($pdo, $q, $alcanceProf, $idUsuario));
        } // otros roles restringidos (solicitante) no ven tickets de profesor
        $tickets = array_slice($tickets, 0, 8);

        $usuarios = $amplio ? BusquedaGlobalRepository::usuarios($pdo, $q) : [];
        $componentes = in_array($rol, [Rol::ADMIN, Rol::TECNICO, Rol::BECA], true) ? buscarComponentes($pdo, $q, false, 8) : [];

        return [
            'tickets' => array_map(fn($t) => [
                'id' => (int)$t['id'],
                'tipo' => $t['origen_tipo'],
                'etiqueta' => '#' . $t['id'] . ' — ' . ($t['nombre_dependencia'] ?: $t['equipo'] ?: 'Ticket'),
                'estado' => $t['estado'],
                // La beca no abre los PDF de ticket: se le manda a su pantalla.
                'url' => $esBeca
                    ? 'tickets.php'
                    : ($t['origen_tipo'] === 'profesor'
                        ? 'ticket_profesor_pdf.php?id=' . (int)$t['id']
                        : 'ticket_pdf.php?id=' . (int)$t['id']),
            ], $tickets),
            'usuarios' => array_map(fn($u) => [
                'id' => (int)$u['id'],
                'etiqueta' => $u['nombre_completo'] . (!empty($u['codigo']) ? ' — ' . $u['codigo'] : ''),
                'rol' => $u['role'],
                'url' => $rol === Rol::ADMIN ? 'register.php?id=' . (int)$u['id'] : null, // el admin abre la ficha para editarla
            ], $usuarios),
            'componentes' => array_map(fn($c) => [
                'id' => (int)$c['id_componente'],
                'etiqueta' => $c['codigo_inventario'] . ' — ' . ucfirst($c['tipo']) . ($c['marca'] ? ' ' . $c['marca'] : ''),
                'url' => 'ficha_componente.php?id=' . (int)$c['id_componente'],
            ], $componentes),
        ];
    }
}
