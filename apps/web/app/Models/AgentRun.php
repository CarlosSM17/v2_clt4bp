<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agent_job_id', 'modelo', 'version_prompt', 'hash_contexto', 'tokens_entrada', 'tokens_salida',
    'tokens_cache_escritura', 'tokens_cache_lectura', 'costo_usd', 'duracion_ms', 'intentos', 'validaciones',
    'decision', 'uids_aceptados', 'decidido_at', 'fragmentos'])]
class AgentRun extends Model
{
    protected function casts(): array
    {
        return [
            'validaciones' => 'array',
            'uids_aceptados' => 'array',
            'fragmentos' => 'array',
            'costo_usd' => 'float',
            'decidido_at' => 'datetime',
        ];
    }

    public function trabajo(): BelongsTo
    {
        return $this->belongsTo(AgentJob::class, 'agent_job_id');
    }
}
