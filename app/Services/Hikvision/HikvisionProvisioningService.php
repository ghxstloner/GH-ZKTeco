<?php

namespace App\Services\Hikvision;

use App\Models\Hikvision\HikUserInfo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Provisioning de usuarios Hikvision via Bridge ISUP.
 *
 * Por cada (dispositivo, employeeNo): upsert en `hikvision_user_info` con
 * SYNC_STATUS inicial 'pending'; luego llama al Bridge y, según el
 * resultado, marca uno de:
 *
 *   synced | failed | offline | validation_error | feature_disabled |
 *   unauthorized | not_found | pending
 *
 * NO se llama a DELETE /users ({employeeNo}) porque el Bridge lo retorna
 * 501 / not_implemented. Queda como TODO para cuando el Bridge lo soporte.
 *
 * Requisito: conexion tenant `empresa` ya configurada (caller responsable).
 */
class HikvisionProvisioningService
{
    /**
     * Crea/actualiza un usuario en el dispositivo y opcionalmente sube foto.
     *
     * @param  string  $deviceSerial     Serial real del dispositivo (DEVICE_SERIAL).
     * @param  string  $bridgeDeviceId   deviceId delegate del Bridge.
     * @param  string  $employeeNo
     * @param  array{
     *     ficha:int,
     *     name?:?string,
     * }  $user
     * @param  string|null  $photoPath    Ruta absoluta a la foto a subir.
     */
    public static function provisionar(
        string $deviceSerial,
        string $bridgeDeviceId,
        string $employeeNo,
        array $user,
        ?string $photoPath = null,
    ): HikUserInfo {
        $client = app(HikvisionBridgeClient::class);

        $conn = DB::connection('empresa');
        $now = Carbon::now();

        // 1) Upsert en hikvision_user_info con SYNC_STATUS pending.
        $existing = $conn->table('hikvision_user_info')
            ->where('DEVICE_SERIAL', $deviceSerial)
            ->where('EMPLOYEE_NO', $employeeNo)
            ->first();

        $baseRow = [
            'FICHA' => (int) ($user['ficha'] ?? 0),
            'NAME' => $user['name'] ?? null,
            'PHOTO_SYNCED' => 0,
            'SYNC_STATUS' => 'pending',
            'SYNC_ERROR' => null,
            'updated_at' => $now,
        ];

        if ($existing) {
            $conn->table('hikvision_user_info')
                ->where('USER_ID', $existing->USER_ID)
                ->update($baseRow);
            $userId = $existing->USER_ID;
        } else {
            $baseRow['DEVICE_SERIAL'] = $deviceSerial;
            $baseRow['EMPLOYEE_NO'] = $employeeNo;
            $baseRow['created_at'] = $now;
            $userId = $conn->table('hikvision_user_info')->insertGetId($baseRow);
        }

        // 2) Llamar al Bridge: PUT /users/{employeeNo} (+ foto opcional).
        try {
            $payload = [
                'employeeNo' => $employeeNo,
                'name' => $user['name'] ?? '',
            ];
            $client->syncUser($bridgeDeviceId, $employeeNo, $payload, $photoPath);

            $syncStatus = 'synced';
            $syncError = null;
            $photoSynced = $photoPath !== null ? 1 : 0;

            // Si subió foto exitosamente, intentar además syncFace (opcional).
            if ($photoPath !== null) {
                try {
                    $client->syncFace($bridgeDeviceId, $employeeNo, $photoPath);
                    $photoSynced = 1;
                } catch (\Throwable $faceErr) {
                    // syncUser ya tuvo éxito; la cara fallar no es fatal.
                    Log::warning('[HikvisionProvisioning] syncFace fallo {emp}@{serial}: {err}', [
                        'emp' => $employeeNo,
                        'serial' => $deviceSerial,
                        'err' => $faceErr->getMessage(),
                    ]);
                }
            }
        } catch (HikvisionFeatureDisabledException $e) {
            $syncStatus = 'feature_disabled';
            $syncError = $e->getMessage();
            $photoSynced = 0;
        } catch (HikvisionBridgeException $e) {
            [$syncStatus, $syncError] = static::classifyError($e);
            $photoSynced = 0;
        } catch (\Throwable $e) {
            $syncStatus = 'failed';
            $syncError = $e->getMessage();
            $photoSynced = 0;
        }

        $conn->table('hikvision_user_info')
            ->where('USER_ID', $userId)
            ->update([
                'SYNC_STATUS' => $syncStatus,
                'SYNC_ERROR' => $syncError,
                'PHOTO_SYNCED' => $photoSynced,
                'LAST_SYNCED_AT' => $syncStatus === 'synced' ? Carbon::now() : null,
                'updated_at' => Carbon::now(),
            ]);

        Log::info('[HikvisionProvisioning] {status} usuario {emp}@{serial}', [
            'status' => $syncStatus,
            'emp' => $employeeNo,
            'serial' => $deviceSerial,
        ]);

        return HikUserInfo::find($userId);
    }

    /**
     * Verifica (sin modificar) si un usuario existe en el dispositivo.
     *
     * @return array{exists:bool, data:?array}
     */
    public static function verificar(string $bridgeDeviceId, string $employeeNo): array
    {
        $client = app(HikvisionBridgeClient::class);

        try {
            $data = $client->verifyUser($bridgeDeviceId, $employeeNo);

            return ['exists' => $data !== null, 'data' => $data];
        } catch (HikvisionFeatureDisabledException $e) {
            return ['exists' => false, 'data' => null];
        }
    }

    /**
     * TODO deleteUser: el Bridge retorna 501 / not_implemented para
     * DELETE /api/devices/{deviceId}/users/{employeeNo}. Cuando lo soporte,
     * añadir HikvisionBridgeClient::deleteUser() y un método aquí que lo use.
     */

    /**
     * Clasifica un HikvisionBridgeException en SYNC_STATUS legible.
     *
     * @return array{0:string,1:?string}
     */
    private static function classifyError(HikvisionBridgeException $e): array
    {
        $status = $e->httpStatus ?? 0;
        $msg = mb_strtolower($e->getMessage());

        return match (true) {
            $status === 401 || $status === 403 => ['unauthorized', $e->getMessage()],
            $status === 404 || str_contains($msg, 'not_found') => ['not_found', $e->getMessage()],
            $status === 502 || $status === 503 => ['offline', $e->getMessage()],
            str_contains($msg, 'validation') => ['validation_error', $e->getMessage()],
            default => ['failed', $e->getMessage()],
        };
    }
}
