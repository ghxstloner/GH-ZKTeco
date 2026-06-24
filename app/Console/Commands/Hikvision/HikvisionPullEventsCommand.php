<?php

namespace App\Console\Commands\Hikvision;

use App\Services\Hikvision\HikvisionEventPullService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Hace PULL de marcaciones AcsEvent Hikvision desde el Bridge ISUP y las
 * persiste en `hikvision_event_log` (idempotente por DEDUP_KEY).
 *
 * Modelo DEVICE-DRIVEN: el servicio recorre los dispositivos del Bridge y
 * por cada uno resuelve el tenant via `deviceId == codigo empresa`. NO se
 * pasa --empresa: el deviceId ya lleva implicito el tenant.
 *
 * Uso:
 *   php artisan hikvision:pull-events
 *   php artisan hikvision:pull-events --from="2026-06-24T00:00:00-05:00" --to="2026-06-24T12:00:00-05:00"
 *   php artisan hikvision:pull-events --device=123
 *
 * Sin --from/--to: usa HIK_EVENTS_LOOKBACK_MINUTES (defecto 10) hasta now.
 */
class HikvisionPullEventsCommand extends Command
{
    protected $signature = 'hikvision:pull-events
                            {--from= : ISO-8601 inicio (ej: 2026-06-24T00:00:00-05:00)}
                            {--to=   : ISO-8601 fin}
                            {--device= : deviceId del Bridge para limitar a un dispositivo}';

    protected $description = 'Pull de marcaciones Hikvision desde el Bridge ISUP al tenant (hikvision_event_log) por deviceId == codigo empresa';

    public function handle(): int
    {
        $optDevice = $this->option('device');
        $optDevice = is_string($optDevice) && $optDevice !== '' ? $optDevice : null;

        $from = $this->parseTime($this->option('from'));
        $to = $this->parseTime($this->option('to'));

        $rangeLabel = '['.($from ? $from->toIso8601String() : 'auto').', '.($to ? $to->toIso8601String() : 'now').']';
        $this->info("Pull Hikvision en rango {$rangeLabel} ...");

        $stats = HikvisionEventPullService::pullRango($from, $to, $optDevice);

        $this->newLine();
        $this->info(
            'devices='.$stats['devices']
            .' inserted='.$stats['events_inserted']
            .' duplicates='.$stats['duplicates']
            .' skipped='.$stats['skipped']
            .' errors='.$stats['errors']
        );

        return self::SUCCESS;
    }

    private function parseTime(mixed $value): ?CarbonImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable $e) {
            $this->error("Fecha/hora invalida: {$value}");

            throw $e;
        }
    }
}
