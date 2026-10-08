<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class Auditoria
{
    /** Registra una acción sensible. Llamar después de que la acción tuvo éxito. */
    public static function registrar(string $accion, ?Model $entidad = null, array $datos = []): void
    {
        AuditLog::create([
            'actor_id' => auth()->id(),
            'accion' => $accion,
            'entidad_tipo' => $entidad ? $entidad->getMorphClass() : null,
            'entidad_id' => $entidad?->getKey(),
            'datos' => $datos ?: null,
            'ip' => request()?->ip(),
        ]);
    }
}
