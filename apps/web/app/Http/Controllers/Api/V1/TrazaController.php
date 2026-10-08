<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\Agente\ClienteAgente;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Trazas de código del material: la consola pide los pasos reales de una traza que escribió el instructor. */
class TrazaController extends Controller
{
    /** POST /courses/{course}/trazas {bloque, lenguaje?} → {bloque} con los pasos calculados */
    public function completar(Request $request, Course $course, ClienteAgente $agente): JsonResponse
    {
        Gate::authorize('update', $course);
        $datos = $request->validate([
            'bloque' => ['required', 'string', 'max:30000'],
            'lenguaje' => ['nullable', Rule::in(['c', 'cpp', 'python'])],
        ]);

        try {
            $bloque = $agente->completarTraza($datos['lenguaje'] ?? $course->lenguaje->value, $datos['bloque']);
        } catch (RequestException $e) {
            // 422: el código no compila o no se pudo ejecutar; el agente explica por qué
            abort_if($e->response->status() === 422, 422, (string) $e->response->json('detail', 'No se pudo trazar.'));
            abort(503, 'El trazador no está disponible.');
        } catch (ConnectionException) {
            abort(503, 'El agente no está disponible.');
        }

        return response()->json(['data' => ['bloque' => $bloque]]);
    }
}
