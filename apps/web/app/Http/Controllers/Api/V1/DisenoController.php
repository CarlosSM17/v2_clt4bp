<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Diseno\TiposElemento;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\DesignElement;
use App\Services\Agente\ClienteAgente;
use App\Services\Diseno\ConstructorDiseno;
use App\Services\Diseno\ServicioSincronizacion;
use App\Services\Diseno\VerificadorCodigo;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Sincronización del diseño 4C/ID con la consola (descarga por cursor y subida por lotes). */
class DisenoController extends Controller
{
    public function __construct(private readonly ServicioSincronizacion $sync) {}

    /** GET /courses/{course}/design?desde=123 */
    public function index(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('view', $course);
        $desde = (int) ($request->validate(['desde' => ['nullable', 'integer', 'min:0']])['desde'] ?? 0);

        return response()->json($this->sync->cambiosDesde($course, $desde));
    }

    /** POST /courses/{course}/design/sync */
    public function sincronizar(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('update', $course);
        $request->validate([
            'cambios' => ['required', 'array', 'min:1', 'max:200'],
            'cambios.*.uid' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'cambios.*.tipo' => ['required', Rule::in(array_keys(TiposElemento::ESQUEMAS))],
            'cambios.*.base_version' => ['required', 'integer', 'min:0'],
            'cambios.*.eliminar' => ['boolean'],
            'cambios.*.agent_run_id' => ['nullable', 'integer'],
        ]);

        // El contenido se toma del JSON original como objetos: así {} y [] no se confunden
        $cuerpo = json_decode($request->getContent(), false, 64, JSON_THROW_ON_ERROR);
        $resultados = array_map(fn (object $c) => $this->sync->aplicar($course, $request->user(), $c), $cuerpo->cambios);

        return response()->json([
            'resultados' => $resultados,
            'cursor' => (int) DesignElement::where('course_id', $course->id)->max('seq'),
        ]);
    }

    /** GET /courses/{course}/design/{uid}/versions: historial de un elemento. */
    public function versiones(Course $course, string $uid): JsonResponse
    {
        Gate::authorize('view', $course);
        $elemento = DesignElement::where('course_id', $course->id)->where('uid', $uid)->firstOrFail();

        return response()->json(['data' => $elemento->versiones()->orderByDesc('version')
            ->get(['version', 'contenido', 'estado', 'autor_tipo', 'actualizado_por', 'eliminado', 'created_at'])]);
    }

    /** POST /courses/{course}/design/verify: verificador CLT4BP sobre lo que hay en el servidor. */
    public function verificar(Course $course, ConstructorDiseno $constructor, VerificadorCodigo $codigo, ClienteAgente $agente): JsonResponse
    {
        Gate::authorize('update', $course);

        return response()->json(['data' => $this->informe($course, $constructor, $codigo, $agente)]);
    }

    /**
     * POST /courses/{course}/design/approve {uids: [...]}
     * Aprobar exige que ni los elementos ni sus variantes tengan hallazgos de nivel «error».
     */
    public function aprobar(Request $request, Course $course, ConstructorDiseno $constructor, VerificadorCodigo $codigo, ClienteAgente $agente): JsonResponse
    {
        Gate::authorize('update', $course);
        $uids = $request->validate(['uids' => ['required', 'array', 'min:1', 'max:200'], 'uids.*' => ['string', 'max:64']])['uids'];

        $informe = $this->informe($course, $constructor, $codigo, $agente);
        $bloqueantes = array_values(array_filter($informe['hallazgos'], fn ($h) => $h['nivel'] === 'error' && in_array($h['elemento_uid'], $uids, true)));
        if ($bloqueantes) {
            return response()->json(['message' => 'Hay errores que impiden aprobar.', 'hallazgos' => $bloqueantes], 422);
        }

        DB::transaction(function () use ($course, $uids) {
            DB::select('select pg_advisory_xact_lock(?)', [$course->id]);
            $elementos = DesignElement::where('course_id', $course->id)->whereNull('eliminado_at')
                ->where(fn ($q) => $q->whereIn('uid', $uids)->orWhere(fn ($v) => $v->where('tipo', 'variante')->whereIn('padre_uid', $uids)))
                ->where('estado', 'borrador')->get();
            foreach ($elementos as $e) {
                // Nuevo seq sin nueva versión: las consolas se enteran del cambio de estado
                $e->update(['estado' => 'aprobado', 'seq' => (int) DB::scalar("select nextval('design_seq')")]);
            }
        });
        Auditoria::registrar('diseno.aprobado', $course, ['uids' => $uids]);

        return response()->json(['data' => ['aprobados' => $uids, 'advertencias' => $informe['advertencias']]]);
    }

    private function informe(Course $course, ConstructorDiseno $constructor, VerificadorCodigo $codigo, ClienteAgente $agente): array
    {
        $diseno = $constructor->construir($course);

        return $agente->verificar($diseno, $codigo->resultados($diseno));
    }
}
