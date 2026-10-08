<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\Evaluacion\ServicioAvance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** «Mi avance»: el dashboard del estudiante. */
class AvanceController extends Controller
{
    public function show(Request $request, Course $curso, ServicioAvance $avance): Response|RedirectResponse
    {
        $inscripcion = AulaController::inscripcion($request, $curso);
        if (! $inscripcion) {
            return redirect()->route('diagnostico.index', $curso);
        }

        return Inertia::render('aula/Avance', [
            'curso' => $curso->only('id', 'titulo'),
            ...$avance->de($inscripcion),
        ]);
    }
}
