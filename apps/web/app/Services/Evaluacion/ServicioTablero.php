<?php

namespace App\Services\Evaluacion;

use App\Domain\Evaluacion\Alertas;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\InstrumentResponse;
use App\Models\LearningEvent;
use App\Models\Release;
use App\Models\TaskProgress;
use App\Models\TaskSubmission;
use DateTimeImmutable;
use Illuminate\Support\Collection;

/** Dashboard del instructor (propuesta, 9.3): el grupo, a quién apoyar y la ficha de cada estudiante. */
class ServicioTablero
{
    public function __construct(private readonly ServicioResultados $resultados) {}

    public function grupo(Course $curso): array
    {
        $tareas = $this->tareas($curso);
        $inscripciones = $this->resultados->inscripciones($curso);
        $ids = $inscripciones->pluck('id')->all();
        $progreso = TaskProgress::whereIn('enrollment_id', $ids)->get();
        $porInscripcion = $progreso->groupBy('enrollment_id');
        $ultima = LearningEvent::whereIn('enrollment_id', $ids)->groupBy('enrollment_id')
            ->selectRaw('enrollment_id, max(ocurrido_at) as ultima')->pluck('ultima', 'enrollment_id');

        $alertas = Alertas::desde($curso->configuracion['alertas'] ?? []);
        $ahora = new DateTimeImmutable();

        $estudiantes = $inscripciones->map(function (Enrollment $e) use ($porInscripcion, $ultima, $alertas, $ahora) {
            $suyo = ($porInscripcion[$e->id] ?? collect())->keyBy('tarea_uid');
            $ultimaActividad = isset($ultima[$e->id]) ? new DateTimeImmutable($ultima[$e->id]) : null;

            return [
                'enrollment_id' => $e->id,
                'nombre' => $e->user->name,
                'seudonimo' => $e->seudonimo,
                'estado' => $e->estado->value,
                'grupo' => $e->membresia?->group?->only('clave', 'nombre'),
                'nivel' => $e->perfil?->nivel,
                'cp' => $e->perfil?->cp_global,
                'ultima_actividad' => $ultimaActividad?->format(DATE_ATOM),
                'celdas' => $suyo->map(fn (TaskProgress $p) => [
                    'estado' => $p->estado, 'fraccion' => $p->mejor_fraccion, 'intentos' => $p->intentos, 'esfuerzo' => $p->esfuerzo,
                ]),
                'alertas' => $alertas->de([
                    'estado' => $e->estado->value,
                    'ultima_actividad' => $ultimaActividad,
                    'banderas' => $e->perfil?->banderas ?? [],
                    'tareas' => $suyo->map(fn (TaskProgress $p) => ['esfuerzo' => $p->esfuerzo, 'fraccion' => $p->mejor_fraccion])->values()->all(),
                ], $ahora),
            ];
        })->values();

        return [
            'tareas' => $tareas->values(),
            'estudiantes' => $estudiantes,
            'diagnostico' => [
                'faltan' => $inscripciones->filter(fn ($e) => in_array($e->estado->value, ['inscrito', 'diagnostico'], true))->count(),
                'niveles' => $inscripciones->countBy(fn ($e) => $e->perfil?->nivel ?? 'sin_perfil'),
                'grupos' => $inscripciones->countBy(fn ($e) => $e->membresia?->group?->nombre ?? 'Sin grupo'),
            ],
            'esfuerzo_por_tarea' => $this->esfuerzoPorTarea($tareas, $progreso),
            'ayudas' => $this->ayudas($curso, $tareas, $ids, $progreso),
            'carga_por_clase' => $this->resultados->cargaPorClase($curso),
            'imms' => $this->resultados->resumenInstrumento($curso, 'imms'),
        ];
    }

    /** Ficha de un estudiante: perfil, trayectoria, respuestas, auto-explicaciones y código enviado. */
    public function estudiante(Course $curso, Enrollment $e): array
    {
        $tareas = $this->tareas($curso);
        $e->loadMissing(['user:id,name', 'perfil', 'membresia.group']);

        return [
            'enrollment_id' => $e->id,
            'nombre' => $e->user->name,
            'seudonimo' => $e->seudonimo,
            'estado' => $e->estado->value,
            'lenguaje' => $curso->lenguaje->value,
            'grupo' => $e->membresia?->group?->only('clave', 'nombre'),
            'perfil' => $e->perfil?->only('cp_recall', 'cp_comprension', 'cp_teorico', 'cp_practico', 'cp_global', 'nivel', 'mslq', 'indices', 'banderas'),
            'puntajes' => $this->resultados->puntajes($curso, [$e->id])->get($e->id),
            'instrumentos' => InstrumentResponse::with('administration.instrument:id,clave,nombre')
                ->where('enrollment_id', $e->id)->whereNotNull('completado_at')->orderBy('completado_at')->get()
                ->map(fn (InstrumentResponse $r) => [
                    'instrumento' => $r->administration->instrument->nombre,
                    'momento' => $r->administration->momento,
                    'clase_uid' => $r->administration->clase_uid,
                    'subescalas' => $r->puntajes['subescalas'] ?? [],
                    'completado_at' => $r->completado_at,
                ]),
            'tareas' => TaskProgress::where('enrollment_id', $e->id)->get()
                ->sortBy(fn ($p) => $tareas[$p->tarea_uid]['posicion'] ?? PHP_INT_MAX)->values()
                ->map(fn (TaskProgress $p) => [
                    ...$p->only('tarea_uid', 'estado', 'intentos', 'mejor_fraccion', 'esfuerzo', 'autoexplicacion', 'completado_at'),
                    'titulo' => $tareas[$p->tarea_uid]['titulo'] ?? $p->tarea_uid,
                ]),
            'envios' => TaskSubmission::where('enrollment_id', $e->id)->latest()->limit(50)
                ->get(['id', 'tarea_uid', 'numero', 'estado', 'fraccion', 'codigo', 'autoexplicacion', 'created_at']),
            'trayectoria' => LearningEvent::where('enrollment_id', $e->id)->latest('ocurrido_at')->limit(300)
                ->get(['verbo', 'objeto_tipo', 'objeto_uid', 'resultado', 'duracion_ms', 'ocurrido_at']),
        ];
    }

    /** Tareas de la publicación vigente en orden de curso (clase y tarea). La versión base: los uid no cambian entre variantes. */
    private function tareas(Course $curso): Collection
    {
        $m = Release::vigente($curso->id)?->manifiesto ?? ['clases' => [], 'tareas' => []];
        $ordenClase = collect($m['clases'])->pluck('orden', 'uid');

        return collect($m['tareas'])
            ->sortBy([fn ($a, $b) => ($ordenClase[$a['clase_uid']] ?? 99) <=> ($ordenClase[$b['clase_uid']] ?? 99), ['orden', 'asc']])
            ->values()
            ->map(fn ($t, $i) => [
                'uid' => $t['uid'], 'titulo' => $t['titulo'], 'clase_uid' => $t['clase_uid'],
                'clase_orden' => $ordenClase[$t['clase_uid']] ?? null, 'orden' => $t['orden'],
                'nivel_apoyo' => $t['nivel_apoyo'], 'posicion' => $i,
            ])
            ->keyBy('uid');
    }

    /** Esfuerzo mental medio (Paas, 1–9) y desempeño medio por tarea. */
    private function esfuerzoPorTarea(Collection $tareas, Collection $progreso): array
    {
        $porTarea = $progreso->groupBy('tarea_uid');

        return $tareas->map(function ($t) use ($porTarea) {
            $p = $porTarea[$t['uid']] ?? collect();
            $conEsfuerzo = $p->whereNotNull('esfuerzo');

            return [
                'tarea_uid' => $t['uid'],
                'abrieron' => $p->count(),
                'n_esfuerzo' => $conEsfuerzo->count(),
                'esfuerzo' => $conEsfuerzo->isEmpty() ? null : round($conEsfuerzo->avg('esfuerzo'), 2),
                'desempeno' => $p->whereNotNull('mejor_fraccion')->isEmpty() ? null : round($p->whereNotNull('mejor_fraccion')->avg('mejor_fraccion'), 3),
            ];
        })->values()->all();
    }

    /** Uso de la ayuda justo a tiempo: cuántos de los que abrieron la tarea consultaron alguna de sus ayudas. */
    private function ayudas(Course $curso, Collection $tareas, array $ids, Collection $progreso): array
    {
        $ayudaTarea = collect(Release::vigente($curso->id)?->manifiesto['procedimental'] ?? [])->pluck('tarea_uid', 'uid');
        $consultas = LearningEvent::whereIn('enrollment_id', $ids)->where('verbo', 'consulto_ayuda')
            ->get(['enrollment_id', 'objeto_uid'])
            ->groupBy(fn ($ev) => $ayudaTarea[$ev->objeto_uid] ?? null);

        $abrieron = $progreso->countBy('tarea_uid');

        return $tareas->map(fn ($t) => [
            'tarea_uid' => $t['uid'],
            'consultas' => ($consultas[$t['uid']] ?? collect())->count(),
            'estudiantes' => ($consultas[$t['uid']] ?? collect())->unique('enrollment_id')->count(),
            'abrieron' => $abrieron[$t['uid']] ?? 0,
        ])->filter(fn ($a) => $a['abrieron'] > 0)->values()->all();
    }
}
