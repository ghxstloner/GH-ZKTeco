<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        Commands\DumpSchema::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * NOTA: en este proyecto (Laravel 12 con bootstrap fluido via
     * bootstrap/app.php Application::configure()) este método está OBSOLETO.
     * Las cadencias scheduladas se registran en routes/console.php con la
     * facade Schedule::. Se mantiene el stub por compatibilidad.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire:daily')->hourly();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
