<?php

namespace App\Services\Agente;

use App\Models\AgentRelayRequest;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Relevo hacia un agente que corre en otro equipo (ADR 0008). Con la plataforma en la nube y el agente en la máquina
 * del instructor, la nube no puede abrir una conexión hacia el agente: cada llamada queda como solicitud pendiente, el
 * conector del agente la reclama (sondeo largo, siempre de salida), la reenvía al agente y devuelve su respuesta.
 * Quien llama recibe la misma Response que en el modo directo, así que `->throw()`, `->json()` y los reintentos de la
 * cola no cambian.
 */
class Relevo
{
    private const VISTO = 'agente:relevo:visto';

    /**
     * @param  array<string, mixed>|null  $cuerpo
     * @param  array{0: resource, 1: string}|null  $archivo  flujo y nombre original (material del curso)
     * @param  bool  $interactivo  alguien espera la respuesta en pantalla: sin conector, fallar de inmediato
     *
     * @throws ConnectionException sin respuesta a tiempo (agente apagado o sin conector)
     */
    public function enviar(string $metodo, string $ruta, ?array $cuerpo, int $timeout, ?array $archivo = null, bool $interactivo = false): Response
    {
        if ($interactivo && ! $this->conectado()) {
            throw new ConnectionException('No hay ningún agente local conectado: inicia el agente en tu equipo (infra/agente-local).');
        }
        $solicitud = $this->crear($metodo, $ruta, $cuerpo, $timeout, $archivo);

        return $this->esperar($solicitud, $timeout);
    }

    /** @param  array{0: resource, 1: string}|null  $archivo */
    public function crear(string $metodo, string $ruta, ?array $cuerpo, int $timeout, ?array $archivo = null): AgentRelayRequest
    {
        $guardado = null;
        if ($archivo) {
            $guardado = 'relevo/'.Str::uuid()->toString();
            Storage::disk('local')->writeStream($guardado, $archivo[0]);
        }

        return AgentRelayRequest::create([
            'metodo' => $metodo,
            'ruta' => $ruta,
            'cuerpo' => $cuerpo === null ? null : json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'archivo' => $guardado,
            'archivo_nombre' => $archivo[1] ?? null,
            'vence_at' => now()->addSeconds($timeout),
        ]);
    }

    /** Espera la respuesta del conector; al vencer, la solicitud se retira para que nadie la atienda tarde. */
    public function esperar(AgentRelayRequest $solicitud, int $timeout, int $pausaMs = 300): Response
    {
        $limite = microtime(true) + $timeout;
        do {
            $actual = AgentRelayRequest::find($solicitud->id);
            if ($actual?->estado === 'respondida') {
                // Leída la respuesta, la solicitud sobra: no se guarda el diseño ni el resultado más de lo necesario
                $actual->borrarArchivo();
                $actual->delete();

                return new Response(new Psr7Response(
                    $actual->respuesta_estado ?? 502,
                    ['Content-Type' => 'application/json'],
                    $actual->respuesta ?? '',
                ));
            }
            usleep($pausaMs * 1000);
        } while (microtime(true) < $limite);

        $solicitud->borrarArchivo();
        $solicitud->delete();

        throw new ConnectionException(
            $this->conectado()
                ? "El agente local no respondió en {$timeout} s."
                : 'No hay ningún agente local conectado: inicia el agente en tu equipo (infra/agente-local).'
        );
    }

    /**
     * Para el conector: la solicitud pendiente más antigua, marcada como suya. Espera hasta $espera segundos a que
     * llegue una (sondeo largo): así el conector no satura la plataforma preguntando y la respuesta llega en un segundo.
     */
    public function reclamar(int $espera): ?AgentRelayRequest
    {
        $limite = microtime(true) + $espera;
        do {
            $this->marcarVisto();
            $id = DB::transaction(function () {
                // SKIP LOCKED: dos conectores (o dos hilos de uno) nunca reclaman la misma solicitud
                $fila = DB::selectOne(
                    "select id from agent_relay_requests where estado = 'pendiente' and vence_at > ? order by created_at limit 1 for update skip locked",
                    [now()],
                );
                if (! $fila) {
                    return null;
                }
                AgentRelayRequest::whereKey($fila->id)->update(['estado' => 'reclamada', 'reclamada_at' => now()]);

                return $fila->id;
            });
            if ($id) {
                return AgentRelayRequest::find($id);
            }
            if ($espera > 0) {
                usleep(500_000);
            }
        } while (microtime(true) < $limite);

        return null;
    }

    public function responder(AgentRelayRequest $solicitud, int $estado, string $cuerpo): void
    {
        $solicitud->update([
            'estado' => 'respondida',
            'respuesta_estado' => $estado,
            'respuesta' => $cuerpo,
            'respondida_at' => now(),
        ]);
    }

    public function marcarVisto(): void
    {
        Cache::put(self::VISTO, now()->getTimestamp(), now()->addDay());
    }

    /** ¿El conector preguntó por trabajo hace poco? (lo hace cada ~25 s mientras corre) */
    public function conectado(): bool
    {
        $visto = Cache::get(self::VISTO);

        return $visto !== null && now()->getTimestamp() - (int) $visto <= (int) config('services.agente.relevo_ausente', 90);
    }
}
