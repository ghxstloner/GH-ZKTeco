<?php
// app/Models/FcmToken.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FcmToken extends Model
{
    protected $fillable = [
        'crew_id',
        'iata_aerolinea', // ⚠️ NUEVO
        'fcm_token',
        'platform',
        'device_info',
        'last_used_at'
    ];

    protected $casts = [
        'device_info' => 'array',
        'last_used_at' => 'datetime'
    ];

    public function tripulante()
    {
        return $this->belongsTo(TripulanteSolicitud::class, ['crew_id', 'iata_aerolinea'], ['crew_id', 'iata_aerolinea']);
    }
}
