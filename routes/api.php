<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ZKTeco\ProFaceX\UploadProcessController;
use App\Http\Controllers\ZKTeco\ProFaceX\DownloadProcessController;
use App\Http\Controllers\Api\MarcacionController;

/*
|--------------------------------------------------------------------------
| API Routes - ZKTeco & Marcaciones Amaxonia
|--------------------------------------------------------------------------
*/

// Rutas con código de empresa (multi-tenancy)
Route::group(['prefix' => '{codEmpresa}', 'where' => ['codEmpresa' => '[0-9]+'], 'middleware' => 'empresa'], function () {
    
    // Rutas para dispositivos ZKTeco con empresa
    Route::prefix('iclock')->group(function () {
        Route::get('/cdata', [UploadProcessController::class, 'getCdata']);
        Route::post('/cdata', [UploadProcessController::class, 'postCdata']);
        Route::get('/devicecmd', [DownloadProcessController::class, 'getRequest']);
        Route::post('/devicecmd', [DownloadProcessController::class, 'postDeviceCmd']);
    });

    // Rutas para marcaciones de aplicaciones móviles con empresa
    Route::prefix('marcacion')->group(function () {
        Route::post('/procesar', [MarcacionController::class, 'procesarMarcacion']);
        Route::get('/obtener', [MarcacionController::class, 'obtenerMarcaciones']);
        Route::get('/estado', [MarcacionController::class, 'verificarEstado']);
        Route::get('/empresa', [MarcacionController::class, 'obtenerEmpresaInfo']);
    });
});

// Rutas sin código de empresa (para compatibilidad)
Route::prefix('iclock')->group(function () {
    Route::get('/cdata', [UploadProcessController::class, 'getCdata']);
    Route::post('/cdata', [UploadProcessController::class, 'postCdata']);
    Route::get('/devicecmd', [DownloadProcessController::class, 'getRequest']);
    Route::post('/devicecmd', [DownloadProcessController::class, 'postDeviceCmd']);
});

Route::prefix('marcacion')->group(function () {
    Route::post('/procesar', [MarcacionController::class, 'procesarMarcacion']);
    Route::get('/obtener', [MarcacionController::class, 'obtenerMarcaciones']);
    Route::get('/estado', [MarcacionController::class, 'verificarEstado']);
    Route::get('/empresa', [MarcacionController::class, 'obtenerEmpresaInfo']);
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
