<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['instrument_administration_id', 'enrollment_id', 'respuestas', 'puntajes', 'completado_at'])]
class InstrumentResponse extends Model
{
    protected function casts(): array
    {
        return ['respuestas' => 'array', 'puntajes' => 'array', 'completado_at' => 'datetime'];
    }

    public function administration(): BelongsTo
    {
        return $this->belongsTo(InstrumentAdministration::class, 'instrument_administration_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
