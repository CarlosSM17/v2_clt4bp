<?php

namespace Tests\Unit\Domain;

use App\Domain\Perfil\Agrupador;
use PHPUnit\Framework\TestCase;

class AgrupadorTest extends TestCase
{
    public function test_por_nivel_nombra_de_menor_a_mayor(): void
    {
        $grupos = (new Agrupador)->porNivel([
            'E-1' => ['cp' => 20, 'nivel' => 'basico'],
            'E-2' => ['cp' => 85, 'nivel' => 'avanzado'],
            'E-3' => ['cp' => 30, 'nivel' => 'basico'],
        ]);

        $this->assertCount(2, $grupos);
        $this->assertSame('Ruta A', $grupos[0]['nombre']);
        $this->assertSame(['E-1', 'E-3'], $grupos[0]['miembros']);
        $this->assertSame('avanzado', $grupos[1]['nivel']);
    }

    public function test_kmeans_separa_dos_grupos_claros(): void
    {
        $datos = [];
        foreach (range(1, 6) as $i) {
            $datos["B{$i}"] = [20 + $i, 25 + $i, 3.0, 3.2];
            $datos["A{$i}"] = [80 + $i, 85 + $i, 5.5, 5.8];
        }
        $r = (new Agrupador)->kmeans($datos);

        $this->assertCount(2, $r['grupos']);
        $this->assertEqualsCanonicalizing(['B1', 'B2', 'B3', 'B4', 'B5', 'B6'], $r['grupos'][0]['miembros']);
        $this->assertTrue($r['silueta'] > 0.7);
    }

    public function test_kmeans_es_reproducible(): void
    {
        $datos = [];
        foreach (range(1, 8) as $i) {
            $datos["E{$i}"] = [$i * 10.0, $i * 9.0, 4.0 + $i / 10, 4.5];
        }

        $this->assertSame((new Agrupador)->kmeans($datos, 7), (new Agrupador)->kmeans($datos, 7));
    }
}
