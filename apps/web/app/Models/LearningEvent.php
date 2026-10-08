<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['enrollment_id', 'verbo', 'objeto_tipo', 'objeto_uid', 'release_id', 'resultado', 'duracion_ms', 'origen', 'ocurrido_at'])]
class LearningEvent extends Model
{
    public $timestamps = false; // ocurrido_at (reloj del cliente) y recibido_at (servidor)

    protected function casts(): array
    {
        return ['resultado' => 'array', 'ocurrido_at' => 'datetime', 'recibido_at' => 'datetime'];
    }
}
