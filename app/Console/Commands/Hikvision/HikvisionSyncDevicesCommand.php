<?php

namespace App\Console\Commands\Hikvision;

use App\Services\Hikvision\HikvisionDeviceSyncService;
use Illuminate\Console\Command;

/**
 * Sincroniza los dispositivos Hikvision del Bridge ISUP al catalogo tenant.
 *
 * Modelo DEVICE-DRIVEN: el servicio lee todos los dispositivos del Bridge,
 * resuelve el tenant de cada uno por `deviceId == codigo empresa`, conmuta
 * la conexion `empresa` y hace upsert en `hikvision_device_info` +
 * `asistencia_dispositivos` de ESA BD.
 *
 * No requiere --empresa: el deviceId YA lleva implicito el tenant destino.
 *
 * Uso:
 *   php artisan hikvision:sync-devices
 */
class HikvisionSyncDevicesCommand extends Command
{
    protected $signature = 'hikvision:sync-devices';

    protected $description = 'Sincroniza dispositivos Hikvision desde el Bridge ISUP al tenant (hikvision_device_info + asistencia_dispositivos) por deviceId == codigo empresa';

    public function handle(): int
    {
        $this->info('Sincronizando dispositivos Hikvision desde el Bridge...');

        $stats = HikvisionDeviceSyncService::sincronizar();

        $this->newLine();
        $this->info(
            'devices='.$stats['devices']
            .' updated='.$stats['updated']
            .' skipped='.$stats['skipped']
            .' errors='.$stats['errors']
        );

        return self::SUCCESS;
    }
}
