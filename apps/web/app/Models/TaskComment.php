<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['course_id', 'tarea_uid', 'diff_group_id', 'user_id', 'texto', 'oculto'])]
class TaskComment extends Model
{
    protected function casts(): array
    {
        return ['oculto' => 'boolean'];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
