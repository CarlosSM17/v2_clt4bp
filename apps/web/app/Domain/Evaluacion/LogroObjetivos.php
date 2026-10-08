<?php

namespace App\Domain\Evaluacion;

/** Porcentaje de logro por objetivo en el post-test (paso 10): cuánto sacó el grupo y cuántos alcanzan el criterio. */
final class LogroObjetivos
{
    /**
     * @param  iterable<array{inscripcion: int, item: int, objetivo: ?string, puntos: float, fraccion: ?float}>  $filas  una por ítem del post-test y estudiante que lo presentó (fraccion null = sin responder = 0)
     * @return list<array{codigo: string, items: int, estudiantes: int, media: float, logran: float}> media: % promedio de los estudiantes en los ítems del objetivo; logran: % de estudiantes con ≥ criterio
     */
    public static function calcular(iterable $filas, float $criterio = 70.0): array
    {
        $acumulado = []; // objetivo => inscripción => [obtenido, posible]
        $items = [];
        foreach ($filas as $f) {
            if (! $f['objetivo']) {
                continue; // ítems sin objetivo no cuentan para el logro por objetivo
            }
            $a = &$acumulado[$f['objetivo']][$f['inscripcion']];
            $a ??= [0.0, 0.0];
            $a[0] += $f['puntos'] * (float) ($f['fraccion'] ?? 0);
            $a[1] += $f['puntos'];
            unset($a);
            $items[$f['objetivo']][$f['item']] = true;
        }

        $resultado = [];
        foreach ($acumulado as $codigo => $porEstudiante) {
            $porcentajes = array_map(fn ($a) => $a[1] > 0 ? 100 * $a[0] / $a[1] : 0.0, array_values($porEstudiante));

            $resultado[] = [
                'codigo' => (string) $codigo,
                'items' => count($items[$codigo]),
                'estudiantes' => count($porcentajes),
                'media' => round(array_sum($porcentajes) / count($porcentajes), 2),
                'logran' => round(100 * count(array_filter($porcentajes, fn ($p) => $p >= $criterio)) / count($porcentajes), 2),
            ];
        }
        usort($resultado, fn ($x, $y) => strnatcmp($x['codigo'], $y['codigo'])); // OB-2 antes que OB-10

        return $resultado;
    }
}
