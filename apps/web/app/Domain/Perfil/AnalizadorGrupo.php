<?php

namespace App\Domain\Perfil;

/** Decisión del modelo: ¿el grupo tiene conocimientos previos homogéneos? (sección 6.3). */
final class AnalizadorGrupo
{
    public function __construct(private readonly ConfiguracionPerfil $config) {}

    /**
     * @param  list<array{cp: float, nivel: string}>  $perfiles
     * @return array{n: int, media: float, desviacion: float, cv: float|null, nivel_modal: string|null,
     *               proporcion_modal: float, recomendacion: string, confiable: bool,
     *               histograma: array<string, int>, niveles: array<string, int>}
     */
    public function analizar(array $perfiles): array
    {
        $n = count($perfiles);
        $cps = array_column($perfiles, 'cp');
        $media = $n ? array_sum($cps) / $n : 0.0;
        $desviacion = $n > 1
            ? sqrt(array_sum(array_map(fn ($x) => ($x - $media) ** 2, $cps)) / ($n - 1))
            : 0.0;
        $cv = match (true) {
            $n === 0 => null,
            $media > 0 => $desviacion / $media,
            default => $desviacion == 0.0 ? 0.0 : null,   // todos en cero: homogéneo
        };

        $niveles = array_fill_keys(CalculadoraPerfil::NIVELES, 0);
        foreach ($perfiles as $p) {
            $niveles[$p['nivel']]++;
        }
        arsort($niveles);
        $nivelModal = $n ? array_key_first($niveles) : null;
        $proporcionModal = $n ? $niveles[$nivelModal] / $n : 0.0;

        $homogeneo = $cv !== null
            && $cv <= $this->config->cvMaximoHomogeneo
            && $proporcionModal >= $this->config->proporcionModalMinima;

        return [
            'n' => $n,
            'media' => round($media, 2),
            'desviacion' => round($desviacion, 2),
            'cv' => $cv === null ? null : round($cv, 3),
            'nivel_modal' => $nivelModal,
            'proporcion_modal' => round($proporcionModal, 3),
            'recomendacion' => $homogeneo ? 'homogeneo' : 'heterogeneo',
            'confiable' => $n >= $this->config->minimoConfiable,
            'histograma' => $this->histograma($cps),
            'niveles' => array_merge(array_fill_keys(CalculadoraPerfil::NIVELES, 0), $niveles),
        ];
    }

    /** Frecuencias por tramos de 10 puntos: 0-9, 10-19, …, 90-100. */
    private function histograma(array $cps): array
    {
        $tramos = [];
        for ($i = 0; $i < 10; $i++) {
            $tramos[sprintf('%d-%d', $i * 10, $i === 9 ? 100 : $i * 10 + 9)] = 0;
        }
        $claves = array_keys($tramos);
        foreach ($cps as $cp) {
            $tramos[$claves[min(9, (int) floor($cp / 10))]]++;
        }

        return $tramos;
    }
}
