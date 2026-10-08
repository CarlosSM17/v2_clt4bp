<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Perfil\Agrupador;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\DiffGroup;
use App\Models\GroupAnalysis;
use App\Models\GroupMembership;
use App\Models\Instrument;
use App\Models\InstrumentAdministration;
use App\Services\Diagnostico\ServicioDiagnostico;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Diagnóstico, perfiles, decisión de homogeneidad y grupos, vistos desde la consola. */
class DiagnosticoController extends Controller
{
    /** Prepara el diagnóstico: aplica el MSLQ (pre) en el curso si aún no está. */
    public function preparar(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('update', $course);
        $datos = $request->validate([
            'abre_at' => ['nullable', 'date'],
            'cierra_at' => ['nullable', 'date', 'after:abre_at'],
        ]);
        $mslq = Instrument::where('clave', 'mslq')->latest('id')->firstOrFail();

        $aplicacion = InstrumentAdministration::firstOrCreate(
            ['course_id' => $course->id, 'instrument_id' => $mslq->id, 'momento' => 'pre'],
            $datos,
        );

        return response()->json(['data' => $aplicacion], 201);
    }

    /** Tabla de avance del diagnóstico y perfil de cada estudiante. */
    public function estado(Course $course, ServicioDiagnostico $servicio): JsonResponse
    {
        Gate::authorize('view', $course);

        $filas = $course->enrollments()->with(['user:id,name', 'perfil', 'membresia.group'])
            ->whereNotIn('estado', ['solicitud', 'rechazada', 'baja'])->get()
            ->map(fn ($e) => [
                'enrollment_id' => $e->id,
                'nombre' => $e->user->name,
                'seudonimo' => $e->seudonimo,
                'estado' => $e->estado,
                'pasos' => $servicio->pasos($e),
                'perfil' => $e->perfil?->only('cp_teorico', 'cp_practico', 'cp_global', 'nivel', 'mslq', 'indices', 'banderas'),
                'grupo' => $e->membresia?->group?->only('clave', 'nombre'),
            ]);

        return response()->json(['data' => $filas]);
    }

    public function analisis(Course $course): JsonResponse
    {
        Gate::authorize('view', $course);

        return response()->json(['data' => GroupAnalysis::where('course_id', $course->id)->latest('id')->first()]);
    }

    /** El instructor confirma o cambia la recomendación, con justificación. */
    public function decidir(Request $request, Course $course, GroupAnalysis $analisis): JsonResponse
    {
        Gate::authorize('update', $course);
        abort_unless($analisis->course_id === $course->id, 404);
        $datos = $request->validate([
            'decision' => ['required', Rule::in(['homogeneo', 'heterogeneo'])],
            'justificacion' => [Rule::requiredIf($request->input('decision') !== $analisis->recomendacion), 'nullable', 'string', 'max:2000'],
        ]);

        $analisis->update([...$datos, 'decidido_por' => $request->user()->id, 'decidido_at' => now()]);
        Auditoria::registrar('grupo.decision_homogeneidad', $course, $datos);

        return response()->json(['data' => $analisis]);
    }

    /** Propone grupos sin guardarlos (por nivel o por k-means). */
    public function proponerGrupos(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('update', $course);
        $metodo = $request->validate(['metodo' => ['required', Rule::in(['nivel', 'kmeans'])]])['metodo'];

        $inscripciones = $course->enrollments()->with('perfil')->get()->filter(fn ($e) => $e->perfil);
        $porSeudonimo = $inscripciones->keyBy('seudonimo');
        $agrupador = new Agrupador;

        $propuesta = $metodo === 'nivel'
            ? ['silueta' => null, 'grupos' => $agrupador->porNivel(
                $porSeudonimo->map(fn ($e) => ['cp' => $e->perfil->cp_global, 'nivel' => $e->perfil->nivel])->all())]
            : $agrupador->kmeans($porSeudonimo->map(fn ($e) => [
                $e->perfil->cp_teorico, $e->perfil->cp_practico,
                (float) ($e->perfil->indices['motivacion'] ?? 4), (float) ($e->perfil->indices['estrategias_cognitivas'] ?? 4),
            ])->all());

        // Traduce seudónimos a ids de inscripción para que la consola pueda editar y guardar
        $propuesta['grupos'] = array_map(fn ($g) => [...$g,
            'miembros' => array_map(fn ($s) => $porSeudonimo[$s]->id, $g['miembros'])], $propuesta['grupos']);

        return response()->json(['data' => $propuesta]);
    }

    /** Guarda los grupos definitivos. Cierra las membresías anteriores (historial). */
    public function guardarGrupos(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('update', $course);
        $datos = $request->validate([
            'grupos' => ['required', 'array', 'min:1', 'max:6'],
            'grupos.*.clave' => ['required', 'string', 'max:8', 'distinct'],
            'grupos.*.nombre' => ['required', 'string', 'max:60'],
            'grupos.*.nivel' => ['nullable', Rule::in(['basico', 'intermedio', 'avanzado'])],
            'grupos.*.miembros' => ['array'],
            'grupos.*.miembros.*' => ['integer', Rule::exists('enrollments', 'id')->where('course_id', $course->id)],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($datos, $course) {
            $ahora = now();
            GroupMembership::whereHas('group', fn ($q) => $q->where('course_id', $course->id))
                ->whereNull('hasta')->update(['hasta' => $ahora]);

            foreach (array_values($datos['grupos']) as $i => $g) {
                $grupo = DiffGroup::updateOrCreate(
                    ['course_id' => $course->id, 'clave' => $g['clave']],
                    ['nombre' => $g['nombre'], 'nivel' => $g['nivel'] ?? null, 'orden' => $i + 1],
                );
                foreach ($g['miembros'] ?? [] as $inscripcionId) {
                    GroupMembership::create([
                        'diff_group_id' => $grupo->id, 'enrollment_id' => $inscripcionId,
                        'desde' => $ahora, 'motivo' => $datos['motivo'] ?? 'agrupación inicial',
                    ]);
                }
            }
        });
        Auditoria::registrar('grupos.guardados', $course, ['grupos' => count($datos['grupos'])]);

        return response()->json(['ok' => true]);
    }
}
