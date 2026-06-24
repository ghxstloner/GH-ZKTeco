<?php

/*
|--------------------------------------------------------------------------
| Configuración de la integración Hikvision (modo PULL vía ISUP Bridge)
|--------------------------------------------------------------------------
|
| Laravel NO se comunica directamente con los dispositivos Hikvision.
| En su lugar consulta un Bridge ISUP/EHome externo que ya sabe hablar
| el protocolo nativo Hikvision y expone una API REST simplificada.
|
| Flujo:
|   Laravel  ->  HTTP JSON (header X-Flow-Bridge-Token)  ->  Bridge  ->  Dispositivo (ISUP/EHome)
|
| TODOS los secretos (URL del bridge, token) se leen únicamente desde .env
| aquí en esta config; NUNCA se guardan por dispositivo en base de datos.
|
| Las variables HIK_* del lado del servidor ISUP (HIK_PUBLIC_IP, HIK_DAS_PORT,
| HIK_ISUP_KEY, etc.) son consumidas por el Bridge, no por Laravel. Se
| documentan en .env.example como referencia pero NO se usan aquí.
*/

return [

    /*
    | URL base y token del Bridge. Obligatorios para operar.
    */
    'bridge_url' => env('HIK_BRIDGE_URL'),

    'bridge_token' => env('HIK_BRIDGE_TOKEN'),

    /*
    | Timeout global en segundos para TODAS las llamadas al Bridge.
    | El Bridge usa 15s internamente para eventos; mantener >= 15.
    */
    'timeout' => env('HIK_BRIDGE_TIMEOUT', 15),

    /*
    | Consulta de eventos AcsEvent.
    */
    'events' => [
        // Tamaño de página por peticion POST /events/search (paginación searchResultPosition).
        'max_results' => env('HIK_EVENTS_MAX_RESULTS', 30),

        // Por cuántos minutos hacia atrás consultar cuando no se pasa --from/--to explícitamente.
        'lookback_minutes' => env('HIK_EVENTS_LOOKBACK_MINUTES', 10),
    ],

    // Cadencia del scheduler (en minutos), traducida a expresion cron:
    //   N > 1  =>  "slash-N asterisk asterisk asterisk asterisk"
    //   N <= 1 =>  cada minuto
    'schedule' => [
        'sync_devices_minutes' => env('HIK_SYNC_SCHEDULE_MINUTES', 5),
        'pull_events_minutes' => env('HIK_PULL_SCHEDULE_MINUTES', 1),
    ],
];
