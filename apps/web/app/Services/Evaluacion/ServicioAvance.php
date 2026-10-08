<?php

namespace App\Services\Evaluacion;

use App\Domain\Evaluacion\MensajesLogro;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Services\Aula\ServicioAula;

/** Dashboard del estudiante (propuesta, 9.2): solo su propio avance, sin rankings ni comparaciones. */
class ServicioAvance
{
    /** Subescalas del MSLQ que se muestran en el radar (las de ansiedad, pares y ayuda quedan fuera: se leen al revés o confunden). */
    public const RADAR = ['intrinseca', 'valor_tarea', 'autoeficacia', 'control', 'elaboracion', 'organizacion',
        'pensamiento_critico', 'metacognicion', 'tiempo_ambiente', 'regulacion_esfuerzo'];

    public function __construct(
        private readonly ServicioAula $aula,
        private readonly ServicioResultados $resultados,
        private readonly ServicioEvaluacion $evaluacion,
    ) {}

    public function de(Enrollment $inscripcion): array
    {
        $ctx = $this->aula->contexto($inscripcion);
        $curso = $inscripcion->course;

        $clases = collect($ctx->clases)->map(fn (array $c) => [
            'orden' => $c['orden'],
            'titulo' => $c['titulo'],
            'estado' => $ctx->estados[$c['uid']]['estado'],
            'tareas' => collect($ctx->tareasDe($c['uid']))->map(function (array $t) use ($ctx) {
                $p = $ctx->progreso->get($t['uid']);

                return [
                    'orden' => $t['orden'], 'titulo' => $t['titulo'], 'nivel_apoyo' => $t['nivel_apoyo'],
                    'estado' => $p?->estado ?? 'sin_empezar', 'fraccion' => $p?->mejor_fraccion, 'esfuerzo' => $p?->esfuerzo,
                ];
            })->all(),
        ])->all();

        $puntajes = $this->resultados->puntajes($curso, [$inscripcion->id])->get($inscripcion->id);

        $prePost = collect(['recall', 'comprension', 'practico'])->mapWithKeys(fn ($m) => [$m => [
            'pre' => $puntajes['pre'][$m] ?? null, 'post' => $puntajes['post'][$m] ?? null,
        ]])->all();

        $mslq = $this->resultados->instrumento($curso, 'mslq', [$inscripcion->id])->get($inscripcion->id) ?? [];

        $nombres = collect(Instrument::where('clave', 'mslq')->latest('id')->first()?->definicion['subescalas'] ?? [])->pluck('nombre', 'clave');

        return [
            'clases' => collect($clases)->map(fn ($c) => [
                ...collect($c)->only('orden', 'titulo', 'estado')->all(),
                'total' => count($c['tareas']),
                'completadas' => collect($c['tareas'])->where('estado', 'completada')->count(),
            ])->all(),
            'prePost' => $prePost,
            'mslq' => collect(self::RADAR)->map(fn ($k) => [
                'clave' => $k, 'nombre' => $nombres[$k] ?? $k,
                'pre' => $mslq['pre']['subescalas'][$k] ?? null, 'post' => $mslq['post']['subescalas'][$k] ?? null,
            ])->all(),
            // Esfuerzo en el orden del curso (de la clase más simple a la más compleja)
            'esfuerzo' => collect($clases)->flatMap(fn ($c) => collect($c['tareas'])->whereNotNull('esfuerzo')
                ->map(fn ($t) => ['etiqueta' => "C{$c['orden']}·T{$t['orden']}", 'titulo' => $t['titulo'], 'valor' => $t['esfuerzo']]))
                ->values()->all(),
            'logros' => MensajesLogro::para($clases, [
                'teorico' => ['pre' => $puntajes['pre']['teorico'] ?? null, 'post' => $puntajes['post']['teorico'] ?? null],
                'practico' => ['pre' => $puntajes['pre']['practico'] ?? null, 'post' => $puntajes['post']['practico'] ?? null],
            ]),
            'evaluacionFinal' => $this->evaluacion->abierta($curso->id),
        ];
    }
}
