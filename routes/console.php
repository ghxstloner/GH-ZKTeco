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
