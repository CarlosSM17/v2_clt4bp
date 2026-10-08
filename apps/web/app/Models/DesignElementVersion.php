<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['design_element_id', 'version', 'contenido', 'estado', 'autor_tipo', 'actualizado_por', 'eliminado'])]
class DesignElementVersion extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['contenido' => 'object', 'eliminado' => 'boolean'];
    }
}
