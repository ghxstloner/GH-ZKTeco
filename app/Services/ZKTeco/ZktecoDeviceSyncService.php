<?php

namespace App\Services\ZKTeco;

use App\Models\Attendance\AsistenciaDispositivo;
use App\Models\ZKTeco\ProFaceX\ProFxDeviceInfo;
use App\Services\Attendance\AsistenciaDispositivoService;
use App\Services\DatabaseSwitchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza los dispositivos ZKTeco/ProFaceX (tabla tenant
 * `profacex_device_info`) hacia el catalogo unificado
 * `asistencia_dispositivos`.
 *
 * Modelo TENANT-DRIVEN (NO device-driven como Hikvision): ZKTeco no tiene
 * un Bridge central que liste dispositivos. Los dispositivos se registran
 * solos por PUSH en la BD de cada tenant, asi que debemos iterar los tenants
 * y leer `profacex_device_info` en cada uno.
 *
 * NO toca `app/Services/ZKTeco/ProFaceX/**` (zona vedada). Solo LEE el
 * modelo `ProFxDeviceInfo` y escribe via `AsistenciaDispositivoService`.
 *
 * Identificacion: DEVICE_SN (estable, unico por hardware, quemado en
 * firmware) → `asistencia_dispositivos.source_device_id`.
 */
class ZktecoDeviceSyncService
{
    /**
     * Itera todas las empresas activas (nomina_activo=1) y, por cada una,
     * vuelca sus `profacex_device_info` a `asistencia_dispositivos`.
     *
     * @param  string|null  $onlyCodigo  Limitar a una sola empresa.
     * @return array{tenants:int, devices:int, updated:int, skipped:int, errors:int}
     */
    public static function sincronizar(?string $onlyCodigo = null): array
    {
        $stats = ['tenants' => 0, 'devices' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        foreach (static::listTenants($onlyCodigo) as $tenant) {
            ++$stats['tenants'];
            static::processTenant($tenant, $stats);
        }

        Log::info('[ZktecoDeviceSync] sincronizacion completada', $stats);

        return $stats;
    }

    /**
     * @param  object  $tenant  fila nomempresa (codigo, bd_nomina, nombre)
     */
    private static function processTenant(object $tenant, array &$stats): void
    {
        $bd = (string) ($tenant->bd_nomina ?? '');

        if ($bd === '') {
            Log::warning('[ZktecoDeviceSync] empresa {cod} sin bd_nomina, se omite', [
                'cod' => $tenant->codigo,
            ]);
            ++$stats['errors'];

            return;
        }

        // Omitir tenants cuya BD no exista (no es fatal, es solo una empresa
        // nueva sin dispositivo alguno). Si la BD existe pero falla otra
        // cosa, setBdEmpresa lanza y se captura abajo.
        $exists = DB::connection('mysql')->selectOne(
            'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$bd],
        );

        if (!$exists) {
            Log::warning('[ZktecoDeviceSync] BD inexistente {bd} (empresa {cod}), se omite', [
                'bd' => $bd,
                'cod' => $tenant->codigo,
            ]);
            ++$stats['skipped'];

            return;
        }

        try {
            DatabaseSwitchService::setBdEmpresa((string) $tenant->codigo);

            // Leer los dispositivos ZKTeco registrados en esta BD tenant.
            // ProFxDeviceInfo tiene $connection='empresa', asi que resuelve
            // automaticamente la BD conmutada.
            $devices = ProFxDeviceInfo::query()->get();

            foreach ($devices as $device) {
                try {
                    static::upsertOne($device);
                    ++$stats['updated'];
                } catch (\Throwable $e) {
                    ++$stats['errors'];
                    Log::error('[ZktecoDeviceSync] error con dispositivo {sn} (empresa {cod}): {err}', [
                        'sn' => $device->DEVICE_SN ?? '?',
                        'cod' => $tenant->codigo,
                        'err' => $e->getMessage(),
                    ]);
                }
                ++$stats['devices'];
            }
        } catch (\Throwable $e) {
            ++$stats['errors'];
            Log::error('[ZktecoDeviceSync] fallo empresa {cod}: {err}', [
                'cod' => $tenant->codigo,
                'err' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Upsert de un dispositivo ProFaceX en `asistencia_dispositivos`.
     * Requiere que la conexion `empresa` ya este conmutada al tenant.
     */
    private static function upsertOne(ProFxDeviceInfo $device): void
    {
        $serial = (string) ($device->DEVICE_SN ?? '');

        if ($serial === '') {
            throw new \RuntimeException('ProFxDeviceInfo sin DEVICE_SN, no se puede catalogar');
        }

        // stateActual: 'Online' si hubo actividad en los ultimos 10 min,
        // 'Offline' en caso contrario (definido en el modelo ProFxDeviceInfo).
        $estadoCal = $device->stateActual;
        $isOnline = $estadoCal !== 'Offline';

        // Conservar el STATE crudo del dispositivo ('Online' por defecto)
        $stateRaw = (string) ($device->STATE ?? 'Online');
        $isOnline = $isOnline || strcasecmp($stateRaw, 'Online') === 0;

        AsistenciaDispositivoService::upsert(
            driver: AsistenciaDispositivo::DRIVER_ZKTECO,
            marca: 'ZKTeco',
            row: [
                'source_table' => 'profacex_device_info',
                'source_device_id' => $serial,
                'nombre' => (string) ($device->DEVICE_NAME ?? $serial),
                'serial' => $serial,
                'bridge_device_id' => null, // sin bridge
                'estado' => $isOnline ? 'Activo' : 'Offline',
                'is_active' => $isOnline,
            ],
            metadata: [
                'ip' => $device->IPADDRESS ?? null,
                'push_version' => $device->PUSH_VERSION ?? null,
                'dev_language' => $device->DEV_LANGUAGE ?? null,
                'alias' => $device->ALIAS_NAME ?? null,
                'temperatura' => $device->TEMPERATURE ?? null,
                'mask' => $device->MASK ?? null,
                'palm' => $device->PALM ?? null,
                'state_raw' => $stateRaw,
            ],
        );
    }

    /**
     * Lista las empresas activas.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private static function listTenants(?string $onlyCodigo)
    {
        $query = DB::connection('mysql')
            ->table('nomempresa')
            ->where('nomina_activo', 1)
            ->select(['codigo', 'bd_nomina', 'nombre']);

        if ($onlyCodigo !== null && $onlyCodigo !== '') {
            $query->where('codigo', $onlyCodigo);
        }

        return $query->get();
    }
}
