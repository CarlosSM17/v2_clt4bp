<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'nombre', 'condicion', 'efectos', 'recomendaciones', 'fundamento', 'activa', 'orden'])]
class EffectRule extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['condicion' => 'array', 'efectos' => 'array', 'recomendaciones' => 'array', 'activa' => 'boolean'];
    }
}
