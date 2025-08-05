<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Aerolinea extends Model
{
    /**
     * La tabla asociada con el modelo.
     */
    protected $table = 'aerolineas';

    /**
     * La clave primaria para el modelo.
     */
    protected $primaryKey = 'id_aerolinea';

    /**
     * Indica si el modelo debe ser timestamped.
     */
    public $timestamps = false;

    /**
     * Los atributos que se pueden asignar masivamente.
     */
    protected $fillable = [
        'descripcion',
        'siglas',
        'logo',
    ];

    /**
     * Los atributos que deben ser casteados.
     */
    /*
    protected $casts = [
        'logo' => 'binary',
    ];
    */

    /**
     * Obtiene los tripulantes de esta aerolínea.
     */
    public function tripulantes(): HasMany
    {
        return $this->hasMany(Tripulante::class, 'id_aerolinea');
    }

    /**
     * Obtiene los usuarios de esta aerolínea.
     */
    public function usuarios(): HasMany
    {
        return $this->hasMany(SecUser::class, 'id_aerolinea');
    }

    /**
     * Accessor para el logo en base64.
     */
    public function getLogoBase64Attribute(): ?string
    {
        if ($this->logo) {
            return base64_encode($this->logo);
        }
        return null;
    }
}