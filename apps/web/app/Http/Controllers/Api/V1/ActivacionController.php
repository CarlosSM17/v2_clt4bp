<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activation;
use App\Models\Course;
use App\Models\DiffGroup;
use App\Models\LearningEvent;
use App\Models\Release;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Plan de implementación (paso 8): cuándo abre y cierra cada clase para cada grupo. */
class ActivacionController extends Controller
{
    public function index(Course $course): JsonResponse
    {
        Gate::authorize('update', $course);
        $release = Release::vigente($course->id);
        $clases = collect($release?->manifiesto['clases'] ?? []);
        $grupos = DiffGroup::where('course_id', $course->id)->orderBy('orden')->get(['id', 'clave', 'nombre']);

        // Cuántos estudiantes ya abrieron cada clase (el instructor ve si el aviso llegó)
        $abrieron = LearningEvent::where('verbo', 'abrio_clase')
            ->whereIn('enrollment_id', $course->enrollments()->select('id'))
            ->groupBy('objeto_uid')->select('objeto_uid', DB::raw('count(distinct enrollment_id) as n'))
            ->pluck('n', 'objeto_uid');

        return response()->json(['data' => [
            'clases' => $clases->sortBy('orden')->values()
                ->map(fn ($c) => ['uid' => $c['uid'], 'orden' => $c['orden'], 'titulo' => $c['titulo'], 'abrieron' => $abrieron[$c['uid']] ?? 0]),
            'grupos' => $grupos,
            // Solo las de la publicación vigente: las de clases de otras versiones se guardan aparte (ver update)
            'activaciones' => Activation::where('course_id', $course->id)->whereIn('clase_uid', $clases->pluck('uid'))
                ->get()->map(fn (Activation $a) => [
                    'clase_uid' => $a->clase_uid,
                    'grupo_clave' => $grupos->firstWhere('id', $a->diff_group_id)?->clave,
                    'abre_at' => $a->abre_at?->toIso8601String(),
                    'cierra_at' => $a->cierra_at?->toIso8601String(),
                    'requiere_anterior' => $a->requiere_anterior,
                    'avisado' => $a->avisado_at !== null,
                ]),
        ]]);
    }

    /**
     * PUT /courses/{course}/activations: reemplaza el plan de las clases de la publicación vigente. Las fechas de
     * clases que ya no están en ella se conservan: si se revierte a una versión que las tiene, vuelven a aplicar.
     */
    public function update(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('update', $course);
        $datos = $request->validate([
            'activaciones' => ['present', 'array', 'max:500'],
            'activaciones.*.clase_uid' => ['required', 'string', 'max:64'],
            'activaciones.*.grupo_clave' => ['nullable', 'string', 'max:8'],
            'activaciones.*.abre_at' => ['required', 'date'],
            'activaciones.*.cierra_at' => ['nullable', 'date', 'after:activaciones.*.abre_at'],
            'activaciones.*.requiere_anterior' => ['boolean'],
        ]);
        $clases = collect(Release::vigente($course->id)?->manifiesto['clases'] ?? [])->pluck('uid');
        $grupos = DiffGroup::where('course_id', $course->id)->pluck('id', 'clave');

        $vistos = [];
        foreach ($datos['activaciones'] as $i => $a) {
            $clave = $a['clase_uid'].'|'.($a['grupo_clave'] ?? '*');
            $errores = match (true) {
                ! $clases->contains($a['clase_uid']) => "La clase «{$a['clase_uid']}» no está en la publicación vigente: recarga el plan.",
                ($a['grupo_clave'] ?? null) !== null && ! $grupos->has($a['grupo_clave']) => 'El grupo no existe.',
                isset($vistos[$clave]) => 'Esa clase ya tiene fechas para ese grupo.',
                default => null,
            };
            if ($errores) {
                throw ValidationException::withMessages(["activaciones.{$i}" => $errores]);
            }
            $vistos[$clave] = true;
        }

        DB::transaction(function () use ($course, $datos, $grupos, $clases) {
            $plan = Activation::where('course_id', $course->id)->whereIn('clase_uid', $clases);
            $anteriores = (clone $plan)->get()->keyBy(fn ($a) => $a->clase_uid.'|'.$a->diff_group_id);
            $plan->delete();
            foreach ($datos['activaciones'] as $a) {
                $grupoId = isset($a['grupo_clave']) ? $grupos[$a['grupo_clave']] : null;
                $previa = $anteriores->get($a['clase_uid'].'|'.$grupoId);
                $abre = now()->parse($a['abre_at']);
                Activation::create([
                    'course_id' => $course->id,
                    'clase_uid' => $a['clase_uid'],
                    'diff_group_id' => $grupoId,
                    'abre_at' => $abre,
                    'cierra_at' => $a['cierra_at'] ?? null,
                    'requiere_anterior' => $a['requiere_anterior'] ?? false,
                    // Si la fecha de apertura no cambió, no se vuelve a avisar
                    'avisado_at' => $previa && $previa->abre_at->equalTo($abre) ? $previa->avisado_at : null,
                ]);
            }
        });
        Auditoria::registrar('activaciones.actualizadas', $course, ['total' => count($datos['activaciones'])]);

        return $this->index($course);
    }
}
