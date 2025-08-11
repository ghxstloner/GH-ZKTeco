<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// ELIMINA O COMENTA ESTO DE AQUÍ
/*
Route::group([
    'prefix' => 'iclock'
], function () {
    Route::group([
        'prefix' => 'cdata'
    ], function () {
        Route::get('', [UploadProcessController::class, 'getCdata']);
        Route::post('', [UploadProcessController::class, 'postCdata']);
    });

    Route::get('/getrequest', [DownloadProcessController::class, 'getRequest']);
    Route::post('/devicecmd', [DownloadProcessController::class, 'postDeviceCmd']);
});
*/

// Aquí pueden ir otras rutas de tu aplicación web, si las tienes.
Route::get('/', function () {
    return view('welcome');
});
