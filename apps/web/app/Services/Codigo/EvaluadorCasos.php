<?php

namespace App\Services\Codigo;

use App\Domain\Pruebas\ComparadorSalida;

/** Ejecuta un programa contra sus casos de prueba y resume el resultado. */
final class EvaluadorCasos
{
    public function __construct(private readonly EjecutorCodigo $ejecutor) {}

    /**
     * @param  list<array{entrada: string, salida_esperada: string, oculto: bool}>  $casos
     * @return array{aprobados: int, total: int, fraccion: float, error_compilacion: string|null, casos: list<array<string, mixed>>}
     */
    public function evaluar(string $lenguaje, string $codigo, array $casos): array
    {
        $total = count($casos);
        $aprobados = 0;
        $detalle = [];

        foreach ($casos as $i => $caso) {
            // Una entrada vacía llega como null (ConvertEmptyStringsToNull)
            $r = $this->ejecutor->ejecutar($lenguaje, $codigo, $caso['entrada'] ?? '');

            if (! $r->compilo) {
                return ['aprobados' => 0, 'total' => $total, 'fraccion' => 0.0,
                    'error_compilacion' => mb_substr($r->errores, 0, 2000), 'casos' => []];
            }

            $ok = ! $r->excedioLimite && $r->codigo === 0 && ComparadorSalida::iguales($r->salida, $caso['salida_esperada']);
            $aprobados += $ok ? 1 : 0;

            $detalle[] = [
                'caso' => $i + 1,
                'aprobado' => $ok,
                'oculto' => $caso['oculto'],
                'tiempo_excedido' => $r->excedioLimite,
                'entrada' => $caso['oculto'] ? null : $caso['entrada'],
                'esperada' => $caso['oculto'] ? null : $caso['salida_esperada'],
                'obtenida' => $caso['oculto'] ? null : mb_substr($r->salida, 0, 2000),
            ];
        }

        return [
            'aprobados' => $aprobados,
            'total' => $total,
            'fraccion' => $total ? round($aprobados / $total, 4) : 0.0,
            'error_compilacion' => null,
            'casos' => $detalle,
        ];
    }
}
