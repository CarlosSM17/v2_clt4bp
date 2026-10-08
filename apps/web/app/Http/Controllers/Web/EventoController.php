<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\LearningEvent;
use App\Models\Release;
use App\Services\Aula\Eventos;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Recibe lotes de eventos del navegador (cada 10 s o al salir de la página). */
class EventoController extends Controller
{
    public function store(Request $request, Course $curso): Response
    {
        $inscripcion = AulaController::inscripcion($request, $curso) ?? abort(403);
        $datos = $request->validate([
            'eventos' => ['required', 'array', 'max:50'],
            'eventos.*.verbo' => ['required', Rule::in(Eventos::VERBOS_CLIENTE)],
            'eventos.*.objeto_tipo' => ['nullable', Rule::in(Eventos::OBJETOS)],
            'eventos.*.objeto_uid' => ['nullable', 'string', 'max:64'],
            'eventos.*.resultado' => ['nullable', 'array'],
            'eventos.*.duracion_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'eventos.*.ocurrido_at' => ['required', 'date'],
        ]);

        $release = Release::vigente($curso->id)?->id;
        $ahora = now();
        $filas = array_map(function (array $e) use ($inscripcion, $release, $ahora) {
            // Un reloj del cliente muy desfasado no debe ensuciar los datos: se usa la hora del servidor
            $cuando = Carbon::parse($e['ocurrido_at']);
            if ($cuando->lt($ahora->copy()->subDay()) || $cuando->gt($ahora->copy()->addMinutes(5))) {
                $cuando = $ahora;
            }
            $resultado = isset($e['resultado']) ? json_encode($e['resultado']) : null;

            return [
                'enrollment_id' => $inscripcion->id,
                'verbo' => $e['verbo'],
                'objeto_tipo' => $e['objeto_tipo'] ?? null,
                'objeto_uid' => $e['objeto_uid'] ?? null,
                'release_id' => $release,
                'resultado' => $resultado !== null && strlen($resultado) <= 2000 ? $resultado : null,
                'duracion_ms' => $e['duracion_ms'] ?? null,
                'origen' => 'cliente',
                'ocurrido_at' => $cuando,
                'recibido_at' => $ahora,
            ];
        }, $datos['eventos']);
        LearningEvent::insert($filas);

        return response()->noContent();
    }
}
