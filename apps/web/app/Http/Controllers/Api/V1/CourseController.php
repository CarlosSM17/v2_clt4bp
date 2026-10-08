<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoCurso;
use App\Enums\Lenguaje;
use App\Enums\Rol;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Support\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CourseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Course::class);
        $user = $request->user();

        $cursos = Course::query()
            ->when(! $user->hasRole(Rol::Admin->value), fn ($q) => $q->whereHas(
                'instructores', fn ($i) => $i->whereKey($user->id)
            ))
            ->withCount([
                'enrollments as solicitudes_count' => fn ($q) => $q->where('estado', 'solicitud'),
                'enrollments as inscritos_count' => fn ($q) => $q->whereNotIn('estado', ['solicitud', 'rechazada', 'baja']),
            ])
            ->latest('updated_at')
            ->get();

        return CourseResource::collection($cursos);
    }

    public function store(Request $request): CourseResource
    {
        Gate::authorize('create', Course::class);
        $datos = $this->validar($request);

        $curso = DB::transaction(function () use ($datos, $request) {
            $curso = Course::create([...$datos, 'owner_id' => $request->user()->id, 'estado' => EstadoCurso::Diseno]);
            $curso->instructores()->attach($request->user()->id, ['rol' => 'responsable']);

            return $curso;
        });

        Auditoria::registrar('curso.creado', $curso);

        return new CourseResource($curso);
    }

    public function show(Course $course): CourseResource
    {
        Gate::authorize('view', $course);

        return new CourseResource($course->loadCount([
            'enrollments as solicitudes_count' => fn ($q) => $q->where('estado', 'solicitud'),
            'enrollments as inscritos_count' => fn ($q) => $q->whereNotIn('estado', ['solicitud', 'rechazada', 'baja']),
        ]));
    }

    public function update(Request $request, Course $course): CourseResource
    {
        Gate::authorize('update', $course);
        $course->update($this->validar($request, parcial: true));
        Auditoria::registrar('curso.actualizado', $course, ['campos' => array_keys($request->all())]);

        return new CourseResource($course);
    }

    private function validar(Request $request, bool $parcial = false): array
    {
        $requerido = $parcial ? 'sometimes' : 'required';

        return $request->validate([
            'titulo' => [$requerido, 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'lenguaje' => [$requerido, Rule::enum(Lenguaje::class)],
            'nivel_educativo' => [$requerido, Rule::in(['secundaria', 'preparatoria', 'universidad'])],
            'estado' => ['sometimes', Rule::enum(EstadoCurso::class)],
            'inicia_el' => ['nullable', 'date'],
            'termina_el' => ['nullable', 'date', 'after_or_equal:inicia_el'],
        ]);
    }
}
