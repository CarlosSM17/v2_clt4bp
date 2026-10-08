<?php

namespace App\Http\Controllers\Web;

use App\Enums\EstadoInscripcion;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CursoController extends Controller
{
    public function index(Request $request): Response
    {
        $inscripciones = $request->user()->enrollments()
            ->with('course:id,titulo,descripcion,lenguaje,inicia_el,termina_el')
            ->whereNotIn('estado', [EstadoInscripcion::Rechazada->value])
            ->latest()->get()
            ->map(fn (Enrollment $e) => [
                'id' => $e->id,
                'estado' => $e->estado,
                'acceso' => $e->estado->daAcceso(),
                'curso' => $e->course,
            ]);

        return Inertia::render('cursos/Index', ['inscripciones' => $inscripciones]);
    }

    public function solicitar(Request $request): RedirectResponse
    {
        $datos = $request->validate(['codigo' => ['required', 'string', 'size:8']]);
        $curso = Course::where('codigo_inscripcion', strtoupper($datos['codigo']))
            ->whereIn('estado', ['diseno', 'activo'])->first();

        if (! $curso) {
            return back()->withErrors(['codigo' => 'No existe un curso abierto con ese código.']);
        }

        $inscripcion = Enrollment::firstOrCreate(
            ['course_id' => $curso->id, 'user_id' => $request->user()->id],
            ['estado' => EstadoInscripcion::Solicitud, 'solicitado_at' => now()],
        );

        Inertia::flash('toast', $inscripcion->wasRecentlyCreated
            ? ['type' => 'success', 'message' => 'Solicitud enviada. Tu instructor debe aprobarla.']
            : ['type' => 'info', 'message' => 'Ya tenías una solicitud o inscripción en este curso.']);

        return back();
    }

    public function show(Request $request, Course $curso): RedirectResponse
    {
        Gate::authorize('acceder', $curso);
        $inscripcion = $curso->enrollments()->where('user_id', $request->user()->id)->firstOrFail();

        // Mientras no haya perfil, el curso empieza por el diagnóstico
        if (in_array($inscripcion->estado, [EstadoInscripcion::Inscrito, EstadoInscripcion::Diagnostico], true)) {
            return redirect()->route('diagnostico.index', $curso);
        }

        // Con perfil, el curso es el reproductor 4C/ID (Etapa 5)
        return redirect()->route('aula.mapa', $curso);
    }
}
