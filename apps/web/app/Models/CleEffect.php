<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'nombre', 'grupo', 'definicion', 'materializacion', 'cuando_usar', 'cuando_no'])]
class CleEffect extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';
}
