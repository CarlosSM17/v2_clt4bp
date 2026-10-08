<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['enrollment_id', 'version', 'cp_recall', 'cp_comprension', 'cp_teorico', 'cp_practico', 'cp_global', 'nivel', 'mslq', 'indices', 'banderas'])]
class StudentProfile extends Model
{
    protected function casts(): array
    {
        return [
            'cp_recall' => 'float',
            'cp_comprension' => 'float',
            'cp_teorico' => 'float',
            'cp_practico' => 'float',
            'cp_global' => 'float',
            'mslq' => 'array',
            'indices' => 'array',
            'banderas' => 'array',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
