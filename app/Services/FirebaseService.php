<?php
// app/Services/FirebaseService.php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use App\Models\FcmToken;
use Illuminate\Support\Facades\Log;

class FirebaseService
{
    private $messaging;

    public function __construct()
    {
        $factory = (new Factory)
            ->withServiceAccount(storage_path('app/firebase/firebase-service-account.json'));

        $this->messaging = $factory->createMessaging();
    }

    /**
     * Enviar notificación de planificación a un tripulante específico
     */
    public function sendPlanificacionNotification(string $crewId, string $iataAerolinea, array $planificacionData, string $accion = 'creada')
    {
        try {
            // ⚠️ OBTENER TOKENS POR crew_id + iata_aerolinea
            $tokens = FcmToken::where('crew_id', $crewId)
                ->where('iata_aerolinea', $iataAerolinea)
                ->where('last_used_at', '>', now()->subDays(30))
                ->pluck('fcm_token')
                ->toArray();

            if (empty($tokens)) {
                Log::warning("No se encontraron tokens FCM para crew_id: {$crewId} en aerolínea: {$iataAerolinea}");
                return false;
            }

            // Crear el mensaje
            $title = $accion === 'modificada' ? 'Planificación Modificada' : 'Nueva Planificación';
            $body = $this->buildNotificationBody($planificacionData, $accion);

            $notification = Notification::create($title, $body);

            $data = [
                'type' => 'planificacion_' . $accion,
                'crew_id' => $crewId,
                'iata_aerolinea' => $iataAerolinea,
                'planificacion_id' => (string)$planificacionData['id'],
                'numero_vuelo' => $planificacionData['numero_vuelo'] ?? '',
                'fecha_vuelo' => $planificacionData['fecha_vuelo'] ?? '',
                'action' => 'open_planificaciones'
            ];

            // Enviar a todos los tokens del usuario
            $messages = [];
            foreach ($tokens as $token) {
                $messages[] = CloudMessage::withTarget('token', $token)
                    ->withNotification($notification)
                    ->withData($data);
            }

            $sendReport = $this->messaging->sendAll($messages);

            Log::info("Notificaciones enviadas para crew_id {$crewId}: {$sendReport->successes()->count()} exitosas, {$sendReport->failures()->count()} fallidas");

            // Limpiar tokens inválidos
            $this->cleanInvalidTokens($sendReport, $tokens);

            return $sendReport->successes()->count() > 0;

        } catch (\Exception $e) {
            Log::error("Error enviando notificación Firebase: " . $e->getMessage());
            return false;
        }
    }

    private function buildNotificationBody(array $planificacionData, string $accion): string
    {
        $numeroVuelo = $planificacionData['numero_vuelo'] ?? 'N/A';
        $fechaVuelo = $planificacionData['fecha_vuelo'] ?? 'N/A';

        if ($accion === 'modificada') {
            return "Tu vuelo {$numeroVuelo} del {$fechaVuelo} ha sido modificado";
        } else {
            return "Nuevo vuelo asignado: {$numeroVuelo} para el {$fechaVuelo}";
        }
    }

    private function cleanInvalidTokens($sendReport, array $tokens)
    {
        $failures = $sendReport->failures();
        foreach ($failures as $failure) {
            $invalidToken = $failure->target()->value();
            Log::warning("Token FCM inválido eliminado: {$invalidToken}");
            FcmToken::where('fcm_token', $invalidToken)->delete();
        }
    }
}
