<?php

namespace App\Models\Hikvision;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marcacion/evento AcsEvent consultado via PULL al Bridge ISUP.
 *
 * Idempotente por DEDUP_KEY (no por SERIAL_NO, que puede ser NULL).
 */
class HikEventLog extends Model
{
    protected $connection = 'empresa';

    protected $table = 'hikvision_event_log';

    protected $primaryKey = 'EVENT_ID';

    public $timestamps = true;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = 'updated_at';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'EVENT_TIME' => 'datetime',
            'RAW_RESPONSE' => 'json',
            'FACE_RECT' => 'json',
            'PROCESSED' => 'boolean',
            'SERIAL_NO' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(HikDeviceInfo::class, 'DEVICE_SERIAL', 'DEVICE_SERIAL');
    }
}
