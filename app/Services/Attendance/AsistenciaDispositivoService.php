<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AsistenciaDispositivo;
use App\Services\DatabaseSwitchService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Upsert centralizado en `asistencia_dispositivos` (catalogo unificado).
 *
 * Es independiente del driver: Hikvision lo usa hoy; ZKTeco/ProFaceX lo
 * usara mañana sin tener que cambiar su logica interna. Reutilizable para
 * cualquier driver que quiera reflejar sus dispositivos en este catalogo.
 *
 * Requisito previo: `DatabaseSwitchService::setBdEmpresa($codigo)` debe
 * haber sido llamado antes (variable conexion `empresa` ya apuntando al
 * tenant correcto) — asi como lo exige AmaxoniaMarcacionService.
 */
class AsistenciaDispositivoService
{
    /**
     * Upsert (no duplica entre drivers ni entre tenants):
     *  unique = (driver, source_table, source_device_id, empresa_codigo).
     *
     * @param  string  $driver       Constante DRIVER_* de AsistenciaDispositivo.
     * @param  string  $marca        Marca legible (Hikvision, ZKTeco, ...).
     * @param  array{
     *     source_table:string,
     *     source_device_id:string,
     *     nombre:string,
     *     serial?:?string,
     *     bridge_device_id?:?string,
     *     estado?:string,
     *     is_active?:bool,
     *     id_proyecto?:?int,
     * }  $row
     * @param  array<string,mixed>  $metadata  JSON a merge-into metadata.
     */
    public static function upsert(
        string $driver,
        string $marca,
        array $row,
        array $metadata = [],
    ): AsistenciaDispositivo {
        if (!DatabaseSwitchService::hayEmpresaConfigurada()) {
            throw new \RuntimeException('[AsistenciaDispositivo] no hay empresa configurada');
        }

        $empresa = DatabaseSwitchService::getEmpresaActual();
        $empresaCodigo = (string) ($empresa['codigo'] ?? '');

        $conn = DatabaseSwitchService::getConexionEmpresa();
        $now = Carbon::now();

        // 1) Cargo fila existente (si la hay) para mergear metadata JSON.
        $existing = $conn->table('asistencia_dispositivos')
            ->where('driver', $driver)
            ->where('source_table', $row['source_table'])
            ->where('source_device_id', $row['source_device_id'])
            ->where('empresa_codigo', $empresaCodigo)
            ->first();

        $mergedMetadata = $metadata;
        if ($existing && !empty($existing->metadata)) {
            $prev = json_decode((string) $existing->metadata, true);
            if (is_array($prev)) {
                $mergedMetadata = array_merge($prev, $metadata);
            }
        }

        $payload = [
            'empresa_codigo' => $empresaCodigo,
            'driver' => $driver,
            'marca' => $marca,
            'nombre' => $row['nombre'],
            'source_table' => $row['source_table'],
            'source_device_id' => $row['source_device_id'],
            'bridge_device_id' => $row['bridge_device_id'] ?? null,
            'serial' => $row['serial'] ?? null,
            'estado' => $row['estado'] ?? 'Activo',
            'is_active' => array_key_exists('is_active', $row) ? (int) (bool) $row['is_active'] : 1,
            'id_proyecto' => $row['id_proyecto'] ?? null,
            'last_seen_at' => $now,
            'metadata' => json_encode($mergedMetadata, JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ];

        if ($existing) {
            $conn->table('asistencia_dispositivos')
                ->where('id', $existing->id)
                ->update($payload);

            Log::debug('[AsistenciaDispositivo] updated {driver}/{src}/{cod}', [
                'driver' => $driver,
                'src' => $row['source_device_id'],
                'cod' => $empresaCodigo,
            ]);

            // Refrescar y devolver modelo.
            $model = AsistenciaDispositivo::find($existing->id);
        } else {
            $payload['created_at'] = $now;
            $id = $conn->table('asistencia_dispositivos')->insertGetId($payload);
            $model = AsistenciaDispositivo::find($id);

            Log::debug('[AsistenciaDispositivo] inserted {driver}/{src}/{cod}', [
                'driver' => $driver,
                'src' => $row['source_device_id'],
                'cod' => $empresaCodigo,
            ]);
        }

        return $model;
    }

    /**
     * Marca last_seen_at = now() para un dispositivo ya existente (liveness).
     */
    public static function tocar(string $driver, string $sourceTable, string $sourceDeviceId): void
    {
        if (!DatabaseSwitchService::hayEmpresaConfigurada()) {
            return;
        }
        $empresa = DatabaseSwitchService::getEmpresaActual();
        DatabaseSwitchService::getConexionEmpresa()
            ->table('asistencia_dispositivos')
            ->where('driver', $driver)
            ->where('source_table', $sourceTable)
            ->where('source_device_id', $sourceDeviceId)
            ->where('empresa_codigo', (string) ($empresa['codigo'] ?? ''))
            ->update(['last_seen_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
    }

    /**
     * Conexión tenant cruda (alias para no repetir el guard en servicios que
     * solo necesitan insertar/select).
     */
    public static function conn()
    {
        return DatabaseSwitchService::getConexionEmpresa();
    }
}
