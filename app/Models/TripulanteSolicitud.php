<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Storage;

class TripulanteSolicitud extends Authenticatable
{
    use HasFactory, HasApiTokens;

    protected $table = 'tripulantes_solicitudes';
    protected $primaryKey = 'id_solicitud';
    public $timestamps = true;

    protected $fillable = [
        'crew_id',
        'nombres',
        'apellidos',
        'pasaporte',
        'identidad',
        'iata_aerolinea',
        'email',
        'posicion',
        'imagen',
        'password',
        'estado',
        'activo',
        'fecha_solicitud',
        'fecha_aprobacion',
        'motivo_rechazo',
        'aprobado_por',
        'rechazado_por',
        'email_verified',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'fecha_solicitud' => 'datetime',
        'fecha_aprobacion' => 'datetime',
        'activo' => 'boolean',
        'email_verified' => 'boolean',
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    // Accessors
    public function getNombresApellidosAttribute()
    {
        return $this->nombres . ' ' . $this->apellidos;
    }

    public function getImagenUrlAttribute(): ?string
    {
        if ($this->imagen && $this->iata_aerolinea && $this->crew_id) {
            $baseUrl = env('IMAGEN_URL_BASE');
            return "{$baseUrl}/{$this->iata_aerolinea}/{$this->crew_id}/{$this->imagen}";
        }
        return null;
    }

    // Relaciones
    public function posicionModel()
    {
        return $this->belongsTo(Posicion::class, 'posicion', 'id_posicion');
    }

    public function aerolinea()
    {
        return $this->belongsTo(Aerolinea::class, 'iata_aerolinea', 'siglas');
    }

    // Scopes necesarios
    public function scopePendientes($query)
    {
        return $query->where('estado', 'Pendiente');
    }

    public function scopeAprobados($query)
    {
        return $query->where('estado', 'Aprobado');
    }

    public function scopeDenegados($query)
    {
        return $query->where('estado', 'Denegado');
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeBuscarPorNombre($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nombres', 'like', "%{$search}%")
              ->orWhere('apellidos', 'like', "%{$search}%")
              ->orWhere('crew_id', 'like', "%{$search}%")
              ->orWhere('pasaporte', 'like', "%{$search}%");
        });
    }

    // Métodos de estado
    public function isPending()
    {
        return $this->estado === 'Pendiente';
    }

    public function isApproved()
    {
        return $this->estado === 'Aprobado';
    }

    public function isDenied()
    {
        return $this->estado === 'Denegado';
    }

    public function approve($approvedBy = null)
    {
        $this->update([
            'estado' => 'Aprobado',
            'activo' => true,
            'fecha_aprobacion' => now(),
            'aprobado_por' => $approvedBy,
            'motivo_rechazo' => null,
        ]);
    }

    public function deny($reason, $deniedBy = null)
    {
        $this->update([
            'estado' => 'Denegado',
            'activo' => false,
            'fecha_aprobacion' => null,
            'motivo_rechazo' => $reason,
            'rechazado_por' => $deniedBy,
        ]);
    }
}
