<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['course_id', 'nombre', 'momento', 'tipo', 'forma', 'tiempo_limite_min', 'abre_at', 'cierra_at'])]
class Assessment extends Model
{
    protected function casts(): array
    {
        return ['abre_at' => 'datetime', 'cierra_at' => 'datetime'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'assessment_items')
            ->withPivot('orden', 'puntos')->orderByPivot('orden');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(AssessmentAttempt::class);
    }

    /** Sin fechas = siempre abierta (así funcionan las pruebas pre del diagnóstico). */
    public function abierta(): bool
    {
        return (! $this->abre_at || $this->abre_at->isPast()) && (! $this->cierra_at || $this->cierra_at->isFuture());
    }
}
