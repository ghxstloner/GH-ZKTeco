<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PuntoControl extends Model
{
    protected $table = 'puntos_control';
    protected $primaryKey = 'id_punto';
    public $timestamps = false;

    protected $fillable = [
        'id_aeropuerto',
        'descripcion_punto',
    ];

    // Relaciones
    public function aeropuerto(): BelongsTo
    {
        return $this->belongsTo(Aeropuerto::class, 'id_aeropuerto', 'id_aeropuerto');
    }

    public function marcaciones(): HasMany
    {
        return $this->hasMany(Marcacion::class, 'punto_control', 'id_punto');
    }

    public function dispositivosPuntos(): HasMany
    {
        return $this->hasMany(DispositivoPunto::class, 'id_punto', 'id_punto');
    }
}
