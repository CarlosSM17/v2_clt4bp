<?php

namespace Tests\Unit\Domain;

use App\Domain\Perfil\AnalizadorGrupo;
use App\Domain\Perfil\ConfiguracionPerfil;
use PHPUnit\Framework\TestCase;

class AnalizadorGrupoTest extends TestCase
{
    private function perfiles(array $cps): array
    {
        return array_map(fn ($cp) => ['cp' => (float) $cp, 'nivel' => match (true) {
            $cp > 70 => 'avanzado', $cp >= 40 => 'intermedio', default => 'basico',
        }], $cps);
    }

    public function test_coeficiente_de_variacion(): void
    {
        $r = (new AnalizadorGrupo(new ConfiguracionPerfil))->analizar($this->perfiles([40, 50, 60]));

        $this->assertSame(50.0, $r['media']);
        $this->assertSame(10.0, $r['desviacion']);
        $this->assertSame(0.2, $r['cv']);
    }

    public function test_grupo_homogeneo(): void
    {
        $r = (new AnalizadorGrupo(new ConfiguracionPerfil))->analizar($this->perfiles([50, 52, 55, 58, 60, 48, 51, 57, 54, 53]));

        $this->assertSame('homogeneo', $r['recomendacion']);
        $this->assertSame('intermedio', $r['nivel_modal']);
        $this->assertTrue($r['confiable']);
    }

    public function test_grupo_heterogeneo_y_poco_confiable(): void
    {
        $r = (new AnalizadorGrupo(new ConfiguracionPerfil))->analizar($this->perfiles([15, 30, 55, 80, 90]));

        $this->assertSame('heterogeneo', $r['recomendacion']);
        $this->assertFalse($r['confiable']);   // menos de 10 estudiantes
        $this->assertSame(1, $r['histograma']['90-100']);
    }

    public function test_sin_perfiles(): void
    {
        $r = (new AnalizadorGrupo(new ConfiguracionPerfil))->analizar([]);

        $this->assertSame(0, $r['n']);
        $this->assertNull($r['cv']);
        $this->assertNull($r['nivel_modal']);
        $this->assertSame('heterogeneo', $r['recomendacion']);
    }
}
