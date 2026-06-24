<?php

namespace App\Models\Hikvision;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Usuario asociado a un dispositivo Hikvision y su estado de sincronizacion.
 *
 * SYNC_STATUS: pending | synced | failed | offline | validation_error |
 *              feature_disabled | unauthorized | not_found
 */
class HikUserInfo extends Model
{
    protected $connection = 'empresa';

    protected $table = 'hikvision_user_info';

    protected $primaryKey = 'USER_ID';

    public $timestamps = true;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'FICHA' => 'integer',
            'PHOTO_SYNCED' => 'boolean',
            'LAST_SYNCED_AT' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(HikDeviceInfo::class, 'DEVICE_SERIAL', 'DEVICE_SERIAL');
    }
}
