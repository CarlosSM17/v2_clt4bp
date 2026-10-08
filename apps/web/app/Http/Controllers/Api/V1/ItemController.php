<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Item;
use App\Services\Codigo\EvaluadorCasos;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ItemController extends Controller
{
    public function index(Course $course): JsonResponse
    {
        Gate::authorize('update', $course);

        // makeVisible: el instructor sí ve clave y solución
        return response()->json(['data' => $course->items()->latest()->get()->makeVisible(['clave', 'solucion'])]);
    }

    public function store(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('update', $course);
        $item = Item::create([...$this->validar($request), 'course_id' => $course->id, 'estado' => 'borrador']);
        Auditoria::registrar('item.creado', $item);

        return response()->json(['data' => $item->makeVisible(['clave', 'solucion'])], 201);
    }

    public function update(Request $request, Item $item): JsonResponse
    {
        Gate::authorize('update', $item->course);
        $item->update([...$this->validar($request), 'verificado_at' => null]);

        return response()->json(['data' => $item->makeVisible(['clave', 'solucion'])]);
    }

    /** Ejecuta la solución de referencia contra todos los casos; si pasan, el ítem queda verificado. */
    public function verificar(Item $item, EvaluadorCasos $evaluador): JsonResponse
    {
        Gate::authorize('update', $item->course);
        abort_unless($item->tipo === 'programacion', 422, 'Solo los problemas de programación se verifican.');

        $resultado = $evaluador->evaluar($item->lenguaje, (string) $item->solucion, $item->casos_prueba ?? []);
        $ok = $resultado['total'] > 0 && $resultado['aprobados'] === $resultado['total'];
        $item->update(['verificado_at' => $ok ? now() : null]);

        return response()->json(['verificado' => $ok, 'resultado' => $resultado]);
    }

    public function aprobar(Item $item): JsonResponse
    {
        Gate::authorize('update', $item->course);
        abort_if($item->tipo === 'programacion' && ! $item->verificado_at, 422, 'Verifica la solución antes de aprobar.');
        $item->update(['estado' => 'aprobado']);
        Auditoria::registrar('item.aprobado', $item);

        return response()->json(['data' => $item->makeVisible(['clave', 'solucion'])]);
    }

    private function validar(Request $request): array
    {
        $tipo = $request->input('tipo');

        return $request->validate([
            'tipo' => ['required', Rule::in(['opcion_multiple', 'respuesta_corta', 'prediccion_salida', 'parsons', 'programacion'])],
            'nivel' => ['required', Rule::in(['recall', 'comprension', 'practica'])],
            'objetivo' => ['nullable', 'string', 'max:32'],
            'enunciado' => ['required', 'array'],
            'enunciado.md' => ['required', 'string', 'max:10000'],
            'enunciado.opciones' => [Rule::requiredIf($tipo === 'opcion_multiple'), 'array'],
            'enunciado.lineas' => [Rule::requiredIf($tipo === 'parsons'), 'array'],
            'enunciado.codigo_inicial' => ['nullable', 'string'],
            'clave' => [Rule::requiredIf($tipo !== 'programacion'), 'nullable', 'array'],
            'lenguaje' => [Rule::requiredIf($tipo === 'programacion'), 'nullable', Rule::in(['c', 'cpp', 'python'])],
            'casos_prueba' => [Rule::requiredIf($tipo === 'programacion'), 'nullable', 'array', 'min:1'],
            'casos_prueba.*.entrada' => ['present', 'nullable', 'string'],
            'casos_prueba.*.salida_esperada' => ['required', 'string'],
            'casos_prueba.*.oculto' => ['required', 'boolean'],
            'solucion' => [Rule::requiredIf($tipo === 'programacion'), 'nullable', 'string'],
        ]);
    }
}
