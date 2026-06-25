<?php

namespace App\Services\Hikvision;

use App\Services\DatabaseSwitchService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pull de marcaciones (eventos AcsEvent) desde el Bridge ISUP por rango de
 * tiempo, persistencia idempotente en `hikvision_event_log`.
 *
 * Modelo DEVICE-DRIVEN: el `deviceId` del Bridge ES el `codigo` de empresa.
 * Se itera GET /api/devices del Bridge; por cada dispositivo:
 *  1. resolverTenantPorCodigo(deviceId) -> setBdEmpresa(deviceId).
 *  2. Recuperar la fila hikvision_device_info de ESA BD (la creó sync-devices).
 *  3. POST /events/search paginado, parsear eventsJson, insert idempotente.
 *
 * Idempotencia:
 *  - DEDUP_KEY = serialNo si existe -> "{serial}|{bridge}|{serialNo}"
 *  - si no, sha1 de "{serial}|{bridge}|{empNo}|{eventTime}|{major}|{minor}"
 *
 * Resiliencia:
 *  - deviceIds que no existan en nomempresa -> skip silencioso.
 *  - searchEvents feature_disabled / JSON invalido -> log + continue.
 *  - un dispositivo con error NO aborta a los demás.
 */
class HikvisionEventPullService
{
    /**
     * @param  string|null  $bridgeDeviceId  Si se pasa, solo ese dispositivo del Bridge.
     * @return array{devices:int, events_inserted:int, duplicates:int, skipped:int, errors:int}
     */
    public static function pullRango(
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?string $bridgeDeviceId = null,
    ): array {
        $client = app(HikvisionBridgeClient::class);

        $lookback = (int) config('hikvision.events.lookback_minutes', 10);
        $maxResults = (int) config('hikvision.events.max_results', 30);

        $now = CarbonImmutable::now();
        $from = $from ?? $now->subMinutes($lookback);
        $to = $to ?? $now;

        $stats = ['devices' => 0, 'events_inserted' => 0, 'duplicates' => 0, 'skipped' => 0, 'errors' => 0];

        // Lista de dispositivos del Bridge (fuente de verdad para el loop).
        try {
            $bridgeDevices = $client->listDevices();
        } catch (HikvisionFeatureDisabledException $e) {
            Log::warning('[HikvisionEvents] listDevices feature_disabled: '.$e->getMessage());

            return $stats;
        } catch (\Throwable $e) {
            Log::error('[HikvisionEvents] listDevices fallo: '.$e->getMessage());
            throw $e;
        }

        foreach ($bridgeDevices as $bd) {
            $id = (string) ($bd['deviceId'] ?? '');

            // Filtro --device opcional (por deviceId del Bridge).
            if ($bridgeDeviceId !== null && $bridgeDeviceId !== '' && $id !== $bridgeDeviceId) {
                continue;
            }

            ++$stats['devices'];

            if ($id === '' || !is_numeric($id)) {
                Log::warning('[HikvisionEvents] deviceId invalido: {id}', ['id' => $id ?: '(vacio)']);
                ++$stats['errors'];
                continue;
            }

            $codigo = (string) ((int) $id);

            // deviceId == codigo empresa: resolver (y conmutar) el tenant.
            $empresa = static::resolverTenantPorCodigo($codigo);
            if ($empresa === null) {
                ++$stats['skipped'];
                continue;
            }

            // Recuperar el dispositivo en la BD tenant (lo debió crear sync-devices).
            $device = DB::connection('empresa')
                ->table('hikvision_device_info')
                ->where('BRIDGE_DEVICE_ID', $id)
                ->where('IS_ACTIVE', 1)
                ->first();

            if ($device === null) {
                Log::info('[HikvisionEvents] device {id} no registrado en BD tenant {cod}, se omite (correr sync-devices primero)', [
                    'id' => $id,
                    'cod' => $codigo,
                ]);
                ++$stats['skipped'];
                continue;
            }

            try {
                $result = static::pullOneDevice($client, $device, $from, $to, $maxResults);
                $stats['events_inserted'] += $result['inserted'];
                $stats['duplicates'] += $result['duplicates'];
            } catch (HikvisionFeatureDisabledException $e) {
                Log::warning('[HikvisionEvents] feature_disabled {serial}: {err}', [
                    'serial' => $device->DEVICE_SERIAL,
                    'err' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                ++$stats['errors'];
                Log::error('[HikvisionEvents] error {serial}: {err}', [
                    'serial' => $device->DEVICE_SERIAL,
                    'err' => $e->getMessage(),
                ]);
            } finally {
                // Marcar último poll en la BD del dispositivo, sin importar resultado.
                DB::connection('empresa')
                    ->table('hikvision_device_info')
                    ->where('DEVICE_ID', $device->DEVICE_ID)
                    ->update(['LAST_POLLED_AT' => CarbonImmutable::now()]);
            }
        }

        Log::info('[HikvisionEvents] pull completado', $stats);

        return $stats;
    }

    /**
     * @return array<string,mixed>|null  Empresa activa, o null si el codigo
     *                                   no corresponde a un tenant nuestro.
     */
    private static function resolverTenantPorCodigo(string $codigo): ?array
    {
        $existe = DB::connection('mysql')
            ->table('nomempresa')
            ->where('codigo', $codigo)
            ->where('nomina_activo', 1)
            ->exists();

        if (!$existe) {
            Log::info('[HikvisionEvents] deviceId {cod} no es empresa activa, se omite', ['cod' => $codigo]);

            return null;
        }

        DatabaseSwitchService::setBdEmpresa($codigo);

        return DatabaseSwitchService::getEmpresaActual();
    }

    /**
     * @param  object  $device  Fila hikvision_device_info.
     * @return array{inserted:int, duplicates:int}
     */
    private static function pullOneDevice(
        HikvisionBridgeClient $client,
        object $device,
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $maxResults,
    ): array {
        $bridgeId = (string) ($device->BRIDGE_DEVICE_ID ?? '');
        $serial = (string) $device->DEVICE_SERIAL;

        if ($bridgeId === '') {
            Log::warning('[HikvisionEvents] device {serial} sin BRIDGE_DEVICE_ID, saltado', ['serial' => $serial]);

            return ['inserted' => 0, 'duplicates' => 0];
        }

        $startIso = $from->toIso8601String();
        $endIso = $to->toIso8601String();

        $inserted = 0;
        $duplicates = 0;
        $position = 0;

        // Paginacion via searchResultPosition + maxResults.
        while (true) {
            $envelope = $client->searchEvents($bridgeId, $startIso, $endIso, $position, $maxResults);

            $events = static::parseEventsJson($envelope['data']['eventsJson'] ?? null);
            $count = count($events);

            if ($count === 0) {
                break;
            }

            foreach ($events as $event) {
                [$ok, $dup] = static::insertEvent($serial, $bridgeId, $event);
                $inserted += $ok;
                $duplicates += $dup;
            }

            // Si la pagina vino incompleta o igual, fin.
            if ($count < $maxResults) {
                break;
            }
            $position += $maxResults;

            // Salvaguarda anti-loop infinito.
            if ($position > 100000) {
                Log::warning('[HikvisionEvents] loop paginacion abortado {serial} pos={pos}', [
                    'serial' => $serial,
                    'pos' => $position,
                ]);
                break;
            }
        }

        Log::debug('[HikvisionEvents] {serial}: inserted={ins} duplicates={dup}', [
            'serial' => $serial,
            'ins' => $inserted,
            'dup' => $duplicates,
        ]);

        return ['inserted' => $inserted, 'duplicates' => $duplicates];
    }

    /**
     * Parsea el string JSON de eventsJson en una lista de eventos.
     *
     * @return list<array<string,mixed>>
     */
    private static function parseEventsJson(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Log::warning('[HikvisionEvents] eventsJson invalido: {err}', ['err' => $e->getMessage()]);

            return [];
        }

        // Estructura canonica Hikvision: {"AcsEvent":{"InfoList":[...]}}
        if (isset($decoded['AcsEvent']['InfoList']) && is_array($decoded['AcsEvent']['InfoList'])) {
            return $decoded['AcsEvent']['InfoList'];
        }
        if (isset($decoded['InfoList']) && is_array($decoded['InfoList'])) {
            return $decoded['InfoList'];
        }
        if (is_array($decoded) && array_is_list($decoded)) {
            return $decoded;
        }

        return [];
    }

    /**
     * Inserta un evento de forma idempotente via DEDUP_KEY.
     *
     * @param  array<string,mixed>  $event
     * @return array{0:int,1:int}  [inserted=1?, duplicate=1?]
     */
    private static function insertEvent(string $serial, string $bridgeId, array $event): array
    {
        $conn = DB::connection('empresa');

        $dedup = static::computeDedupKey($serial, $bridgeId, $event);

        // Mapa de campos Hikvision -> columnas Laravel.
        $row = static::mapEvent($serial, $bridgeId, $event, $dedup);

        try {
            $conn->table('hikvision_event_log')->insert($row);

            return [1, 0];
        } catch (\Throwable $e) {
            // Duplicate key -> DEDUP_KEY ya existia. Ignorar.
            if (static::isDuplicateKeyError($e)) {
                return [0, 1];
            }
            throw $e;
        }
    }

    /**
     * DEDUP_KEY:
     *  - Si existe serialNo: "{serial}|{bridgeId}|{serialNo}"
     *  - Si no: sha1("{serial}|{bridgeId}|{empNo}|{eventTime}|{major}|{minor}")
     */
    private static function computeDedupKey(string $serial, string $bridgeId, array $event): string
    {
        $serialNo = $event['serialNo'] ?? null;

        if ($serialNo !== null && $serialNo !== '') {
            return $serial.'|'.$bridgeId.'|'.$serialNo;
        }

        $empNo = (string) ($event['employeeNo'] ?? '');
        $eventTime = (string) ($event['time'] ?? $event['currentEventTime'] ?? $event['eventTime'] ?? '');
        $major = (string) ($event['majorEventType'] ?? $event['major'] ?? '');
        $minor = (string) ($event['minorEventType'] ?? $event['minor'] ?? '');

        return substr(sha1($serial.'|'.$bridgeId.'|'.$empNo.'|'.$eventTime.'|'.$major.'|'.$minor), 0, 64);
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private static function mapEvent(string $serial, string $bridgeId, array $event, string $dedup): array
    {
        $raw = $event;
        $eventTime = static::extractTime($event);

        $ficha = null;
        // El Bridge puede enviar el número de empleado en `employeeNo` o en
        // `employeeNoString` (camelCase). Se acepta cualquiera de los dos.
        $empNo = isset($event['employeeNo']) && $event['employeeNo'] !== ''
            ? (string) $event['employeeNo']
            : (isset($event['employeeNoString']) && $event['employeeNoString'] !== ''
                ? (string) $event['employeeNoString']
                : null);
        if ($empNo !== null && is_numeric($empNo)) {
            $ficha = (int) $empNo;
        }

        return [
            'DEVICE_SERIAL' => $serial,
            'DEDUP_KEY' => $dedup,
            'BRIDGE_DEVICE_ID' => $bridgeId,
            'EMPLOYEE_NO' => $empNo,
            'EMPLOYEE_NO_STRING' => $empNo,
            'FICHA' => $ficha,
            'EVENT_TIME' => $eventTime,
            'MAJOR_EVENT' => static::intOrNull($event['majorEventType'] ?? $event['major'] ?? null),
            'MINOR_EVENT' => static::intOrNull($event['minorEventType'] ?? $event['minor'] ?? null),
            'VERIFY_MODE' => $event['verifyMode'] ?? null,
            'CURRENT_VERIFY_MODE' => $event['currentVerifyMode'] ?? null,
            'ATTENDANCE_STATUS' => null,
            'EVENT_NAME' => $event['name'] ?? $event['eventName'] ?? null,
            'SERIAL_NO' => static::intOrNull($event['serialNo'] ?? null),
            'CARD_READER_NO' => static::intOrNull($event['cardReaderNo'] ?? null),
            'DOOR_NO' => static::intOrNull($event['doorNo'] ?? null),
            'CARD_TYPE' => static::intOrNull($event['cardType'] ?? null),
            'USER_TYPE' => $event['userType'] ?? null,
            'MASK' => isset($event['mask']) ? (string) $event['mask'] : null,
            'FACE_RECT' => isset($event['faceRect']) && is_array($event['faceRect'])
                ? json_encode($event['faceRect'], JSON_UNESCAPED_UNICODE)
                : null,
            'RAW_RESPONSE' => json_encode($raw, JSON_UNESCAPED_UNICODE),
            'PROCESSED' => 0,
            'SYNC_ERROR' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private static function extractTime(array $event): ?string
    {
        foreach (['time', 'currentEventTime', 'eventTime'] as $key) {
            if (!empty($event[$key])) {
                try {
                    return CarbonImmutable::parse($event[$key])->toDateTimeString();
                } catch (\Throwable $_) {
                    return (string) $event[$key];
                }
            }
        }

        return null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private static function isDuplicateKeyError(\Throwable $e): bool
    {
        $msg = mb_strtolower((string) $e->getMessage());
        $code = (string) $e->getCode();

        // MySQL duplicate entry: SQLSTATE 23000 / code 1062.
        return $code === '23000'
            || $code === '1062'
            || str_contains($msg, 'duplicate entry');
    }

    /**
     * Lista los dispositivos activos Hikvision del tenant actual (helper
     * usado por el comando pull-events para loguear detalle).
     *
     * @return list<object>
     */
    public static function listarDispositivosActivos(): array
    {
        return DB::connection('empresa')
            ->table('hikvision_device_info')
            ->where('IS_ACTIVE', 1)
            ->get()
            ->all();
    }
}
