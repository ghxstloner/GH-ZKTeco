<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TripulanteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id_tripulante' => $this->id_tripulante,
            'id_aerolinea' => $this->id_aerolinea,
            'iata_aerolinea' => $this->iata_aerolinea,
            'crew_id' => $this->crew_id,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'nombres_apellidos' => $this->nombres_apellidos,
            'pasaporte' => $this->pasaporte,
            'identidad' => $this->identidad,
            'posicion' => $this->posicion,
            'imagen' => $this->imagen,
            'imagen_url' => $this->imagen_url,
            'fecha_creacion' => $this->fecha_creacion?->format('Y-m-d H:i:s'),

            // Relaciones
            'aerolinea' => new AerolineaResource($this->whenLoaded('aerolinea')),
            'posicion_info' => new PosicionResource($this->whenLoaded('posicionModel')),
        ];
    }
}