<?php

namespace App\Console\Commands\Hikvision;

use App\Models\Attendance\AsistenciaDispositivo;
use App\Services\DatabaseSwitchService;
use App\Services\Hikvision\HikvisionBridgeClient;
use App\Services\Hikvision\HikvisionDeviceIdentity;
use App\Services\Hikvision\HikvisionDeviceIdentityResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HikvisionDedupeDevicesCommand extends Command
{
    protected $signature = 'hikvision:dedupe-devices
                            {--empresa= : Codigo de empresa/tenant}
                            {--dry-run : Muestra cambios sin aplicar}
                            {--apply : Aplica la deduplicacion}';

    protected $description = 'Deduplica dispositivos Hikvision legacy/canonicos en asistencia_dispositivos e hikvision_device_info';

    public function handle(): int
    {
        $empresa = (string) ($this->option('empresa') ?? '');
        if ($empresa === '') {
            $this->error('Debe indicar --empresa=CODIGO.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $dryRun = (bool) $this->option('dry-run') || !$apply;
        if ($apply && (bool) $this->option('dry-run')) {
            $this->error('Use --dry-run o --apply, no ambos.');

            return self::FAILURE;
        }

        DatabaseSwitchService::setBdEmpresa($empresa);

        $identity = HikvisionDeviceIdentityResolver::resolve($empresa, $empresa);
        if ($identity === null) {
            $this->error("No se pudo resolver identidad legacy para empresa {$empresa}.");

            return self::FAILURE;
        }

        $bridgeInfo = $this->fetchBridgeInfo($identity);
        $macAddress = HikvisionDeviceIdentityResolver::extractMacAddress($bridgeInfo ?? []);
        $physicalKey = HikvisionDeviceIdentityResolver::physicalDeviceKey($macAddress);
        $currentSerial = (string) (($bridgeInfo['serialNumber'] ?? '') ?: '');

        $this->info($dryRun ? 'DRY-RUN: no se aplicaran cambios.' : 'APPLY: se aplicaran cambios.');
        $this->line("Empresa: {$empresa}");
        $this->line("Canonical device id: {$identity->canonicalDeviceId}");
        $this->line("Device sequence: {$identity->deviceSequence}");
        $this->line('Serial actual Bridge: '.($currentSerial !== '' ? $currentSerial : '(no disponible)'));
        $this->line('MAC actual Bridge: '.($macAddress !== null ? $macAddress : '(no disponible)'));

        $attendanceRows = DB::connection('empresa')
            ->table('asistencia_dispositivos')
            ->where('empresa_codigo', $empresa)
            ->where('driver', AsistenciaDispositivo::DRIVER_HIKVISION)
            ->where(function ($query) use ($identity, $empresa, $currentSerial) {
                $query->where('source_device_id', $identity->canonicalDeviceId)
                    ->orWhere('source_device_id', $identity->rawDeviceId)
                    ->orWhere('bridge_device_id', $identity->rawDeviceId)
                    ->orWhere('bridge_device_id', $empresa);

                if ($currentSerial !== '') {
                    $query->orWhere('source_device_id', $currentSerial)
                        ->orWhere('serial', $currentSerial);
                }
            })
            ->orderBy('id')
            ->get();

        if ($attendanceRows->count() <= 1) {
            $this->info('No hay duplicados en asistencia_dispositivos para este criterio.');
        } else {
            $this->dedupeAttendanceRows($attendanceRows, $identity, $currentSerial, $macAddress, $physicalKey, $apply);
        }

        $hikRows = DB::connection('empresa')
            ->table('hikvision_device_info')
            ->where(function ($query) use ($identity, $currentSerial) {
                $query->where('BRIDGE_DEVICE_ID', $identity->rawDeviceId)
                    ->orWhere('DEVICE_SERIAL', $identity->rawDeviceId)
                    ->orWhere('DEVICE_SERIAL', $identity->canonicalDeviceId);

                if ($currentSerial !== '') {
                    $query->orWhere('DEVICE_SERIAL', $currentSerial);
                }

                if ($this->hasColumn('hikvision_device_info', 'ISUP_DEVICE_ID_CANONICAL')) {
                    $query->orWhere('ISUP_DEVICE_ID_CANONICAL', $identity->canonicalDeviceId);
                }
            })
            ->orderBy('DEVICE_ID')
            ->get();

        if ($hikRows->count() <= 1) {
            $this->info('No hay duplicados en hikvision_device_info para este criterio.');
        } else {
            $this->dedupeHikDeviceRows($hikRows, $identity, $currentSerial, $macAddress, $physicalKey, $apply);
        }

        return self::SUCCESS;
    }

    private function dedupeAttendanceRows($rows, HikvisionDeviceIdentity $identity, string $currentSerial, ?string $macAddress, ?string $physicalKey, bool $apply): void
    {
        $survivor = $this->chooseAttendanceSurvivor($rows, $currentSerial);
        $duplicates = $rows->where('id', '!=', $survivor->id)->values();

        $this->warn('asistencia_dispositivos duplicados detectados: '.$rows->pluck('id')->implode(', '));
        $this->line("Superviviente: id {$survivor->id}");
        $this->line('Duplicados a desactivar: '.$duplicates->pluck('id')->implode(', '));

        $refCounts = $this->attendanceReferenceCounts($rows->pluck('id')->all());
        if ($refCounts !== []) {
            $this->line('Referencias personal_dispositivos: '.json_encode($refCounts));
        }

        if (!$apply) {
            return;
        }

        DB::connection('empresa')->transaction(function () use ($survivor, $duplicates, $identity, $currentSerial, $macAddress, $physicalKey) {
            $serial = $currentSerial !== '' ? $currentSerial : (string) ($survivor->serial ?? $identity->canonicalDeviceId);
            $metadata = $this->mergedAttendanceMetadata($survivor, [
                'isupDeviceIdRaw' => $identity->rawDeviceId,
                'isupDeviceIdCanonical' => $identity->canonicalDeviceId,
                'deviceSequence' => $identity->deviceSequence,
                'macAddress' => $macAddress,
                'physicalDeviceKey' => $physicalKey,
                'dedupedDuplicateIds' => $duplicates->pluck('id')->values()->all(),
            ]);

            $payload = [
                'source_device_id' => $identity->canonicalDeviceId,
                'serial' => $serial,
                'bridge_device_id' => $identity->rawDeviceId,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                'updated_at' => Carbon::now(),
                'last_seen_at' => Carbon::now(),
            ];
            $this->putIfColumn($payload, 'asistencia_dispositivos', 'isup_device_id_raw', $identity->rawDeviceId);
            $this->putIfColumn($payload, 'asistencia_dispositivos', 'isup_device_id_canonical', $identity->canonicalDeviceId);
            $this->putIfColumn($payload, 'asistencia_dispositivos', 'device_sequence', $identity->deviceSequence);
            $this->putIfColumn($payload, 'asistencia_dispositivos', 'mac_address', $macAddress);
            $this->putIfColumn($payload, 'asistencia_dispositivos', 'physical_device_key', $physicalKey);

            DB::connection('empresa')->table('asistencia_dispositivos')
                ->where('id', $survivor->id)
                ->update($payload);

            $this->migratePersonalDispositivoReferences($duplicates->pluck('id')->all(), (int) $survivor->id);

            foreach ($duplicates as $duplicate) {
                $duplicateMetadata = $this->mergedAttendanceMetadata($duplicate, [
                    'duplicateOf' => $survivor->id,
                    'dedupedAt' => Carbon::now()->toDateTimeString(),
                ]);

                $duplicatePayload = [
                    'estado' => 'Duplicado',
                    'is_active' => 0,
                    'metadata' => json_encode($duplicateMetadata, JSON_UNESCAPED_UNICODE),
                    'updated_at' => Carbon::now(),
                ];
                $this->putIfColumn($duplicatePayload, 'asistencia_dispositivos', 'isup_device_id_canonical', null);
                $this->putIfColumn($duplicatePayload, 'asistencia_dispositivos', 'physical_device_key', null);

                DB::connection('empresa')->table('asistencia_dispositivos')
                    ->where('id', $duplicate->id)
                    ->update($duplicatePayload);
            }
        });
    }

    private function dedupeHikDeviceRows($rows, HikvisionDeviceIdentity $identity, string $currentSerial, ?string $macAddress, ?string $physicalKey, bool $apply): void
    {
        $survivor = $this->chooseHikDeviceSurvivor($rows, $currentSerial);
        $duplicates = $rows->where('DEVICE_ID', '!=', $survivor->DEVICE_ID)->values();

        $this->warn('hikvision_device_info duplicados detectados: '.$rows->pluck('DEVICE_ID')->implode(', '));
        $this->line("Superviviente: DEVICE_ID {$survivor->DEVICE_ID}");
        $this->line('Duplicados a desactivar: '.$duplicates->pluck('DEVICE_ID')->implode(', '));

        if (!$apply) {
            return;
        }

        DB::connection('empresa')->transaction(function () use ($survivor, $duplicates, $identity, $currentSerial, $macAddress, $physicalKey) {
            $payload = [
                'DEVICE_SERIAL' => $currentSerial !== '' ? $currentSerial : (string) ($survivor->DEVICE_SERIAL ?? $identity->canonicalDeviceId),
                'BRIDGE_DEVICE_ID' => $identity->rawDeviceId,
                'updated_at' => Carbon::now(),
            ];
            $this->putIfColumn($payload, 'hikvision_device_info', 'ISUP_DEVICE_ID_RAW', $identity->rawDeviceId);
            $this->putIfColumn($payload, 'hikvision_device_info', 'ISUP_DEVICE_ID_CANONICAL', $identity->canonicalDeviceId);
            $this->putIfColumn($payload, 'hikvision_device_info', 'DEVICE_SEQUENCE', $identity->deviceSequence);
            $this->putIfColumn($payload, 'hikvision_device_info', 'MAC_ADDRESS', $macAddress);
            $this->putIfColumn($payload, 'hikvision_device_info', 'PHYSICAL_DEVICE_KEY', $physicalKey);

            DB::connection('empresa')->table('hikvision_device_info')
                ->where('DEVICE_ID', $survivor->DEVICE_ID)
                ->update($payload);

            foreach ($duplicates as $duplicate) {
                $duplicatePayload = [
                    'STATE' => 'Duplicado',
                    'IS_ACTIVE' => 0,
                    'updated_at' => Carbon::now(),
                ];
                $this->putIfColumn($duplicatePayload, 'hikvision_device_info', 'ISUP_DEVICE_ID_CANONICAL', null);
                $this->putIfColumn($duplicatePayload, 'hikvision_device_info', 'PHYSICAL_DEVICE_KEY', null);

                DB::connection('empresa')->table('hikvision_device_info')
                    ->where('DEVICE_ID', $duplicate->DEVICE_ID)
                    ->update($duplicatePayload);
            }
        });
    }

    private function chooseAttendanceSurvivor($rows, string $currentSerial): object
    {
        $refCounts = $this->attendanceReferenceCounts($rows->pluck('id')->all());

        return $rows
            ->sortBy(function ($row) use ($currentSerial, $refCounts) {
                $score = 0 - (($refCounts[$row->id] ?? 0) * 10000);
                if ($currentSerial !== '' && (string) ($row->serial ?? '') === $currentSerial) {
                    $score -= 500;
                }
                if (!is_numeric((string) ($row->serial ?? ''))) {
                    $score -= 100;
                }
                if (!empty($row->metadata) && str_contains((string) $row->metadata, '"model"')) {
                    $score -= 50;
                }

                return [$score, (int) $row->id];
            })
            ->first();
    }

    private function chooseHikDeviceSurvivor($rows, string $currentSerial): object
    {
        return $rows
            ->sortBy(function ($row) use ($currentSerial) {
                $score = 0;
                if ($currentSerial !== '' && (string) ($row->DEVICE_SERIAL ?? '') === $currentSerial) {
                    $score -= 500;
                }
                if (!is_numeric((string) ($row->DEVICE_SERIAL ?? ''))) {
                    $score -= 100;
                }

                return [$score, (int) $row->DEVICE_ID];
            })
            ->first();
    }

    private function attendanceReferenceCounts(array $ids): array
    {
        if (!Schema::connection('empresa')->hasTable('personal_dispositivos')) {
            return [];
        }

        $counts = [];
        foreach ($this->personalDispositivoReferenceColumns() as $column) {
            $rows = DB::connection('empresa')
                ->table('personal_dispositivos')
                ->select($column, DB::raw('COUNT(*) as total'))
                ->whereIn($column, $ids)
                ->groupBy($column)
                ->get();

            foreach ($rows as $row) {
                $id = (int) $row->{$column};
                $counts[$id] = ($counts[$id] ?? 0) + (int) $row->total;
            }
        }

        return $counts;
    }

    private function migratePersonalDispositivoReferences(array $duplicateIds, int $survivorId): void
    {
        if (!Schema::connection('empresa')->hasTable('personal_dispositivos')) {
            return;
        }

        foreach ($this->personalDispositivoReferenceColumns() as $column) {
            DB::connection('empresa')
                ->table('personal_dispositivos')
                ->whereIn($column, $duplicateIds)
                ->update([$column => $survivorId]);
        }
    }

    private function personalDispositivoReferenceColumns(): array
    {
        return array_values(array_filter([
            $this->hasColumn('personal_dispositivos', 'asistencia_dispositivo_id') ? 'asistencia_dispositivo_id' : null,
            $this->hasColumn('personal_dispositivos', 'dispositivo_id') ? 'dispositivo_id' : null,
            $this->hasColumn('personal_dispositivos', 'id_dispositivo') ? 'id_dispositivo' : null,
        ]));
    }

    private function mergedAttendanceMetadata(object $row, array $extra): array
    {
        $metadata = [];
        if (!empty($row->metadata)) {
            $decoded = json_decode((string) $row->metadata, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        return array_merge($metadata, $extra);
    }

    private function fetchBridgeInfo(HikvisionDeviceIdentity $identity): ?array
    {
        try {
            if ((string) config('hikvision.bridge_url') === '') {
                return null;
            }

            return app(HikvisionBridgeClient::class)->getDeviceInfo($identity->rawDeviceId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function putIfColumn(array &$payload, string $table, string $column, mixed $value): void
    {
        if ($this->hasColumn($table, $column)) {
            $payload[$column] = $value;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::connection('empresa')->hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }
}
