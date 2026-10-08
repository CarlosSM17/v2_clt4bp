<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['course_id', 'clave', 'nombre', 'nivel', 'orden'])]
class DiffGroup extends Model
{
    public function membresiasVigentes(): HasMany
    {
        return $this->hasMany(GroupMembership::class)->whereNull('hasta');
    }
}
