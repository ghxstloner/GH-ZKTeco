<?php

namespace App\Console\Commands\Hikvision;

use App\Services\AmaxoniaMarcacionService;
use Illuminate\Console\Command;

/**
 * Drena eventos de marcacion Hikvision pendientes hacia nomina.
 *
 * Lee `hikvision_event_log.PROCESSED=0` de TODOS los tenants activos de
 * `nomempresa` y los entrega a
 * `AmaxoniaMarcacionService::procesarMarcacion` (mismo pipeline que las
 * marcaciones ZKTeco via `profacex_att_log`).
 *
 * Al terminar marca `PROCESSED=1` (con `SYNC_ERROR` poblado si la fila falla),
 * para que el siguiente ciclo no vuelva a intentar el mismo evento.
 *
 * Diseno tenant-driven: `procesarMarcacion()` usa internamente
 * `DatabaseSwitchService::getConexionEmpresa()`, por lo que el drenador
 * conmuta el tenant una vez por empresa activa.
 *
 * Cadencia habitual: cada 1 minuto via Schedule (routes/console.php),
 * inmediatamente despues del `hikvision:pull-events` que persiste los
 * eventos.
 *
 * Uso:
 *   php artisan hikvision:process-events
 */
class HikvisionProcessEventsCommand extends Command
{
    protected $signature = 'hikvision:process-events';

    protected $description = 'Drena hikvision_event_log.PROCESSED=0 hacia AmaxoniaMarcacionService (nomina) por cada tenant activo';

    public function handle(): int
    {
        $stats = AmaxoniaMarcacionService::procesarEventosHikvisionPendientes();

        $this->info(
            'empresas='.$stats['empresas']
            .' procesados='.$stats['procesados']
            .' errores='.$stats['errores']
            .' total='.$stats['total']
        );

        return self::SUCCESS;
    }
}
