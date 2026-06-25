<?php

namespace App\Console\Commands\ZKTeco;

use App\Services\ZKTeco\ZktecoDeviceSyncService;
use Illuminate\Console\Command;

/**
 * Sincroniza los dispositivos ZKTeco/ProFaceX hacia el catalogo unificado
 * `asistencia_dispositivos`.
 *
 * Modelo TENANT-DRIVEN: itera las empresas activas de `nomempresa`, conmuta
 * la conexion `empresa` a cada una, lee `profacex_device_info` y hace upsert
 * en `asistencia_dispositivos` con driver='zkteco'.
 *
 * Uso:
 *   php artisan zkteco:sync-devices                 # todas las empresas activas
 *   php artisan zkteco:sync-devices --empresa=123   # solo una empresa
 */
class ZktecoSyncDevicesCommand extends Command
{
    protected $signature = 'zkteco:sync-devices
                            {--empresa= : Código de empresa (nomempresa.codigo) para limitar a una sola}';

    protected $description = 'Sincroniza dispositivos ZKTeco/ProFaceX desde profacex_device_info al catalogo unificado asistencia_dispositivos';

    public function handle(): int
    {
        $only = $this->option('empresa');
        $only = is_string($only) && $only !== '' ? $only : null;

        if ($only !== null) {
            $this->info("Sincronizando dispositivos ZKTeco de la empresa {$only} ...");
        } else {
            $this->info('Sincronizando dispositivos ZKTeco de todas las empresas activas ...');
        }

        $stats = ZktecoDeviceSyncService::sincronizar($only);

        $this->newLine();
        $this->info(
            'tenants='.$stats['tenants']
            .' devices='.$stats['devices']
            .' updated='.$stats['updated']
            .' skipped='.$stats['skipped']
            .' errors='.$stats['errors']
        );

        return self::SUCCESS;
    }
}
