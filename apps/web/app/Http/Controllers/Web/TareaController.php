<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\CalificarEnvio;
use App\Models\Course;
use App\Models\TaskProgress;
use App\Models\TaskSubmission;
use App\Services\Aula\ContextoAula;
use App\Services\Aula\Eventos;
use App\Services\Aula\ServicioAula;
use App\Services\Codigo\EjecutorCodigo;
use App\Services\Codigo\EvaluadorCasos;
use App\Services\Evaluacion\SeleccionAdaptativa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Acciones del estudiante dentro de una tarea: autoguardado, ejecutar, enviar, completar y valorar el esfuerzo. */
class TareaController extends Controller
{
    public function __construct(private readonly ServicioAula $aula) {}

    public function borrador(Request $request, Course $curso, string $tarea): Response
    {
        $ctx = $this->ctx($request, $curso);
        $ctx->tarea($tarea);
        $datos = $request->validate(['codigo' => ['present', 'nullable', 'string', 'max:20000']]);
        TaskProgress::updateOrCreate(
            ['enrollment_id' => $ctx->inscripcion->id, 'tarea_uid' => $tarea],
            ['borrador_codigo' => $datos['codigo']],
        );

        return response()->noContent();
    }

    /** «Ejecutar»: corre el código con la entrada que escriba el estudiante; no califica. */
    public function ejecutar(Request $request, Course $curso, string $tarea, EjecutorCodigo $ejecutor): JsonResponse
    {
        $ctx = $this->ctx($request, $curso);
        $t = $ctx->tarea($tarea);
        $datos = $request->validate(['codigo' => ['required', 'string', 'max:20000'], 'entrada' => ['nullable', 'string', 'max:10000']]);

        $r = $ejecutor->ejecutar($t['lenguaje'], $datos['codigo'], $datos['entrada'] ?? '');
        Eventos::registrar($ctx->inscripcion->id, 'ejecuto', 'tarea', $tarea, ['compilo' => $r->compilo, 'excedio' => $r->excedioLimite], $ctx->release->id);

        return response()->json([
            'compilo' => $r->compilo,
            'salida' => mb_substr($r->salida, 0, 5000),
            'errores' => mb_substr($r->errores, 0, 5000),
            'excedio_limite' => $r->excedioLimite,
        ]);
    }

    /** «Enviar»: se califica en segundo plano contra todos los casos, incluidos los ocultos. */
    public function enviar(Request $request, Course $curso, string $tarea): JsonResponse
    {
        $ctx = $this->ctx($request, $curso);
        $t = $ctx->tarea($tarea);
        abort_unless($ctx->puedeEnviar($t['clase_uid']), 409, 'Esta clase no está abierta para envíos.');
        abort_if($t['nivel_apoyo'] === 'ejemplo_resuelto', 422, 'Un ejemplo resuelto no se envía: se estudia.');
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:20000'],
            'autoexplicacion' => [($t['pide_autoexplicacion'] ?? false) ? 'required' : 'nullable', 'string', 'min:20', 'max:3000'],
        ], ['autoexplicacion.required' => 'Antes de enviar, explica con tus palabras cómo resolviste la tarea.']);

        $envio = TaskSubmission::create([
            'enrollment_id' => $ctx->inscripcion->id,
            'tarea_uid' => $tarea,
            'release_id' => $ctx->release->id,
            'numero' => TaskSubmission::where('enrollment_id', $ctx->inscripcion->id)->where('tarea_uid', $tarea)->count() + 1,
            'codigo' => $datos['codigo'],
            'autoexplicacion' => $datos['autoexplicacion'] ?? null,
        ]);
        Eventos::registrar($ctx->inscripcion->id, 'envio', 'tarea', $tarea, ['envio_id' => $envio->id, 'numero' => $envio->numero], $ctx->release->id);
        CalificarEnvio::dispatch($envio->id);

        return response()->json(['id' => $envio->id], 202);
    }

    /** La interfaz consulta aquí el resultado de un envío hasta que está calificado. */
    public function envio(Request $request, TaskSubmission $envio): JsonResponse
    {
        abort_unless($envio->inscripcion->user_id === $request->user()->id, 403);

        // JSON_PRESERVE_ZERO_FRACTION: 1.0 (100 %) no debe llegar como el entero 1
        return response()->json($envio->only('id', 'numero', 'estado', 'fraccion', 'resultado'), 200, options: JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Ejemplo resuelto: se marca como estudiado (con su auto-explicación, si la pide). */
    public function completar(Request $request, Course $curso, string $tarea): Response
    {
        $ctx = $this->ctx($request, $curso);
        $t = $ctx->tarea($tarea);
        abort_unless($t['nivel_apoyo'] === 'ejemplo_resuelto', 422);
        $datos = $request->validate([
            'autoexplicacion' => [($t['pide_autoexplicacion'] ?? false) ? 'required' : 'nullable', 'string', 'min:20', 'max:3000'],
        ]);

        TaskProgress::updateOrCreate(['enrollment_id' => $ctx->inscripcion->id, 'tarea_uid' => $tarea], [
            'estado' => 'completada', 'completado_at' => now(),
            'autoexplicacion' => $datos['autoexplicacion'] ?? null,
        ]);
        Eventos::registrar($ctx->inscripcion->id, 'completo_tarea', 'tarea', $tarea, null, $ctx->release->id);

        return response()->noContent();
    }

    /** Esfuerzo mental percibido (Paas, 1 = muy, muy bajo … 9 = muy, muy alto). */
    public function esfuerzo(Request $request, Course $curso, string $tarea, SeleccionAdaptativa $seleccion): Response|JsonResponse
    {
        $ctx = $this->ctx($request, $curso);
        $ctx->tarea($tarea);
        $valor = (int) $request->validate(['valor' => ['required', 'integer', 'between:1,9']])['valor'];

        TaskProgress::updateOrCreate(['enrollment_id' => $ctx->inscripcion->id, 'tarea_uid' => $tarea], ['esfuerzo' => $valor]);
        Eventos::registrar($ctx->inscripcion->id, 'valoro_esfuerzo', 'tarea', $tarea, ['valor' => $valor], $ctx->release->id);

        if (! ($curso->configuracion['seleccion_adaptativa'] ?? false)) {
            return response()->noContent();
        }

        return response()->json(['sugerencia' => $seleccion->sugerir($ctx, $tarea)]);
    }

    /** Práctica rápida: se comprueba al momento (son ejercicios cortos) y no cuenta como envío. */
    public function comprobarPractica(Request $request, Course $curso, string $practica, int $ejercicio, EvaluadorCasos $evaluador): JsonResponse
    {
        $ctx = $this->ctx($request, $curso);
        $p = collect($ctx->manifiesto['practica_parcial'])->firstWhere('uid', $practica) ?? abort(404);
        $e = $p['ejercicios'][$ejercicio] ?? abort(404);
        $codigo = $request->validate(['codigo' => ['required', 'string', 'max:10000']])['codigo'];

        $r = $evaluador->evaluar($p['lenguaje'], $codigo, $e['casos_prueba']);
        Eventos::registrar($ctx->inscripcion->id, 'comprobo_practica', 'practica', $practica,
            ['ejercicio' => $ejercicio, 'aprobados' => $r['aprobados'], 'total' => $r['total']], $ctx->release->id);

        return response()->json($r);
    }

    private function ctx(Request $request, Course $curso): ContextoAula
    {
        return $this->aula->contexto(AulaController::inscripcion($request, $curso) ?? abort(403));
    }
}
