<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['enrollment_id', 'tarea_uid', 'estado', 'borrador_codigo', 'intentos', 'mejor_fraccion', 'esfuerzo',
    'autoexplicacion', 'iniciado_at', 'completado_at'])]
class TaskProgress extends Model
{
    protected $table = 'task_progress';

    protected function casts(): array
    {
        return ['mejor_fraccion' => 'float', 'iniciado_at' => 'datetime', 'completado_at' => 'datetime'];
    }
}
