<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ZKTeco\ProFaceX\UploadProcessController;
use App\Http\Controllers\ZKTeco\ProFaceX\DownloadProcessController;

/*
|--------------------------------------------------------------------------
| API Routes - ZKTeco & Marcaciones Amaxonia
|--------------------------------------------------------------------------
|
| Estas rutas son para la comunicación "stateless" (sin estado), ideal
| para la comunicación con dispositivos externos como los biométricos.
| No tienen protección CSRF, a diferencia de las rutas en web.php.
|
*/

// Rutas con código de empresa (multi-tenancy)
// El dispositivo debe estar configurado para apuntar a: https://dominio.com/api/{codigo}
Route::group(['prefix' => '{codigo}', 'where' => ['codigo' => '[0-9]+'], 'middleware' => \App\Http\Middleware\EmpresaMiddleware::class], function () {

    // Rutas para dispositivos ZKTeco (protocolo Push)
    Route::prefix('iclock')->group(function () {

        // Rutas para que el dispositivo SUBA información al servidor (marcaciones, estado, etc.)
        Route::get('/cdata', [UploadProcessController::class, 'getCdata']);
        Route::post('/cdata', [UploadProcessController::class, 'postCdata']);

        // --- RUTA DE REGISTRO AÑADIDA ---
        // 3. El dispositivo se registra en el servidor. Solo necesita una respuesta OK.
        Route::post('/registry', function (Request $request) {
            Log::info('Dispositivo registrado exitosamente: ' . $request->input('SN'));
            return response('OK', 200)->header('Content-Type', 'text/plain');
        });

        // --- RUTAS DE COMANDOS CORREGIDAS ---

        // 1. El dispositivo PREGUNTA por nuevos comandos pendientes en esta ruta.
        Route::get('/getrequest', [DownloadProcessController::class, 'getRequest']);

        // 2. El dispositivo INFORMA el resultado de los comandos que ejecutó en esta ruta.
        Route::post('/devicecmd', [DownloadProcessController::class, 'postDeviceCmd']);
    });

});

// Ruta de salud del sistema (no requiere código de empresa)
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'Amaxonia ZKTeco API',
        'version' => '1.0.0',
        'timestamp' => now()->toISOString()
    ]);
});
