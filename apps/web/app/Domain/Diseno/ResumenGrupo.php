<?php

namespace App\Domain\Diseno;

/** Convierte los perfiles de un grupo en los «hechos» sobre los que se evalúan las reglas de preselección. */
final class ResumenGrupo
{
    /**
     * @param  list<array{nivel: string, mslq: array<string, float>, banderas: list<string>}>  $perfiles
     * @return array{n: int, nivel_modal: string|null, niveles: array<string, int>, media: array<string, float>, proporcion: array<string, float>}
     */
    public static function desdePerfiles(array $perfiles): array
    {
        $n = count($perfiles);
        $niveles = ['basico' => 0, 'intermedio' => 0, 'avanzado' => 0];
        $sumas = [];
        $cuentas = [];
        $banderas = ['baja_autoeficacia' => 0, 'alta_ansiedad' => 0, 'baja_autorregulacion' => 0];

        foreach ($perfiles as $p) {
            $niveles[$p['nivel']] = ($niveles[$p['nivel']] ?? 0) + 1;
            foreach ($p['mslq'] as $subescala => $valor) {
                $sumas[$subescala] = ($sumas[$subescala] ?? 0) + $valor;
                $cuentas[$subescala] = ($cuentas[$subescala] ?? 0) + 1;
            }
            foreach ($p['banderas'] as $b) {
                $banderas[$b] = ($banderas[$b] ?? 0) + 1;
            }
        }

        $ordenados = $niveles;
        arsort($ordenados); // estable: en empate gana el nivel más bajo (criterio conservador de 2.6)

        $media = [];
        foreach ($sumas as $subescala => $suma) {
            $media[$subescala] = round($suma / $cuentas[$subescala], 2);
        }

        return [
            'n' => $n,
            'nivel_modal' => $n ? array_key_first($ordenados) : null,
            'niveles' => $niveles,
            'media' => $media,
            'proporcion' => array_map(fn ($c) => $n ? round($c / $n, 3) : 0.0, $banderas),
        ];
    }
}
