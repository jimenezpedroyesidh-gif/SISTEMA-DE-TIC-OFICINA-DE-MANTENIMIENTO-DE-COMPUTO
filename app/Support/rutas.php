<?php
/**
 * Mapa único de páginas: quién abre cada una, a dónde entra cada rol y qué ve en el menú lateral.
 *
 * Agregar una página: public/<pagina>.php + su controlador + su vista, y aquí una línea en PAGINAS_POR_ROL
 * (si no es para todos) y su enlace en menuPorRol(). El controlador se protege con requirePagina('<pagina>.php');
 * el menú no muestra enlaces a páginas que el rol no puede abrir y los avisos (NotificationService::destino)
 * nunca mandan a una página prohibida.
 */

/** Páginas que solo abren ciertos roles. Las que no están aquí piden solo sesión (cada controlador decide). */
const PAGINAS_POR_ROL = [
    // Administración
    'usuarios.php'                   => [Rol::ADMIN],
    'register.php'                   => [Rol::ADMIN],
    'conexiones.php'                 => [Rol::ADMIN],
    'glpi.php'                       => [Rol::ADMIN],
    'archivo_semestral.php'          => [Rol::ADMIN],
    'sla.php'                        => [Rol::ADMIN],
    'indicadores.php'                => [Rol::ADMIN],
    'seguridad.php'                  => [Rol::ADMIN],
    'solicitudes_baja.php'           => [Rol::ADMIN],
    // Salas e inventario
    'gestionar_salas.php'            => [Rol::ADMIN, Rol::TECNICO],
    'distribucion_sala.php'          => [Rol::ADMIN, Rol::TECNICO],
    'ficha_equipo.php'               => [Rol::ADMIN, Rol::TECNICO], // la ficha de UN equipo (?id=) la abre también la beca
    'ficha_componente.php'           => [Rol::ADMIN, Rol::TECNICO, Rol::BECA],
    'gestionar_inventario.php'       => [Rol::ADMIN, Rol::TECNICO],
    'revisar_componentes_bodega.php' => [Rol::ADMIN, Rol::TECNICO],
    // Mantenimiento
    'mantenimientos_programados.php' => [Rol::ADMIN, Rol::TECNICO, Rol::BECA],
    'mantenimiento_campanas.php'     => [Rol::ADMIN, Rol::TECNICO],
    'historial_mantenimiento.php'    => [Rol::ADMIN, Rol::TECNICO],
    // Soluciones técnicas: técnico gestiona, admin supervisa y autoriza, la beca solo ve sus tareas (lo decide el servicio).
    'soluciones_tecnicas.php'        => [Rol::ADMIN, Rol::TECNICO, Rol::BECA],
    'descargar_solucion.php'         => [Rol::ADMIN, Rol::TECNICO, Rol::BECA],
    'pantalla.php'                   => [Rol::VISUALIZACION, Rol::ADMIN, Rol::TECNICO],
    'horarios.php'                   => [Rol::ADMIN, Rol::TECNICO, Rol::BECA, Rol::PROFESOR, Rol::SECRETARIA, Rol::VIGILANTE],
    // Creación de tickets
    'create_ticket.php'              => [Rol::SOLICITANTE, Rol::SECRETARIA],
    'create_ticket_profesor.php'     => [Rol::PROFESOR, Rol::ADMIN],
    'create_ticket_beca.php'         => [Rol::BECA, Rol::TECNICO, Rol::ADMIN],
    'actividades.php'                => [Rol::SECRETARIA, Rol::BECA],
];

/** ¿El rol puede abrir esa página? (las que no están en el mapa: sí). */
function puedeAbrirPagina(string $rol, string $archivo): bool
{
    return !isset(PAGINAS_POR_ROL[$archivo]) || in_array($rol, PAGINAS_POR_ROL[$archivo], true);
}

/** Guard de un controlador: exige sesión con uno de los roles de la página (ver requireRole). */
function requirePagina(string $archivo): void
{
    requireRole(PAGINAS_POR_ROL[$archivo] ?? Rol::TODOS);
}

/**
 * Página de inicio de cada rol: su lista de tickets (primer elemento del menú). Devuelve null si el rol no
 * existe (no se le concede acceso por defecto).
 */
function rutaInicioPorRol(string $rol): ?string
{
    return [
        Rol::TECNICO       => 'tickets.php',
        Rol::SOLICITANTE   => 'tickets.php',
        Rol::BECA          => 'tickets.php',
        Rol::PROFESOR      => 'tickets.php',
        Rol::SECRETARIA    => 'tickets.php',
        Rol::ADMIN         => 'tickets.php',
        Rol::VIGILANTE     => 'horarios.php',  // guardia: solo horarios de salas y mantenimientos
        Rol::VISUALIZACION => 'pantalla.php',  // televisor de la oficina de mantenimiento
    ][$rol] ?? null;
}

/* Ítems del menú. $icono es el nombre de un ícono de iconos.php (SVG de línea, no emoji). */
function enlace(string $href, string $icono, string $etiqueta): array {
    return ['tipo' => 'link', 'href' => $href, 'icono' => $icono, 'etiqueta' => $etiqueta];
}
function grupo(string $icono, string $etiqueta, array $hijos): array {
    return ['tipo' => 'grupo', 'icono' => $icono, 'etiqueta' => $etiqueta, 'hijos' => $hijos];
}
/** Rótulo de sección (texto pequeño en mayúsculas que separa bloques del menú). */
function seccion(string $etiqueta): array {
    return ['tipo' => 'seccion', 'etiqueta' => $etiqueta];
}

/**
 * Menú lateral del rol. $shell: datos del encabezado (veHorariosSalas, veHorasBecas). Quita los enlaces a
 * páginas que el rol no puede abrir (PAGINAS_POR_ROL), así el menú y los permisos nunca se contradicen.
 */
function menuPorRol(string $rol, array $shell = []): array
{
    $menus = [
        Rol::PROFESOR => [
            enlace('tickets.php', 'tickets', 'Mis tickets'),
            enlace('create_ticket_profesor.php', 'plus', 'Nuevo ticket'),
            enlace('horarios.php', 'calendario', 'Mi horario'),
            enlace('escanear_qr.php', 'escanear', 'Escanear QR'),
        ],
        Rol::BECA => [
            grupo('tickets', 'Mis tickets', [
                enlace('tickets.php', 'tickets', 'Reportes de daño'),
                enlace('tickets.php?vista=bodega', 'bodega', 'Piezas en bodega'),
                enlace('tickets.php?vista=creados', 'archivo', 'Mis tickets creados'),
                enlace('tickets.php?vista=checklist', 'herramienta', 'Checklist de equipos'),
            ]),
            enlace('create_ticket_beca.php', 'plus', 'Nuevo ticket'),
            enlace('escanear_qr.php', 'escanear', 'Escanear QR'),
            seccion('Mi trabajo'),
            enlace('mantenimientos_programados.php', 'herramienta', 'Mis mantenimientos'),
            enlace('soluciones_tecnicas.php', 'pc', 'Mis tareas técnicas'),
            enlace('horarios.php', 'calendario', 'Mi turno'),
            enlace('seguimiento_horas.php', 'reloj', !empty($shell['veHorasBecas']) ? 'Seguimiento de horas' : 'Mis horas'),
        ],
        Rol::TECNICO => [
            enlace('tickets.php', 'tickets', 'Tickets'),
            enlace('create_ticket_beca.php', 'plus', 'Nuevo ticket'),
            enlace('escanear_qr.php', 'escanear', 'Escanear QR'),
            seccion('Gestión'),
            enlace('gestionar_salas.php', 'sala', 'Salas y QR'),
            grupo('caja', 'Inventario', [
                enlace('ficha_equipo.php', 'sala', 'Fichas por salón'),
                enlace('ficha_equipo.php?vista=equipos', 'monitor', 'Fichas por equipo'),
                enlace('gestionar_inventario.php', 'archivo', 'Componentes'),
                enlace('revisar_componentes_bodega.php', 'bodega', 'Bodega'),
            ]),
            grupo('herramienta', 'Mantenimiento', [
                enlace('mantenimientos_programados.php', 'herramienta', 'Por sala'),
                enlace('mantenimiento_campanas.php', 'calendario', 'Campañas (varias salas)'),
                enlace('historial_mantenimiento.php', 'grafica', 'Historial y análisis'),
                enlace('historial_mantenimiento.php?vista=acciones', 'archivo', 'Acciones correctivas'),
            ]),
            grupo('pc', 'Soluciones técnicas', [
                enlace('soluciones_tecnicas.php', 'grafica', 'Panel de seguimiento'),
                enlace('soluciones_tecnicas.php?vista=catalogo', 'archivo', 'Catálogo y versiones'),
            ]),
            enlace('horarios.php', 'calendario', 'Horarios'),
            enlace('pantalla.php', 'monitor', 'Pantalla de la oficina (TV)'),
        ],
        Rol::ADMIN => [
            enlace('tickets.php', 'dashboard', 'Panel general'),
            enlace('create_ticket_profesor.php', 'plus', 'Nueva solicitud (docente)'),
            seccion('Gestión'),
            enlace('gestionar_salas.php', 'sala', 'Salas y QR'),
            grupo('caja', 'Inventario', [
                enlace('ficha_equipo.php', 'sala', 'Fichas por salón'),
                enlace('ficha_equipo.php?vista=equipos', 'monitor', 'Fichas por equipo'),
                enlace('gestionar_inventario.php', 'archivo', 'Componentes'),
                enlace('revisar_componentes_bodega.php', 'bodega', 'Bodega'),
                enlace('solicitudes_baja.php', 'baja', 'Solicitudes de baja'),
            ]),
            grupo('herramienta', 'Mantenimiento', [
                enlace('mantenimientos_programados.php', 'herramienta', 'Por sala'),
                enlace('mantenimiento_campanas.php', 'calendario', 'Campañas (varias salas)'),
                enlace('historial_mantenimiento.php', 'grafica', 'Historial y análisis'),
                enlace('historial_mantenimiento.php?vista=acciones', 'archivo', 'Acciones correctivas'),
            ]),
            grupo('pc', 'Soluciones técnicas', [
                enlace('soluciones_tecnicas.php', 'grafica', 'Panel de seguimiento'),
                enlace('soluciones_tecnicas.php?vista=catalogo', 'archivo', 'Catálogo y versiones'),
            ]),
            enlace('horarios.php', 'calendario', 'Horarios'),
            enlace('pantalla.php', 'monitor', 'Pantalla de la oficina (TV)'),
            seccion('Administración'),
            enlace('seguimiento_horas.php', 'reloj', 'Horas de becas'),
            enlace('usuarios.php', 'usuarios', 'Usuarios'),
            enlace('archivo_semestral.php', 'archivo', 'Cierre de semestre'),
            grupo('conexion', 'Conexiones', [
                enlace('conexiones.php', 'base', 'Bases de datos y GLPI'),
                enlace('glpi.php', 'monitor', 'Inventario GLPI'),
            ]),
            // Calidad del servicio y seguridad: hoy las administra el admin; a futuro pasarán al rol super admin.
            seccion('Calidad y seguridad'),
            grupo('grafica', 'Calidad del servicio', [
                enlace('indicadores.php', 'grafica', 'Indicadores'),
                enlace('sla.php', 'reloj', 'Tickets y SLA'),
                enlace('sla.php?vista=acuerdos', 'calendario', 'Acuerdos y horario'),
            ]),
            grupo('escudo', 'Seguridad', [
                enlace('seguridad.php', 'escudo', 'Panel de seguridad'),
                enlace('seguridad.php?vista=bitacora', 'archivo', 'Bitácora de accesos'),
                enlace('seguridad.php?vista=respaldos', 'base', 'Respaldos'),
                enlace('seguridad.php?vista=incidentes', 'campana', 'Incidentes'),
            ]),
        ],
        Rol::SECRETARIA => [
            enlace('tickets.php', 'tickets', 'Mis tickets'),
            enlace('create_ticket.php', 'plus', 'Nuevo ticket'),
            enlace('actividades.php', 'tablero', 'Tablero de actividades'),
        ],
        // Guardia de seguridad: solo consulta los horarios de las salas y los mantenimientos (para no prestar la sala).
        Rol::VIGILANTE => [
            enlace('horarios.php', 'calendario', 'Horarios de salas'),
        ],
        // Cuenta del televisor: una sola pantalla que se actualiza sola.
        Rol::VISUALIZACION => [
            enlace('pantalla.php', 'monitor', 'Pantalla de mantenimiento'),
        ],
        Rol::SOLICITANTE => [
            enlace('tickets.php', 'tickets', 'Mis tickets'),
            enlace('create_ticket.php', 'plus', 'Nuevo ticket'),
            enlace('escanear_qr.php', 'escanear', 'Escanear QR'),
        ],
    ];


    $items = $menus[$rol] ?? [];
    // Secretaria: «Horarios de salas» solo si el admin le activó ese permiso.
    if ($rol === Rol::SECRETARIA && !empty($shell['veHorariosSalas'])) {
        $items[] = enlace('horarios.php', 'calendario', 'Horarios de salas');
    }
    // Beca asignada por el admin a la secretaría: no atiende tickets; su menú es su tablero, su turno y sus horas.
    if ($rol === Rol::BECA && ($_SESSION['area_beca'] ?? '') === AreaBeca::SECRETARIA) {
        $items = [
            enlace('actividades.php', 'tablero', 'Tablero de actividades'),
            enlace('horarios.php', 'calendario', 'Mi turno'),
            enlace('seguimiento_horas.php', 'reloj', !empty($shell['veHorasBecas']) ? 'Seguimiento de horas' : 'Mis horas'),
        ];
    }

    // Seguimiento de horas: solo las becas tienen horas (ya está en su menú) y el admin ve todas.
    // A cualquier otro rol se le muestra si el admin le activó «Ver horas de todas las becas» en Usuarios.
    if (!in_array($rol, [Rol::BECA, Rol::ADMIN], true) && !empty($shell['veHorasBecas'])) {
        $items[] = enlace('seguimiento_horas.php', 'reloj', 'Horas de becas');
    }

    $permitido = fn(array $e) => puedeAbrirPagina($rol, explode('?', $e['href'], 2)[0]);
    $salida = [];
    foreach ($items as $item) {
        if ($item['tipo'] === 'link' && !$permitido($item)) continue;
        if ($item['tipo'] === 'grupo') {
            $item['hijos'] = array_values(array_filter($item['hijos'], $permitido));
            if (!$item['hijos']) continue;
        }
        $salida[] = $item;
    }
    return $salida;
}
