<?php

namespace Tests\Unit\Domain;

use App\Domain\Pruebas\CalificadorItems;
use App\Domain\Pruebas\ComparadorSalida;
use PHPUnit\Framework\TestCase;

class CalificadorItemsTest extends TestCase
{
    public function test_opcion_multiple(): void
    {
        $c = new CalificadorItems;
        $this->assertSame(1.0, $c->fraccion('opcion_multiple', ['correcta' => 'b'], 'b'));
        $this->assertSame(0.0, $c->fraccion('opcion_multiple', ['correcta' => 'b'], 'c'));
    }

    public function test_respuesta_corta_ignora_mayusculas_y_espacios(): void
    {
        $c = new CalificadorItems;
        $this->assertSame(1.0, $c->fraccion('respuesta_corta', ['aceptadas' => ['int main']], '  INT   Main '));
    }

    public function test_prediccion_de_salida_tolera_espacios_finales(): void
    {
        $c = new CalificadorItems;
        $this->assertSame(1.0, $c->fraccion('prediccion_salida', ['salida' => "1\n2\n"], "1  \r\n2"));
    }

    public function test_parsons_da_credito_parcial(): void
    {
        $c = new CalificadorItems;
        $correcto = ['l1', 'l2', 'l3', 'l4'];
        $this->assertSame(1.0, $c->fraccion('parsons', ['orden' => $correcto], $correcto));
        $this->assertSame(0.75, $c->fraccion('parsons', ['orden' => $correcto], ['l1', 'l3', 'l2', 'l4']));
    }

    public function test_comparador_de_salidas(): void
    {
        $this->assertTrue(ComparadorSalida::iguales("9.00\n", '9.00'));
        $this->assertFalse(ComparadorSalida::iguales('9.0', '9.00'));
    }
}
