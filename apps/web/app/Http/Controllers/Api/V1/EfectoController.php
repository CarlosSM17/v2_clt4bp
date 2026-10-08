<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Diseno\Preseleccion;
use App\Domain\Diseno\ResumenGrupo;
use App\Http\Controllers\Controller;
use App\Models\CleEffect;
use App\Models\Course;
use App\Models\EffectRule;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Catálogo de efectos de la TCC y preselección (paso 3 de CLT4BP). */
class EfectoController extends Controller
{
    public function catalogo(): JsonResponse
    {
        return response()->json(['data' => CleEffect::orderBy('grupo')->orderBy('nombre')->get()]);
    }

    /** GET /courses/{course}/preselection?grupo=G1&interactividad=alta&multimedia=1 */
    public function preseleccion(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('view', $course);
        $datos = $request->validate([
            'grupo' => ['nullable', 'string', 'max:8'],
            'interactividad' => ['nullable', Rule::in(['baja', 'media', 'alta'])],
            'multimedia' => ['nullable', 'boolean'],
        ]);

        // Perfiles vigentes del curso o, si se pide, solo de un grupo diferenciado
        $perfiles = $course->enrollments()
            ->whereNotIn('estado', ['solicitud', 'rechazada', 'baja'])
            ->when($datos['grupo'] ?? null, fn ($q, $clave) => $q->whereHas('membresia.group', fn ($g) => $g->where('clave', $clave)))
            ->with('perfil')->get()->pluck('perfil')->filter()
            ->map(fn ($p) => ['nivel' => $p->nivel, 'mslq' => $p->mslq, 'banderas' => $p->banderas])
            ->values()->all();

        $hechos = [
            ...ResumenGrupo::desdePerfiles($perfiles),
            'interactividad_tema' => $datos['interactividad'] ?? null,
            'usa_multimedia' => (bool) ($datos['multimedia'] ?? false),
        ];
        $reglas = EffectRule::where('activa', true)->orderBy('orden')->get()->toArray();

        return response()->json(['data' => [
            'hechos' => $hechos,
            ...(new Preseleccion)->evaluar($reglas, $hechos),
        ]]);
    }

    public function reglas(): JsonResponse
    {
        return response()->json(['data' => EffectRule::orderBy('orden')->get()]);
    }

    /** PUT /admin/effect-rules/{regla}: el administrador ajusta una regla sin tocar código. */
    public function actualizarRegla(Request $request, EffectRule $regla): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'condicion' => ['required', 'array'],
            'efectos' => ['present', 'array'],
            'efectos.*' => ['string', Rule::exists('cle_effects', 'id')],
            'recomendaciones' => ['present', 'array'],
            'recomendaciones.*' => ['string', 'max:300'],
            'fundamento' => ['required', 'string', 'max:1000'],
            'activa' => ['required', 'boolean'],
        ]);
        abort_unless($this->condicionValida($datos['condicion']), 422, 'La condición no tiene una forma válida.');

        $antes = $regla->only('condicion', 'efectos', 'activa');
        $regla->update($datos);
        Auditoria::registrar('regla_efectos.actualizada', $regla, ['antes' => $antes]);

        return response()->json(['data' => $regla]);
    }

    private function condicionValida(array $c): bool
    {
        foreach (['todas', 'alguna'] as $combinador) {
            if (isset($c[$combinador])) {
                return is_array($c[$combinador]) && $c[$combinador] !== []
                    && collect($c[$combinador])->every(fn ($h) => is_array($h) && $this->condicionValida($h));
            }
        }

        return isset($c['campo'], $c['op']) && array_key_exists('valor', $c)
            && in_array($c['op'], ['=', '!=', '<', '<=', '>', '>=', 'en'], true);
    }
}
