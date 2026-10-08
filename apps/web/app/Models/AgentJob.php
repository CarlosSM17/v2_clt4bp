<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['course_id', 'solicitado_por', 'plantilla', 'paso', 'parametros', 'estado', 'resultado', 'error', 'clave_idempotencia'])]
class AgentJob extends Model
{
    protected function casts(): array
    {
        return [
            'parametros' => 'array',
            'resultado' => 'object', // conserva {} y [] tal como los devolvió el agente
        ];
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    public function corrida(): HasOne
    {
        return $this->hasOne(AgentRun::class);
    }
}
