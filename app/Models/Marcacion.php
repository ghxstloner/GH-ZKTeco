<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Marcacion extends Model
{
    /**
     * La tabla asociada con el modelo.
     */
    protected $table = 'marcacion';

    /**
     * La clave primaria para el modelo en Eloquent.
     */
    protected $primaryKey = 'id_marcacion';

    /**
     * Indica si la IDs son auto-incrementales.
     */
    public $incrementing = true;

    /**
     * El tipo de dato de la clave auto-incremental.
     */
    protected $keyType = 'int';

    public $timestamps = false;

    /**
     * Los atributos que se pueden asignar masivamente.
     */
    protected $fillable = [
        'id_planificacion',
        'id_evento', // ✅ AGREGAR
        'id_tripulante',
        'crew_id',
        'fecha_marcacion',
        'hora_entrada', // ✅ AGREGAR
        'hora_salida',  // ✅ AGREGAR
        'hora_marcacion',
        'lugar_marcacion',
        'punto_control',
        'procesado',
        'tipo_marcacion',
        'usuario',
        'transporte', // ✅ AGREGAR
        'alimentacion', // ✅ AGREGAR
    ];

    protected $casts = [
        'fecha_marcacion' => 'date',
        'transporte' => 'decimal:2',
        'alimentacion' => 'decimal:2',
    ];

    // Relaciones
    public function planificacion(): BelongsTo
    {
        return $this->belongsTo(Planificacion::class, 'id_planificacion', 'id');
    }

    public function tripulante(): BelongsTo
    {
        return $this->belongsTo(Tripulante::class, 'id_tripulante', 'id_tripulante');
    }

    public function puntoControl(): BelongsTo
    {
        return $this->belongsTo(PuntoControl::class, 'punto_control', 'id_punto');
    }

    public function lugarMarcacion(): BelongsTo
    {
        return $this->belongsTo(Aeropuerto::class, 'lugar_marcacion', 'id_aeropuerto');
    }
}
