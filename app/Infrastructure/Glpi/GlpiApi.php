<?php
/**
 * Cliente mínimo de la API REST de GLPI (apirest.php, disponible en GLPI 10 y 11) para ESCRIBIR:
 * crear activos y cambiar su ubicación. Autenticación: App-Token + token de usuario (Preferencias → Token API).
 *
 *   $api = new GlpiApi($url, $appToken, $userToken);
 *   $id = $api->crear('Monitor', ['name' => ..., 'serial' => ..., 'locations_id' => 5]);
 *   $api->actualizar('Monitor', $id, ['locations_id' => 7]);
 *   $api->cerrar();
 *
 * Errores: lanza RuntimeException con un mensaje corto (sin tokens) para guardarlo en la cola.
 */
final class GlpiApi
{
    private string $url;
    private ?string $sesion = null;

    public function __construct(string $url, private string $appToken, private string $userToken, private int $timeout = 8)
    {
        $this->url = rtrim($url, '/');
        if (!preg_match('~^https?://~i', $this->url)) {
            throw new RuntimeException('La URL de la API de GLPI debe empezar por http:// o https://');
        }
    }

    /** Abre sesión y devuelve el nombre del usuario de GLPI (sirve para «Probar conexión»). */
    public function iniciar(): string
    {
        $r = $this->llamar('GET', '/initSession', null, ['Authorization: user_token ' . $this->userToken]);
        if (empty($r['session_token'])) {
            throw new RuntimeException('GLPI no devolvió session_token.');
        }
        $this->sesion = (string)$r['session_token'];
        try {
            $info = $this->llamar('GET', '/getFullSession');
            return (string)($info['session']['glpiname'] ?? 'usuario API');
        } catch (RuntimeException $e) {
            return 'usuario API';
        }
    }

    public function crear(string $itemtype, array $input): int
    {
        $r = $this->llamar('POST', '/' . rawurlencode($itemtype), ['input' => $input]);
        $id = (int)($r['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('GLPI no devolvió el id del activo creado.');
        return $id;
    }

    public function actualizar(string $itemtype, int $id, array $input): void
    {
        $this->llamar('PUT', '/' . rawurlencode($itemtype) . '/' . $id, ['input' => $input]);
    }

    public function cerrar(): void
    {
        if ($this->sesion === null) return;
        try { $this->llamar('GET', '/killSession'); } catch (RuntimeException $e) { }
        $this->sesion = null;
    }

    private function llamar(string $metodo, string $ruta, ?array $cuerpo = null, array $cabeceras = []): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP no tiene la extensión curl activa.');
        }
        if ($this->sesion === null && $ruta !== '/initSession') {
            $this->iniciar();
        }
        $h = array_merge(['Content-Type: application/json', 'App-Token: ' . $this->appToken], $cabeceras);
        if ($this->sesion !== null) $h[] = 'Session-Token: ' . $this->sesion;
        $ch = curl_init($this->url . $ruta);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h,
            CURLOPT_CONNECTTIMEOUT => $this->timeout, CURLOPT_TIMEOUT => $this->timeout, CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($cuerpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo, JSON_UNESCAPED_UNICODE));
        $resp = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            throw new RuntimeException('GLPI no responde: ' . $err);
        }
        $json = json_decode((string)$resp, true);
        if ($codigo >= 400) {
            // GLPI responde errores como ["ERROR_CODIGO", "mensaje"]
            $msg = is_array($json) ? implode(' - ', array_map('strval', array_slice($json, 0, 2))) : mb_substr((string)$resp, 0, 120);
            throw new RuntimeException("GLPI respondió $codigo: $msg");
        }
        return is_array($json) ? $json : [];
    }
}
