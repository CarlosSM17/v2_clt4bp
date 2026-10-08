<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['course_id', 'uid', 'tipo', 'padre_uid', 'orden', 'contenido', 'estado', 'version', 'autor_tipo',
    'agent_run_id', 'actualizado_por', 'seq', 'eliminado_at'])]
class DesignElement extends Model
{
    protected function casts(): array
    {
        return [
            // 'object' y no 'array': así un {} vacío sigue siendo {} al volver a JSON
            'contenido' => 'object',
            'eliminado_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function versiones(): HasMany
    {
        return $this->hasMany(DesignElementVersion::class);
    }

    /** Lo que ve la consola al sincronizar. */
    public function paraSincronizar(): array
    {
        return [
            'uid' => $this->uid,
            'tipo' => $this->tipo,
            'padre_uid' => $this->padre_uid,
            'orden' => $this->orden,
            'contenido' => $this->contenido,
            'estado' => $this->estado,
            'version' => $this->version,
            'autor_tipo' => $this->autor_tipo,
            'eliminado' => $this->eliminado_at !== null,
            'seq' => $this->seq,
        ];
    }
}
