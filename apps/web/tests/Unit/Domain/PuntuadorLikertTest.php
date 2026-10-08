<?php

namespace Tests\Unit\Domain;

use App\Domain\Instrumentos\PuntuadorLikert;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PuntuadorLikertTest extends TestCase
{
    private function definicion(): array
    {
        return [
            'escala' => ['min' => 1, 'max' => 7],
            'items' => [
                ['id' => 'a1', 'inverso' => false], ['id' => 'a2', 'inverso' => true],
                ['id' => 'b1', 'inverso' => false], ['id' => 'b2', 'inverso' => false],
            ],
            'subescalas' => [
                ['clave' => 'a', 'items' => ['a1', 'a2']],
                ['clave' => 'b', 'items' => ['b1', 'b2']],
            ],
            'indices' => ['total' => ['a', 'b']],
        ];
    }

    public function test_recodifica_inversos_y_promedia(): void
    {
        $r = (new PuntuadorLikert($this->definicion()))->puntuar(['a1' => 6, 'a2' => 2, 'b1' => 3, 'b2' => 4]);

        // a2 inverso: 8 - 2 = 6 → a = (6 + 6) / 2
        $this->assertSame(6.0, $r['subescalas']['a']);
        $this->assertSame(3.5, $r['subescalas']['b']);
        $this->assertSame(4.75, $r['indices']['total']);
    }

    public function test_informa_items_faltantes(): void
    {
        $p = new PuntuadorLikert($this->definicion());
        $this->assertSame(['b1', 'b2'], $p->faltantes(['a1' => 1, 'a2' => 1]));
    }

    public function test_rechaza_valores_fuera_de_escala(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PuntuadorLikert($this->definicion()))->puntuar(['a1' => 9]);
    }

    public function test_rechaza_items_que_no_existen(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PuntuadorLikert($this->definicion()))->puntuar(['zz' => 3]);
    }

    public function test_la_estructura_del_mslq_es_valida(): void
    {
        $def = json_decode(file_get_contents(dirname(__DIR__, 5).'/instruments/mslq.json'), true);
        $ids = array_column($def['items'], 'id');
        $enSubescalas = array_merge(...array_column($def['subescalas'], 'items'));

        $this->assertCount(73, $ids);                                 // 31 de motivación + 42 de estrategias
        $this->assertCount(13, $def['subescalas']);
        $this->assertEqualsCanonicalizing($ids, $enSubescalas);       // cada ítem en una sola subescala
        $this->assertCount(0, array_filter($def['items'], fn ($i) => $i['inverso']));
        $this->assertSame(['min' => 1, 'max' => 7], ['min' => $def['escala']['min'], 'max' => $def['escala']['max']]);
    }

    public function test_los_indices_del_mslq_se_calculan_con_todas_las_respuestas(): void
    {
        $def = json_decode(file_get_contents(dirname(__DIR__, 5).'/instruments/mslq.json'), true);
        $p = new PuntuadorLikert($def);
        $respuestas = array_fill_keys($p->idsDeItems(), 5);

        $r = $p->puntuar($respuestas);

        $this->assertSame([], $p->faltantes($respuestas));
        $this->assertCount(13, $r['subescalas']);
        $this->assertSame(5.0, $r['indices']['motivacion']);
        $this->assertSame(5.0, $r['indices']['autorregulacion']);
    }

    public function test_los_instrumentos_cs_y_paas_son_cargables(): void
    {
        foreach (['cs' => [10, 0, 10], 'paas' => [1, 1, 9]] as $clave => [$n, $min, $max]) {
            $def = json_decode(file_get_contents(dirname(__DIR__, 5)."/instruments/{$clave}.json"), true);
            $this->assertSame($clave, $def['clave']);
            $this->assertCount($n, $def['items']);
            $this->assertSame([$min, $max], [$def['escala']['min'], $def['escala']['max']]);
        }
    }
}
