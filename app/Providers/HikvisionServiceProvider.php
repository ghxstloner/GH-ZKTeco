<?php

namespace App\Providers;

use App\Services\Hikvision\HikvisionBridgeClient;
use Illuminate\Support\ServiceProvider;

/**
 * Registra el cliente del Bridge Hikvision como singleton del contenedor.
 *
 * El cliente construye su configuración (url, token, timeout) una sola vez
 * desde config('hikvision.*') y reutiliza una instancia PendingRequest. Como
 * lo usan tanto los servicios estáticos (sync/events/provisioning) como los
 * comandos Artisan, conviene un único binding singleton en lugar de leer la
 * config en cada llamada.
 */
class HikvisionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HikvisionBridgeClient::class, function ($app) {
            return new HikvisionBridgeClient(
                bridgeUrl: (string) config('hikvision.bridge_url', ''),
                bridgeToken: (string) config('hikvision.bridge_token', ''),
                timeout: (int) config('hikvision.timeout', 15),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
