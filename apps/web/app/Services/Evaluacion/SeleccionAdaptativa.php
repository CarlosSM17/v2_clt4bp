<?php

namespace App\Services\Evaluacion;

use App\Domain\Evaluacion\Eficiencia;
use App\Models\Enrollment;
use App\Models\TaskProgress;
use App\Services\Aula\ContextoAula;
use App\Services\Aula\Eventos;
use Illuminate\Support\Collection;

/**
 * Extensión opcional (propuesta, 9.4; se activa con courses.configuracion.seleccion_adaptativa = true):
 * al valorar el esfuerzo de una tarea, compara desempeño y esfuerzo con los de sus compañeros en esa misma
 * tarea y sugiere menos apoyo, seguir igual o repasar con más apoyo. Es una sugerencia: no bloquea nada.
 */
class SeleccionAdaptativa
{
    private const MAS_APOYO = ['ejemplo_resuelto', 'por_completar'];

    /** @return array{eficiencia: float, decision: string, mensaje: string, tarea_uid: ?string}|null */
    public function sugerir(ContextoAula $ctx, string $tareaUid): ?array
    {
        $config = $ctx->inscripcion->course->configuracion ?? [];

        // Se lee de nuevo: el contexto se armó antes de guardar el esfuerzo
        $yo = TaskProgress::where('enrollment_id', $ctx->inscripcion->id)->where('tarea_uid', $tareaUid)->first();
        if (! $yo || $yo->mejor_fraccion === null || $yo->esfuerzo === null) {
            return null; // un ejemplo resuelto no tiene desempeño: no hay nada que comparar
        }

        $companeros = TaskProgress::where('tarea_uid', $tareaUid)->whereNotNull('mejor_fraccion')->whereNotNull('esfuerzo')
            ->whereIn('enrollment_id', Enrollment::where('course_id', $ctx->inscripcion->course_id)->select('id'))
            ->get(['mejor_fraccion', 'esfuerzo']);
        if ($companeros->count() < (int) ($config['seleccion_minimo'] ?? 5)) {
            return null; // con muy pocos datos la estandarización no significa nada
        }

        $zp = Eficiencia::z($yo->mejor_fraccion, $companeros->pluck('mejor_fraccion')->map(fn ($v) => (float) $v)->all());
        $zr = Eficiencia::z((float) $yo->esfuerzo, $companeros->pluck('esfuerzo')->map(fn ($v) => (float) $v)->all());
        if ($zp === null || $zr === null) {
            return null;
        }
        $e = round(Eficiencia::valor($zp, $zr), 3);
        $decision = Eficiencia::decision($e, (float) ($config['seleccion_umbral'] ?? 0.5));

        $t = $ctx->tarea($tareaUid);
        $hermanas = collect($ctx->tareasDe($t['clase_uid']));
        $sugerencia = match ($decision) {
            'menos_apoyo' => $this->avanzar($ctx, $hermanas, $t),
            'mas_apoyo' => $this->repasar($hermanas, $t),
            default => ['mensaje' => 'Vas bien: continúa con la siguiente tarea.', 'tarea_uid' => null],
        };
        Eventos::registrar($ctx->inscripcion->id, 'recibio_sugerencia', 'tarea', $tareaUid,
            ['eficiencia' => $e, 'decision' => $decision, 'sugerida' => $sugerencia['tarea_uid']], $ctx->release->id);

        return ['eficiencia' => $e, 'decision' => $decision, ...$sugerencia];
    }

    /** Buen desempeño con poco esfuerzo: saltar a la siguiente tarea con menos guía o a la siguiente clase. */
    private function avanzar(ContextoAula $ctx, Collection $hermanas, array $t): array
    {
        $siguiente = $hermanas->first(fn ($h) => $h['orden'] > $t['orden'] && ! in_array($h['nivel_apoyo'], self::MAS_APOYO, true));
        if ($siguiente) {
            return ['mensaje' => "Lo resolviste con buen desempeño y poco esfuerzo: puedes pasar directo a «{$siguiente['titulo']}».",
                'tarea_uid' => $siguiente['uid']];
        }
        $i = collect($ctx->clases)->search(fn ($c) => $c['uid'] === $t['clase_uid']);
        $clase = $ctx->clases[$i + 1] ?? null;

        return ['mensaje' => $clase
            ? "Dominas esta clase: cuando se abra, sigue con «{$clase['titulo']}»."
            : 'Dominas esta clase de tareas.', 'tarea_uid' => null];
    }

    /** Mucho esfuerzo con desempeño bajo: repasar el ejemplo resuelto o la tarea por completar de la clase. */
    private function repasar(Collection $hermanas, array $t): array
    {
        $apoyo = $hermanas->first(fn ($h) => $h['uid'] !== $t['uid'] && in_array($h['nivel_apoyo'], self::MAS_APOYO, true));

        return $apoyo
            ? ['mensaje' => "Esta tarea te costó mucho. Antes de seguir, repasa «{$apoyo['titulo']}» y vuelve a intentarlo con la ayuda abierta.",
                'tarea_uid' => $apoyo['uid']]
            : ['mensaje' => 'Esta tarea te costó mucho. Abre las ayudas de la tarea y revisa «Antes de empezar» de la clase.', 'tarea_uid' => null];
    }
}
