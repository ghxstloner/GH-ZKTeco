<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Planificacion extends Model
{
    protected $table = 'planificacion';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'id_aerolinea',
        'iata_aerolinea',
        'id_aeropuerto',
        'id_tripulante',
        'crew_id',
        'id_posicion',
        'fecha_vuelo',
        'numero_vuelo',
        'hora_vuelo',
        'estatus',
    ];

    protected $casts = [
        'fecha_vuelo' => 'date',
    ];

    // Relaciones
    public function tripulante(): BelongsTo
    {
        return $this->belongsTo(Tripulante::class, 'id_tripulante', 'id_tripulante');
    }

    public function aerolinea(): BelongsTo
    {
        return $this->belongsTo(Aerolinea::class, 'id_aerolinea', 'id_aerolinea');
    }

    public function posicionModel(): BelongsTo
    {
        return $this->belongsTo(Posicion::class, 'id_posicion', 'id_posicion');
    }
}