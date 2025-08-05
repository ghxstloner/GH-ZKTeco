<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TripulanteApiController;
use App\Http\Controllers\Api\DispositivosApiController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\NotificationController;

// ============================================================================
// RUTAS ORIGINALES PARA DISPOSITIVOS ZKTECO (NO MODIFICADAS)
// ============================================================================

// Rutas API para gestión de tripulantes y dispositivos ZKTeco
Route::middleware([\App\Http\Middleware\ApiTokenMiddleware::class])->group(function () {
    Route::post('/tripulantes', [DispositivosApiController::class, 'store']);
    Route::post('/tripulantes/sync-devices', [DispositivosApiController::class, 'syncDevices']);
    Route::post('/tripulantes/clear-devices', [DispositivosApiController::class, 'clearDevices']);
});

// ============================================================================
// RUTAS PÚBLICAS (SIN AUTENTICACIÓN - PARA REGISTRO Y LOGIN)
// ============================================================================

// Rutas de autenticación públicas
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/initiate-register', [AuthController::class, 'initiateRegister']);
    Route::post('/verify-email', [AuthController::class, 'verifyEmailAndRegister']);
    Route::post('/resend-pin', [AuthController::class, 'resendVerificationPin']);
    Route::post('/check-status', [AuthController::class, 'checkStatus']);
});

// Datos públicos necesarios para el registro
Route::get('/posiciones', [AuthController::class, 'posiciones']);
Route::get('/aerolineas', [AuthController::class, 'aerolineas']); // ← NUEVA

// Endpoint PÚBLICO para el sistema externo (sin autenticación)
Route::post('/notifications/planificacion-changed', [NotificationController::class, 'planificacionChanged']);

// ============================================================================
// RUTAS PROTEGIDAS POR SANCTUM (REQUIEREN AUTENTICACIÓN)
// ============================================================================

Route::middleware(['auth:sanctum'])->group(function () {

    // === Rutas de Autenticación Protegidas ===
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });

    // === Rutas específicas para Tripulantes ===
    Route::prefix('tripulante')->group(function () {
        // Planificaciones del tripulante
        Route::get('/planificaciones', [TripulanteApiController::class, 'getPlanificaciones']);
        Route::get('/planificaciones/{id}', [TripulanteApiController::class, 'getPlanificacion']);
        Route::get('/planificaciones/{id}/marcacion', [TripulanteApiController::class, 'getMarcacionInfo']);

        // Perfil del tripulante
        Route::get('/profile', [TripulanteApiController::class, 'getProfile']);
        Route::put('/profile', [TripulanteApiController::class, 'updateProfile']);
        Route::post('/change-password', [TripulanteApiController::class, 'changePassword']);
    });

    // === Rutas de Gestión de Tripulantes (CRUD - Para administradores) ===
    Route::prefix('crew')->group(function () {
        Route::get('/', [TripulanteApiController::class, 'index']);
        Route::post('/', [TripulanteApiController::class, 'store']);
        Route::get('/{id}', [TripulanteApiController::class, 'show']);
        Route::put('/{id}', [TripulanteApiController::class, 'update']);
        Route::delete('/{id}', [TripulanteApiController::class, 'destroy']);
    });

    Route::post('/notifications/register-fcm-token', [NotificationController::class, 'registerFcmToken']);
    Route::delete('/notifications/remove-fcm-token', [NotificationController::class, 'removeFcmToken']);

});
