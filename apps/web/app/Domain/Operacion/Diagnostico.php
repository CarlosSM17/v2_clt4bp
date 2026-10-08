<?php

namespace App\Domain\Operacion;

/**
 * Reglas de la revisión automática de operación (7.5). Recibe mediciones ya tomadas y devuelve
 * los problemas encontrados; no toca la red ni la base de datos, así que se prueba sola.
 */
final class Diagnostico
{
    public function __construct(
        private readonly int $minutosCola = 10,
        private readonly float $discoLibreMinimo = 0.15,
        private readonly float $gastoDiarioUsd = 5.0,
    ) {}

    /**
     * @param  array{agente: bool, piston: bool, cola_mas_antigua_min: array<string, ?float>,
     *               fallidos_ultima_hora: int, disco_libre: float, gasto_hoy_usd: float}  $m
     * @return list<array{clave: string, texto: string}>
     */
    public function problemas(array $m): array
    {
        $p = [];
        if (! $m['agente']) {
            $p[] = ['clave' => 'agente', 'texto' => 'El servicio del agente no responde (systemctl status clt4bp-agente).'];
        }
        if (! $m['piston']) {
            $p[] = ['clave' => 'piston', 'texto' => 'Piston no responde: los estudiantes no pueden ejecutar ni enviar código.'];
        }
        foreach ($m['cola_mas_antigua_min'] as $cola => $minutos) {
            if ($minutos !== null && $minutos >= $this->minutosCola) {
                $p[] = ['clave' => "cola:{$cola}", 'texto' => sprintf(
                    'La cola «%s» tiene trabajos esperando desde hace %d minutos: ¿están corriendo los trabajadores?', $cola, (int) $minutos)];
            }
        }
        if ($m['fallidos_ultima_hora'] > 0) {
            $p[] = ['clave' => 'fallidos', 'texto' => "{$m['fallidos_ultima_hora']} trabajos fallaron en la última hora (artisan queue:failed)."];
        }
        if ($m['disco_libre'] < $this->discoLibreMinimo) {
            $p[] = ['clave' => 'disco', 'texto' => sprintf('Queda %d %% de disco libre.', (int) round(100 * $m['disco_libre']))];
        }
        if ($m['gasto_hoy_usd'] >= $this->gastoDiarioUsd) {
            $p[] = ['clave' => 'gasto', 'texto' => sprintf('El agente lleva US$ %.2f hoy (umbral: US$ %.2f).', $m['gasto_hoy_usd'], $this->gastoDiarioUsd)];
        }

        return $p;
    }
}
