<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Aeropuerto extends Model
{
    protected $table = 'aeropuertos';
    protected $primaryKey = 'id_aeropuerto';
    public $timestamps = false;

    protected $fillable = [
        'descripcion_aeropuerto',
        'codigo_iata',
    ];

    // Relaciones
    public function puntosControl(): HasMany
    {
        return $this->hasMany(PuntoControl::class, 'id_aeropuerto', 'id_aeropuerto');
    }

    public function marcaciones(): HasMany
    {
        return $this->hasMany(Marcacion::class, 'id_aeropuerto', 'id_aeropuerto');
    }

    // Accesores
    public function getDescripcionAttribute()
    {
        return $this->descripcion_aeropuerto;
    }

    public function getCodigoAttribute()
    {
        return $this->codigo_iata;
    }
}
