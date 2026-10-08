<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\Evaluacion\ServicioEvaluacion;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Evaluación final desde la consola: abrir la ventana y ver quién va en qué. */
class EvaluacionController extends Controller
{
    public function __construct(private readonly ServicioEvaluacion $evaluacion) {}

    /** POST /courses/{course}/evaluacion-final {abre_at, cierra_at, instrumentos[]} */
    public function preparar(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('update', $course);
        $datos = $request->validate([
            'abre_at' => ['required', 'date'],
            'cierra_at' => ['required', 'date', 'after:abre_at'],
            'instrumentos' => ['present', 'array'],
            'instrumentos.*' => [Rule::in(ServicioEvaluacion::FINALES)],
        ]);

        $r = $this->evaluacion->preparar($course, CarbonImmutable::parse($datos['abre_at']),
            CarbonImmutable::parse($datos['cierra_at']), array_values(array_unique($datos['instrumentos'])));

        return response()->json(['data' => $r]);
    }

    /** GET /courses/{course}/evaluacion-final: ventana y pasos «post» de cada estudiante. */
    public function estado(Course $course): JsonResponse
    {
        Gate::authorize('view', $course);
        $inscripciones = $course->enrollments()->with('user:id,name')
            ->whereNotIn('estado', ['solicitud', 'rechazada', 'baja'])->orderBy('id')->get();

        return response()->json(['data' => [
            'ventana' => $this->evaluacion->ventana($course->id),
            'abierta' => $this->evaluacion->abierta($course->id),
            'estudiantes' => $inscripciones->map(fn ($e) => [
                'enrollment_id' => $e->id, 'nombre' => $e->user->name, 'seudonimo' => $e->seudonimo,
                'estado' => $e->estado, 'pasos' => $this->evaluacion->pasos($e),
            ]),
        ]]);
    }
}
