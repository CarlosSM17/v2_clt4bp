<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['course_id', 'clase_uid', 'diff_group_id', 'abre_at', 'cierra_at', 'requiere_anterior', 'avisado_at'])]
class Activation extends Model
{
    protected function casts(): array
    {
        return ['abre_at' => 'immutable_datetime', 'cierra_at' => 'immutable_datetime', 'avisado_at' => 'datetime', 'requiere_anterior' => 'boolean'];
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(DiffGroup::class, 'diff_group_id');
    }
}
