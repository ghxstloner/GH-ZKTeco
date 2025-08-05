<?php

use App\Http\Controllers\ZKTeco\ProFaceX\DownloadProcessController;
use App\Http\Controllers\ZKTeco\ProFaceX\UploadProcessController;
use Illuminate\Support\Facades\Route;

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
