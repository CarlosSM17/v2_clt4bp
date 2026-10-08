<?php

namespace App\Domain\Diseno;

/**
 * Paso 3 de CLT4BP: sugiere efectos de la TCC a partir de reglas «condición → efectos».
 * Las reglas son datos (tabla effect_rules), así el administrador las ajusta sin tocar código.
 */
final class Preseleccion
{
    /**
     * @param  list<array{id: string, nombre: string, condicion: array, efectos: list<string>, recomendaciones: list<string>, fundamento: string}>  $reglas
     * @param  array<string, mixed>  $hechos  resumen del grupo + contexto (interactividad_tema, usa_multimedia)
     * @return array{efectos: list<array{id: string, reglas: list<string>, fundamentos: list<string>}>,
     *               recomendaciones: list<array{texto: string, regla: string}>, reglas_activadas: list<string>}
     */
    public function evaluar(array $reglas, array $hechos): array
    {
        $efectos = [];
        $recomendaciones = [];
        $activadas = [];

        foreach ($reglas as $regla) {
            if (! self::cumple($regla['condicion'], $hechos)) {
                continue;
            }
            $activadas[] = $regla['id'];
            foreach ($regla['efectos'] as $id) {
                $efectos[$id] ??= ['id' => $id, 'reglas' => [], 'fundamentos' => []];
                $efectos[$id]['reglas'][] = $regla['id'];
                $efectos[$id]['fundamentos'][] = $regla['fundamento'];
            }
            foreach ($regla['recomendaciones'] ?? [] as $texto) {
                $recomendaciones[] = ['texto' => $texto, 'regla' => $regla['id']];
            }
        }

        return ['efectos' => array_values($efectos), 'recomendaciones' => $recomendaciones, 'reglas_activadas' => $activadas];
    }

    /** Condición: {"todas": [...]}, {"alguna": [...]} o una hoja {"campo", "op", "valor"}. */
    public static function cumple(array $condicion, array $hechos): bool
    {
        if (isset($condicion['todas'])) {
            foreach ($condicion['todas'] as $c) {
                if (! self::cumple($c, $hechos)) {
                    return false;
                }
            }

            return true;
        }
        if (isset($condicion['alguna'])) {
            foreach ($condicion['alguna'] as $c) {
                if (self::cumple($c, $hechos)) {
                    return true;
                }
            }

            return false;
        }

        $valor = self::valor($hechos, $condicion['campo']);
        if ($valor === null) {
            return false; // sin dato no se activa la regla
        }
        $esperado = $condicion['valor'];

        return match ($condicion['op']) {
            '=' => $valor == $esperado,
            '!=' => $valor != $esperado,
            '<' => $valor < $esperado,
            '<=' => $valor <= $esperado,
            '>' => $valor > $esperado,
            '>=' => $valor >= $esperado,
            'en' => in_array($valor, (array) $esperado, true),
            default => false,
        };
    }

    /** Lee "media.metacognicion" de ['media' => ['metacognicion' => 3.8]]. */
    private static function valor(array $hechos, string $campo): mixed
    {
        $actual = $hechos;
        foreach (explode('.', $campo) as $parte) {
            if (! is_array($actual) || ! array_key_exists($parte, $actual)) {
                return null;
            }
            $actual = $actual[$parte];
        }

        return $actual;
    }
}
