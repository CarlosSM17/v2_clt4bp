<?php

namespace Tests\Unit\Domain;

use App\Domain\Aula\AplicadorVariantes;
use App\Domain\Aula\VistaEstudiante;
use PHPUnit\Framework\TestCase;

class VistaEstudianteTest extends TestCase
{
    private function ejemplo(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../../../../packages/contracts/examples/diseno-curso/clase-recorridos.json'), true);
    }

    public function test_aplicar_variante_respeta_campos_fijos_y_mezcla_diseno(): void
    {
        $base = ['uid' => 't1', 'orden' => 1, 'solucion' => 'x', 'titulo' => 'A', 'diseno' => ['interactividad' => 'baja', 'efectos' => []]];
        $r = AplicadorVariantes::aplicar($base, ['uid' => 'otro', 'orden' => 9, 'solucion' => 'y', 'titulo' => 'B', 'diseno' => ['interactividad' => 'alta']]);

        $this->assertSame(['uid' => 't1', 'orden' => 1, 'solucion' => 'x', 'titulo' => 'B', 'diseno' => ['interactividad' => 'alta', 'efectos' => []]], $r);
        $this->assertSame($base, AplicadorVariantes::aplicar($base, null));
    }

    public function test_cada_grupo_ve_solo_su_variante(): void
    {
        $m = $this->ejemplo();
        $m['variantes'] = [[
            'uid' => 'tc1-t3-G1', 'elemento_uid' => 'tc1-t3', 'grupo_clave' => 'G1',
            'cambios' => ['nivel_apoyo' => 'por_completar'], 'diferenciacion' => ['dimensiones' => ['proceso'], 'razon' => 'x'],
        ]];

        $g1 = AplicadorVariantes::paraGrupo($m, 'G1');
        $this->assertSame('por_completar', $g1['tareas'][2]['nivel_apoyo']);
        $this->assertSame('convencional', AplicadorVariantes::paraGrupo($m, 'G2')['tareas'][2]['nivel_apoyo']);
        $this->assertSame('convencional', AplicadorVariantes::paraGrupo($m, null)['tareas'][2]['nivel_apoyo']);
        $this->assertArrayNotHasKey('variantes', $g1);
    }

    public function test_el_estudiante_no_recibe_soluciones_ni_casos_ocultos(): void
    {
        $t = $this->ejemplo()['tareas'][2];
        $t['casos_prueba'][1]['oculto'] = true;
        $v = VistaEstudiante::tarea($t);

        $this->assertArrayNotHasKey('solucion', $v);
        $this->assertArrayNotHasKey('diseno', $v);
        $this->assertCount(1, $v['ejemplos']);
        $this->assertSame(1, $v['casos_ocultos']);
        $this->assertSame('Convencional', $v['etiqueta_apoyo']);
        $this->assertStringNotContainsString($t['solucion'], json_encode($v));
    }

    public function test_el_papel_de_cada_tarea_como_en_el_mapa_de_ruta(): void
    {
        $dado = [...$this->ejemplo()['tareas'][0], 'orden' => 6, 'pide_autoexplicacion' => true, 'nivel_apoyo' => 'ejemplo_resuelto'];

        $this->assertSame('Autoexplicación', VistaEstudiante::tarea([...$dado, 'titulo' => 'Autoexplicación: Conversión de tipo'])['papel']);
        $this->assertSame('Imaginación', VistaEstudiante::tarea([...$dado, 'titulo' => 'Imaginación: Variables y operaciones'])['papel']);
        // El primer ejemplo resuelto también puede pedir autoexplicación: es el ejemplo, sin papel aparte
        $this->assertNull(VistaEstudiante::tarea([...$dado, 'orden' => 1, 'titulo' => 'Registro de paciente'])['papel']);
        // La solución libre la dice su etiqueta de apoyo: sin papel aparte (no se repite el chip)
        $libre = VistaEstudiante::tarea([...$dado, 'nivel_apoyo' => 'solucion_libre', 'pide_autoexplicacion' => false]);
        $this->assertSame(['Solución libre', null], [$libre['etiqueta_apoyo'], $libre['papel']]);
    }
}
