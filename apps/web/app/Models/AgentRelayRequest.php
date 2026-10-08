<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Facades\Storage;

/** Una llamada al agente local en espera de su conector (ADR 0008). */
#[Fillable(['metodo', 'ruta', 'cuerpo', 'archivo', 'archivo_nombre', 'estado', 'respuesta_estado', 'respuesta', 'vence_at', 'reclamada_at', 'respondida_at'])]
class AgentRelayRequest extends Model
{
    use HasUuids, Prunable;

    protected function casts(): array
    {
        return [
            // «cuerpo» queda como texto JSON: decodificarlo en PHP convertiría {} en [] y el agente lo rechazaría
            'vence_at' => 'datetime',
            'reclamada_at' => 'datetime',
            'respondida_at' => 'datetime',
        ];
    }

    /** Solicitudes y respuestas viejas (llevan el diseño seudonimizado): fuera a las 24 horas. */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDay());
    }

    protected function pruning(): void
    {
        $this->borrarArchivo();
    }

    public function borrarArchivo(): void
    {
        if ($this->archivo) {
            Storage::disk('local')->delete($this->archivo);
        }
    }
}
