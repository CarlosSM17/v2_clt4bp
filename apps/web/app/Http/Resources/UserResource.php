<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'institucion' => $this->institucion,
            'roles' => $this->getRoleNames(),
            'dos_pasos' => $this->two_factor_confirmed_at !== null,
            'invitacion_aceptada' => $this->invitacion_aceptada_at !== null,
            'suspendido' => $this->suspendido_at !== null,
            'created_at' => $this->created_at,
        ];
    }
}
