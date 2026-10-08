<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['enrollment_id', 'tarea_uid', 'release_id', 'numero', 'codigo', 'autoexplicacion', 'estado', 'resultado', 'fraccion'])]
class TaskSubmission extends Model
{
    protected function casts(): array
    {
        return ['resultado' => 'array', 'fraccion' => 'float'];
    }

    public function inscripcion(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }
}
