<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Integracion Hikvision (PULL via ISUP Bridge)
|--------------------------------------------------------------------------
|
| Este proyecto usa el bootstrap fluido de Laravel 12 (Application::configure
| en bootstrap/app.php), por lo que app/Console/Kernel.php::schedule() NO es
| invocado. Las cadencias se registran aqui con la facade Schedule::command().
|
| Cadencia configurable en minutos desde .env:
|   N > 1  =>  expresion cron con "slash-N" entre los minutos
|   N <= 1 =>  cada minuto
*/
Schedule::command('hikvision:sync-devices')
    ->cron(((int) env('HIK_SYNC_SCHEDULE_MINUTES', 5)) > 1
        ? '*/'.(int) env('HIK_SYNC_SCHEDULE_MINUTES', 5).' * * * *'
        : '* * * * *')
    ->name('hik-sync-devices')
    ->withoutOverlapping();

Schedule::command('hikvision:pull-events')
    ->cron(((int) env('HIK_PULL_SCHEDULE_MINUTES', 1)) > 1
        ? '*/'.(int) env('HIK_PULL_SCHEDULE_MINUTES', 1).' * * * *'
        : '* * * * *')
    ->name('hik-pull-events')
    ->withoutOverlapping();

// Drena `hikvision_event_log.PROCESSED=0` hacia AmaxoniaMarcacionService
// (nomina) por cada tenant activo. Corre inmediatamente despues del pull
// para cerrar el ciclo dispositivo -> nomina en un mismo tick.
Schedule::command('hikvision:process-events')
    ->cron(((int) env('HIK_PROCESS_SCHEDULE_MINUTES', 1)) > 1
        ? '*/'.(int) env('HIK_PROCESS_SCHEDULE_MINUTES', 1).' * * * *'
        : '* * * * *')
    ->name('hik-process-events')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Integracion ZKTeco/ProFaceX (tenant-driven, sin Bridge)
|--------------------------------------------------------------------------
|
| A diferencia de Hikvision, ZKTeco no tiene un Bridge central; los
| dispositivos se autoregistran por PUSH en la BD de cada tenant. Esta
| tarea recorre las empresas activas de `nomempresa` y vuelca
| `profacex_device_info` al catalogo unificado `asistencia_dispositivos`.
|
| Cadencia configurable desde .env (default 5 min — los dispositivos ya
| notifican su heartbeat al ser consultados o por PUSH, no necesitan
| minuto a minuto).
*/
Schedule::command('zkteco:sync-devices')
    ->cron(((int) env('ZK_SYNC_SCHEDULE_MINUTES', 5)) > 1
        ? '*/'.(int) env('ZK_SYNC_SCHEDULE_MINUTES', 5).' * * * *'
        : '* * * * *')
    ->name('zk-sync-devices')
    ->withoutOverlapping();
