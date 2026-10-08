<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['course_id', 'instrument_id', 'momento', 'clase_uid', 'abre_at', 'cierra_at'])]
class InstrumentAdministration extends Model
{
    protected function casts(): array
    {
        return ['abre_at' => 'datetime', 'cierra_at' => 'datetime'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(InstrumentResponse::class);
    }

    public function abierta(): bool
    {
        return (! $this->abre_at || $this->abre_at->isPast()) && (! $this->cierra_at || $this->cierra_at->isFuture());
    }
}
