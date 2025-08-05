<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dispositivo extends Model
{
    protected $table = 'profacex_device_info';
    protected $primaryKey = 'DEVICE_ID';
    public $timestamps = false;

    protected $fillable = [
        'DEVICE_ID',
        // Agrega aquí otros campos que necesites de la tabla profacex_device_info
    ];

    // Relaciones
    public function dispositivosPuntos(): HasMany
    {
        return $this->hasMany(DispositivoPunto::class, 'id_device', 'DEVICE_ID');
    }
}
