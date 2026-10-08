<?php

namespace Tests\Unit\Domain;

use App\Domain\Operacion\Diagnostico;
use PHPUnit\Framework\TestCase;

class DiagnosticoTest extends TestCase
{
    private function sano(): array
    {
        return [
            'agente' => true, 'piston' => true, 'cola_mas_antigua_min' => ['default' => null, 'agente' => 2.5],
            'fallidos_ultima_hora' => 0, 'disco_libre' => 0.6, 'gasto_hoy_usd' => 1.2,
        ];
    }

    public function test_sin_problemas_no_hay_alertas(): void
    {
        $this->assertSame([], (new Diagnostico())->problemas($this->sano()));
    }

    public function test_cada_regla_produce_su_alerta(): void
    {
        $m = ['agente' => false, 'piston' => false, 'cola_mas_antigua_min' => ['default' => 12.7, 'agente' => 3.0],
            'fallidos_ultima_hora' => 4, 'disco_libre' => 0.08, 'gasto_hoy_usd' => 5.0];

        $p = (new Diagnostico(minutosCola: 10, discoLibreMinimo: 0.15, gastoDiarioUsd: 5.0))->problemas($m);

        $this->assertSame(['agente', 'piston', 'cola:default', 'fallidos', 'disco', 'gasto'], array_column($p, 'clave'));
        $this->assertStringContainsString('hace 12 minutos', $p[2]['texto']);
        $this->assertStringContainsString('8 %', $p[4]['texto']);
        $this->assertStringContainsString('US$ 5.00', $p[5]['texto']);
    }

    public function test_los_umbrales_se_configuran(): void
    {
        $m = $this->sano();
        $m['cola_mas_antigua_min']['agente'] = 6.0;

        $this->assertSame([], (new Diagnostico(minutosCola: 10))->problemas($m));
        $this->assertSame(['cola:agente'], array_column((new Diagnostico(minutosCola: 5))->problemas($m), 'clave'));
    }
}
