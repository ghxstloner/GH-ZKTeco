<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'login' => $this->login,
            'name' => $this->name,
            'email' => $this->email,
            'active' => $this->active,
            'is_admin' => $this->isAdmin(),
            'role' => $this->role,
            'phone' => $this->phone,
            'picture_base64' => $this->picture_base64,
            'aerolinea' => new AerolineaResource($this->whenLoaded('aerolinea')),
        ];
    }
}