<?php

namespace App\Jobs;

use App\Models\AssessmentAttempt;
use App\Services\Codigo\EvaluadorCasos;
use App\Services\Diagnostico\ServicioDiagnostico;
use App\Services\Evaluacion\ServicioEvaluacion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Califica los problemas de programación de un intento y calcula sus totales. */
class CalificarIntento implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(public int $intentoId) {}

    public function handle(EvaluadorCasos $evaluador, ServicioDiagnostico $diagnostico, ServicioEvaluacion $evaluacion): void
    {
        $intento = AssessmentAttempt::with(['assessment.items', 'responses', 'enrollment'])->findOrFail($this->intentoId);
        $respuestas = $intento->responses->keyBy('item_id');

        $obtenido = ['recall' => 0.0, 'comprension' => 0.0, 'practica' => 0.0];
        $posible = ['recall' => 0.0, 'comprension' => 0.0, 'practica' => 0.0];

        foreach ($intento->assessment->items as $item) {
            $puntos = (float) $item->pivot->puntos;
            $respuesta = $respuestas->get($item->id);

            if ($item->tipo === 'programacion' && $respuesta && $respuesta->fraccion === null) {
                $codigo = (string) ($respuesta->respuesta['codigo'] ?? '');
                $resultado = $codigo === ''
                    ? ['fraccion' => 0.0, 'casos' => [], 'error_compilacion' => null]
                    : $evaluador->evaluar($item->lenguaje, $codigo, $item->casos_prueba ?? []);
                $respuesta->update(['fraccion' => $resultado['fraccion'], 'detalle' => $resultado]);
            }

            $posible[$item->nivel] += $puntos;
            $obtenido[$item->nivel] += $puntos * (float) ($respuesta?->fraccion ?? 0);
        }

        $sub = [];
        foreach ($posible as $nivel => $p) {
            if ($p > 0) {
                $sub[$nivel] = round(100 * $obtenido[$nivel] / $p, 2);
            }
        }
        $total = array_sum($posible);

        $intento->update([
            'porcentaje' => $total > 0 ? round(100 * array_sum($obtenido) / $total, 2) : 0,
            'subpuntajes' => $sub,
            'calificado_at' => now(),
        ]);

        $intento->assessment->momento === 'post'
            ? $evaluacion->verificarCompleto($intento->enrollment)
            : $diagnostico->verificarCompleto($intento->enrollment);
    }
}
