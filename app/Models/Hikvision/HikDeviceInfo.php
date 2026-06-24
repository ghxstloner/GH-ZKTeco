<?php

namespace App\Models\Hikvision;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dispositivo Hikvision detectado o sincronizado desde el Bridge ISUP.
 *
 * Vive en la BD tenant (conexion `empresa`, cambiada dinamicamente por
 * DatabaseSwitchService). No guarda credenciales por dispositivo: el token
 * y la URL del Bridge estan en config/hikvision.php desde .env.
 */
class HikDeviceInfo extends Model
{
    protected $connection = 'empresa';

    protected $table = 'hikvision_device_info';

    protected $primaryKey = 'DEVICE_ID';

    public $timestamps = true;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'LAST_ACTIVITY' => 'datetime',
            'LAST_POLLED_AT' => 'datetime',
            'USER_COUNT' => 'integer',
            'IS_ACTIVE' => 'boolean',
        ];
    }

    /**
     * Eventos (marcaciones) registrados por este dispositivo.
     */
    public function events(): HasMany
    {
        return $this->hasMany(HikEventLog::class, 'DEVICE_SERIAL', 'DEVICE_SERIAL');
    }

    /**
     * Usuarios provisionados a este dispositivo.
     */
    public function users(): HasMany
    {
        return $this->hasMany(HikUserInfo::class, 'DEVICE_SERIAL', 'DEVICE_SERIAL');
    }
}
