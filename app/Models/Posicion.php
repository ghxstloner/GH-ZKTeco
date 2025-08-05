<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Posicion extends Model
{
    /**
     * La tabla asociada con el modelo.
     */
    protected $table = 'posiciones';

    /**
     * La clave primaria para el modelo.
     */
    protected $primaryKey = 'id_posicion';

    /**
     * Indica si el modelo debe ser timestamped.
     */
    public $timestamps = false;

    /**
     * Los atributos que se pueden asignar masivamente.
     */
    protected $fillable = [
        'codigo_posicion',
        'descripcion',
    ];

    /**
     * Obtiene los tripulantes que tienen esta posición.
     */
    public function tripulantes(): HasMany
    {
        return $this->hasMany(Tripulante::class, 'posicion', 'id_posicion');
    }
}