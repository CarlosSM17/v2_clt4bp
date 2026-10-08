<?php

namespace App\Domain\Pruebas;

/** Compara la salida de un programa con la esperada, tolerando espacios finales. */
final class ComparadorSalida
{
    public static function normalizar(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $lineas = array_map('rtrim', explode("\n", $texto));
        while ($lineas !== [] && end($lineas) === '') {
            array_pop($lineas);
        }

        return implode("\n", $lineas);
    }

    public static function iguales(string $obtenida, string $esperada): bool
    {
        return self::normalizar($obtenida) === self::normalizar($esperada);
    }
}
