<?php

namespace App\Models\Attendance;

use Illuminate\Database\Eloquent\Model;

/**
 * Catalogo unificado de dispositivos de asistencia (Hikvision + ZKTeco
 * proximamente). Una fila por (driver, source_table, source_device_id,
 * empresa_codigo); el mismo dispositivo fisico puede aparecer replicado
 * en varios tenants — es intencional.
 */
class AsistenciaDispositivo extends Model
{
    protected $connection = 'empresa';

    protected $table = 'asistencia_dispositivos';

    protected $primaryKey = 'id';

    public $timestamps = true;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'metadata' => 'json',
        ];
    }

    public const DRIVER_HIKVISION = 'hikvision';

    public const DRIVER_ZKTECO = 'zkteco';
}
