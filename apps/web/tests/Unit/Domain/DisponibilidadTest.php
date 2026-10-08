<?php

namespace Tests\Unit\Domain;

use App\Domain\Aula\Disponibilidad;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class DisponibilidadTest extends TestCase
{
    public function test_estados_de_disponibilidad(): void
    {
        $a = ['abre_at' => new DateTimeImmutable('2027-02-01 08:00'), 'cierra_at' => new DateTimeImmutable('2027-02-15 23:59'), 'requiere_anterior' => true];
        $dia = fn (string $f) => new DateTimeImmutable($f);

        $this->assertSame('sin_programar', Disponibilidad::estado(null, $dia('2027-02-02'), true));
        $this->assertSame('programada', Disponibilidad::estado($a, $dia('2027-01-31'), true));
        $this->assertSame('bloqueada', Disponibilidad::estado($a, $dia('2027-02-02'), false));
        $this->assertSame('abierta', Disponibilidad::estado($a, $dia('2027-02-02'), true));
        $this->assertSame('cerrada', Disponibilidad::estado($a, $dia('2027-02-16'), true));
        $this->assertSame('abierta', Disponibilidad::estado([...$a, 'cierra_at' => null], $dia('2030-01-01'), true));
    }

    public function test_la_activacion_del_grupo_gana_a_la_general(): void
    {
        $acts = [
            ['clase_uid' => 'tc1', 'diff_group_id' => null, 'n' => 'general'],
            ['clase_uid' => 'tc1', 'diff_group_id' => 7, 'n' => 'G7'],
            ['clase_uid' => 'tc2', 'diff_group_id' => 7, 'n' => 'otra'],
        ];
        $this->assertSame('G7', Disponibilidad::elegir($acts, 'tc1', 7)['n']);
        $this->assertSame('general', Disponibilidad::elegir($acts, 'tc1', 8)['n']);
        $this->assertSame('general', Disponibilidad::elegir($acts, 'tc1', null)['n']);
        $this->assertNull(Disponibilidad::elegir($acts, 'tc2', 8));
    }
}
