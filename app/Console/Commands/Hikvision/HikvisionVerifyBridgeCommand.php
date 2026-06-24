<?php

namespace App\Console\Commands\Hikvision;

use App\Services\Hikvision\HikvisionBridgeClient;
use Illuminate\Console\Command;

/**
 * Verifica la conectividad con el Bridge ISUP Hikvision: hace ping al
 * endpoint /apidevices y reporta disponibilidad + jumlah dispositivos.
 *
 * Uso:
 *   php artisan hikvision:verify-bridge
 *
 * Este comando NO necesita un tenant (sólo habla con el Bridge). Es la
 * primera verificación manual recomendada tras configurar .env.
 */
class HikvisionVerifyBridgeCommand extends Command
{
    protected $signature = 'hikvision:verify-bridge';

    protected $description = 'Verifica conectividad y token del Bridge ISUP Hikvision (GET /api.devices)';

    public function handle(HikvisionBridgeClient $client): int
    {
        $url = (string) config('hikvision.bridge_url');
        $token = (string) config('hikvision.bridge_token');

        if ($url === '') {
            $this->error('HIK_BRIDGE_URL no esta configurado en .env');

            return self::FAILURE;
        }
        if ($token === '') {
            $this->warn('HIK_BRIDGE_TOKEN esta vacio en .env (algunos bridges lo rechazan).');
        }

        $this->info("Conectando a {$url} ...");

        try {
            $devices = $client->listDevices();
        } catch (\Throwable $e) {
            $this->error('Fallo al conectar con el Bridge: '.$e->getMessage());

            return self::FAILURE;
        }

        $count = count($devices);
        $this->info("OK: el Bridge respondio. Dispositivos conocidos: {$count}");

        if ($count > 0) {
            $this->table(
                ['deviceId', 'deviceType', 'isOnline'],
                array_map(static fn ($d) => [
                    $d['deviceId'] ?? '?',
                    $d['deviceType'] ?? '?',
                    (int) ($d['isOnline'] ?? 0) === 1 ? 'SI' : 'no',
                ], $devices),
            );
        }

        return self::SUCCESS;
    }
}
