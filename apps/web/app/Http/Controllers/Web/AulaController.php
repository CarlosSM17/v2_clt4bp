<?php

namespace App\Http\Controllers\Web;

use App\Domain\Aula\VistaEstudiante;
use App\Enums\EstadoInscripcion;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\MediaAsset;
use App\Models\TaskComment;
use App\Models\TaskProgress;
use App\Models\TaskSubmission;
use App\Services\Aula\ContextoAula;
use App\Services\Aula\ServicioAula;
use App\Services\Evaluacion\ServicioEvaluacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** El reproductor 4C/ID: mapa del curso, clase de tareas, vista de tarea y práctica rápida. */
class AulaController extends Controller
{
    public function __construct(
        private readonly ServicioAula $aula,
        private readonly ServicioEvaluacion $evaluacion,
    ) {}

    public function mapa(Request $request, Course $curso): Response|RedirectResponse
    {
        $inscripcion = self::inscripcion($request, $curso);
        if (! $inscripcion) {
            return redirect()->route('diagnostico.index', $curso);
        }
        $ctx = $this->aula->contexto($inscripcion);

        return Inertia::render('aula/Mapa', [
            'curso' => $curso->only('id', 'titulo', 'lenguaje'),
            'grupo' => $ctx->grupo?->nombre,
            'publicacion' => $ctx->release->numero,
            'clases' => collect($ctx->clases)->map(function (array $c) use ($ctx) {
                $tareas = $ctx->tareasDe($c['uid']);
                $e = $ctx->estados[$c['uid']];

                return [
                    'uid' => $c['uid'],
                    'orden' => $c['orden'],
                    'titulo' => $c['titulo'],
                    'descripcion' => $c['descripcion_complejidad'],
                    'estado' => $e['estado'],
                    'abre_at' => $e['activacion']['abre_at'] ?? null,
                    'cierra_at' => $e['activacion']['cierra_at'] ?? null,
                    'tareas' => count($tareas),
                    'completadas' => collect($tareas)->filter(fn ($t) => $ctx->progreso->get($t['uid'])?->estado === 'completada')->count(),
                ];
            }),
            'hayPractica' => count($ctx->manifiesto['practica_parcial']) > 0,

            // Etapa 6: escala CS pendiente de una clase ya completa y aviso de la evaluación final
            'escalaCarga' => $this->evaluacion->escalaCarga($ctx),
            'evaluacionFinal' => $this->evaluacion->abierta($curso->id)
                ? ['cierra_at' => $this->evaluacion->ventana($curso->id)['cierra_at'], 'estado' => $inscripcion->estado]
                : null,
        ]);
    }

    public function clase(Request $request, Course $curso, string $clase): Response
    {
        $inscripcion = self::inscripcion($request, $curso) ?? abort(403);
        $ctx = $this->aula->contexto($inscripcion);
        $c = $ctx->clase($clase);
        $this->aula->marcarCursando($inscripcion);

        $soporte = collect($ctx->manifiesto['soporte'])->where('clase_uid', $clase)->values();
        // Información procedimental del tema (tarjeta, guía, errores, ejemplo isomórfico) y su protocolo verbal
        $delTema = collect($ctx->manifiesto['procedimental'])->where('clase_uid', $clase)->values();
        [$protocolo, $procedimental] = $delTema->partition(fn ($a) => $a['tipo'] === 'protocolo_verbal');

        return Inertia::render('aula/Clase', [
            'curso' => $curso->only('id', 'titulo'),
            'clase' => [...collect($c)->only('uid', 'orden', 'titulo')->all(), 'descripcion' => $c['descripcion_complejidad']],
            'estado' => $ctx->estados[$clase]['estado'],
            'soporte' => $soporte->map(fn ($s) => VistaEstudiante::soporte($s)),
            'procedimental' => $procedimental->values()->map(fn ($a) => VistaEstudiante::ayuda($a)),
            'protocolo' => $protocolo->values()->map(fn ($a) => VistaEstudiante::ayuda($a)),
            'tareas' => collect($ctx->tareasDe($clase))->map(fn ($t) => [
                ...collect(VistaEstudiante::tarea($t))->only(
                    'uid', 'orden', 'titulo', 'nivel_apoyo', 'etiqueta_apoyo', 'tiempo_estimado_min', 'papel', 'rutas'
                )->all(),
                'estado' => $ctx->progreso->get($t['uid'])?->estado ?? 'sin_empezar',
                'mejor_fraccion' => $ctx->progreso->get($t['uid'])?->mejor_fraccion,
            ]),
            'rutas' => $this->nombresDeRutas($ctx),
            'medios' => $this->aula->medios($ctx, ...$soporte->pluck('cuerpo_md')->all(), ...$delTema->pluck('cuerpo_md')->all()),
        ]);
    }

    public function tarea(Request $request, Course $curso, string $tarea): Response
    {
        $inscripcion = self::inscripcion($request, $curso) ?? abort(403);
        $ctx = $this->aula->contexto($inscripcion);
        $t = $ctx->tarea($tarea);
        $this->aula->marcarCursando($inscripcion);

        $progreso = TaskProgress::firstOrCreate(
            ['enrollment_id' => $inscripcion->id, 'tarea_uid' => $tarea],
            ['iniciado_at' => now()],
        );
        // Sus ayudas y las del tema de su clase (tarjeta de sintaxis, guía, errores frecuentes, ejemplo isomórfico)
        $ayudas = collect($ctx->manifiesto['procedimental'])
            ->filter(fn ($a) => ($a['tarea_uid'] ?? null) === $tarea || ($a['clase_uid'] ?? null) === $t['clase_uid'])
            ->sortBy(fn ($a) => empty($a['tarea_uid']) ? 1 : 0)->values();
        $hermanas = collect($ctx->tareasDe($t['clase_uid']))->pluck('uid');
        $i = $hermanas->search($tarea);

        return Inertia::render('aula/Tarea', [
            'curso' => $curso->only('id', 'titulo'),
            'clase' => collect($ctx->clase($t['clase_uid']))->only('uid', 'titulo')->all(),
            'tarea' => VistaEstudiante::tarea($t),
            'rutas' => $this->nombresDeRutas($ctx),
            'ayudas' => $ayudas->map(fn ($a) => VistaEstudiante::ayuda($a)),
            'progreso' => $progreso->only('estado', 'borrador_codigo', 'intentos', 'mejor_fraccion', 'esfuerzo', 'autoexplicacion'),
            'envios' => TaskSubmission::where('enrollment_id', $inscripcion->id)->where('tarea_uid', $tarea)
                ->latest()->limit(5)->get(['id', 'numero', 'estado', 'fraccion', 'created_at']),
            'puedeEnviar' => $ctx->puedeEnviar($t['clase_uid']),
            'anterior' => $i > 0 ? $hermanas[$i - 1] : null,
            'siguiente' => $hermanas[$i + 1] ?? null,
            'comentarios' => ($t['colaborativa'] ?? false) ? $this->comentarios($curso, $tarea, $ctx->grupo?->id, $request->user()->id) : null,
            'medios' => $this->aula->medios($ctx, $t['enunciado_md'], ...$ayudas->pluck('cuerpo_md')->all()),
        ]);
    }

    public function practica(Request $request, Course $curso): Response
    {
        $inscripcion = self::inscripcion($request, $curso) ?? abort(403);
        $ctx = $this->aula->contexto($inscripcion);

        return Inertia::render('aula/Practica', [
            'curso' => $curso->only('id', 'titulo'),
            'practicas' => collect($ctx->manifiesto['practica_parcial'])->map(fn ($p) => VistaEstudiante::practica($p)),
        ]);
    }

    /** Archivo de un medio. La ruta está firmada (vence en 2 h) y además exige sesión e inscripción. */
    public function medio(Request $request, Course $curso, string $uid): BinaryFileResponse
    {
        $inscripcion = self::inscripcion($request, $curso) ?? abort(403);
        abort_unless(collect($this->aula->contexto($inscripcion)->release->manifiesto['medios'] ?? [])->contains('uid', $uid), 404);
        $medio = MediaAsset::where('course_id', $curso->id)->where('uid', $uid)->firstOrFail();

        // BinaryFileResponse atiende peticiones Range: el video se puede adelantar sin descargarlo completo
        return response()->file(Storage::disk('local')->path($medio->ruta), ['Content-Type' => $medio->mime]);
    }

    /** Inscripción con acceso al material; null si todavía está en el diagnóstico. */
    public static function inscripcion(Request $request, Course $curso): ?Enrollment
    {
        $inscripcion = Enrollment::where('course_id', $curso->id)->where('user_id', $request->user()->id)->firstOrFail();
        abort_unless($inscripcion->estado->daAcceso(), 403);

        return in_array($inscripcion->estado, [EstadoInscripcion::Inscrito, EstadoInscripcion::Diagnostico], true) ? null : $inscripcion;
    }

    /** @return array<string, string> clave del grupo → nombre de su ruta («Ruta A»…), para las etiquetas de las tareas */
    private function nombresDeRutas(ContextoAula $ctx): array
    {
        return collect($ctx->manifiesto['curso']['grupos'] ?? [])->pluck('nombre', 'clave')->all();
    }

    private function comentarios(Course $curso, string $tarea, ?int $grupoId, int $yo): array
    {
        return TaskComment::with('autor:id,name')->where('course_id', $curso->id)->where('tarea_uid', $tarea)
            ->where('diff_group_id', $grupoId)->where('oculto', false)->oldest()->limit(200)->get()
            ->map(fn (TaskComment $c) => [
                'id' => $c->id, 'texto' => $c->texto, 'autor' => $c->autor->name,
                'mio' => $c->user_id === $yo, 'fecha' => $c->created_at,
            ])->all();
    }
}
