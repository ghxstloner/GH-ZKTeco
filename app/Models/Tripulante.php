<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Tripulante extends Model
{
    protected $table = 'tripulantes';
    protected $primaryKey = 'id_tripulante';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;

    protected $fillable = [
        'id_aerolinea',
        'iata_aerolinea',
        'crew_id',
        'nombres',
        'apellidos',
        'pasaporte',
        'identidad',
        'posicion',
        'imagen',
        'fecha_creacion',
        'estatus',
    ];

    /**
     * Los atributos que deben ser casteados.
     */
    protected $casts = [
        'fecha_creacion' => 'datetime',
    ];

    /**
     * Relación con la aerolínea.
     */
    public function aerolinea(): BelongsTo
    {
        return $this->belongsTo(Aerolinea::class, 'id_aerolinea');
    }

    /**
     * Relación con la posición.
     */
    public function posicionModel(): BelongsTo
    {
        return $this->belongsTo(Posicion::class, 'posicion', 'id_posicion');
    }

    /**
     * Accesor para obtener el nombre completo del tripulante.
     */
    protected function nombresApellidos(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => trim(($attributes['nombres'] ?? '') . ' ' . ($attributes['apellidos'] ?? '')),
        );
    }

    /**
     * Accesor para obtener la URL completa de la imagen.
     */
    public function getImagenUrlAttribute(): ?string
    {
        if ($this->imagen && $this->crew_id) {
            $baseUrl = env('IMAGEN_URL_BASE');
            return "{$baseUrl}/{$this->crew_id}/{$this->imagen}";
        }
        return null;
    }

    /**
     * Scope para filtrar por aerolínea.
     */
    public function scopePorAerolinea($query, $idAerolinea)
    {
        return $query->where('id_aerolinea', $idAerolinea);
    }

    /**
     * Scope para filtrar por posición.
     */
    public function scopePorPosicion($query, $posicion)
    {
        return $query->where('posicion', $posicion);
    }

    /**
     * Scope para buscar por nombre o apellido.
     */
    public function scopeBuscarPorNombre($query, $termino)
    {
        return $query->where(function ($q) use ($termino) {
            $q->where('nombres', 'like', "%{$termino}%")
              ->orWhere('apellidos', 'like', "%{$termino}%");
        });
    }

    /**
     * Boot del modelo para establecer fecha de creación automáticamente.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($tripulante) {
            if (!$tripulante->fecha_creacion) {
                $tripulante->fecha_creacion = now();
            }
        });
    }
}
