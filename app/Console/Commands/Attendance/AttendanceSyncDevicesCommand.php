<?php

namespace App\Console\Commands\Attendance;

use Illuminate\Console\Command;

/**
 * Catalog general de dispositivos de asistencia (Hikvision hoy, ZKTeco
 * mañana). Fase actual: delega al sync Hikvision, que es DEVICE-DRIVEN
 * (recorre el Bridge y resuelve el tenant por deviceId == codigo).
 * No acepta -- empresa: el deviceId ya lleva el tenant implicito.
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
        // Fase actual: solo Hikvision. Futuro: anadir rama ZKTeco sin tocar
        // app/Services/ZKTeco (solo un wrapper que llame a AsistenciaDispositivoService).
        $this->info('Sync asistencia_dispositivos (driver: hikvision) ...');

        $this->call('hikvision:sync-devices');

        return self::SUCCESS;
    }
}
