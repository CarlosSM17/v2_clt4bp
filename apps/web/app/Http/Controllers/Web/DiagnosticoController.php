<?php

namespace App\Http\Controllers\Web;

use App\Domain\Pruebas\CalificadorItems;
use App\Enums\EstadoInscripcion;
use App\Http\Controllers\Controller;
use App\Jobs\CalificarIntento;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\InstrumentAdministration;
use App\Models\InstrumentResponse;
use App\Models\ItemResponse;
use App\Services\Codigo\EjecutorCodigo;
use App\Services\Diagnostico\ServicioDiagnostico;
use App\Services\Evaluacion\ServicioEvaluacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DiagnosticoController extends Controller
{
    public function __construct(
        private readonly ServicioDiagnostico $servicio,
        private readonly ServicioEvaluacion $evaluacion,
    ) {}

    public function index(Request $request, Course $curso): Response
    {
        $inscripcion = $this->inscripcion($request, $curso);
        if ($inscripcion->estado === EstadoInscripcion::Inscrito) {
            $inscripcion->update(['estado' => EstadoInscripcion::Diagnostico]);
        }

        return Inertia::render('diagnostico/Index', [
            'curso' => $curso->only('id', 'titulo'),
            'pasos' => $this->servicio->pasos($inscripcion),
            'estado' => $inscripcion->estado,
        ]);
    }

    // ---------- Cuestionarios Likert ----------

    public function cuestionario(Request $request, Course $curso, InstrumentAdministration $aplicacion): Response
    {
        $inscripcion = $this->inscripcion($request, $curso);
        abort_unless($aplicacion->course_id === $curso->id && $aplicacion->abierta(), 404);

        $respuesta = InstrumentResponse::firstOrCreate(
            ['instrument_administration_id' => $aplicacion->id, 'enrollment_id' => $inscripcion->id],
        );
        $def = $aplicacion->instrument->definicion;

        return Inertia::render('diagnostico/Cuestionario', [
            'curso' => $curso->only('id', 'titulo'),
            'aplicacionId' => $aplicacion->id,
            'nombre' => $def['nombre'],
            'escala' => $def['escala'],
            'partes' => $def['partes'],
            'items' => collect($def['items'])->map(fn ($i) => ['id' => $i['id'], 'texto' => $i['texto']]),
            'respuestas' => (object) ($respuesta->respuestas ?? []),
            'completado' => $respuesta->completado_at !== null,
        ]);
    }

    /** Guarda respuestas parciales (se llama al cambiar de página del cuestionario). */
    public function guardarCuestionario(Request $request, Course $curso, InstrumentAdministration $aplicacion): RedirectResponse
    {
        $respuesta = $this->respuestaAbierta($request, $curso, $aplicacion);
        $escala = $aplicacion->instrument->definicion['escala'];
        $datos = $request->validate([
            'respuestas' => ['required', 'array'],
            'respuestas.*' => ['integer', "between:{$escala['min']},{$escala['max']}"],
        ]);

        $validos = array_intersect_key($datos['respuestas'], array_flip($aplicacion->instrument->puntuador()->idsDeItems()));
        $respuesta->update(['respuestas' => array_merge($respuesta->respuestas ?? [], $validos)]);

        return back();
    }

    public function completarCuestionario(Request $request, Course $curso, InstrumentAdministration $aplicacion): RedirectResponse
    {
        $respuesta = $this->respuestaAbierta($request, $curso, $aplicacion);
        $puntuador = $aplicacion->instrument->puntuador();

        $faltan = $puntuador->faltantes($respuesta->respuestas ?? []);
        if ($faltan !== []) {
            return back()->withErrors(['respuestas' => 'Faltan '.count($faltan).' respuestas.']);
        }

        $respuesta->update(['puntajes' => $puntuador->puntuar($respuesta->respuestas), 'completado_at' => now()]);
        match ($aplicacion->momento) {
            'pre' => $this->servicio->verificarCompleto($respuesta->enrollment),
            'post' => $this->evaluacion->verificarCompleto($respuesta->enrollment),
            default => null, // «clase»: la escala CS al cerrar una clase de tareas
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cuestionario completado. ¡Gracias!']);

        return $this->volver($curso->id, $aplicacion->momento);
    }

    // ---------- Pruebas ----------

    public function prueba(Request $request, Course $curso, Assessment $prueba): Response|RedirectResponse
    {
        $inscripcion = $this->inscripcion($request, $curso);
        // Las pruebas pre siempre están disponibles; las post, solo dentro de la ventana de evaluación final
        abort_unless($prueba->course_id === $curso->id && ($prueba->momento === 'pre' || $prueba->abierta()), 404);

        $intento = AssessmentAttempt::firstOrCreate(
            ['assessment_id' => $prueba->id, 'enrollment_id' => $inscripcion->id],
            ['iniciado_at' => now()],
        );
        if ($intento->enviado_at) {
            return $this->volver($curso->id, $prueba->momento);
        }

        return Inertia::render('diagnostico/Prueba', [
            'curso' => $curso->only('id', 'titulo'),
            'prueba' => $prueba->only('id', 'nombre', 'tipo', 'tiempo_limite_min'),
            'intentoId' => $intento->id,
            'venceAt' => $prueba->tiempo_limite_min ? $intento->iniciado_at->addMinutes($prueba->tiempo_limite_min) : null,
            'items' => $prueba->items->map(fn ($i) => $i->paraEstudiante()),
            'respuestas' => (object) $intento->responses()->pluck('respuesta', 'item_id')->all(),
        ]);
    }

    public function guardarRespuesta(Request $request, AssessmentAttempt $intento): RedirectResponse
    {
        $this->intentoAbierto($request, $intento);
        $datos = $request->validate([
            'item_id' => ['required', 'integer', Rule::exists('assessment_items', 'item_id')->where('assessment_id', $intento->assessment_id)],
            'respuesta' => ['present'],
        ]);

        ItemResponse::updateOrCreate(
            ['assessment_attempt_id' => $intento->id, 'item_id' => $datos['item_id']],
            ['respuesta' => ['valor' => $datos['respuesta']['valor'] ?? null, 'codigo' => $datos['respuesta']['codigo'] ?? null]],
        );

        return back();
    }

    /** "Ejecutar" en un problema de programación: corre el código con la entrada que escriba el estudiante. */
    public function ejecutar(Request $request, AssessmentAttempt $intento, EjecutorCodigo $ejecutor): JsonResponse
    {
        $this->intentoAbierto($request, $intento);
        $datos = $request->validate([
            'item_id' => ['required', 'integer'],
            'codigo' => ['required', 'string', 'max:20000'],
            'entrada' => ['nullable', 'string', 'max:10000'],
        ]);
        $item = $intento->assessment->items()->whereKey($datos['item_id'])->where('tipo', 'programacion')->firstOrFail();

        $r = $ejecutor->ejecutar($item->lenguaje, $datos['codigo'], $datos['entrada'] ?? '');

        return response()->json([
            'compilo' => $r->compilo,
            'salida' => mb_substr($r->salida, 0, 5000),
            'errores' => mb_substr($r->errores, 0, 5000),
            'excedio_limite' => $r->excedioLimite,
        ]);
    }

    public function enviar(Request $request, AssessmentAttempt $intento, CalificadorItems $calificador): RedirectResponse
    {
        $this->intentoAbierto($request, $intento, permitirVencido: true);
        $respuestas = $intento->responses()->get()->keyBy('item_id');

        // Los ítems cerrados se califican ya; la programación, en segundo plano
        foreach ($intento->assessment->items as $item) {
            if ($item->tipo === 'programacion') {
                continue;
            }
            $r = $respuestas->get($item->id) ?? new ItemResponse(['assessment_attempt_id' => $intento->id, 'item_id' => $item->id]);
            $r->fraccion = $calificador->fraccion($item->tipo, $item->clave ?? [], $r->respuesta['valor'] ?? null);
            $r->save();
        }
        foreach ($intento->assessment->items->where('tipo', 'programacion') as $item) {
            ItemResponse::firstOrCreate(['assessment_attempt_id' => $intento->id, 'item_id' => $item->id]);
        }

        $intento->update(['enviado_at' => now()]);
        CalificarIntento::dispatch($intento->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Prueba enviada. La calificación se completa en unos segundos.']);

        return $this->volver($intento->assessment->course_id, $intento->assessment->momento);
    }

    // ---------- Ayudantes ----------

    /** Cada paso regresa a su lista: el diagnóstico (pre), la evaluación final (post) o el mapa (escala CS de una clase). */
    private function volver(int $cursoId, string $momento): RedirectResponse
    {
        return redirect()->route(match ($momento) {
            'post' => 'evaluacion.final',
            'clase' => 'aula.mapa',
            default => 'diagnostico.index',
        }, $cursoId);
    }

    private function inscripcion(Request $request, Course $curso): Enrollment
    {
        Gate::authorize('acceder', $curso);

        return Enrollment::where('course_id', $curso->id)->where('user_id', $request->user()->id)->firstOrFail();
    }

    private function respuestaAbierta(Request $request, Course $curso, InstrumentAdministration $aplicacion): InstrumentResponse
    {
        $inscripcion = $this->inscripcion($request, $curso);
        abort_unless($aplicacion->course_id === $curso->id && $aplicacion->abierta(), 404);
        $respuesta = InstrumentResponse::firstOrCreate(
            ['instrument_administration_id' => $aplicacion->id, 'enrollment_id' => $inscripcion->id],
        );
        abort_if($respuesta->completado_at !== null, 409, 'Este cuestionario ya fue completado.');

        return $respuesta;
    }

    /** Dueño correcto y aún sin enviar. Al enviar se permite rebasar el tiempo (envío automático). */
    private function intentoAbierto(Request $request, AssessmentAttempt $intento, bool $permitirVencido = false): void
    {
        abort_unless($intento->enrollment->user_id === $request->user()->id, 403);
        abort_if($intento->enviado_at !== null, 409, 'Esta prueba ya fue enviada.');
        $limite = $intento->assessment->tiempo_limite_min;
        $vencido = $limite && $intento->iniciado_at->addMinutes($limite + 1)->isPast();   // 1 minuto de gracia
        abort_if($vencido && ! $permitirVencido, 409, 'Se agotó el tiempo de la prueba.');
    }
}
