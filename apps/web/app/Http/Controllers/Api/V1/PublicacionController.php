<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Release;
use App\Services\Aula\ServicioPublicacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Publicaciones del curso: revisar, publicar y revertir (desde la consola). */
class PublicacionController extends Controller
{
    public function __construct(private readonly ServicioPublicacion $servicio) {}

    public function index(Course $course): JsonResponse
    {
        Gate::authorize('update', $course);
        $vigente = Release::vigente($course->id);

        return response()->json(['data' => [
            'vigente' => $vigente?->id,
            'publicaciones' => Release::with('autor:id,name')->where('course_id', $course->id)->orderByDesc('numero')
                ->get(['id', 'numero', 'nota', 'estado', 'huella', 'publicado_por', 'created_at']),
        ]]);
    }

    /** GET /courses/{course}/releases/preview: qué entraría en una publicación y qué lo impide. */
    public function revisar(Course $course): JsonResponse
    {
        Gate::authorize('update', $course);
        ['manifiesto' => $m, 'problemas' => $problemas] = $this->servicio->revisar($course);

        return response()->json(['data' => [
            'problemas' => $problemas,
            'conteo' => collect(['clases', 'tareas', 'soporte', 'procedimental', 'practica_parcial', 'variantes', 'medios'])
                ->mapWithKeys(fn ($l) => [$l => count($m->{$l})]),
        ]]);
    }

    public function store(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('update', $course);
        $datos = $request->validate(['nota' => ['nullable', 'string', 'max:2000'], 'clave_idempotencia' => ['required', 'uuid']]);
        $release = $this->servicio->publicar($course, $request->user(), $datos['nota'] ?? null, $datos['clave_idempotencia']);

        return response()->json(['data' => $release], $release->wasRecentlyCreated ? 201 : 200);
    }

    public function rollback(Release $release): JsonResponse
    {
        Gate::authorize('update', Course::findOrFail($release->course_id));

        return response()->json(['data' => ['vigente' => $this->servicio->revertir($release)->only('id', 'numero')]]);
    }
}
