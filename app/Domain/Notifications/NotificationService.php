<?php
require_once __DIR__ . '/../../Support/validacion.php'; // correoValido()

/**
 * Casos de uso de notificaciones (pantalla + correo). Equivale, sin cambiar
 * comportamiento, a las antiguas funciones sueltas de notificaciones.php, que
 * siguen existiendo como fachada en app/Support/notificaciones.php.
 *
 * Criterio: la notificación es secundaria al cambio real que la dispara, por
 * eso crear()/notificarPorCorreo() nunca propagan un fallo de escritura/envío.
 */
final class NotificationService
{
    public const TIPOS_VALIDOS = ['TICKET_ESTADO', 'MANTENIMIENTO', 'REQUERIMIENTO', 'SISTEMA'];

    /** Crea una notificación en pantalla. Un tipo desconocido se guarda como SISTEMA. */
    public static function crear(PDO $pdo, int $idUsuario, string $tipo, string $mensaje, ?string $urlDestino = null): void
    {
        if (!in_array($tipo, self::TIPOS_VALIDOS, true)) {
            $tipo = 'SISTEMA';
        }
        try {
            NotificationRepository::insertar($pdo, $idUsuario, $tipo, $mensaje, $urlDestino);
        } catch (PDOException $e) {
            RegistroErrores::registrar("Error creando notificación para usuario {$idUsuario}", $e);
        }
    }

    public static function ultimas(PDO $pdo, int $idUsuario, int $limite = 10): array
    {
        return NotificationRepository::ultimas($pdo, $idUsuario, $limite);
    }

    public static function contarNoLeidas(PDO $pdo, int $idUsuario): int
    {
        return NotificationRepository::contarNoLeidas($pdo, $idUsuario);
    }

    public static function marcarLeida(PDO $pdo, int $idNotificacion, int $idUsuario): bool
    {
        return NotificationRepository::marcarLeida($pdo, $idNotificacion, $idUsuario);
    }

    public static function marcarTodasLeidas(PDO $pdo, int $idUsuario): void
    {
        NotificationRepository::marcarTodasLeidas($pdo, $idUsuario);
    }

    /**
     * Correo real (SMTP/PHPMailer) con el mismo mensaje. Respeta
     * users.notificar_por_correo y solo envía a correos válidos
     * (dominios de config/app.php). Credenciales en config/smtp.php.
     * Un fallo de envío queda en el log y no tumba la operación.
     */
    public static function notificarPorCorreo(PDO $pdo, int $idUsuario, string $mensaje): void
    {
        $usuario = NotificationRepository::datosParaCorreo($pdo, $idUsuario);

        if (!$usuario || empty($usuario['notificar_por_correo']) || !correoValido($usuario['correo'] ?? '')) {
            return;
        }

        Mailer::enviar($usuario['correo'], $usuario['nombre_completo'] ?? '', 'Sistema de Mantenimiento — nueva notificación', $mensaje);
    }

    /** Una notificación por cada usuario ACTIVO del rol. */
    public static function notificarPorRol(PDO $pdo, string $rol, string $tipo, string $mensaje, ?string $urlDestino = null): void
    {
        foreach (NotificationRepository::idsPorRol($pdo, $rol) as $idUsuario) {
            self::crear($pdo, (int)$idUsuario, $tipo, $mensaje, $urlDestino);
        }
    }

    public static function notificarAdmins(PDO $pdo, string $tipo, string $mensaje, ?string $urlDestino = null): void
    {
        self::notificarPorRol($pdo, Rol::ADMIN, $tipo, $mensaje, $urlDestino);
    }

    /** Páginas antiguas o mal escritas que siguen guardadas en avisos viejos → página actual. */
    private const PAGINAS_ANTIGUAS = [
        'panel_becario.php' => 'mantenimientos_programados.php', 'pdf_ticket.php' => 'ticket_pdf.php', 'pdf_ticket_profesor.php' => 'ticket_profesor_pdf.php',
        'admin_tickets.php' => 'tickets.php', 'list_tickets.php' => 'tickets.php', 'my_tickets.php' => 'tickets.php',
        'my_tickets_becatrabajo.php' => 'tickets.php', 'my_tickets_profesor.php' => 'tickets.php', 'tecnico_tickets.php' => 'tickets.php',
    ];

    /**
     * A dónde lleva de verdad un aviso para quien lo abre: corrige páginas antiguas, descarta direcciones
     * externas o inexistentes y, si la página no es de su rol, lo manda al inicio de su rol (nunca a un error).
     */
    public static function destino(?string $url, string $rol): string
    {
        $inicio = rutaInicioPorRol($rol) ?? 'index.php';
        $url = trim((string)$url);
        if ($url === '' || $url === '#' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url)) return $inicio;
        $partes = parse_url($url);
        $archivo = basename((string)($partes['path'] ?? ''));
        $archivo = self::PAGINAS_ANTIGUAS[$archivo] ?? $archivo;
        if (!preg_match('/^[a-z0-9_]+\.php$/', $archivo) || !is_file(dirname(__DIR__, 3) . '/public/' . $archivo)) return $inicio;
        if (!puedeAbrirPagina($rol, $archivo)) return $inicio;                       // mapa único: app/Support/rutas.php
        // La beca de secretaría solo usa sus páginas.
        if ($rol === Rol::BECA && ($_SESSION['area_beca'] ?? '') === AreaBeca::SECRETARIA && !in_array($archivo, AreaBeca::PAGINAS_SECRETARIA, true)) return 'actividades.php';
        return $archivo . (isset($partes['query']) ? '?' . $partes['query'] : '') . (isset($partes['fragment']) ? '#' . $partes['fragment'] : '');
    }

    /** Abre un aviso: lo marca como leído (si es de quien lo abre) y devuelve a dónde ir. */
    public static function abrir(PDO $pdo, int $idNotificacion, int $idUsuario, string $rol): string
    {
        $n = NotificationRepository::deUsuario($pdo, $idNotificacion, $idUsuario);
        if ($n === null) return 'mis_notificaciones.php';
        self::marcarLeida($pdo, $idNotificacion, $idUsuario);
        return self::destino($n['url_destino'], $rol);
    }

    /** Página de avisos: lista paginada (todas o solo sin leer). @return array{filas:array,total:int,paginas:int,pagina:int} */
    public static function pagina(PDO $pdo, int $idUsuario, bool $soloNoLeidas, int $pagina, int $porPagina = 20): array
    {
        $total = NotificationRepository::contar($pdo, $idUsuario, $soloNoLeidas);
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = min(max(1, $pagina), $paginas);
        return ['filas' => NotificationRepository::listar($pdo, $idUsuario, $soloNoLeidas, $porPagina, ($pagina - 1) * $porPagina),
                'total' => $total, 'paginas' => $paginas, 'pagina' => $pagina];
    }

    /** «hace 5 min», «hace 2 h», «ayer 14:30», «03/10 09:15». */
    public static function haceCuanto(string $fecha, ?int $ahora = null): string
    {
        $ts = strtotime($fecha);
        if ($ts === false) return $fecha;
        $d = ($ahora ?? time()) - $ts;
        if ($d < 60) return 'hace un momento';
        if ($d < 3600) return 'hace ' . intdiv($d, 60) . ' min';
        if ($d < 86400 && date('Y-m-d', $ts) === date('Y-m-d', $ahora ?? time())) return 'hace ' . intdiv($d, 3600) . ' h';
        if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day', $ahora ?? time()))) return 'ayer ' . date('H:i', $ts);
        return date('d/m H:i', $ts);
    }

    public const ETIQUETAS_TIPO = ['TICKET_ESTADO' => 'Ticket', 'MANTENIMIENTO' => 'Mantenimiento', 'REQUERIMIENTO' => 'Solicitud', 'SISTEMA' => 'Sistema'];
}
