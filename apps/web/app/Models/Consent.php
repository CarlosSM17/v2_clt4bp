<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'tipo', 'version', 'otorgado_at', 'revocado_at'])]
class Consent extends Model
{
    protected function casts(): array
    {
        return ['otorgado_at' => 'datetime', 'revocado_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
