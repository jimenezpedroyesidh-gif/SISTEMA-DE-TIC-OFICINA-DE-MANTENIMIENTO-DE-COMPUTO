# Sistema de Tickets UFPS — Mantenimiento de Cómputo

Mesa de ayuda con inventario y horarios para la Oficina de Mantenimiento de Cómputo del
Departamento de Sistemas e Informática (Aula Sur, UFPS). **PHP nativo (sin framework) + PDO +
MySQL/MariaDB**, organizado en capas simples.

**Versión 1.0.0** · requisitos: PHP 8.1 o superior (probado con 8.3) (`pdo_mysql`, `mbstring`, `gd`, `zip`, `sodium`; `pdo_pgsql`
y `curl` para las integraciones opcionales) y MySQL 8 / MariaDB 10.6 o superior.

---

## 1. Descripción general

### Contexto

La oficina atiende daños y solicitudes de las salas de cómputo, lleva el inventario de cada pieza
(torre, monitor, teclado…) y organiza los turnos de técnicos y becas trabajo. Antes eso vivía en
correos, hojas de cálculo y papel; este sistema lo reúne en una sola aplicación web con roles.

### Qué resuelve

- **Tickets** de daño o error en salas: por QR pegado en la sala (sin iniciar sesión) o por
  docentes/solicitantes con cuenta. Seguimiento, asignación a becas, firma y PDF.
- **Requerimientos de docentes**: cambio de sala, préstamo de equipo/sala, instalación de software.
- **Inventario** de equipos y componentes (CMDB simplificado) con traslados, bajas controladas,
  ficha por QR y sincronización con GLPI.
- **Mantenimientos programados** por sala con checklist por equipo y evidencia fotográfica.
- **Soluciones técnicas**: scripts, automatizaciones, GLPI Agent, políticas de Windows, restricciones de
  aplicaciones y configuraciones especiales, con versiones aprobadas, procedimientos y estado por computadora.
- **Horarios** de salas (clases, turnos de becas, mantenimientos, préstamos).
- **Horas de las becas trabajo**, leídas de la base de asistencia facial (PostgreSQL, solo lectura).
- Calidad (SLA, indicadores, encuestas), seguridad (bitácora, 2FA, respaldos), notificaciones,
  búsqueda global y pantalla para el televisor de la oficina.

### Roles

admin · técnico · beca trabajo · secretaria · profesor · solicitante · vigilante · visualización (TV).
El detalle de qué página abre cada rol está en [Roles y acceso por página](#roles-y-acceso-por-página).

---

## 2. Estructura del proyecto

```
tickets/
├── public/                     ← ÚNICA carpeta accesible por URL (document root)
│   ├── *.php                   Puertas de entrada (≈10 líneas): cargan el arranque y delegan al controlador
│   └── assets/
│       ├── estilo.css          Índice CSS: solo @import, en orden de cascada
│       ├── app.css             Todo el CSS en un archivo (lo genera tools/construir_css.php)
│       ├── tokens.css          ★ Variables CSS (:root) — panel de personalización
│       ├── css/01…12-*.css     Estilos por capa: base → layout → componentes → módulos → utilidades
│       ├── vendor/             Chart.js, html5-qrcode y qrcodejs con versión fija (sin CDN)
│       ├── *.js                JavaScript por módulo (sin librerías de build)
│       └── uploads/perfiles/   Fotos de perfil (no se versionan)
├── app/
│   ├── Infrastructure/
│   │   └── Database/Database.php   ★ Conexión PDO única (MySQL) + transacciones + conexiones externas
│   ├── Domain/                 Lógica de negocio y SQL (Repositorios/Servicios por módulo)
│   ├── Presentation/
│   │   ├── Controllers/        Un controlador por página (prepara datos, valida, redirige)
│   │   └── Views/              HTML: layout/ (esqueleto), partials/ (piezas reutilizables), una carpeta por módulo
│   └── Support/                Utilidades: h() (escape XSS), CSRF, sesión, auth, paginación;
│                               rutas.php = mapa único de páginas, roles y menú
├── config/                     *.example.php = plantillas versionadas; *.php = credenciales locales (NO se versionan)
├── database/
│   ├── tickets_db.sql          Esquema completo para instalar (no destructivo)
│   ├── migraciones/            Cambios de esquema numerados (001_….sql), los aplica tools/migrar.php
│   └── seeds/                  Datos de prueba
├── storage/                    Adjuntos y respaldos generados en ejecución (no se versionan)
├── tests/                      Pruebas unitarias y HTTP
├── tools/                      CI local (lint, PHPStan, pruebas) y migrar.php
├── vendor/                     FPDF y PHPMailer incluidos manualmente (sin Composer)
├── bootstrap.php               Arranque común: autocarga, helpers y $pdo
├── .htaccess                   Bloquea por URL todo lo que no sea public/
└── .gitignore
```

Flujo de una petición:

```
public/tickets.php ──► bootstrap.php ($pdo = Database::conexion())
        │
        └──► Presentation/Controllers/Tickets/PanelPage.php
                   │  usa  Domain/Tickets/*Repository.php   (SQL con consultas preparadas)
                   └──►  Presentation/Views/…               (HTML; todo dato sale por h())
```

---

## 3. Arquitectura y reglas de diseño

### 3.1 Base de datos: PDO puro y consultas preparadas

- **Una sola conexión** por petición: `Database::conexion()` en
  `app/Infrastructure/Database/Database.php`. Las credenciales se leen de `config/database.php`;
  ningún otro archivo abre conexiones ni contiene usuario/clave.
- Opciones fijas de la conexión:
  - `PDO::ERRMODE_EXCEPTION` — todo error SQL lanza excepción.
  - `PDO::ATTR_EMULATE_PREPARES => false` — las consultas las prepara **el servidor MySQL**: el SQL
    y los datos viajan por separado, así que un valor nunca puede alterar la consulta.
  - `charset=utf8mb4` — UTF-8 completo, igual que las tablas.
- Si la conexión falla, el detalle (host, usuario, motivo) va **solo al log del servidor**
  (`RegistroErrores`) y el usuario ve un mensaje genérico con código 503. Nunca se muestran
  credenciales ni mensajes de MySQL en pantalla.
- **Toda** consulta con datos externos usa `prepare()` + `execute([...])` con marcadores `?`:

  ```php
  $st = $pdo->prepare('SELECT id, nombre_completo FROM users WHERE correo = ? AND estado_activo = 1');
  $st->execute([$correo]);
  $usuario = $st->fetch(PDO::FETCH_ASSOC);
  ```

- Listas variables (`IN (...)`) se arman con un `?` por elemento:
  `implode(',', array_fill(0, count($ids), '?'))`.
- Los **nombres** de tabla o columna no se pueden parametrizar; cuando son dinámicos se validan
  contra una **lista blanca** antes de usarlos (p. ej. `TicketRepository::TABLAS_VALIDAS`,
  `UserPermissions::PERMISOS`).
- Varias escrituras relacionadas van dentro de `Database::transaccion(function (PDO $pdo) { … })`:
  confirma si todo sale bien y deshace todo si algo falla.
- El SQL vive en `app/Domain/` (repositorios/servicios). Controladores y vistas no escriben SQL
  (una prueba automática lo verifica).

### 3.2 Salida en HTML: escape contra XSS

- Todo valor que viene de la base de datos o del usuario se imprime con `h()`
  (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`, en `app/Support/ui.php`):
  `<?= h($t['descripcion']) ?>`. En inventario se usa su equivalente `h_inv()`.
- Números: `(int)` → `<?= (int)$t['id'] ?>`.
- Datos para JavaScript dentro de `<script>`: `jsJson($valor)` (escapa `<`, `>`, `&`, comillas).
- Formularios con `csrfField()` y verificación `csrfCheck()` en cada POST.

### 3.3 CSS: variables y cascada

- `estilo.css` solo importa: primero `tokens.css` y luego `css/01…12` **en orden de cascada**
  (no reordenar: el orden decide qué regla gana). Las páginas cargan `app.css`, que es lo mismo en un
  solo archivo sin comentarios: tras editar cualquier `.css` corre `php tools/construir_css.php`
  (mientras no lo hagas, las páginas usan `estilo.css` y el cambio se ve igual; el CI avisa si quedó
  desactualizado).
- Solo 5 tamaños de pantalla en los `@media`: 480, 640, 768, 1024 y 1280 px (`max-width` usa el valor
  menos 1). Cada regla responsive vive junto a la regla base que modifica.
- Nada de estilos en línea para mostrar u ocultar: se usa el atributo `hidden` (en HTML y en JS,
  `elemento.hidden = true`). Los únicos `style=""` son las posiciones de la grilla de horarios, que
  salen de los datos.
- CSS y JS se piden con `asset('assets/…')`, que agrega `?v=<fecha del archivo>`: el navegador los
  guarda un año y baja el nuevo apenas cambia. El JS usa `const`/`let` (sin `var`) y no hay librerías
  desde CDN: la política de seguridad (CSP) solo permite scripts del propio servidor.
- **Ningún CSS fuera de `tokens.css` contiene colores literales** (hex/rgb): todo usa `var(--…)`.
  Una prueba automática lo verifica.
- `tokens.css` tiene dos niveles:
  1. **Panel de personalización** (variables en español, al inicio de `:root`): lo único que se
     edita para cambiar el aspecto.
  2. **Tokens internos** (`--color-primary`, `--gray-200`, `--radius-md`, `--space-3`…): los que
     usan los CSS. Leen sus valores del panel; los tonos claros/oscuros de la marca se calculan
     con `color-mix()`.
- Estilos idénticos se agrupan en una sola regla con varios selectores
  (p. ej. `.chart-card, .qr-card { … }`), solo cuando agruparlos no cambia el orden de la cascada.

### 3.4 Sin código repetido

- Piezas de HTML que se repiten van a `app/Presentation/Views/partials/` o a un parcial del módulo,
  y se incluyen con variables (p. ej. `tickets/_tabla_solicitante.php` sirve a «activos» y a
  «solucionados»; `list_tickets/seccion_grupo.php` pinta las bandejas Sistemas y Solicitantes
  recorriendo un arreglo de configuración).
- Listas fijas (tipos de componente, estados…) son constantes de PHP y se pintan con `foreach`.
- Roles, estados de ticket y tablas de tickets nunca se escriben a mano: `Rol::BECA`,
  `TicketWorkflow::SOLUCIONADO`, `TablaTicket::PROFESOR`. Un nombre de tabla que llega del
  formulario se valida con `TablaTicket::desdeEntrada()`.
- Los servicios devuelven datos (`ok`, `cambio`…) para que el controlador decida; nunca se compara
  el texto del mensaje.

### 3.5 Inventario: una sola verdad y aprobación del técnico

- **Todo cambio de pieza** (sale una pieza de un equipo / entra otra), venga de un **ticket** o de la
  **checklist de mantenimiento**, se ejecuta en un solo lugar:
  `InventarioService::aplicarCambioPieza()`. `aprobarCambioPieza()` (tickets) y
  `ejecutarAprobacionInventarioEquipo()` (checklist) solo le pasan los datos.
- **La beca propone, el técnico aprueba.** El propio núcleo comprueba que quien aprueba tenga rol
  `tecnico` (`ROLES_APRUEBAN_PIEZA`); no depende de que cada pantalla lo verifique.
- **Solo se aplica lo pendiente:** la pieza que sale debe seguir en el equipo y la que entra no debe
  estar ya instalada. Aprobar dos veces se rechaza con `CambioPiezaNoAplicable`.
- El equipo sigue **operativo** si se cambia una torre por otra; queda no operativo (y sale de los
  mantenimientos activos) solo si pierde su pieza principal sin reemplazo.
- Cada cambio deja **historial de la pieza** (con motivo) y un **movimiento** con quién solicitó y
  quién aprobó, igual para tickets y checklist.
- Traslados y cambios de posición de equipos (por ticket), reinstalación de piezas y revisión en
  bodega también exigen técnico (la reinstalación y la bodega, técnico o admin); las bajas las aprueba el admin.

### 3.6 Rutas, roles y menú en un solo lugar

`app/Support/rutas.php` es el único sitio que dice quién abre cada página (`PAGINAS_POR_ROL`), a qué
página entra cada rol (`rutaInicioPorRol()`) y qué ve en el menú lateral (`menuPorRol()`). El
controlador se protege con `requirePagina('pagina.php')`, el menú esconde solo los enlaces que el rol
no puede abrir y los avisos nunca llevan a una página prohibida. Una prueba automática falla si el menú
muestra una página que el rol no puede abrir o si una página del mapa no usa `requirePagina()`.

Agregar una página: `public/pagina.php` + controlador + vista, y en `rutas.php` una línea en el mapa
(si no es para todos) y su `enlace()` en el menú del rol.

### 3.7 Migraciones versionadas

La aplicación no pregunta en cada petición si una tabla o columna existe: el esquema se mantiene al día
con migraciones numeradas en `database/migraciones/` (`001_alcance_ciertos_equipos.sql`, …).

```bash
php tools/migrar.php            # aplica las pendientes y las anota en la tabla `migraciones`
php tools/migrar.php --estado   # solo muestra cuáles están aplicadas y cuáles faltan
```

Cada migración se puede repetir sin daño (`MODIFY`, `ADD COLUMN IF NOT EXISTS`, `CREATE TABLE IF NOT
EXISTS`…), una sentencia por `;` al final de línea. Al crear una, refleja el mismo cambio en
`database/tickets_db.sql` para las instalaciones nuevas.

---

## 4. Guía de personalización (colores, bordes y tipografía)

Todo el aspecto visual se controla desde **un solo bloque** al inicio de
`public/assets/tokens.css`:

```css
:root{
  /* Colores de marca */
  --color-principal:   #BC0017;  /* rojo institucional */
  --color-secundario:  #374151;  /* gris oscuro de botones neutros y texto de apoyo */
  --color-texto-sobre-principal: #fff;

  /* Superficies */
  --color-fondo:       #f4f5f7;
  --color-superficie:  #fff;
  --color-borde:       #e2e5ea;

  /* Texto */
  --color-texto:       #111827;
  --color-texto-suave: #6b7280;

  /* Estados */
  --color-exito:       #15803d;
  --color-advertencia: #d97706;
  --color-informacion: #2563eb;
  --color-peligro:     #dc2626;

  /* Forma y tipografía */
  --radio-borde:         8px;
  --radio-borde-pequeno: 6px;
  --radio-borde-grande:  10px;
  --fuente-principal:  "Inter", "Segoe UI", …, sans-serif;
  --tamano-texto-base: .9375rem;
}
```

### Paso a paso: cambiar el color institucional

1. Abre `public/assets/tokens.css`.
2. Busca el bloque **PANEL DE PERSONALIZACIÓN**.
3. Cambia **solo** el valor de `--color-principal`, por ejemplo a azul:
   ```css
   --color-principal: #1d4ed8;
   ```
4. Guarda, corre `php tools/construir_css.php` y recarga el navegador (no hace falta vaciar caché:
   la URL del CSS incluye la fecha del archivo, `app.css?v=…`, y cambia sola).
5. Listo: botones, enlaces, menú activo, foco, cabecera, chips y gráficos usan el nuevo color, y
   sus tonos (hover más oscuro, fondos suaves) se recalculan automáticamente. Los **PDF** también
   lo toman (`app/Support/marca.php` lee `--color-principal`).

### Qué controla cada variable

| Variable | Afecta a |
|---|---|
| `--color-principal` | Botón principal, enlaces, elemento activo del menú, anillo de foco, barra superior, tonos derivados, PDF |
| `--color-secundario` | Botones neutros (`.btn-info`), textos de apoyo, iconos |
| `--color-texto-sobre-principal` | Texto/iconos encima del color principal y de fondos oscuros |
| `--color-fondo` | Fondo general de la página |
| `--color-superficie` | Tarjetas, paneles, tablas, campos, menús desplegables |
| `--color-borde` | Bordes y divisores suaves |
| `--color-texto` | Títulos y texto principal |
| `--color-texto-suave` | Ayudas, fechas, texto atenuado |
| `--color-exito/advertencia/informacion/peligro` | Botones y marcas de estado, semáforos, gráficos |
| `--radio-borde*` | Redondeo de botones, campos, chips, paneles |
| `--fuente-principal`, `--tamano-texto-base` | Tipografía de toda la app |

### Ejemplos

```css
/* Tema verde con esquinas más redondeadas */
--color-principal: #047857;
--radio-borde: 12px;
--radio-borde-grande: 16px;

/* Fondo más cálido */
--color-fondo: #faf7f2;
```

### Notas

- **Prueba un cambio sin editar archivos**: en el navegador, F12 → pestaña *Elements* →
  selecciona `<html>` → en *Styles* edita `--color-principal` dentro de `:root`. Lo verás en vivo.
- Los **fondos suaves de los estados** (`--color-success-soft`, `--color-warning-border`…) y la
  paleta del **televisor** (`--tv-*`) son tokens internos propios: si cambias mucho un color de
  estado, ajústalos también (están más abajo en el mismo archivo, con comentarios).
- No escribas colores hex directamente en `css/*.css`: agrega una variable en `tokens.css` y úsala
  con `var(--…)`. La prueba automática falla si aparece un color literal fuera de `tokens.css`.

### Nombre de la institución y dominio de correo

El nombre, la sigla y el dominio de correo no están escritos en las pantallas: salen de la clave
`institucion` de `config/app.php` (copia `config/app.example.php`). Se usan en el título de cada
página, la cabecera, la portada, los PDF, los correos, la app de doble factor, los respaldos y la
validación de correos. Lo que no pongas toma el valor por defecto (UFPS).

```php
'institucion' => [
    'sigla'          => 'ACME',
    'nombre'         => 'Acme S.A.',
    'ciudad'         => 'Bogotá',
    'sistema'        => 'Mesa de Ayuda',
    'oficina'        => 'Soporte Técnico',
    'ubicacion'      => 'Sede principal, piso 2',
    'contacto'       => 'ext:100',
    'dominio_correo' => 'acme.com',     // único dominio del reporte por QR sin cuenta
    'dominios_extra' => [],             // aceptados además al crear usuarios
],
```

Para otra empresa: cambia este bloque y `--color-principal` en `tokens.css`. El responsable del
tratamiento de datos (aviso de privacidad) lo edita el admin en **Seguridad**.

---

## 5. Instalación limpia en XAMPP

1. Copia (o clona, ver sección 6) la carpeta del proyecto dentro de `C:\xampp\htdocs\`
   (por ejemplo `htdocs\tickets`).
2. Arranca **Apache** y **MySQL** desde el panel de XAMPP.
3. Importa la base de datos (crea `tickets_db` con sus tablas y catálogos):
   - phpMyAdmin → pestaña **Importar** → `database/tickets_db.sql`, o
   - consola: `C:\xampp\mysql\bin\mysql -u root < database\tickets_db.sql`

   El archivo no borra nada (`IF NOT EXISTS` / `INSERT IGNORE`): volver a importarlo sobre una
   base existente solo agrega lo que falte. Después aplica las migraciones (también al actualizar
   el sistema): `C:\xampp\php\php tools\migrar.php`
4. Crea tu configuración local a partir de la plantilla (este archivo **no** está en el
   repositorio):
   ```bash
   copy config\database.example.php config\database.php      # Windows
   cp config/database.example.php config/database.php        # Linux/macOS
   ```
   La plantilla ya sirve para XAMPP (`root` sin clave). Si tu MySQL tiene clave, edítala ahí.
5. Datos de prueba (opcional, recomendado para probar todo):
   - Salas y equipos: importa `database/seeds/seed_datos_prueba_salas.sql` sobre `tickets_db`.
   - Usuarios, uno por rol, **por consola**:
     `C:\xampp\php\php database\seeds\seed_usuarios_prueba.php`
     Imprime las claves una sola vez (son temporales: cada usuario la cambia al entrar).
6. Abre `http://localhost/tickets/` (te lleva a `public/`) y entra con código + correo + clave.
   El `.htaccess` de la raíz bloquea por URL todo lo que no sea `public/`.
7. Opcional (cada uno con su plantilla `*.example.php` → `*.php`):
   - Horas de las becas: `config/postgres.php` (PostgreSQL de asistencia, solo lectura) y
     `extension=pdo_pgsql` en `php.ini`. Sin eso el sistema funciona igual.
   - Correo real: `config/smtp.php`.
   - Inicio con Google: `config/google.php`.

---

## 6. Guía de despliegue con Git

### 6.1 Primer commit (repositorio nuevo)

Si recibiste el proyecto con la carpeta `.git` ya creada (versión 1.0.0 en un solo commit), salta a la 6.2.

```bash
# 1. Entra a la carpeta del proyecto
cd C:\xampp\htdocs\tickets

# 2. Inicializa el repositorio con la rama principal "main"
git init -b main

# 3. Identifícate (una sola vez por equipo)
git config user.name  "Tu Nombre"
git config user.email "tu.correo@ufps.edu.co"

# 4. ANTES de añadir nada, comprueba que las credenciales quedan fuera
git check-ignore -v config/database.php
#   → debe responder:  .gitignore:…:config/database.php   config/database.php
#   Si no responde nada, NO sigas: revisa el .gitignore.

# 5. Añade los archivos y revisa qué va a entrar
git add .
git status
#   En "config/" solo deben aparecer los *.example.php.

# 6. Primer commit
git commit -m "Versión 1.0.0: tickets, inventario, horarios y mantenimiento (PHP + PDO + MySQL)"
git tag -a v1.0.0 -m "Primera versión"
```

### 6.2 Subir a GitHub/GitLab

```bash
# Crea el repositorio vacío en la web (sin README ni .gitignore) y copia su URL
git remote add origin https://github.com/USUARIO/NOMBRE-REPO.git
git push -u origin main
git push origin v1.0.0
```

### 6.3 Trabajo diario

```bash
git pull                       # trae los cambios de los demás
git checkout -b ajuste-colores # rama para tu cambio
# … editas …
git add public/assets/tokens.css
git commit -m "Cambiar color principal a azul"
git push -u origin ajuste-colores   # y abre un Pull Request
```

### 6.4 Clonar en otro equipo o servidor

```bash
git clone https://github.com/USUARIO/NOMBRE-REPO.git tickets
cd tickets
cp config/database.example.php config/database.php   # y pon las credenciales de ese servidor
```
Luego sigue la sección 5 desde el paso 3 (importar `tickets_db.sql` y correr `php tools/migrar.php`).

### 6.5 Qué NO entra al repositorio (ver `.gitignore`)

| Excluido | Por qué |
|---|---|
| `config/*.php` (menos `*.example.php`) | Credenciales de MySQL, PostgreSQL, SMTP, Google |
| `storage/clave_conexiones.key`, `*.key`, `*.pem` | Clave que cifra las contraseñas guardadas en «Conexiones» |
| `storage/adjuntos/*`, `storage/respaldos/`, `uploads/perfiles/*` | Archivos de usuarios y respaldos de la base |
| `*.log`, `sess_*`, `cache/`, `tmp/` | Temporales de PHP |
| `*.xlsx`, `*.csv`, volcados `dump*.sql` | Pueden contener datos personales o claves de prueba |
| `.idea/`, `.vscode/`, `.DS_Store`, `Thumbs.db` | Archivos del editor y del sistema operativo |

### 6.6 Si subiste una credencial por error

```bash
git rm --cached config/database.php     # la saca del repo sin borrarla de tu disco
git commit -m "Quitar credenciales del repositorio"
git push
```
Después **cambia esa contraseña** en MySQL: quedó en el historial de Git y debe considerarse
expuesta.

---

## Base de datos

`database/tickets_db.sql` es el único archivo de base de datos (68 tablas, incluye `tickets_archivo` del cierre de semestre, `secretaria_salas` y las 11 `st_*` de soluciones técnicas): ya incluye todas las migraciones
(permiso_horarios_extra, beca_id, permiso_ver_tickets_sistemas, permiso_ver_horas_becas, conexiones y GLPI).
No hay migraciones sueltas. Es no destructivo: volver a importarlo sobre una base existente solo agrega lo que falte.

## Seguimiento de horas (becas trabajo)

`public/seguimiento_horas.php` (+ `seguimiento_horas_pdf.php`). Lógica en `app/Domain/Horas/`.

- **Solo las becas trabajo tienen horas.** Técnico, secretaria, profesor, solicitante y admin no tienen seguimiento propio.
- La **beca** ve sus propias horas del mes y descarga su PDF («Mis horas» en el menú).
- El **admin** ve todas las becas con sus horas del mes, el detalle día a día de cualquiera y el PDF de una o de todas.
- **Permiso «Ver horas de todas las becas»**: en *Usuarios*, el admin lo activa con un clic a cualquier usuario que no
  sea admin. Quien lo tiene ve lo mismo que el admin y le aparece «Horas de becas» en el menú; sin él, 403.
- Las horas salen de la base de asistencia facial (PostgreSQL, solo lectura, ver Conexiones), cruzando `users.codigo`.
- El PDF es un reporte simple del sistema; no usa ningún formato institucional.

## Conexiones e Inventario GLPI (admin)

Menú del admin → **Conexiones**:

- **Bases de datos y GLPI** (`conexiones.php`): tres tarjetas con «Guardar» y «Probar conexión».
  - *Asistencia facial (PostgreSQL en la nube)*: de aquí salen las horas de las becas. Reemplaza a `config/postgres.php`
    (si no hay nada guardado, se sigue leyendo ese archivo). Requiere `extension=pdo_pgsql` en php.ini.
  - *GLPI — base de datos*: IP, puerto, base y un usuario MySQL de **solo lectura**. Sirve para traer el inventario.
  - *GLPI — API REST*: URL de `apirest.php`, App-Token y token de usuario (en GLPI: Configuración → General → API, y
    Mis preferencias → Token de API). Sirve para **escribir** en GLPI. Aquí también se elige la ubicación de GLPI
    de bodega / oficina de mantenimiento.
  - Contraseñas y tokens se guardan cifrados (AES-256-GCM) en `conexiones_externas`; la clave se crea sola en
    `storage/clave_conexiones.key` (no se versiona). Si se pierde ese archivo, hay que volver a escribir las contraseñas.
  - Si un servidor externo no responde, se espera como máximo 3 s y no se reintenta durante 1 minuto.
- **Inventario GLPI** (`glpi.php`): inventario agrupado por tipo y ubicación (con detalle y marca de lo que ya está en el
  sistema por serial), «Vincular por serial», vínculo sala ↔ ubicación de GLPI y cola de envíos con reintento.

**Qué se envía a GLPI** (tabla `glpi_cola`, por la API):
- *Alta* de cada componente de Equipos TI (torre/todo en uno/portátil → Computer, monitor → Monitor, el resto → Peripheral).
  Si ya existe en GLPI con el mismo serial, se vincula en vez de duplicarlo. La infraestructura (sillas, aire…) no se envía.
- *Cambio de ubicación* de cada componente movido o aprobado por un técnico/admin, y de cada equipo completo cuyo traslado
  aprueba el técnico. Lo que mueve una beca sin aprobación no se envía.
- Se encola en la misma transacción del cambio y se envía al terminar la petición; si falla, queda en la cola con el motivo.

## Roles y acceso por página

Guards en `app/Support/auth.php`. Todos los roles entran por `tickets.php`, menos vigilante (`horarios.php`) y visualizacion (`pantalla.php`).

- solicitante, secretaria: create_ticket
- profesor: create_ticket_profesor
- beca_trabajo, tecnico, admin: create_ticket_beca, ficha_componente, mantenimientos_programados, soluciones_tecnicas y descargar_solucion (la beca solo sus tareas)
- tecnico, admin: gestionar_salas, gestionar_inventario, revisar_componentes_bodega, ficha_equipo (la beca solo un equipo)
- admin: usuarios, register, solicitudes_baja, conexiones, glpi, archivo_semestral (cierre de semestre)
- vigilante: SOLO horarios (todas las salas, solo lectura: clases, préstamos y mantenimientos), perfil y cambiar_clave
- visualizacion (televisor de la oficina): SOLO pantalla.php (también la abren técnico y admin)
- La lista cerrada de páginas de vigilante y visualizacion está en `PAGINAS_ROL_RESTRINGIDO` (auth.php): una página nueva no les queda abierta por descuido.
- seguimiento_horas: beca_trabajo (las suyas), admin (todas), otro rol con el permiso activado (todas)
- todos los autenticados: horarios (según rol), perfil, cambiar_clave, escanear_qr, buscar_global
- públicas: login, index, reportar (QR), consultar_estado

Permisos puntuales que activa el admin en *Usuarios*: secretaria → «Editar horarios» (en Horarios → Más → «Permisos de edición para secretaría» el admin elige además QUÉ salas ve y edita; siempre ve y edita los turnos de las becas del área secretaría) y «Ver todos los tickets de Sistemas»;
cualquier rol menos admin → «Ver horas de todas las becas».

## Mantenimiento de salas

- **Por sala** (`mantenimientos_programados.php`): el formulario va por pasos: sala y tipo → alcance
  (toda la sala, solo cómputo, ciertos equipos —se eligen en una lista— o solo el videobeam) → detalle →
  fecha y coordinador. La checklist se arma solo con los equipos del alcance; «solo el videobeam» no tiene
  checklist y muestra el videobeam de la sala con su estado. «Buscar el primer espacio libre» revisa clases
  (con excepciones), turnos, préstamos y otros mantenimientos de la sala durante toda la duración. Se pueden
  vincular reportes abiertos de la sala: al cerrar el mantenimiento como realizado se cierran con él. Si
  después aparece un cruce (p. ej. un préstamo), el listado lo marca y ofrece reprogramar; cada cambio de
  fecha queda en el historial.
- **Campañas** (`mantenimiento_campanas.php`): una actividad en varias salas, en el orden elegido, cada una en
  su primer espacio libre. Con «Finalizar una sala antes de continuar», la siguiente queda bloqueada hasta que
  la anterior se cierre o se cancele.
- **Historial y análisis** (`historial_mantenimiento.php`): intervenciones, fallas frecuentes, equipos
  reincidentes y tiempos de atención por sala, bloque, equipo, responsable o semestre (también con tickets ya
  archivados), exportable a CSV. El informe del semestre propone acciones correctivas que se convierten en
  mantenimientos del semestre siguiente.
- La checklist solo incluye equipos que están en la sala y operativos (con «ciertos equipos», los elegidos
  aunque no estén operativos: puede ser justo el que hay que reparar). Si un equipo sale de la sala mientras
  el mantenimiento está activo, su fila pasa a `EXCLUIDO_TRASLADO`: no bloquea el cierre y queda como rastro.
- Para cerrar como **Realizado** se exige al menos una foto de evidencia general. El enlace de referencia
  (p. ej. un video) le dice a quien lo tome qué se espera que haga.
- Permisos: el técnico programa, reprograma y crea campañas; la beca ejecuta la checklist; el admin supervisa
  y define acciones correctivas.

## Soluciones técnicas

`soluciones_tecnicas.php` (+ `descargar_solucion.php`). Lógica en `app/Domain/SolucionesTecnicas/`, vistas en
`app/Presentation/Views/soluciones/`, tablas `st_*` (migración `002_soluciones_tecnicas.sql`). Reutiliza `salas`,
`equipos`, `users` y `mantenimientos_programados` por id: no duplica ninguna.

- **Catálogo**: código (automático `ST-0001` o propio), nombre, tipo (`st_tipos`: script, automatización, agente de
  inventario, política de Windows, restricción de aplicaciones, configuración especial), descripción, objetivo,
  responsable técnico y estado (borrador → activa cuando el admin aprueba la primera versión; suspendida; retirada).
- **Versiones y archivos**: el técnico sube una versión con sus archivos (scripts, instaladores, configuración,
  documentos; extensiones en lista blanca, máx. 64 MB c/u) y queda *en revisión*; el **admin** la aprueba o rechaza
  (nunca quien la subió). La vigente es la aprobada más reciente; retirarla vuelve a la anterior. Los archivos son
  inmutables, se guardan en `storage/soluciones/` (bloqueada por `.htaccess`, fuera de git, incluida en los
  respaldos) con nombre aleatorio `.dat`, y antes de cada descarga se revisa su **SHA-256**: si cambió, no se entrega.
- **Procedimientos** de instalación, ejecución, verificación y reversión, con enlace a video y capturas. Editar crea
  una redacción nueva; la anterior queda como no vigente.
- **Dónde se aplica**: salas completas (sus equipos de cómputo operativos; los que entran después quedan incluidos) o
  equipos puntuales por código. Cada equipo tiene un despliegue: pendiente, programado, sin verificar
  (implementado), verificado, con incidencia o revertido; *desactualizado* si tiene una versión que ya no es la vigente.
- **Integración con mantenimiento**: «Programar en un mantenimiento» crea un mantenimiento de sala normal (alcance
  «ciertos equipos», instalación de software, actividades = procedimientos) y puede asignar los equipos a una beca o
  técnico. Se ejecuta y se cierra en *Mantenimientos* con sus reglas; al cerrarlo, la checklist pasa sola a los
  despliegues (Realizado → implementado, Incompleto → con incidencia, el resto vuelve a pendiente). El módulo de
  mantenimiento no se modificó: cada pantalla de soluciones sincroniza primero los mantenimientos cerrados.
- **Verificación manual** por equipo (responsable, fecha, resultado, observaciones, evidencias): siempre una fila
  nueva. **Panel de seguimiento**: cobertura por solución, por sala y por equipo, pendientes, incidencias, versiones
  desactualizadas, verificaciones pendientes, versiones por aprobar y mantenimientos en curso.
- **Historial** (`st_historial`): cada acción con el estado anterior; la aplicación nunca lo edita ni lo borra.
- **Permisos** (revisados en el servidor con el rol de la base): el técnico gestiona y documenta; el admin supervisa
  y autoriza (aprueba/rechaza/retira versiones, suspende o retira soluciones); la beca solo ve **Mis tareas
  técnicas** (equipos asignados o tomados en un mantenimiento activo con una solución): instrucciones, archivos de esa
  versión, capturas y sus propias evidencias.

## Rendimiento y capacidad

Probado con **2.000.000 de componentes, 120.000 tickets, 40 salas y 50 usuarios simultáneos sin pausa** en un
servidor de 2 núcleos: ninguna página pasa de 1 s y el buscador de componentes responde en milisegundos (todas
las consultas van por índice). El límite práctico está cerca de 140.000 tickets activos con
`memory_limit = 128M`; el cierre de semestre los mantiene muy por debajo.

Configuración recomendada para el servidor (XAMPP o Linux):

```ini
; MySQL/MariaDB (my.ini, sección [mysqld])
innodb_buffer_pool_size = 512M   ; XAMPP trae 16M; 512M–1G según la RAM
max_connections         = 300    ; mayor que los procesos de Apache
slow_query_log          = 1
long_query_time         = 1
; PHP (php.ini)
opcache.enable = 1
memory_limit   = 128M
```

Activa además `mod_deflate` y `mod_headers` en Apache (compresión y caché de `public/assets`).
`php tools/carga/revisar_capacidad.php` revisa, en solo lectura, el volumen de datos, los índices y esta
configuración contra la base real; córrelo al inicio de cada semestre. Las demás herramientas de
`tools/carga/` generan datos y miden tiempos, solo contra una base que termine en `_test`.

## Pruebas

`php tools/ci.php` corre `php -l`, classmap, `app.css` al día, PHPStan (`tools/phpstan.phar`), pruebas unitarias y lints;
`php tools/ci.php http` las pruebas HTTP. Necesitan una base aparte `tickets_db_test` (ver `tests/http/README.md`).
La integración con GLPI se prueba contra un GLPI simulado (`tests/glpi/`).

## Solo Departamento de Sistemas e Informática (por ahora)

El equipo trabaja únicamente con los tickets del Departamento: salas del departamento (QR con o sin sesión),
profesores del departamento, lo que registra la secretaría y lo que crean técnicos y becas. Regla única:
`TicketConsultas::condicionSistemasTicketsSQL / condicionSistemasProfesorSQL` (y `esTicketDeSistemas()`).

- **Técnico:** ve y atiende solo el Departamento. La bandeja «Otras dependencias» y cualquier acción sobre
  esos tickets (tomar, editar, conversar…) quedan bloqueadas en el servidor salvo que el admin le active
  **«Ver y atender tickets de otras dependencias»** en *Usuarios* (`users.permiso_ver_tickets_externos`,
  apagado por defecto).
- **Beca:** igual que antes, solo el Departamento (o lo que le asignen). Ya no recibe avisos de QR de salas de
  otras dependencias.
- **Admin:** sigue viendo «Otras dependencias» en su panel, solo para supervisar. Además es docente del
  Departamento: con **«Nueva solicitud (docente)»** crea requerimientos o daños como un profesor, pero solo
  para las salas de su dependencia, y los sigue en la pestaña **«Mis solicitudes»** (conversación, solución,
  fotos y PDF).

## Traslado a la oficina de mantenimiento (bodega) desde la checklist

En el plano de un mantenimiento programado, quien tomó un equipo ahora puede elegir como resultado
**«Trasladar a la oficina de mantenimiento (bodega)»**: escoge la pieza que se lleva (la torre, normalmente) y
explica la falla. El equipo queda **«Traslado por aprobar»** (cuenta como pendiente: el mantenimiento no se
puede cerrar). El técnico lo **aprueba** (la pieza pasa a bodega «en revisión» con historial y movimiento; si es
la torre o el todo en uno el equipo queda no operativo y sale del mantenimiento) o lo **rechaza** (vuelve a quien
lo tenía para resolverlo en la sala). Usa el mismo núcleo de inventario que el cambio de pieza
(`InventarioService::aplicarCambioPieza`). Código: `MantenimientoService::proponerTraslado / aprobarTraslado /
rechazarTraslado`; estado nuevo `mantenimiento_equipos.estado = 'TRASLADO_PENDIENTE'`.

## Cierre con evidencia, conversación y listas compactas

**Evidencia obligatoria al solucionar.** Los daños de sala de profesores (panel o QR con sesión), los
requerimientos de **instalación de software** y los reportes por **QR sin sesión** no pasan a
«Solucionado» sin dos cosas: la **explicación de la solución** (mínimo 15 caracteres) y **al menos una
foto** del trabajo. La regla vive en `TicketEvidencia::exige()/faltante()` y la revisan los tres
servicios de estado (técnico, beca y técnico en tickets normales); el formulario avisa antes de enviar
(`shell.js`). Las fotos se guardan en `ticket_adjuntos` con `categoria = 'EVIDENCIA'`, se re-codifican a
JPEG (máx. 1600 px, sin datos GPS del celular) y se ven solo por `descargar_adjunto.php` (con permisos).
Préstamos y cambios de sala no la piden.

**Conversación con quien reportó.** `ticket_comentarios.visibilidad` separa las **notas internas**
(`INTERNO`, solo personal) del **chat con el solicitante** (`CONVERSACION`). Solo escribe el técnico/beca
que tomó el ticket; el admin la lee. El profesor/solicitante responde libremente cuando soporte le
escribió; si nadie le ha respondido, solo puede **reiterar** cuando vence el tiempo de respuesta del
acuerdo de servicio (`sla_politicas.horas_respuesta`, en horas hábiles) y la reiteración queda marcada y
notifica a soporte (a todos los técnicos si nadie lo ha tomado). Quien reportó por QR sin cuenta
conversa desde «Consultar estado de mi reporte» con su código. **Todo** —solución, conversación y
fotos— se imprime en el PDF del ticket como constancia.

**Listas compactas (`table.data.tk-lista`, `css/12-tickets-compactos.css`).** Todas las bandejas de
tickets (técnico, beca, admin, profesor, solicitante/secretaria) usan las mismas columnas: *Ticket ·
Solicitud · Personas · Estado · Acciones*. La descripción se corta a dos líneas con «Ver detalle»; la
acción principal queda a la vista y lo demás va en «Más ▾» y en ventanas flotantes. En celular cada
ticket es una ficha. Los tickets en «Solucionado» siguen visibles para que el técnico los apruebe.

Piezas: `TicketEvidencia`, `TicketConversacion`, `TicketInteraccionService` (acciones comunes de los
paneles) y los parciales `_tk_menu.php`, `_tk_conversacion.php`, `_tk_evidencias.php`,
`_tk_campos_cierre.php`, `_tk_resumen_profesor.php`. Base de datos: ver el bloque «SOLUCIÓN,
CONVERSACIÓN Y EVIDENCIA FOTOGRÁFICA» al final de `database/tickets_db.sql` (re-importarlo solo agrega lo
que falta).

## Notas para quien vaya a modificar el proyecto

- No hay dependencias por Composer: `vendor/fpdf` y `vendor/PHPMailer`
  están incluidos directamente en el repositorio.
- Cada archivo de `public/` es solo una puerta de entrada: carga `session_boot.php` y `bootstrap.php`
  y le pasa el trabajo a su controlador en `app/Presentation/Controllers/`.
  Nada de SQL ni HTML en `public/`. El esqueleto de página se incluye con `LAYOUT_DIR`
  (`app/Presentation/Views/layout/`).
- El proyecto se conecta también, por separado, a una base PostgreSQL
  del sistema de asistencia facial (ver
  `app/Infrastructure/Database/PostgresConnection.php`) — son dos
  bases de datos distintas, no una unificada.
- Los archivos en `config/` y las subidas de usuario en
  `storage/adjuntos/` y `public/assets/uploads/perfiles/` están
  excluidos del repositorio (`.gitignore`); cada entorno los genera o
  configura por su cuenta.

## Licencia

Ver [LICENSE](LICENSE).
