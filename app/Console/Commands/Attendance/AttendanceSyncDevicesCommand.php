<?php

namespace App\Console\Commands\Attendance;

use Illuminate\Console\Command;

/**
 * Catalog general de dispositivos de asistencia (Hikvision hoy, ZKTeco
 * mañana). Fase actual: delega al sync Hikvision, que es DEVICE-DRIVEN
 * canonico (recorre el Bridge y resuelve el tenant por Device ID ISUP).
 * No acepta --empresa: el Device ID ya lleva el tenant implicito.
 *
 * Uso:
 *   php artisan attendance:sync-devices
 */
class AttendanceSyncDevicesCommand extends Command
{
    protected $signature = 'attendance:sync-devices';

    protected $description = 'Sincroniza el catalogo unificado asistencia_dispositivos (todos los drivers activos)';

    public function handle(): int
    {
        // Hikvision = DEVICE-DRIVEN canonico (recorre el Bridge y resuelve el
        // tenant desde Device ID ISUP). ZKTeco = TENANT-DRIVEN (itera
        // nomempresa y lee profacex_device_info en cada BD de tenant).
        $this->info('Sync asistencia_dispositivos (driver: hikvision) ...');
        $this->call('hikvision:sync-devices');

        $this->info('Sync asistencia_dispositivos (driver: zkteco) ...');
        $this->call('zkteco:sync-devices');

        return self::SUCCESS;
    }
}
