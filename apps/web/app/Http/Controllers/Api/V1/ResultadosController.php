<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoCurso;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\ResultReview;
use App\Services\Evaluacion\Exportador;
use App\Services\Evaluacion\ServicioResultados;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Revisión de resultados (paso 10), decisión de cerrar o iterar y exportación de datos. */
class ResultadosController extends Controller
{
    public function __construct(private readonly ServicioResultados $resultados) {}

    /** GET /courses/{course}/resultados?control={id}: el curso de control debe ser otro curso que el instructor imparte. */
    public function show(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('view', $course);
        $control = null;
        if ($request->filled('control')) {
            $control = Course::findOrFail((int) $request->query('control'));
            abort_if($control->id === $course->id, 422, 'El curso de control debe ser otro curso.');
            Gate::authorize('view', $control);
        }

        return response()->json(['data' => $this->resultados->revision($course, $control)]);
    }

    /** POST /courses/{course}/resultados/decision {decision, regresar_a?, notas, agent_job_id?} */
    public function decidir(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('update', $course);
        $datos = $request->validate([
            'decision' => ['required', Rule::in(['cerrar', 'iterar'])],
            'regresar_a' => ['nullable', 'required_if:decision,iterar', Rule::in(['fase1', 'fase2'])],
            'notas' => ['required', 'string', 'min:10', 'max:5000'],
            'agent_job_id' => ['nullable', 'integer',
                Rule::exists('agent_jobs', 'id')->where('course_id', $course->id)->where('plantilla', 'informe_revision')],
        ]);

        // La fotografía de los resultados se guarda con la decisión: después pueden cambiar (nuevas entregas)
        $resumen = $this->resultados->revision($course);
        unset($resumen['revisiones']);

        $revision = DB::transaction(function () use ($datos, $course, $resumen, $request) {
            Course::whereKey($course->id)->lockForUpdate()->first(); // dos decisiones a la vez no repiten número

            return ResultReview::create([
                ...$datos,
                'regresar_a' => $datos['decision'] === 'iterar' ? $datos['regresar_a'] : null,
                'course_id' => $course->id,
                'numero' => (int) ResultReview::where('course_id', $course->id)->max('numero') + 1,
                'resumen' => $resumen,
                'decidido_por' => $request->user()->id,
            ]);
        });
        if ($revision->decision === 'cerrar') {
            $course->update(['estado' => EstadoCurso::Concluido]); // el material sigue visible; el ciclo de diseño terminó
        }
        Auditoria::registrar('resultados.decision', $course, ['decision' => $revision->decision, 'numero' => $revision->numero]);

        return response()->json(['data' => $revision], 201);
    }

    /** GET /courses/{course}/exportacion?formato=csv|xlsx */
    public function exportar(Request $request, Course $course, Exportador $exportador): BinaryFileResponse
    {
        Gate::authorize('update', $course);
        $formato = $request->validate(['formato' => ['required', Rule::in(['csv', 'xlsx'])]])['formato'];

        $ruta = $exportador->generar($course, $formato);
        Auditoria::registrar('datos.exportados', $course, ['formato' => $formato]);
        $nombre = 'clt4bp-curso'.$course->id.'-'.now()->format('Ymd-His').($formato === 'xlsx' ? '.xlsx' : '.zip');

        return response()->download($ruta, $nombre)->deleteFileAfterSend();
    }
}
