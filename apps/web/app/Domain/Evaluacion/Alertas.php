<?php

namespace App\Domain\Evaluacion;

use DateTimeImmutable;

/** Alertas del dashboard del instructor (propuesta, 9.3). Umbrales configurables por curso. */
final class Alertas
{
    public const TIPOS = [
        'sin_actividad' => 'Sin actividad',
        'esfuerzo_sin_desempeno' => 'Esfuerzo alto con desempeño bajo',
        'diagnostico_incompleto' => 'Diagnóstico incompleto',
        'ansiedad_alta' => 'Ansiedad alta',
    ];

    public function __construct(
        private readonly int $diasSinActividad = 5,
        private readonly int $esfuerzoAlto = 7, // escala de Paas, 1–9
        private readonly float $desempenoBajo = 0.5, // fracción de casos aprobados
        private readonly int $repeticiones = 2,
    ) {}

    /** @param  array<string, mixed>  $configuracion  courses.configuracion['alertas'] */
    public static function desde(array $configuracion): self
    {
        return new self(
            (int) ($configuracion['dias_sin_actividad'] ?? 5),
            (int) ($configuracion['esfuerzo_alto'] ?? 7),
            (float) ($configuracion['desempeno_bajo'] ?? 0.5),
            (int) ($configuracion['repeticiones'] ?? 2),
        );
    }

    /**
     * @param  array{estado: string, ultima_actividad: ?DateTimeImmutable, banderas: list<string>, tareas: list<array{esfuerzo: ?int, fraccion: ?float}>}  $e
     * @return list<array{tipo: string, texto: string}>
     */
    public function de(array $e, DateTimeImmutable $ahora): array
    {
        $alertas = [];

        if (in_array($e['estado'], ['inscrito', 'diagnostico'], true)) {
            $alertas[] = ['tipo' => 'diagnostico_incompleto', 'texto' => 'No ha terminado el diagnóstico inicial.'];
        }

        if (in_array($e['estado'], ['con_perfil', 'cursando'], true)) {
            $ultima = $e['ultima_actividad'];
            $dias = $ultima ? (int) $ultima->diff($ahora)->days : null;
            if ($dias === null || $dias >= $this->diasSinActividad) {
                $alertas[] = ['tipo' => 'sin_actividad', 'texto' => $dias === null
                    ? 'Todavía no entra al material.'
                    : "Lleva {$dias} días sin actividad."];
            }
        }

        $dificiles = array_filter($e['tareas'], fn ($t) => $t['esfuerzo'] !== null && $t['esfuerzo'] >= $this->esfuerzoAlto
            && $t['fraccion'] !== null && $t['fraccion'] < $this->desempenoBajo);
        if (count($dificiles) >= $this->repeticiones) {
            $alertas[] = ['tipo' => 'esfuerzo_sin_desempeno',
                'texto' => count($dificiles).' tareas con esfuerzo alto y menos de '.round(100 * $this->desempenoBajo).' % de casos aprobados.'];
        }

        if (in_array('alta_ansiedad', $e['banderas'], true)) {
            $alertas[] = ['tipo' => 'ansiedad_alta', 'texto' => 'Ansiedad ante los exámenes alta en el MSLQ inicial.'];
        }

        return $alertas;
    }
}
