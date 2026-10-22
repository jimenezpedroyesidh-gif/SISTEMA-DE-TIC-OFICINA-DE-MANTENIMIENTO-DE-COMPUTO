<?php
/**
 * Cliente HTTP mínimo para las pruebas funcionales y de seguridad (README §28-29).
 * - Una instancia = un "navegador" con su propia cookie de sesión.
 * - No sigue redirecciones (para poder comprobar a dónde redirige el servidor).
 * - post() recoge solo el token CSRF de la página indicada (como lo haría un navegador).
 */
final class HttpResponse
{
    public int $status = 0;
    public array $headers = [];
    public string $body = '';

    public function location(): string { return $this->headers['location'] ?? ''; }
    public function header(string $n): string { return $this->headers[strtolower($n)] ?? ''; }
    public function json() { return json_decode($this->body, true); }
    public function contains(string $s): bool { return strpos($this->body, $s) !== false; }
    public function redirects(): bool { return in_array($this->status, [301, 302, 303, 307, 308], true); }
    /** ¿Lo mandó a iniciar sesión? */
    public function toLogin(): bool { return $this->redirects() && strpos($this->location(), 'login.php') !== false; }
    /** ¿Acceso rechazado de cualquier forma (login, 403 o mensaje de denegado)? */
    public function denied(): bool
    {
        return $this->toLogin() || $this->status === 403 || stripos($this->body, 'acceso denegado') !== false
            || stripos($this->body, 'no tienes permiso') !== false || stripos($this->body, 'no tienen acceso') !== false;
    }
}

final class HttpClient
{
    private string $base;
    private string $jar;

    public function __construct(string $base)
    {
        $this->base = rtrim($base, '/') . '/';
        $this->jar = tempnam(sys_get_temp_dir(), 'cj_');
    }

    public function __destruct() { @unlink($this->jar); }

    public function request(string $method, string $path, $data = null, array $headers = []): HttpResponse
    {
        $ch = curl_init(preg_match('~^https?://~', $path) ? $path : $this->base . ltrim($path, '/'));
        $h = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        foreach ($headers as $k => $v) $h[] = "$k: $v";
        if ($h) curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        $raw = (string)curl_exec($ch);
        $r = new HttpResponse();
        $r->status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        foreach (preg_split('/\r?\n/', substr($raw, 0, $hs)) as $line) {
            if (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $r->headers[strtolower(trim($k))] = trim($v); }
        }
        $r->body = substr($raw, $hs);
        curl_close($ch);
        return $r;
    }

    public function get(string $path): HttpResponse { return $this->request('GET', $path); }

    /** POST tal cual (sin token CSRF) — para probar el rechazo. */
    public function postRaw(string $path, array $data = [], array $headers = []): HttpResponse
    {
        return $this->request('POST', $path, http_build_query($data), $headers);
    }

    /** Token CSRF que el servidor puso en una página (campo oculto csrf_token). */
    public function csrf(string $page): string
    {
        $r = $this->get($page);
        // campo oculto de los formularios, o data-csrf del layout (peticiones AJAX)
        return preg_match('/name="csrf_token" value="([^"]+)"/', $r->body, $m) || preg_match('/data-csrf="([^"]+)"/', $r->body, $m)
            ? html_entity_decode($m[1]) : '';
    }

    /** POST como un navegador: primero pide la página para obtener el token y lo adjunta. */
    public function post(string $path, array $data = [], ?string $paginaToken = null): HttpResponse
    {
        $data['csrf_token'] = $this->csrf($paginaToken ?? $path);
        return $this->postRaw($path, $data);
    }

    /** POST multipart con archivos: $files = ['campo' => ['nombre.ext', 'contenido', 'mime']]. */
    public function postFile(string $path, array $data, array $files, ?string $paginaToken = null): HttpResponse
    {
        $data['csrf_token'] = $this->csrf($paginaToken ?? $path);
        foreach ($files as $campo => [$nombre, $contenido, $mime]) {
            $tmp = tempnam(sys_get_temp_dir(), 'up_');
            file_put_contents($tmp, $contenido);
            $data[$campo] = new CURLFile($tmp, $mime, $nombre);
        }
        return $this->request('POST', $path, $data);
    }

    /**
     * Reporte anónimo por QR como lo haría una persona: abre el formulario, responde la pregunta de
     * verificación (suma), acepta el aviso de privacidad y envía. $extra reemplaza o quita campos (null = quitar).
     */
    public function reportarQr(string $token, array $data, array $extra = []): HttpResponse
    {
        $pagina = 'reportar.php?token=' . $token;
        $r = $this->get($pagina);
        if (preg_match('/name="csrf_token" value="([^"]+)"/', $r->body, $m)) $data['csrf_token'] = html_entity_decode($m[1]);
        if (preg_match('/¿Cuánto es (\d+) \+ (\d+)\?/u', $r->body, $m)) $data['reto'] = (string)((int)$m[1] + (int)$m[2]);
        $data['acepto_privacidad'] = '1';
        // Sin sesión el formulario pide nombre y correo institucional (para contactar a quien reporta).
        $data += ['nombre_contacto' => 'ZT Persona QR', 'correo_contacto' => 'zt.persona.qr@ufps.edu.co'];
        foreach ($extra as $k => $v) {
            if ($v === null) unset($data[$k]); else $data[$k] = $v;
        }
        return $this->postRaw($pagina, $data);
    }

    public function login(string $codigo, string $correo, string $clave): HttpResponse
    {
        return $this->postRaw('login.php', ['codigo' => $codigo, 'correo' => $correo, 'password' => $clave]);
    }
}
