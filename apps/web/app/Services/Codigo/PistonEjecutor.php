<?php

namespace App\Services\Codigo;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

final class PistonEjecutor implements EjecutorCodigo
{
    /** lenguaje del curso => [lenguaje de Piston, nombre de archivo] */
    private const LENGUAJES = [
        'c' => ['c', 'main.c'],
        'cpp' => ['c++', 'main.cpp'],
        'python' => ['python', 'main.py'],
    ];

    public function ejecutar(string $lenguaje, string $codigo, string $entrada = '', int $limiteMs = 3000): ResultadoEjecucion
    {
        [$lenguajePiston, $archivo] = self::LENGUAJES[$lenguaje]
            ?? throw new InvalidArgumentException("Lenguaje no soportado: {$lenguaje}");

        $respuesta = Http::baseUrl(config('services.piston.url'))
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 500, throw: false)
            ->post('/execute', [
                'language' => $lenguajePiston,
                'version' => '*',
                'files' => [['name' => $archivo, 'content' => $codigo]],
                'stdin' => $entrada,
                'compile_timeout' => 10000,
                'run_timeout' => $limiteMs,
                'run_memory_limit' => 128 * 1024 * 1024,
            ]);

        if ($respuesta->failed()) {
            throw new RuntimeException('Piston no respondió correctamente (HTTP '.$respuesta->status().').');
        }

        return ResultadoEjecucion::desdePiston($respuesta->json());
    }
}
