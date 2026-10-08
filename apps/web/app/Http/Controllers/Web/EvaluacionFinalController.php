<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\Evaluacion\ServicioEvaluacion;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Lista de pasos de la evaluación final. Cada paso usa las mismas páginas del diagnóstico. */
class EvaluacionFinalController extends Controller
{
    public function __construct(private readonly ServicioEvaluacion $evaluacion) {}

    public function index(Request $request, Course $curso): Response
    {
        $inscripcion = AulaController::inscripcion($request, $curso) ?? abort(403);
        $abierta = $this->evaluacion->abierta($curso->id);
        if ($abierta) {
            $this->evaluacion->iniciar($inscripcion);
        }

        return Inertia::render('evaluacion/Final', [
            'curso' => $curso->only('id', 'titulo'),
            'ventana' => $this->evaluacion->ventana($curso->id),
            'abierta' => $abierta,
            'pasos' => $abierta ? $this->evaluacion->pasos($inscripcion) : [],
            'estado' => $inscripcion->estado,
        ]);
    }
}
