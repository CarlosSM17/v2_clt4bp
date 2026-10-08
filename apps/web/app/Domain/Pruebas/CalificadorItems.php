<?php

namespace App\Domain\Pruebas;

use InvalidArgumentException;

/**
 * Califica ítems de respuesta cerrada. Devuelve la fracción obtenida (0 a 1).
 * Los problemas de programación se califican aparte, ejecutando sus casos de prueba.
 */
final class CalificadorItems
{
    /**
     * @param  array<string, mixed>  $clave  la clave guardada con el ítem
     * @param  mixed  $respuesta  lo que envió el estudiante
     */
    public function fraccion(string $tipo, array $clave, mixed $respuesta): float
    {
        return match ($tipo) {
            'opcion_multiple' => (string) $respuesta === (string) $clave['correcta'] ? 1.0 : 0.0,
            'respuesta_corta' => $this->respuestaCorta($clave['aceptadas'], (string) $respuesta),
            'prediccion_salida' => ComparadorSalida::iguales((string) $respuesta, (string) $clave['salida']) ? 1.0 : 0.0,
            'parsons' => $this->parsons($clave['orden'], is_array($respuesta) ? $respuesta : []),
            default => throw new InvalidArgumentException("Tipo de ítem no calificable aquí: {$tipo}"),
        };
    }

    /** @param list<string> $aceptadas */
    private function respuestaCorta(array $aceptadas, string $respuesta): float
    {
        $norm = fn (string $s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
        foreach ($aceptadas as $a) {
            if ($norm($a) === $norm($respuesta)) {
                return 1.0;
            }
        }

        return 0.0;
    }

    /**
     * Crédito parcial: longitud de la subsecuencia común más larga entre el orden
     * correcto y el enviado, dividida entre el número de líneas.
     *
     * @param  list<string>  $correcto
     * @param  list<string>  $enviado
     */
    private function parsons(array $correcto, array $enviado): float
    {
        $n = count($correcto);
        $m = count($enviado);
        if ($n === 0) {
            return 0.0;
        }
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $dp[$i][$j] = $correcto[$i - 1] === $enviado[$j - 1]
                    ? $dp[$i - 1][$j - 1] + 1
                    : max($dp[$i - 1][$j], $dp[$i][$j - 1]);
            }
        }

        return round($dp[$n][$m] / $n, 4);
    }
}
