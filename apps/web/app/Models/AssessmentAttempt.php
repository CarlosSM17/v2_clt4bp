<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['assessment_id', 'enrollment_id', 'iniciado_at', 'enviado_at', 'calificado_at', 'porcentaje', 'subpuntajes'])]
class AssessmentAttempt extends Model
{
    protected function casts(): array
    {
        return [
            'iniciado_at' => 'datetime',
            'enviado_at' => 'datetime',
            'calificado_at' => 'datetime',
            'porcentaje' => 'float',
            'subpuntajes' => 'array',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(ItemResponse::class);
    }
}
