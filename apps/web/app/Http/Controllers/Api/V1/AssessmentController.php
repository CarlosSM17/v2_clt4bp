<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Course;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AssessmentController extends Controller
{
    public function index(Course $course): JsonResponse
    {
        Gate::authorize('update', $course);

        return response()->json(['data' => Assessment::where('course_id', $course->id)->withCount('items')->get()]);
    }

    public function store(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('update', $course);
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:160'],
            'momento' => ['required', Rule::in(['pre', 'post'])],
            'tipo' => ['required', Rule::in(['teorica', 'practica'])],
            'forma' => ['required', Rule::in(['A', 'B'])],
            'tiempo_limite_min' => ['nullable', 'integer', 'between:5,240'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', Rule::exists('items', 'id')
                ->where('course_id', $course->id)->where('estado', 'aprobado')],
            'items.*.puntos' => ['required', 'numeric', 'between:0.5,100'],
        ]);

        $prueba = DB::transaction(function () use ($datos, $course) {
            $prueba = Assessment::create([...collect($datos)->except('items')->all(), 'course_id' => $course->id]);
            foreach (array_values($datos['items']) as $i => $item) {
                $prueba->items()->attach($item['id'], ['orden' => $i + 1, 'puntos' => $item['puntos']]);
            }

            return $prueba;
        });
        Auditoria::registrar('prueba.creada', $prueba);

        return response()->json(['data' => $prueba->load('items')], 201);
    }
}
