<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['assessment_attempt_id', 'item_id', 'respuesta', 'fraccion', 'detalle'])]
class ItemResponse extends Model
{
    protected function casts(): array
    {
        return ['respuesta' => 'array', 'detalle' => 'array', 'fraccion' => 'float'];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AssessmentAttempt::class, 'assessment_attempt_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
