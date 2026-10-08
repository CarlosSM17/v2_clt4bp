<?php

namespace App\Domain\Evaluacion;

/**
 * Eficiencia instruccional de Paas y van Merriënboer: E = (zP - zR) / √2, con P el desempeño y R el esfuerzo
 * mental, estandarizados dentro del grupo. E > 0: rinde más de lo que le cuesta; E < 0: le cuesta más de lo que rinde.
 */
final class Eficiencia
{
    /** @param  list<float>  $muestra */
    public static function z(float $x, array $muestra): ?float
    {
        $n = count($muestra);
        if ($n < 2) {
            return null;
        }
        $media = array_sum($muestra) / $n;
        $de = sqrt(array_sum(array_map(fn ($v) => ($v - $media) ** 2, $muestra)) / ($n - 1));

        return $de > 0 ? ($x - $media) / $de : null;
    }

    public static function valor(float $zDesempeno, float $zEsfuerzo): float
    {
        return ($zDesempeno - $zEsfuerzo) / M_SQRT2;
    }

    /**
     * E de cada estudiante respecto a su grupo.
     *
     * @template K of array-key
     *
     * @param  array<K, array{desempeno: float, esfuerzo: float}>  $datos
     * @return array<K, ?float> null si el grupo no tiene variación en alguna de las dos medidas
     */
    public static function delGrupo(array $datos): array
    {
        $p = array_column($datos, 'desempeno');
        $r = array_column($datos, 'esfuerzo');

        return array_map(function (array $d) use ($p, $r) {
            $zp = self::z($d['desempeno'], $p);
            $zr = self::z($d['esfuerzo'], $r);

            return $zp === null || $zr === null ? null : round(self::valor($zp, $zr), 4);
        }, $datos);
    }

    /**
     * Regla de la selección adaptativa (propuesta, 9.4).
     *
     * @return 'menos_apoyo'|'continuar'|'mas_apoyo'
     */
    public static function decision(float $e, float $umbral = 0.5): string
    {
        return match (true) {
            $e > $umbral => 'menos_apoyo',
            $e < -$umbral => 'mas_apoyo',
            default => 'continuar',
        };
    }
}
