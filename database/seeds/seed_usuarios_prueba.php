<?php
/**
 * seed_usuarios_prueba.php — inserta un usuario de prueba por cada rol
 * (técnico, profesor, admin, beca trabajo, secretaria y solicitante), todos
 * del Departamento de Sistemas e Informática. Pensado para una base recién
 * creada con database/tickets_db.sql, donde todavía no existe ningún usuario
 * para poder entrar por login.php.
 *
 * Las contraseñas NO están en este archivo: se generan al azar (16
 * caracteres) cada vez que se ejecuta, se guardan hasheadas con
 * password_hash() y se muestran UNA sola vez en la consola.
 *
 * CÓMO USARLO (solo por línea de comandos, nunca por navegador):
 *   php database/seeds/seed_usuarios_prueba.php
 * Anota las credenciales que imprime; no se pueden recuperar después.
 *
 * `password_temporal=1`: cada uno debe cambiar su clave en el primer
 * login (mismo comportamiento que register.php usa siempre).
 * `notificar_por_correo=1`: para que puedas probar también el envío de
 * correo real (solo si configuraste config/smtp.php).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta por línea de comandos.');
}

require_once __DIR__ . '/../../bootstrap.php';

function generarClaveAleatoria(int $largo = 16): string
{
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $max = strlen($alfabeto) - 1;
    $clave = '';
    for ($i = 0; $i < $largo; $i++) {
        $clave .= $alfabeto[random_int(0, $max)];
    }
    return $clave;
}

$usuarios = [
    [
        'codigo'   => 'SIS-T01',
        'correo'   => 'carlos.rojas.tec@ufps.edu.co',
        'clave'    => null, // se genera al azar al ejecutar el script
        'nombre'   => 'Carlos Andrés Rojas Pabón',
        'cargo'    => 'profesional',
        'role'     => 'tecnico',
    ],
    [
        'codigo'   => 'SIS-P01',
        'correo'   => 'diana.suarez.prof@ufps.edu.co',
        'clave'    => null, // se genera al azar al ejecutar el script
        'nombre'   => 'Diana Marcela Suárez Contreras',
        'cargo'    => 'profesor',
        'role'     => 'profesor',
    ],
    [
        'codigo'   => 'SIS-A01',
        'correo'   => 'jorge.penaranda.admin@ufps.edu.co',
        'clave'    => null, // se genera al azar al ejecutar el script
        'nombre'   => 'Jorge Luis Peñaranda Vega',
        'cargo'    => 'director',
        'role'     => 'admin',
    ],
    [
        'codigo'   => 'SIS-B01',
        'correo'   => 'brayan.moreno.beca@ufps.edu.co',
        'clave'    => null, // se genera al azar al ejecutar el script
        'nombre'   => 'Brayan Steven Moreno Cárdenas',
        'cargo'    => 'beca_trabajo',
        'role'     => 'beca_trabajo',
    ],
    [
        'codigo'   => 'SIS-S01',
        'correo'   => 'laura.gomez.secre@ufps.edu.co',
        'clave'    => null, // se genera al azar al ejecutar el script
        'nombre'   => 'Laura Patricia Gómez Ríos',
        'cargo'    => 'secretario',
        'role'     => 'secretaria',
    ],
    [
        'codigo'   => 'SIS-L01',
        'correo'   => 'mario.ortiz.lab@ufps.edu.co',
        'clave'    => null, // se genera al azar al ejecutar el script
        'nombre'   => 'Mario Alberto Ortiz León',
        'cargo'    => 'auxiliar',
        'role'     => 'solicitante',
    ],
    [
        'codigo'   => 'SIS-V01',
        'correo'   => 'vigilancia.aulasur@ufps.edu.co',
        'clave'    => null, // se genera al azar al ejecutar el script
        'nombre'   => 'Vigilancia Aula Sur',
        'cargo'    => 'vigilante',
        'role'     => 'vigilante',
    ],
    [
        'codigo'   => 'SIS-TV01',
        'correo'   => 'pantalla.mantenimiento@ufps.edu.co',
        'clave'    => null, // se genera al azar al ejecutar el script
        'nombre'   => 'Pantalla Oficina de Mantenimiento',
        'cargo'    => 'pantalla',
        'role'     => 'visualizacion',
    ],
];

// Sin tilde a propósito: es el mismo nombre canónico único que usa
// unidades_organizacionales.id_unidad=1 (migracion_v16), para que no
// vuelva a quedar guardado de una forma distinta en cada sitio.
$dependencia = 'Departamento de Sistemas e Informatica';
$idUnidad = 1;

foreach ($usuarios as $u) {
    $u['clave'] = generarClaveAleatoria();
    // password_hash() aquí mismo (no reutilizo un hash pre-calculado)
    // para que quede generado con la misma llamada exacta que usa
    // register.php — así nunca hay duda de compatibilidad con
    // password_verify() en login.php.
    $hash = password_hash($u['clave'], PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO users
                (codigo, correo, password, password_temporal, role,
                 nombre_completo, cargo, dependencia, id_unidad, estado_activo, notificar_por_correo)
             VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, 1, 1)"
        );
        $stmt->execute([
            $u['codigo'], $u['correo'], $hash, $u['role'],
            $u['nombre'], $u['cargo'], $dependencia, $idUnidad,
        ]);
        echo "✅ Creado {$u['role']}: {$u['correo']} / clave: {$u['clave']}\n";
    } catch (PDOException $e) {
        // Típicamente: ya existe (correo/codigo duplicado) si
        // corres el script dos veces por accidente.
        echo "⚠️  {$u['correo']}: " . $e->getMessage() . "\n";
    }
}
echo "
Guarda estas claves ahora: no se muestran de nuevo.
";
