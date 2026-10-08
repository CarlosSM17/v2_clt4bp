<?php

namespace App\Domain\Instrumentos;

use InvalidArgumentException;

final class PuntuadorLikert
{
    private int $min;

    private int $max;

    /** @var array<string, bool> id => es inverso */
    private array $inversos = [];

    /** @param array<string, mixed> $definicion */
    public function __construct(private readonly array $definicion)
    {
        $this->min = (int) $definicion['escala']['min'];
        $this->max = (int) $definicion['escala']['max'];
        foreach ($definicion['items'] as $item) {
            $this->inversos[$item['id']] = (bool) ($item['inverso'] ?? false);
        }
    }

    /** @return list<string> */
    public function idsDeItems(): array
    {
        return array_keys($this->inversos);
    }

    /**
     * @param  array<string, int|string>  $respuestas  id del ítem => valor elegido
     * @return list<string> ids sin respuesta
     */
    public function faltantes(array $respuestas): array
    {
        return array_values(array_diff($this->idsDeItems(), array_keys($respuestas)));
    }

    /**
     * @param  array<string, int|string>  $respuestas
     * @return array{subescalas: array<string, float>, indices: array<string, float>}
     */
    public function puntuar(array $respuestas): array
    {
        $recodificadas = [];
        foreach ($respuestas as $id => $valor) {
            if (! isset($this->inversos[$id])) {
                throw new InvalidArgumentException("El ítem {$id} no existe en el instrumento.");
            }
            $v = (int) $valor;
            if ($v < $this->min || $v > $this->max) {
                throw new InvalidArgumentException("Valor fuera de escala en {$id}: {$v}.");
            }
            $recodificadas[$id] = $this->inversos[$id] ? $this->max + $this->min - $v : $v;
        }

        $subescalas = [];
        foreach ($this->definicion['subescalas'] as $sub) {
            $valores = array_values(array_intersect_key($recodificadas, array_flip($sub['items'])));
            if ($valores !== []) {
                $subescalas[$sub['clave']] = round(array_sum($valores) / count($valores), 2);
            }
        }

        $indices = [];
        foreach ($this->definicion['indices'] ?? [] as $clave => $componentes) {
            $valores = array_values(array_intersect_key($subescalas, array_flip($componentes)));
            if (count($valores) === count($componentes)) {
                $indices[$clave] = round(array_sum($valores) / count($valores), 2);
            }
        }

        return ['subescalas' => $subescalas, 'indices' => $indices];
    }
}
