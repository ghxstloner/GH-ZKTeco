<?php

namespace App\Models\ZKTeco\ProFaceX;

use Backpack\CRUD\app\Models\Traits\CrudTrait;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

/** @property string $DEVICE_SN */
class ProFxDeviceInfo extends Model
{
    /*
    |--------------------------------------------------------------------------
    | GLOBAL VARIABLES
    |--------------------------------------------------------------------------
    */

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'profacex_device_info';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'DEVICE_ID';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var boolean
     */
    // public $timestamps = false;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    // protected $fillable = [];

    /**
     * The attributes that should be hidden for arrays
     *
     * @var array
     */
    // protected $hidden = [];



    /*
    |--------------------------------------------------------------------------
    | FUNCTIONS
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function provincia()
    {
        return $this->belongsTo(Provincia::class);
    }

    public function edificio()
    {
        return $this->belongsTo(Edificio::class);
    }

    public function marcaciones()
    {
        return $this->hasMany(ExpedienteMarcacion::class, 'device_id', 'DEVICE_ID');
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopeActivos(Builder $query)
    {
        return $query;
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS
    |--------------------------------------------------------------------------
    */

    public function stateActual(): Attribute
    {
        $stateActual = $this->STATE;
        $tiempoActual = Carbon::now();
        $ultimaActividad = Carbon::parse($this->LAST_ACTIVITY);
        if ($tiempoActual->diffInMinutes($ultimaActividad) > 10) {
            $stateActual = 'Offline';
        }

        return Attribute::make(
            get: fn () => $stateActual,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | MUTATORS
    |--------------------------------------------------------------------------
    */
}
