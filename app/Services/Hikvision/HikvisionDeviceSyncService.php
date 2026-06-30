<?php

namespace App\Services\Hikvision;

use App\Models\Attendance\AsistenciaDispositivo;
use App\Services\Attendance\AsistenciaDispositivoService;
use App\Services\DatabaseSwitchService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Sincroniza los dispositivos Hikvision desde el Bridge ISUP hacia
 * `hikvision_device_info` y `asistencia_dispositivos`.
 *
 * Modelo DEVICE-DRIVEN (no tenant-driven): el `deviceId` configurado en
 * cada dispositivo físico Hikvision ES EXACTAMENTE el `codigo` de la
 * empresa en `nomempresa`. Por tanto:
 *
 *  1. GET /api/devices del Bridge.
 *  2. Por cada dispositivo conocido por el Bridge:
     *      a. Normalizar `deviceId` ISUP como empresa_codigo*1000+secuencia.
     *      b. resolverTenantPorCodigo(): buscar en `nomempresa.codigo` y, si
     *         existe, hacer `DatabaseSwitchService::setBdEmpresa($empresa)` para
     *         apuntar la conexion `empresa` a la BD de esa empresa.
 *      c. UPSERT en hikvision_device_info y asistencia_dispositivos de ESA BD.
 *      d. Si online, enriquecer con GET /device-info (serial, model, fw, type).
 *  3. deviceIds que no existan en nomempresa se omiten con warning (no fallan).
 *  4. Un dispositivo fallido por otra razón NO aborta al resto.
 *
 * NO requiere que el caller haya hecho setBdEmpresa() antes: el propio
 * servicio resuelve y conmuta el tenant por cada dispositivo.
 */
class HikvisionDeviceSyncService
{
    /**
     * @return array{devices:int, updated:int, skipped:int, errors:int}
     */
    public static function sincronizar(): array
    {
        $client = app(HikvisionBridgeClient::class);

        $stats = ['devices' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        try {
            $bridgeDevices = $client->listDevices();
        } catch (HikvisionFeatureDisabledException $e) {
            Log::warning('[HikvisionDeviceSync] listDevices feature_disabled: '.$e->getMessage());

            return $stats;
        } catch (\Throwable $e) {
            Log::error('[HikvisionDeviceSync] listDevices fallo: '.$e->getMessage());
            throw $e;
        }

        $stats['devices'] = count($bridgeDevices);

        foreach ($bridgeDevices as $bd) {
            $bridgeDeviceId = (string) ($bd['deviceId'] ?? '');

            $identity = HikvisionDeviceIdentityResolver::resolve($bridgeDeviceId);
            if ($identity === null) {
                Log::warning('[HikvisionDeviceSync] deviceId invalido, no es codigo de empresa: {id}', [
                    'id' => $bridgeDeviceId ?: '(vacio)',
                ]);
                ++$stats['errors'];
                continue;
            }

            // Resolver tenant desde el Device ID ISUP canonico. Si no existe
            // en nomempresa, se omite silenciosamente.
            $empresa = static::resolverTenantPorCodigo($identity->empresaCodigo);
            if ($empresa === null) {
                ++$stats['skipped'];
                continue;
            }

            try {
                $isOnline = (int) ($bd['isOnline'] ?? 0) === 1;
                static::upsertOne($client, $identity, $isOnline, $bd, $empresa);
                ++$stats['updated'];
            } catch (\Throwable $e) {
                ++$stats['errors'];
                Log::error('[HikvisionDeviceSync] error con dispositivo {id} (empresa {cod}): {err}', [
                    'id' => $bridgeDeviceId,
                    'cod' => $identity->empresaCodigo,
                    'err' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[HikvisionDeviceSync] sincronizacion completada', $stats);

        return $stats;
    }

    /**
     * Verifica que el codigo exista en nomempresa (activa) y conmuta la
     * conexion `empresa` a su BD. Devuelve la fila empresa o null si el
     * codigo no corresponde a ningun tenant (dispositivo ajeno).
     *
     * @return array<string,mixed>|null
     */
    private static function resolverTenantPorCodigo(string $codigo): ?array
    {
        $existe = DB::connection('mysql')
            ->table('nomempresa')
            ->where('codigo', $codigo)
            ->where('nomina_activo', 1)
            ->exists();

        if (!$existe) {
            Log::info('[HikvisionDeviceSync] deviceId {cod} no corresponde a ninguna empresa activa, se omite', [
                'cod' => $codigo,
            ]);

            return null;
        }

        // setBdEmpresa ya valida existencia de la fila y prueba la conexion.
        DatabaseSwitchService::setBdEmpresa($codigo);

        return DatabaseSwitchService::getEmpresaActual();
    }

    /**
     * Upsert de un dispositivo en ambas tablas, en la BD tenant YA conmutada
     * por el caller (resolverTenantPorCodigo) para que coincida con
     * Device ID ISUP canonico.
     *
     * @param  array<string,mixed>  $bridgeDevice  Fila cruda de GET /api/devices
     * @param  array<string,mixed>  $empresa       Fila empresa activa (DatabaseSwitchService::getEmpresaActual())
     */
    private static function upsertOne(
        HikvisionBridgeClient $client,
        HikvisionDeviceIdentity $identity,
        bool $isOnline,
        array $bridgeDevice,
        array $empresa,
    ): void {
        $now = Carbon::now();
        $conn = DB::connection('empresa');
        $bridgeDeviceId = $identity->rawDeviceId;

        // 1) Intentar enriquecer con device-info (solo si online).
        $info = null;
        if ($isOnline) {
            try {
                $info = $client->getDeviceInfo($bridgeDeviceId);
            } catch (HikvisionFeatureDisabledException $e) {
                // raw-isapi apagado: no es fatal, seguimos con lo que tengamos.
                Log::debug('[HikvisionDeviceSync] device-info feature_disabled {id}', ['id' => $bridgeDeviceId]);
            } catch (\Throwable $e) {
                // Dispositivo offline, timeout del dispositivo, etc: log + continuar.
                Log::warning('[HikvisionDeviceSync] device-info fallo {id}: {err}', [
                    'id' => $bridgeDeviceId,
                    'err' => $e->getMessage(),
                ]);
            }
        }

        $serial = (string) ($info['serialNumber'] ?? $identity->canonicalDeviceId);
        $name = (string) ($info['deviceName'] ?? ('Hikvision '.$bridgeDeviceId));
        $model = $info['model'] ?? null;
        $firmware = $info['firmwareVersion'] ?? null;
        $deviceType = $info['hikDeviceType'] ?? ($bridgeDevice['deviceType'] ?? null);
        $macAddress = HikvisionDeviceIdentityResolver::extractMacAddress($info ?? []);
        $physicalDeviceKey = HikvisionDeviceIdentityResolver::physicalDeviceKey($macAddress);

        // 2) Upsert en hikvision_device_info por identidad estable, no por serial.
        $existing = static::findExistingHikDeviceInfo(
            conn: $conn,
            identity: $identity,
            physicalDeviceKey: $physicalDeviceKey,
            serial: $serial,
        );

        $payload = [
            'DEVICE_NAME' => $name,
            'BRIDGE_DEVICE_ID' => $bridgeDeviceId,
            'FW_VERSION' => $firmware,
            'DEVICE_TYPE' => $deviceType,
            'STATE' => $isOnline ? 'Online' : 'Offline',
            'IS_ACTIVE' => $isOnline ? 1 : 0,
            'TRANSPORT_MODE' => 'bridge',
            'BRIDGE_URL' => (string) config('hikvision.bridge_url'),
            'LAST_ACTIVITY' => $isOnline ? $now : null,
            'LAST_POLLED_AT' => $now,
            'updated_at' => $now,
        ];

        foreach ([
            'ISUP_DEVICE_ID_RAW' => $identity->rawDeviceId,
            'ISUP_DEVICE_ID_CANONICAL' => $identity->canonicalDeviceId,
            'DEVICE_SEQUENCE' => $identity->deviceSequence,
            'MAC_ADDRESS' => $macAddress,
            'PHYSICAL_DEVICE_KEY' => $physicalDeviceKey,
        ] as $column => $value) {
            if (static::hasTenantColumn('hikvision_device_info', $column)) {
                $payload[$column] = $value;
            }
        }

        if ($existing) {
            $conn->table('hikvision_device_info')
                ->where('DEVICE_ID', $existing->DEVICE_ID)
                ->update($payload);

            Log::debug('[HikvisionDeviceSync] device updated {serial}', ['serial' => $serial]);
        } else {
            $payload['DEVICE_SERIAL'] = $serial;
            $payload['IP_ADDRESS'] = '';
            $payload['created_at'] = $now;
            $conn->table('hikvision_device_info')->insert($payload);

            Log::debug('[HikvisionDeviceSync] device inserted {serial}', ['serial' => $serial]);
        }

        // 3) Upsert en asistencia_dispositivos.
        AsistenciaDispositivoService::upsert(
            driver: AsistenciaDispositivo::DRIVER_HIKVISION,
            marca: 'Hikvision',
            row: [
                'source_table' => 'hikvision_device_info',
                'source_device_id' => $identity->canonicalDeviceId,
                'nombre' => $name,
                'serial' => $serial,
                'bridge_device_id' => $bridgeDeviceId,
                'isup_device_id_raw' => $identity->rawDeviceId,
                'isup_device_id_canonical' => $identity->canonicalDeviceId,
                'device_sequence' => $identity->deviceSequence,
                'physical_device_key' => $physicalDeviceKey,
                'mac_address' => $macAddress,
                'estado' => $isOnline ? 'Activo' : 'Offline',
                'is_active' => $isOnline,
            ],
            metadata: [
                'model' => $model,
                'firmware' => $firmware,
                'deviceType' => $deviceType,
                'isupDeviceIdRaw' => $identity->rawDeviceId,
                'isupDeviceIdCanonical' => $identity->canonicalDeviceId,
                'deviceSequence' => $identity->deviceSequence,
                'macAddress' => $macAddress,
                'physicalDeviceKey' => $physicalDeviceKey,
                'legacyAlias' => $identity->legacyAlias,
                'bridgeRaw' => $bridgeDevice,
            ],
        );
    }

    private static function findExistingHikDeviceInfo(
        $conn,
        HikvisionDeviceIdentity $identity,
        ?string $physicalDeviceKey,
        string $serial,
    ): ?object {
        if (static::hasTenantColumn('hikvision_device_info', 'ISUP_DEVICE_ID_CANONICAL')) {
            $existing = $conn->table('hikvision_device_info')
                ->where('ISUP_DEVICE_ID_CANONICAL', $identity->canonicalDeviceId)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        if ($physicalDeviceKey !== null && static::hasTenantColumn('hikvision_device_info', 'PHYSICAL_DEVICE_KEY')) {
            $existing = $conn->table('hikvision_device_info')
                ->where('PHYSICAL_DEVICE_KEY', $physicalDeviceKey)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $sourceCandidates = array_values(array_filter(array_unique([
            $serial,
            $identity->canonicalDeviceId,
            $identity->rawDeviceId,
        ]), fn ($value) => $value !== null && $value !== ''));

        return $conn->table('hikvision_device_info')
            ->where(function ($query) use ($sourceCandidates, $identity) {
                $query->whereIn('DEVICE_SERIAL', $sourceCandidates)
                    ->orWhere('BRIDGE_DEVICE_ID', $identity->rawDeviceId);
            })
            ->orderByRaw('CASE WHEN DEVICE_SERIAL = ? THEN 0 ELSE 1 END', [$serial])
            ->orderByDesc('updated_at')
            ->first();
    }

    private static function hasTenantColumn(string $table, string $column): bool
    {
        try {
            return Schema::connection('empresa')->hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }
}
