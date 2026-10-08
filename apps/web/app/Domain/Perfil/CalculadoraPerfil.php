<?php

namespace App\Domain\Perfil;

/**
 * Combina MSLQ y pruebas iniciales en el perfil del estudiante (sección 6.2 de la propuesta).
 *
 * Las subescalas del MSLQ son las de instruments/mslq.json (versión de la tesis, 13 subescalas).
 */
final class CalculadoraPerfil
{
    public const NIVELES = ['basico', 'intermedio', 'avanzado'];

    /** Índices compuestos del MSLQ y las subescalas que los forman. */
    public const INDICES = [
        'motivacion' => ['autoeficacia', 'valor_tarea', 'intrinseca'],
        'estrategias_cognitivas' => ['repaso', 'elaboracion', 'organizacion'],
        'autorregulacion' => ['metacognicion', 'gestion_tiempo', 'regulacion_esfuerzo'],
    ];

    public function __construct(private readonly ConfiguracionPerfil $config) {}

    /**
     * @param  array<string, float>  $mslq  subescala => media (1–7)
     * @return array{cp_recall: float, cp_comprension: float, cp_teorico: float, cp_practico: float,
     *               cp_global: float, nivel: string, mslq: array<string, float>,
     *               indices: array<string, float>, banderas: list<string>}
     */
    public function calcular(array $mslq, float $recall, float $comprension, float $teorico, float $practico): array
    {
        $global = round($this->config->pesoTeorico * $teorico + $this->config->pesoPractico * $practico, 2);

        return [
            'cp_recall' => round($recall, 2),
            'cp_comprension' => round($comprension, 2),
            'cp_teorico' => round($teorico, 2),
            'cp_practico' => round($practico, 2),
            'cp_global' => $global,
            'nivel' => $this->nivel($global),
            'mslq' => $mslq,
            'indices' => $this->indices($mslq),
            'banderas' => $this->banderas($mslq),
        ];
    }

    public function nivel(float $cp): string
    {
        return match (true) {
            $cp > $this->config->umbralAvanzado => 'avanzado',
            $cp >= $this->config->umbralIntermedio => 'intermedio',
            default => 'basico',
        };
    }

    /** @param array<string, float> $mslq */
    private function indices(array $mslq): array
    {
        $indices = [];
        foreach (self::INDICES as $clave => $subescalas) {
            $valores = array_values(array_intersect_key($mslq, array_flip($subescalas)));
            if (count($valores) === count($subescalas)) {
                $indices[$clave] = round(array_sum($valores) / count($valores), 2);
            }
        }

        return $indices;
    }

    /** @param array<string, float> $mslq */
    private function banderas(array $mslq): array
    {
        $banderas = [];
        if (($mslq['autoeficacia'] ?? 7) < 4) {
            $banderas[] = 'baja_autoeficacia';
        }
        if (($mslq['ansiedad'] ?? 1) > 5) {
            $banderas[] = 'alta_ansiedad';
        }
        if (($mslq['metacognicion'] ?? 7) < 4) {
            $banderas[] = 'baja_autorregulacion';
        }

        return $banderas;
    }
}
