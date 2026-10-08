<?php

namespace Tests\Unit\Domain;

use App\Domain\Diseno\Preseleccion;
use App\Domain\Diseno\ResumenGrupo;
use PHPUnit\Framework\TestCase;

class PreseleccionTest extends TestCase
{
    private function reglas(): array
    {
        // El mismo archivo que carga el seeder: la prueba protege las reglas reales
        return json_decode(file_get_contents(__DIR__.'/../../../../../packages/contracts/catalogo/reglas-preseleccion.json'), true);
    }

    private function perfil(string $nivel, float $metacognicion = 5, float $pares = 4, array $banderas = []): array
    {
        return ['nivel' => $nivel, 'mslq' => ['metacognicion' => $metacognicion, 'aprendizaje_pares' => $pares], 'banderas' => $banderas];
    }

    public function test_resumen_del_grupo(): void
    {
        $r = ResumenGrupo::desdePerfiles([
            $this->perfil('basico', 3.5, banderas: ['baja_autoeficacia']),
            $this->perfil('basico', 4.5),
            $this->perfil('intermedio', 4.0),
            $this->perfil('avanzado', 6.0),
        ]);

        $this->assertSame('basico', $r['nivel_modal']);
        $this->assertSame(4.5, $r['media']['metacognicion']);
        $this->assertSame(0.25, $r['proporcion']['baja_autoeficacia']);
    }

    public function test_grupo_basico_con_tema_de_alta_interactividad(): void
    {
        $hechos = [...ResumenGrupo::desdePerfiles([$this->perfil('basico'), $this->perfil('basico')]), 'interactividad_tema' => 'alta'];
        $r = (new Preseleccion)->evaluar($this->reglas(), $hechos);

        $this->assertSame(['R1', 'R3'], $r['reglas_activadas']);
        $ids = array_column($r['efectos'], 'id');
        $this->assertContains('ejemplo_resuelto', $ids);
        $this->assertContains('informacion_transitoria', $ids);
        // elementos_aislados lo sugieren dos reglas: se lista una vez con ambos fundamentos
        $aislados = $r['efectos'][array_search('elementos_aislados', $ids, true)];
        $this->assertSame(['R1', 'R3'], $aislados['reglas']);
    }

    public function test_grupo_avanzado_con_baja_autorregulacion_y_mucha_ansiedad(): void
    {
        $hechos = ResumenGrupo::desdePerfiles([
            $this->perfil('avanzado', 3.0, banderas: ['alta_ansiedad', 'baja_autorregulacion']),
            $this->perfil('avanzado', 3.5, banderas: ['alta_ansiedad']),
            $this->perfil('intermedio', 4.0),
        ]);
        $r = (new Preseleccion)->evaluar($this->reglas(), $hechos);

        $this->assertSame(['R2', 'R4', 'R5'], $r['reglas_activadas']);
        $this->assertNotContains('ejemplo_resuelto', array_column($r['efectos'], 'id'));
        $this->assertContains('R5', array_column($r['recomendaciones'], 'regla'));
    }

    public function test_sin_dato_la_regla_no_se_activa(): void
    {
        $this->assertFalse(Preseleccion::cumple(['campo' => 'media.aprendizaje_pares', 'op' => '>=', 'valor' => 5], []));
        $this->assertTrue(Preseleccion::cumple(['alguna' => [
            ['campo' => 'x', 'op' => '=', 'valor' => 1],
            ['campo' => 'nivel_modal', 'op' => 'en', 'valor' => ['basico', 'intermedio']],
        ]], ['nivel_modal' => 'intermedio']));
    }
}
