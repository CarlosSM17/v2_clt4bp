<?php

namespace Tests\Unit\Domain;

use App\Domain\Aula\AplicadorVariantes;
use PHPUnit\Framework\TestCase;

/** Cada grupo ve solo las tareas de su ruta (mapa de ruta CLT4BP, ADR 0007). */
class RutasTareasTest extends TestCase
{
    private function manifiesto(): array
    {
        return [
            'clases' => [['uid' => 'tc1']],
            'tareas' => [
                ['uid' => 't1', 'rutas' => ['G1']],          // ejemplo resuelto: Ruta A
                ['uid' => 't3', 'rutas' => []],              // todas
                ['uid' => 't6', 'rutas' => ['G2', 'G3']],    // autoexplicación: Ruta B
                ['uid' => 't8'],                             // sin rutas (diseños anteriores): todas
            ],
            'procedimental' => [
                ['uid' => 'p1', 'tarea_uid' => 't1'],
                ['uid' => 'p6', 'tarea_uid' => 't6'],
                ['uid' => 'tema', 'clase_uid' => 'tc1'],     // del tema: la ven todos
            ],
            'soporte' => [], 'objetivos' => [], 'practica_parcial' => [], 'variantes' => [],
        ];
    }

    public function test_cada_grupo_ve_las_tareas_de_su_ruta_y_sus_ayudas(): void
    {
        $a = AplicadorVariantes::paraGrupo($this->manifiesto(), 'G1');
        $this->assertSame(['t1', 't3', 't8'], array_column($a['tareas'], 'uid'));
        $this->assertSame(['p1', 'tema'], array_column($a['procedimental'], 'uid'));

        $b = AplicadorVariantes::paraGrupo($this->manifiesto(), 'G3');
        $this->assertSame(['t3', 't6', 't8'], array_column($b['tareas'], 'uid'));
        $this->assertSame(['p6', 'tema'], array_column($b['procedimental'], 'uid'));
    }

    public function test_sin_grupo_se_ve_todo(): void
    {
        $todo = AplicadorVariantes::paraGrupo($this->manifiesto(), null);
        $this->assertCount(4, $todo['tareas']);
        $this->assertCount(3, $todo['procedimental']);
    }
}
