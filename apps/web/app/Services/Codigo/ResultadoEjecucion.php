<?php

namespace App\Services\Codigo;

final class ResultadoEjecucion
{
    public function __construct(
        public readonly bool $compilo,
        public readonly string $salida,
        public readonly string $errores,
        public readonly int $codigo,
        public readonly bool $excedioLimite,
    ) {}

    /** Interpreta la respuesta de POST /api/v2/execute de Piston. */
    public static function desdePiston(array $json): self
    {
        $compilacion = $json['compile'] ?? null;
        if ($compilacion !== null && ($compilacion['code'] ?? 0) !== 0) {
            return new self(false, '', (string) ($compilacion['stderr'] ?: ($compilacion['output'] ?? '')), (int) ($compilacion['code'] ?? 1), false);
        }

        $ejecucion = $json['run'] ?? [];
        $senal = $ejecucion['signal'] ?? null;

        return new self(
            compilo: true,
            salida: (string) ($ejecucion['stdout'] ?? ''),
            errores: (string) ($ejecucion['stderr'] ?? ''),
            codigo: isset($ejecucion['code']) ? (int) $ejecucion['code'] : -1,
            excedioLimite: $senal === 'SIGKILL',   // Piston mata el proceso al rebasar tiempo o memoria
        );
    }
}
