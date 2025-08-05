<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens; // ← AGREGAR ESTA LÍNEA

class SecUser extends Authenticatable
{
    use HasApiTokens; // ← AGREGAR ESTA LÍNEA

    /**
     * La tabla asociada con el modelo.
     */
    protected $table = 'sec_users';

    /**
     * La clave primaria para el modelo.
     */
    protected $primaryKey = 'login';

    /**
     * El tipo de la clave primaria.
     */
    protected $keyType = 'string';

    /**
     * Indica si la ID es auto-incremental.
     */
    public $incrementing = false;

    /**
     * Indica si el modelo debe ser timestamped.
     */
    public $timestamps = false;

    /**
     * Los atributos que se pueden asignar masivamente.
     */
    protected $fillable = [
        'login',
        'pswd',
        'name',
        'email',
        'active',
        'activation_code',
        'priv_admin',
        'mfa',
        //'picture',
        'role',
        'phone',
        'pswd_last_updated',
        'mfa_last_updated',
        'id_aerolinea',
    ];

    /**
     * Los atributos que deben estar ocultos para la serialización.
     */
    protected $hidden = [
        'pswd',
        'activation_code',
        'mfa',
    ];

    /**
     * Los atributos que deben ser casteados.
     */
    protected $casts = [
        'pswd_last_updated' => 'datetime',
        'mfa_last_updated' => 'datetime'
    ];

    /**
     * Obtiene la aerolínea del usuario.
     */
    public function aerolinea(): BelongsTo
    {
        return $this->belongsTo(Aerolinea::class, 'id_aerolinea');
    }

    /**
     * Verificar si el usuario es administrador.
     */
    public function isAdmin(): bool
    {
        return $this->priv_admin === 'Y';
    }

    /**
     * Verificar si el usuario está activo.
     */
    public function isActive(): bool
    {
        return $this->active === 'Y';
    }

    /**
     * Verificar la contraseña.
     */
    public function checkPassword(string $password): bool
    {
        return hash('sha256', $password) === $this->pswd;
    }

    /**
     * Obtener el nombre del campo de autenticación único.
     */
    public function getAuthIdentifierName(): string
    {
        return 'login';
    }

    /**
     * Obtener el identificador único para autenticación.
     */
    public function getAuthIdentifier()
    {
        return $this->login;
    }

    /**
     * Obtener el nombre del campo de contraseña.
     */
    public function getAuthPasswordName(): string
    {
        return 'pswd';
    }

    /**
     * Obtener la contraseña para autenticación.
     */
    public function getAuthPassword(): string
    {
        return $this->pswd;
    }

    /**
     * Scope para usuarios activos.
     */
    public function scopeActive($query)
    {
        return $query->where('active', 'Y');
    }

    /**
     * Scope para administradores.
     */
    public function scopeAdmin($query)
    {
        return $query->where('priv_admin', 'Y');
    }

    /**
     * Accessor para la imagen en base64.
     */
    /*public function getPictureBase64Attribute(): ?string
    {
        if ($this->picture) {
            return base64_encode($this->picture);
        }
        return null;
    }*/
}