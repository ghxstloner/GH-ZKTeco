<?php

namespace Tests\Feature\Hikvision;

use App\Services\Hikvision\HikvisionBridgeException;
use App\Services\Hikvision\HikvisionBridgeClient;
use App\Services\Hikvision\HikvisionFeatureDisabledException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Smoke test del cliente del Bridge Hikvision.
 *
 * Usa Http::fake para no tocar el Bridge real. Verifica:
 *  1. Header X-Flow-Bridge-Token enviado en cada peticion.
 *  2. Envelope {code,msg,data} parseado correctamente.
 *  3. feature_disabled (HTTP 503) lanza la excepcion tipada correcta.
 *  4. code != 200 lanza HikvisionBridgeException.
 *  5. eventsJson llega como string (el servicio que lo consume lo parsea).
 *
 * NOTA: las migraciones tenant (TenantMigrationRunner) y la logica de sync
 * no son testeables con sqlite in-memory (divergen del esquema MySQL tenant);
 * se cubren via runbook manual (ver docs/Hikvision.md, sección Verificación).
 */
class HikvisionBridgeClientTest extends TestCase
{
    private function makeClient(): HikvisionBridgeClient
    {
        return new HikvisionBridgeClient(
            bridgeUrl: 'http://bridge.test',
            bridgeToken: 'secret-token',
            timeout: 5,
        );
    }

    public function test_lista_de_dispositivos_envia_el_token_y_parsea_el_envelope(): void
    {
        Http::fake([
            'bridge.test/api/devices' => Http::response([
                'code' => 200,
                'msg' => 'Success',
                'data' => [
                    ['deviceId' => 'ABC123', 'deviceType' => 'DVR', 'isOnline' => 1, 'loginId' => 42],
                ],
            ], 200),
        ]);

        $devices = $this->makeClient()->listDevices();

        Http::assertSent(function (\Illuminate\Http\Client\Request $req) {
            return $req->hasHeader('X-Flow-Bridge-Token', 'secret-token')
                && $req->url() === 'http://bridge.test/api/devices';
        });

        $this->assertCount(1, $devices);
        $this->assertSame('ABC123', $devices[0]['deviceId']);
    }

    public function test_search_events_devuelve_el_envelope_con_events_json_como_string(): void
    {
        Http::fake([
            'bridge.test/api/devices/ABC123/events/search' => Http::response([
                'code' => 200,
                'msg' => 'Success',
                'data' => [
                    'deviceId' => 'ABC123',
                    'searchID' => 'x',
                    'status' => 'success',
                    'eventsJson' => '{"AcsEvent":{"InfoList":[]}}',
                    'sdkError' => null,
                    'rawResponseLength' => 0,
                ],
            ], 200),
        ]);

        $envelope = $this->makeClient()->searchEvents('ABC123', '2026-06-24T00:00:00-05:00', '2026-06-24T01:00:00-05:00');

        $this->assertSame(200, $envelope['code']);
        $this->assertIsString($envelope['data']['eventsJson']); // se parsea en el servicio, no aqui
    }

    public function test_feature_disabled_503_lanza_excepcion_tipada_recuperable(): void
    {
        Http::fake([
            'bridge.test/api/devices/ABC123/events/search' => Http::response([
                'code' => 503,
                'msg' => 'feature_disabled',
            ], 503),
        ]);

        $this->expectException(HikvisionFeatureDisabledException::class);

        $this->makeClient()->searchEvents('ABC123', '2026-06-24T00:00:00-05:00', '2026-06-24T01:00:00-05:00');
    }

    public function test_code_no_200_lanza_hikvision_bridge_exception(): void
    {
        Http::fake([
            'bridge.test/api/devices' => Http::response([
                'code' => 500,
                'msg' => 'Boom',
            ], 200),
        ]);

        $this->expectException(HikvisionBridgeException::class);

        $this->makeClient()->listDevices();
    }

    public function test_respuesta_no_json_lanza_hikvision_bridge_exception(): void
    {
        Http::fake([
            'bridge.test/api/devices' => Http::response('<html>not json</html>', 200),
        ]);

        $this->expectException(HikvisionBridgeException::class);

        $this->makeClient()->listDevices();
    }
}
