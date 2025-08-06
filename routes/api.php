<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ZKTeco\ProFaceX\UploadProcessController;
use App\Http\Controllers\ZKTeco\ProFaceX\DownloadProcessController;

/*
|--------------------------------------------------------------------------
| API Routes - ZKTeco & Marcaciones Amaxonia
|--------------------------------------------------------------------------
*/

// Rutas con código de empresa (multi-tenancy)
Route::group(['prefix' => '{codigo}', 'where' => ['codigo' => '[0-9]+'], 'middleware' => \App\Http\Middleware\EmpresaMiddleware::class], function () {

    // Rutas para dispositivos ZKTeco con empresa
    Route::prefix('iclock')->group(function () {
        Route::get('/cdata', [UploadProcessController::class, 'getCdata']);
        Route::post('/cdata', [UploadProcessController::class, 'postCdata']);
        Route::get('/devicecmd', [DownloadProcessController::class, 'getRequest']);
        Route::post('/devicecmd', [DownloadProcessController::class, 'postDeviceCmd']);
    });

});

// Ruta de salud del sistema
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'Amaxonia ZKTeco API',
        'version' => '1.0.0',
        'timestamp' => now()->toISOString()
    ]);
});
