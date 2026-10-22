<?php
/** Creación de tickets desde el panel del solicitante/beca. */

require_once __DIR__ . '/../../Support/paginacion.php';
require_once __DIR__ . '/../../Support/validacion.php'; // validarTexto()

final class TicketCreacionService
{
    public const CATEGORIAS_VALIDAS = ['HARDWARE', 'RED', 'SOFTWARE', 'IMPRESORA', 'PROYECTOR', 'INFRAESTRUCTURA', 'OTRO', 'SEGURIDAD'];

    /**
     * Crea un ticket normal desde el panel interno (formulario clásico).
     *  - solicitante: texto libre, como siempre (dependencia, ubicación, equipo, descripción, categoría).
     *  - secretaria: equipos de cómputo que otras dependencias traen a la oficina de mantenimiento
     *    (no salas). Dependencia de origen, equipo, marca/modelo e inventario opcionales y el número de
     *    radicado de DatArSoft (opcional). Queda en el grupo «Departamento» del técnico (la crea la secretaría del Departamento).
     *
     * @return array{ok:bool,id:?int,error:?string}
     */
    public static function crearTicketPanel(PDO $pdo, int $idUsuario, string $rol, array $in): array
    {
        $err = static fn(string $m): array => ['ok' => false, 'id' => null, 'error' => $m];
        $descripcion = trim((string)($in['descripcion'] ?? ''));
        if ($descripcion === '') {
            return $err('La descripción es obligatoria.');
        }
        if (validarTexto($descripcion, 5000) === null) {
            return $err('La descripción admite máximo 5000 caracteres.');
        }
        $categoria = in_array($in['categoria'] ?? '', self::CATEGORIAS_VALIDAS, true) ? $in['categoria'] : null;

        if ($rol === Rol::SOLICITANTE) {
            $dep = trim((string)($in['dependencia'] ?? ''));
            $ubi = trim((string)($in['ubicacion'] ?? ''));
            $eqp = trim((string)($in['equipo'] ?? ''));
            if ($dep === '' || $ubi === '' || $eqp === '') {
                return $err('Completa dependencia, ubicación y equipo.');
            }
            if (validarTexto($dep, 100) === null || validarTexto($ubi, 100) === null || validarTexto($eqp, 100) === null) {
                return $err('Dependencia, ubicación y equipo admiten máximo 100 caracteres.');
            }
            $pdo->prepare("INSERT INTO tickets (user_id,nombre_dependencia,ubicacion,equipo,descripcion,categoria) VALUES (?,?,?,?,?,?)")
                ->execute([$idUsuario, $dep, $ubi, $eqp, $descripcion, $categoria]);
            $id = (int)$pdo->lastInsertId();
            if ($categoria === 'SEGURIDAD') IncidenteService::desdeTickets($pdo); // aviso inmediato al admin
            return ['ok' => true, 'id' => $id, 'error' => null];
        }

        if ($rol === Rol::SECRETARIA) {
            // La secretaría registra equipos de cómputo que llegan a la oficina de mantenimiento desde otras
            // dependencias (servicios externos). No crea tickets de salas: esos van por QR, profesor o técnico.
            // Aunque no tenga sala, cuenta como del Departamento (TicketConsultas::condicionSistemasTicketsSQL).
            $dep = trim((string)($in['dependencia'] ?? ''));
            $eqp = trim((string)($in['equipo'] ?? ''));
            $inv = trim((string)($in['numero_inventario'] ?? ''));
            $marca = trim((string)($in['marca_modelo'] ?? ''));
            $radicado = strtoupper(trim((string)($in['radicado_datarsoft'] ?? '')));
            if ($dep === '' || $eqp === '') {
                return $err('Indica la dependencia de donde viene el equipo y qué equipo de cómputo es.');
            }
            if (validarTexto($dep, 100) === null || validarTexto($eqp, 100) === null || validarTexto($marca, 100) === null) {
                return $err('Dependencia, equipo y marca/modelo admiten máximo 100 caracteres.');
            }
            if (validarTexto($inv, 50) === null) {
                return $err('El número de inventario admite máximo 50 caracteres.');
            }
            if ($radicado !== '' && !preg_match('/^[A-Z0-9][A-Z0-9 \/._-]{0,39}$/', $radicado)) {
                return $err('El número de radicado de DatArSoft solo admite letras, números, espacios y - / . _ (máximo 40).');
            }
            $ubicacion = TicketRepository::nombreOficinaMantenimiento($pdo) ?? 'Oficina de mantenimiento';
            $pdo->prepare("INSERT INTO tickets (user_id,nombre_dependencia,ubicacion,equipo,marca_modelo,numero_inventario,radicado_datarsoft,descripcion,categoria) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$idUsuario, $dep, $ubicacion, $eqp, $marca !== '' ? $marca : null, $inv !== '' ? $inv : null, $radicado !== '' ? $radicado : null, $descripcion, $categoria]);
            $id = (int)$pdo->lastInsertId();
            AuditoriaService::registrar($pdo, $idUsuario, TablaTicket::NORMAL, $id, 'CREAR', 'ticket de equipo externo creado por secretaria' . ($radicado !== '' ? " (radicado DatArSoft $radicado)" : ''));
            if ($categoria === 'SEGURIDAD') IncidenteService::desdeTickets($pdo);
            return ['ok' => true, 'id' => $id, 'error' => null];
        }

        return $err('Tu rol no puede crear tickets desde este formulario.');
    }
}
