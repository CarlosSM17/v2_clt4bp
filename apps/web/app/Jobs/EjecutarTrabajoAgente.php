<?php

namespace App\Jobs;

use App\Models\AgentJob;
use App\Models\AgentQuota;
use App\Services\Agente\ClienteAgente;
use App\Services\Agente\ContextoAgente;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Llama al agente fuera de la petición HTTP: una generación puede tardar minutos. */
class EjecutarTrabajoAgente implements ShouldQueue
{
    use Queueable;

    public int $timeout; // > timeout HTTP hacia el agente (services.agente.timeout)

    public int $tries = 3;

    public array $backoff = [30, 120];

    public bool $failOnTimeout = true;

    public function __construct(public int $trabajoId)
    {
        $this->onQueue('agente'); // cola propia: no bloquea calificaciones ni análisis
        // Margen para armar el contexto (incluye buscar el material) y guardar el resultado
        $this->timeout = (int) config('services.agente.timeout', 280) + 60;
    }

    public function handle(ClienteAgente $agente, ContextoAgente $contexto): void
    {
        $trabajo = AgentJob::findOrFail($this->trabajoId);
        if ($trabajo->estado === 'listo') {
            return; // ya se completó en un intento anterior
        }
        $trabajo->update(['estado' => 'procesando']);
        $solicitud = $contexto->construir($trabajo);

        try {
            $r = $agente->generar($solicitud);
        } catch (RequestException $e) {
            if (in_array($e->response->status(), [401, 422], true)) {
                $this->fail($e); // token o solicitud inválidos: reintentar no lo arregla

                return;
            }
            throw $e; // 429, 502, 503: la cola reintenta con espera
        }

        DB::transaction(function () use ($trabajo, $r, $solicitud) {
            $trabajo->corrida()->create([
                'modelo' => $r->modelo,
                'version_prompt' => $r->version_prompt,
                'hash_contexto' => hash('sha256', json_encode($solicitud)),
                'tokens_entrada' => $r->uso->entrada,
                'tokens_salida' => $r->uso->salida,
                'tokens_cache_escritura' => $r->uso->cache_escritura,
                'tokens_cache_lectura' => $r->uso->cache_lectura,
                'costo_usd' => $r->uso->costo_usd,
                'duracion_ms' => $r->duracion_ms,
                'intentos' => $r->intentos,
                'validaciones' => json_decode(json_encode($r->validaciones), true),
                'fragmentos' => array_column($solicitud['material'] ?? [], 'id'),
            ]);
            $trabajo->update(['estado' => 'listo', 'resultado' => $r, 'error' => null]);
            AgentQuota::delMes($trabajo->solicitado_por)->increment('usado_usd', $r->uso->costo_usd);
        });
    }

    public function failed(?Throwable $e): void
    {
        AgentJob::whereKey($this->trabajoId)->update([
            'estado' => 'error',
            'error' => mb_substr($e?->getMessage() ?? 'Error desconocido.', 0, 1000),
        ]);
    }
}
