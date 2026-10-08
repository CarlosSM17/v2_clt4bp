<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['course_id', 'resultado', 'recomendacion', 'decision', 'justificacion', 'decidido_por', 'decidido_at'])]
class GroupAnalysis extends Model
{
    protected function casts(): array
    {
        return ['resultado' => 'array', 'decidido_at' => 'datetime'];
    }
}
