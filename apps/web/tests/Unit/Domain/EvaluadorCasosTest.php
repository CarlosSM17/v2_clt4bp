<?php

namespace Tests\Unit\Domain;

use App\Services\Codigo\EjecutorCodigo;
use App\Services\Codigo\EvaluadorCasos;
use App\Services\Codigo\ResultadoEjecucion;
use PHPUnit\Framework\TestCase;

class EvaluadorCasosTest extends TestCase
{
    /** Ejecutor falso: "ejecuta" un programa que suma los números de la entrada. */
    private function sumador(): EjecutorCodigo
    {
        return new class implements EjecutorCodigo
        {
            public function ejecutar(string $lenguaje, string $codigo, string $entrada = '', int $limiteMs = 3000): ResultadoEjecucion
            {
                if (str_contains($codigo, 'ERROR')) {
                    return new ResultadoEjecucion(false, '', "main.c:1: error: expected ';'", 1, false);
                }
                $suma = array_sum(array_map('intval', preg_split('/\s+/', trim($entrada))));

                return new ResultadoEjecucion(true, $suma."\n", '', 0, false);
            }
        };
    }

    public function test_cuenta_casos_aprobados_y_oculta_los_ocultos(): void
    {
        $r = (new EvaluadorCasos($this->sumador()))->evaluar('c', 'programa', [
            ['entrada' => '2 3', 'salida_esperada' => '5', 'oculto' => false],
            ['entrada' => '10 1', 'salida_esperada' => '11', 'oculto' => true],
            ['entrada' => '1 1', 'salida_esperada' => '3', 'oculto' => false],
        ]);

        $this->assertSame(2, $r['aprobados']);
        $this->assertSame(0.6667, $r['fraccion']);
        $this->assertNull($r['casos'][1]['entrada']);
    }

    public function test_error_de_compilacion_da_cero(): void
    {
        $r = (new EvaluadorCasos($this->sumador()))->evaluar('c', 'ERROR', [
            ['entrada' => '1', 'salida_esperada' => '1', 'oculto' => false],
        ]);

        $this->assertSame(0.0, $r['fraccion']);
        $this->assertTrue(str_contains($r['error_compilacion'], 'expected'));
    }

    public function test_interpreta_la_respuesta_de_piston(): void
    {
        $ok = ResultadoEjecucion::desdePiston([
            'compile' => ['stdout' => '', 'stderr' => '', 'code' => 0, 'signal' => null],
            'run' => ['stdout' => "9.00\n", 'stderr' => '', 'code' => 0, 'signal' => null],
        ]);
        $this->assertTrue($ok->compilo);
        $this->assertSame("9.00\n", $ok->salida);

        $muerto = ResultadoEjecucion::desdePiston(['run' => ['stdout' => '', 'stderr' => '', 'code' => null, 'signal' => 'SIGKILL']]);
        $this->assertTrue($muerto->excedioLimite);
        $this->assertSame(-1, $muerto->codigo);

        $noCompila = ResultadoEjecucion::desdePiston(['compile' => ['stdout' => '', 'stderr' => 'error: x', 'code' => 1], 'run' => []]);
        $this->assertFalse($noCompila->compilo);
    }
}
