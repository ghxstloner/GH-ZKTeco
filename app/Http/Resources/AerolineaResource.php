<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AerolineaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id_aerolinea' => $this->id_aerolinea,
            'descripcion' => $this->descripcion,
            'siglas' => $this->siglas,
            'logo_base64' => $this->logo_base64,
        ];
    }
}