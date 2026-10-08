<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['diff_group_id', 'enrollment_id', 'desde', 'hasta', 'motivo'])]
class GroupMembership extends Model
{
    protected function casts(): array
    {
        return ['desde' => 'datetime', 'hasta' => 'datetime'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(DiffGroup::class, 'diff_group_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
