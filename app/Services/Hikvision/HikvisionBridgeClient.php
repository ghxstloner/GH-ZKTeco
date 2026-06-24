<?php

namespace App\Services\Hikvision;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP del Bridge ISUP Hikvision.
 *
 * Vinculado como singleton por HikvisionServiceProvider. Construye una
 * PendingRequest una sola vez con baseUrl + header X-Flow-Bridge-Token y la
 * reutiliza para todas las llamadas. Cada método normaliza el envelope
 * {code,msg,data}, lanza excepciones tipadas y registra siempre con el
 * prefijo [HikvisionBridge] para facilitar el grep en laravel.log.
 *
 * Excepciones:
 *  - HikvisionFeatureDisabledException  -> 503 / feature_disabled (recuperable)
 *  - HikvisionBridgeException            -> cualquier otro error (fatal)
 */
class HikvisionBridgeClient
{
    private readonly PendingRequest $http;

    public function __construct(
        private readonly string $bridgeUrl,
        private readonly string $bridgeToken,
        private readonly int $timeout = 15,
    ) {
        if ($this->bridgeUrl === '') {
            throw new HikvisionBridgeException('[HikvisionBridge] HIK_BRIDGE_URL no configurado');
        }

        $this->http = Http::baseUrl(rtrim($this->bridgeUrl, '/'))
            ->withHeaders([
                'X-Flow-Bridge-Token' => $this->bridgeToken,
                'Accept' => 'application/json',
            ])
            ->timeout($this->timeout);
    }

    /* -----------------------------------------------------------------
     | Dispositivos
     |-----------------------------------------------------------------*/

    /**
     * GET /api/devices
     *
     * @return list<array<string,mixed>>
     */
    public function listDevices(): array
    {
        $envelope = $this->request('GET', '/api/devices');
        $data = $envelope['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * GET /api/devices/{deviceId}
     *
     * @return array<string,mixed>
     */
    public function getDevice(string $bridgeDeviceId): array
    {
        $envelope = $this->request('GET', '/api/devices/'.urlencode($bridgeDeviceId));

        return is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
    }

    /**
     * GET /api/devices/{deviceId}/device-info
     *
     * @return array<string,mixed>
     */
    public function getDeviceInfo(string $bridgeDeviceId): array
    {
        $envelope = $this->request('GET', '/api/devices/'.urlencode($bridgeDeviceId).'/device-info');

        return is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
    }

    /* -----------------------------------------------------------------
     | Eventos AcsEvent (marcaciones)
     |-----------------------------------------------------------------*/

    /**
     * POST /api/devices/{deviceId}/events/search
     *
     * Paginación del lado del Bridge: searchResultPosition + maxResults.
     *
     * @param  string  $startTime  ISO-8601 con offset (ej: 2026-06-24T00:00:00-05:00)
     * @param  string  $endTime    ISO-8601 con offset
     * @return array<string,mixed> Envelope crudo; eventsJson queda como string, no se parsea aquí.
     */
    public function searchEvents(
        string $bridgeDeviceId,
        string $startTime,
        string $endTime,
        int $position = 0,
        int $maxResults = 30,
        int $major = 0,
        int $minor = 0,
        ?string $searchID = null,
    ): array {
        $payload = [
            'startTime' => $startTime,
            'endTime' => $endTime,
            'maxResults' => $maxResults,
            'searchResultPosition' => $position,
            'major' => $major,
            'minor' => $minor,
            'searchID' => $searchID ?? ('flow-bridge-'.uniqid()),
        ];

        return $this->request(
            'POST',
            '/api/devices/'.urlencode($bridgeDeviceId).'/events/search',
            $payload,
        );
    }

    /* -----------------------------------------------------------------
     | Provisioning de usuarios (PUT/POST/GET)
     |-----------------------------------------------------------------*/

    /**
     * PUT /api/devices/{deviceId}/users/{employeeNo}
     * Crea o actualiza un usuario y opcionalmente foto (multipart).
     *
     * @param  array<string,mixed>  $user
     * @return array<string,mixed>
     */
    public function syncUser(string $bridgeDeviceId, string $employeeNo, array $user, ?string $photoPath = null): array
    {
        $url = '/api/devices/'.urlencode($bridgeDeviceId).'/users/'.urlencode($employeeNo);

        $response = $photoPath !== null
            ? $this->http->attach('file', file_get_contents($photoPath), basename($photoPath))->put($url, $user)
            : $this->http->put($url, $user);

        return $this->parse($response, 'PUT', $url);
    }

    /**
     * PUT /api/devices/{deviceId}/users/{employeeNo}/face
     * Solo sube foto.
     *
     * @return array<string,mixed>
     */
    public function syncFace(string $bridgeDeviceId, string $employeeNo, string $photoPath): array
    {
        $url = '/api/devices/'.urlencode($bridgeDeviceId).'/users/'.urlencode($employeeNo).'/face';

        $response = $this->http
            ->attach('file', file_get_contents($photoPath), basename($photoPath))
            ->put($url);

        return $this->parse($response, 'PUT', $url);
    }

    /**
     * GET /api/devices/{deviceId}/users/{employeeNo}/verify
     * Verifica si un usuario existe en el dispositivo.
     *
     * @return array<string,mixed>|null  null si el usuario no existe (404).
     */
    public function verifyUser(string $bridgeDeviceId, string $employeeNo): ?array
    {
        $url = '/api/devices/'.urlencode($bridgeDeviceId).'/users/'.urlencode($employeeNo).'/verify';

        $response = $this->http->get($url);

        $status = $response->status();

        // 404 / not_found es caso válido => null, sin lanzar.
        if ($status === 404) {
            return null;
        }
        if ($status === 503 || str_contains((string) $response->body(), 'feature_disabled')) {
            throw new HikvisionFeatureDisabledException(
                '[HikvisionBridge] verifyUser feature_disabled',
                feature: 'provisioning',
                httpStatus: $status,
            );
        }

        $envelope = $this->parse($response, 'GET', $url);

        if (($envelope['code'] ?? null) === 404 || ($envelope['msg'] ?? '') === 'not_found') {
            return null;
        }

        return is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
    }

    // NOTA: DELETE /api/devices/{deviceId}/users/{employeeNo} no está implementado
    // en el Bridge (retorna 501). Por eso no existe método deleteUser() aquí.
    // Cuando el Bridge lo soporte, añadirlo y exponerlo en HikvisionProvisioningService.

    /* -----------------------------------------------------------------
     | Núcleo HTTP / normalización
     |-----------------------------------------------------------------*/

    /**
     * Ejecuta una petición y devuelve el envelope {code,msg,data}.
     *
     * @param  'GET'|'POST'|'PUT'|'DELETE'  $method
     * @param  array<string,mixed>|null  $json
     * @return array<string,mixed>
     */
    private function request(string $method, string $url, ?array $json = null): array
    {
        $response = match (strtoupper($method)) {
            'GET' => $this->http->get($url),
            'POST' => $this->http->post($url, $json ?? []),
            'PUT' => $this->http->put($url, $json ?? []),
            'DELETE' => $this->http->delete($url),
            default => throw new HikvisionBridgeException("[HikvisionBridge] método no soportado: {$method}"),
        };

        return $this->parse($response, $method, $url);
    }

    /**
     * Normaliza la respuesta del Bridge.
     *
     * @return array<string,mixed>
     */
    private function parse($response, string $method, string $url): array
    {
        $status = $response->status();
        $isFeatureDisabled = $status === 503
            || str_contains((string) $response->body(), 'feature_disabled');

        if ($isFeatureDisabled) {
            $feature = $this->guessFeature($url);
            Log::warning("[HikvisionBridge] {$method} {$url} feature_disabled ({$feature}) status={$status}");

            throw new HikvisionFeatureDisabledException(
                "[HikvisionBridge] {$method} {$url} feature_disabled ({$feature})",
                feature: $feature,
                httpStatus: $status,
            );
        }

        // Errores de transporte / servidor (timeout, 5xx, conexión rechazada).
        if ($response->failed()) {
            $body = mb_substr((string) $response->body(), 0, 500);
            Log::error("[HikvisionBridge] {$method} {$url} HTTP {$status}: {$body}");

            throw new HikvisionBridgeException(
                "[HikvisionBridge] {$method} {$url} falló con HTTP {$status}",
                httpStatus: $status,
            );
        }

        $json = $response->json();

        if (!is_array($json)) {
            $body = mb_substr((string) $response->body(), 0, 500);
            Log::error("[HikvisionBridge] {$method} {$url} respuesta no-JSON: {$body}");

            throw new HikvisionBridgeException(
                "[HikvisionBridge] {$method} {$url} respuesta no-JSON (HTTP {$status})",
                httpStatus: $status,
            );
        }

        $code = $json['code'] ?? null;
        $msg = $json['msg'] ?? '';

        // El envelope Hikvision usa code interno distinto del HTTP code.
        if ($code !== 200 && !$this->isAcceptedNonOk((string) $msg)) {
            $sdkError = $json['data']['sdkError'] ?? null;
            Log::error("[HikvisionBridge] {$method} {$url} code={$code} msg={$msg} sdkError={$sdkError}");

            throw new HikvisionBridgeException(
                "[HikvisionBridge] {$method} {$url} code={$code} msg={$msg} sdkError={$sdkError}",
                bridgeCode: is_numeric($code) ? (int) $code : null,
                httpStatus: $status,
            );
        }

        Log::debug("[HikvisionBridge] {$method} {$url} OK code={$code}");

        return $json;
    }

    /**
     * Mensajes acceptados aunque code != 200 (sin tratar como error).
     */
    private function isAcceptedNonOk(string $msg): bool
    {
        return in_array(strtolower($msg), ['no_data', 'empty', 'no records'], true);
    }

    /**
     * Heurística para etiquetar qué feature del Bridge está deshabilitado
     * según el endpoint invocado.
     */
    private function guessFeature(string $url): string
    {
        return match (true) {
            str_contains($url, '/users/') => 'provisioning',
            str_contains($url, '/events/') => 'attendance-events',
            str_contains($url, '/device-info') => 'raw-isapi',
            str_contains($url, '/channels') => 'channel-sync',
            default => 'unknown',
        };
    }
}
