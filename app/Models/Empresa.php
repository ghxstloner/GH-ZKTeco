<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empresa extends Model
{
    protected $connection = 'mysql'; // Usar conexión principal
    protected $table = 'nomempresa';
    protected $primaryKey = 'codigo';
    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'bd',
        'bd_contabilidad',
        'bd_nomina',
        'admis_activo',
        'contab_activo',
        'nomina_activo'
    ];

    protected $casts = [
        'admis_activo' => 'boolean',
        'contab_activo' => 'boolean',
        'nomina_activo' => 'boolean'
    ];

    public function scopeNominaActiva($query)
    {
        return $query->where('nomina_activo', 1);
    }
}
