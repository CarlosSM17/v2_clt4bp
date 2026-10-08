<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agente\AlcancePlantilla;
use App\Http\Controllers\Controller;
use App\Jobs\EjecutarTrabajoAgente;
use App\Models\AgentJob;
use App\Models\AgentQuota;
use App\Models\Course;
use App\Models\DocumentFragment;
use App\Models\User;
use App\Services\Agente\ClienteAgente;
use App\Services\Agente\ContextoAgente;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Puerta de enlace hacia el agente: la consola nunca habla con él directamente. */
class AgenteController extends Controller
{
    public function plantillas(ClienteAgente $agente): JsonResponse
    {
        return response()->json(['data' => Cache::remember('agente.plantillas', 3600, fn () => $agente->plantillas())]);
    }

    /** GET /courses/{course}/agent/jobs: los últimos trabajos del curso (sin el resultado, que pesa). */
    public function index(Course $course): JsonResponse
    {
        Gate::authorize('update', $course);

        return response()->json(['data' => AgentJob::where('course_id', $course->id)->latest()->limit(20)
            ->with('corrida:id,agent_job_id,decision')
            ->get(['id', 'plantilla', 'paso', 'estado', 'parametros', 'error', 'created_at', 'updated_at'])
            // La lista dice qué propuestas ya se guardaron en el diseño o se descartaron
            ->map(fn (AgentJob $j) => [...$j->withoutRelations()->toArray(), 'decision' => $j->corrida?->decision])]);
    }

    /**
     * DELETE /agent/jobs/{job}: el instructor elimina una propuesta (y su corrida). Lo que ya guardó en el diseño se
     * queda; solo pierde el enlace a la corrida de origen.
     */
    public function destroy(AgentJob $job): Response
    {
        Gate::authorize('update', $job->curso);
        abort_if(in_array($job->estado, ['en_cola', 'procesando'], true), 409, 'La propuesta todavía se está generando: espera a que termine.');
        Auditoria::registrar('agente.propuesta_eliminada', $job->curso, ['trabajo' => $job->id, 'plantilla' => $job->plantilla]);
        $job->delete();

        return response()->noContent();
    }

    /** POST /courses/{course}/agent/jobs */
    public function store(Request $request, Course $course, ContextoAgente $contexto): JsonResponse
    {
        Gate::authorize('update', $course);
        $datos = $request->validate([
            'plantilla' => ['required', Rule::in(array_keys(AlcancePlantilla::PASOS))],
            'alcance' => ['present', 'array'],
            'indicaciones' => ['nullable', 'string', 'max:4000'],
            'calidad' => ['nullable', Rule::in(['normal', 'alta'])],
            'clave_idempotencia' => ['required', 'uuid'],
        ]);
        $usuario = $request->user();

        // Idempotencia: si la consola repite la petición (red inestable), recibe el mismo trabajo
        $previo = AgentJob::where('solicitado_por', $usuario->id)->where('clave_idempotencia', $datos['clave_idempotencia'])->first();
        if ($previo) {
            return response()->json(['data' => $previo]);
        }

        $errores = AlcancePlantilla::validar($datos['plantilla'], $datos['alcance'], $contexto->existentes($course));
        if ($errores) {
            throw ValidationException::withMessages(['alcance' => $errores]);
        }
        abort_if(AgentQuota::delMes($usuario)->agotada(), 429, 'Se agotó tu cuota mensual del agente. Pide al administrador que la amplíe.');

        $trabajo = AgentJob::create([
            'course_id' => $course->id,
            'solicitado_por' => $usuario->id,
            'plantilla' => $datos['plantilla'],
            'paso' => AlcancePlantilla::PASOS[$datos['plantilla']],
            'parametros' => Arr::only($datos, ['alcance', 'indicaciones', 'calidad']),
            'clave_idempotencia' => $datos['clave_idempotencia'],
        ]);
        EjecutarTrabajoAgente::dispatch($trabajo->id);
        Auditoria::registrar('agente.solicitado', $trabajo, ['plantilla' => $trabajo->plantilla]);

        return response()->json(['data' => $trabajo->fresh()], 202);
    }

    /** GET /agent/jobs/{job}: la consola lo consulta cada pocos segundos hasta que está listo. */
    public function show(AgentJob $job): JsonResponse
    {
        Gate::authorize('update', $job->curso);

        return response()->json(['data' => [
            ...$job->toArray(),
            'corrida' => $job->corrida?->only(['id', 'modelo', 'costo_usd', 'duracion_ms', 'intentos', 'decision']),
            // Qué material del curso consultó el agente (RAG). Un documento borrado después ya no aparece
            'material' => DocumentFragment::with('documento:id,titulo')
                ->whereIn('id', $job->corrida?->fragmentos ?? [])->orderBy('id')->get()
                ->map(fn (DocumentFragment $f) => ['documento' => $f->documento->titulo, 'pagina' => $f->pagina]),
        ]]);
    }

    /** POST /agent/jobs/{job}/decision: qué hizo el instructor con la propuesta (dato de investigación). */
    public function decision(Request $request, AgentJob $job): JsonResponse
    {
        Gate::authorize('update', $job->curso);
        abort_unless($job->estado === 'listo' && $job->corrida, 409, 'Este trabajo no tiene propuesta.');
        $datos = $request->validate([
            'decision' => ['required', Rule::in(['aceptado', 'parcial', 'descartado'])],
            'uids' => ['present', 'array'],
            'uids.*' => ['string', 'max:64'],
        ]);
        $job->corrida->update(['decision' => $datos['decision'], 'uids_aceptados' => $datos['uids'], 'decidido_at' => now()]);

        return response()->json(['data' => $job->corrida->only(['id', 'decision', 'uids_aceptados', 'decidido_at'])]);
    }

    /** GET /agent/usage: cuánto lleva gastado el instructor este mes. */
    public function uso(Request $request): JsonResponse
    {
        return response()->json(['data' => AgentQuota::delMes($request->user())->only(['periodo', 'limite_usd', 'usado_usd'])]);
    }

    /** PUT /admin/agent-quotas/{user} {limite_usd} */
    public function actualizarCuota(Request $request, User $user): JsonResponse
    {
        $datos = $request->validate(['limite_usd' => ['required', 'numeric', 'min:0', 'max:10000']]);
        $cuota = AgentQuota::delMes($user);
        $antes = $cuota->limite_usd;
        $cuota->update($datos);
        Auditoria::registrar('agente.cuota', $cuota, ['antes' => $antes, 'despues' => $cuota->limite_usd]);

        return response()->json(['data' => $cuota]);
    }
}
