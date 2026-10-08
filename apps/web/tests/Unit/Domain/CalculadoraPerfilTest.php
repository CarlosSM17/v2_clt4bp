<?php

namespace Tests\Unit\Domain;

use App\Domain\Perfil\CalculadoraPerfil;
use App\Domain\Perfil\ConfiguracionPerfil;
use PHPUnit\Framework\TestCase;

class CalculadoraPerfilTest extends TestCase
{
    private function calc(): CalculadoraPerfil
    {
        return new CalculadoraPerfil(new ConfiguracionPerfil);
    }

    public function test_umbrales_de_nivel(): void
    {
        $c = $this->calc();
        $this->assertSame('basico', $c->nivel(39.99));
        $this->assertSame('intermedio', $c->nivel(40));
        $this->assertSame('intermedio', $c->nivel(70));
        $this->assertSame('avanzado', $c->nivel(70.01));
    }

    public function test_combina_teoria_y_practica_con_sus_pesos(): void
    {
        $p = $this->calc()->calcular([], recall: 60, comprension: 44, teorico: 52, practico: 35);

        $this->assertSame(43.5, $p['cp_global']);
        $this->assertSame('intermedio', $p['nivel']);
    }

    public function test_pesos_configurables(): void
    {
        $c = new CalculadoraPerfil(ConfiguracionPerfil::desde(['perfil' => ['peso_teorico' => 0.3, 'peso_practico' => 0.7]]));
        $this->assertSame(80.0, $c->calcular([], 100, 100, 100, 71.4286)['cp_global']);
    }

    public function test_banderas_e_indices(): void
    {
        $mslq = [
            'autoeficacia' => 3.6, 'valor_tarea' => 5.2, 'intrinseca' => 4.0,
            'ansiedad' => 5.4, 'metacognicion' => 3.9,
        ];
        $p = $this->calc()->calcular($mslq, 50, 50, 50, 50);

        $this->assertSame(['baja_autoeficacia', 'alta_ansiedad', 'baja_autorregulacion'], $p['banderas']);
        $this->assertSame(4.27, $p['indices']['motivacion']);                 // (3.6 + 5.2 + 4.0) / 3
        $this->assertFalse(isset($p['indices']['autorregulacion']));         // faltan subescalas
    }
}
