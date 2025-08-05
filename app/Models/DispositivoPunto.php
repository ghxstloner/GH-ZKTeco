<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispositivoPunto extends Model
{
    protected $table = 'dispositivos_puntos';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'id_punto',
        'id_device',
        'device_sn',
    ];

    // Relaciones
    public function puntoControl(): BelongsTo
    {
        return $this->belongsTo(PuntoControl::class, 'id_punto', 'id_punto');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(Dispositivo::class, 'id_device', 'DEVICE_ID');
    }
}
