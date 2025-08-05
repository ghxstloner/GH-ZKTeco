<?php
// app/Http/Controllers/Api/NotificationController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FcmToken;
use App\Models\TripulanteSolicitud;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    private $firebaseService;

    public function __construct(FirebaseService $firebaseService)
    {
        $this->firebaseService = $firebaseService;
    }

    /**
     * ENDPOINT PRINCIPAL - El sistema externo llama este endpoint
     * cuando crea o modifica una planificación
     */
    public function planificacionChanged(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'crew_id' => 'required|string|max:10',
                'iata_aerolinea' => 'required|string|max:2', // ⚠️ NUEVO CAMPO REQUERIDO
                'accion' => 'required|in:creada,modificada',
                'planificacion' => 'required|array',
                'planificacion.id' => 'required|integer',
                'planificacion.numero_vuelo' => 'nullable|string',
                'planificacion.fecha_vuelo' => 'nullable|date',
                'planificacion.hora_vuelo' => 'nullable',
                'planificacion.iata_aerolinea' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $crewId = $request->crew_id;
            $iataAerolinea = $request->iata_aerolinea; // ⚠️ NUEVO
            $accion = $request->accion;
            $planificacionData = $request->planificacion;

            // ⚠️ BUSCAR POR crew_id + iata_aerolinea (combinación única)
            $tripulante = TripulanteSolicitud::where('crew_id', $crewId)
                ->where('iata_aerolinea', $iataAerolinea)
                ->where('estado', 'Aprobado')
                ->where('activo', true)
                ->first();

            if (!$tripulante) {
                return response()->json([
                    'success' => false,
                    'message' => "Tripulante {$crewId} de aerolínea {$iataAerolinea} no encontrado o no activo"
                ], 404);
            }

            // Enviar notificación
            $enviado = $this->firebaseService->sendPlanificacionNotification(
                $crewId,
                $iataAerolinea, // ⚠️ PASAR TAMBIÉN LA AEROLÍNEA
                $planificacionData,
                $accion
            );

            if ($enviado) {
                Log::info("Notificación enviada exitosamente", [
                    'crew_id' => $crewId,
                    'iata_aerolinea' => $iataAerolinea,
                    'accion' => $accion,
                    'planificacion_id' => $planificacionData['id']
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Notificación enviada exitosamente',
                    'data' => [
                        'crew_id' => $crewId,
                        'iata_aerolinea' => $iataAerolinea,
                        'accion' => $accion,
                        'notificacion_enviada' => true
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo enviar la notificación (posiblemente no hay tokens FCM registrados)',
                    'data' => [
                        'crew_id' => $crewId,
                        'iata_aerolinea' => $iataAerolinea,
                        'notificacion_enviada' => false
                    ]
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('Error en planificacionChanged: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Registrar/actualizar token FCM del dispositivo del tripulante
     */
    public function registerFcmToken(Request $request): JsonResponse
    {
        try {
            $tripulante = $request->user();

            if (!$tripulante || !$tripulante->isApproved() || !$tripulante->activo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autorizado'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'fcm_token' => 'required|string',
                'platform' => 'required|in:ios,android',
                'device_info' => 'nullable|array'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token FCM requerido',
                    'errors' => $validator->errors()
                ], 422);
            }

            // ⚠️ BUSCAR TOKEN POR crew_id + iata_aerolinea + fcm_token
            $fcmToken = FcmToken::updateOrCreate(
                [
                    'crew_id' => $tripulante->crew_id,
                    'iata_aerolinea' => $tripulante->iata_aerolinea, // ⚠️ NUEVO
                    'fcm_token' => $request->fcm_token
                ],
                [
                    'platform' => $request->platform,
                    'device_info' => $request->device_info,
                    'last_used_at' => now()
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Token FCM registrado exitosamente',
                'data' => [
                    'crew_id' => $tripulante->crew_id,
                    'iata_aerolinea' => $tripulante->iata_aerolinea,
                    'token_id' => $fcmToken->id,
                    'registered_at' => $fcmToken->updated_at->format('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error registrando token FCM: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al registrar token FCM',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Eliminar token FCM (logout, desinstalación, etc.)
     */
    public function removeFcmToken(Request $request): JsonResponse
    {
        try {
            $tripulante = $request->user();

            if (!$tripulante) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autorizado'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'fcm_token' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token FCM requerido',
                    'errors' => $validator->errors()
                ], 422);
            }

            // ⚠️ ELIMINAR POR crew_id + iata_aerolinea + fcm_token
            $deleted = FcmToken::where('crew_id', $tripulante->crew_id)
                ->where('iata_aerolinea', $tripulante->iata_aerolinea) // ⚠️ NUEVO
                ->where('fcm_token', $request->fcm_token)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Token FCM eliminado exitosamente',
                'data' => [
                    'tokens_eliminados' => $deleted
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error eliminando token FCM: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar token FCM',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }
}
