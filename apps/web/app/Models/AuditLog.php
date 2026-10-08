<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['actor_id', 'accion', 'entidad_tipo', 'entidad_id', 'datos', 'ip'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;   // la bitácora solo inserta

    protected function casts(): array
    {
        return ['datos' => 'array'];
    }
}
