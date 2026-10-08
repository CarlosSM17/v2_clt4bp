<?php

namespace Tests\Unit\Domain;

use App\Domain\Diseno\ReglaSincronizacion as R;
use PHPUnit\Framework\TestCase;

class ReglaSincronizacionTest extends TestCase
{
    private function actual(int $version, array $contenido = ['a' => 1], bool $eliminado = false): array
    {
        return ['version' => $version, 'contenido' => $contenido, 'eliminado' => $eliminado];
    }

    public function test_crear_un_elemento_nuevo(): void
    {
        $this->assertSame(R::APLICAR, R::decidir(null, 0, ['a' => 1], false));
    }

    public function test_misma_version_se_aplica(): void
    {
        $this->assertSame(R::APLICAR, R::decidir($this->actual(3), 3, ['a' => 2], false));
    }

    public function test_version_vieja_con_contenido_distinto_es_conflicto(): void
    {
        $this->assertSame(R::CONFLICTO, R::decidir($this->actual(4, ['a' => 9]), 3, ['a' => 2], false));
    }

    public function test_reintento_con_el_mismo_contenido_no_es_conflicto(): void
    {
        // El servidor ya aplicó el cambio (versión 4), pero la respuesta nunca llegó a la consola
        $servidor = $this->actual(4, ['b' => ['y' => 2, 'x' => 1], 'a' => 1]);
        $this->assertSame(R::YA_APLICADO, R::decidir($servidor, 3, ['a' => 1, 'b' => ['x' => 1, 'y' => 2]], false));
    }

    public function test_eliminar_algo_ya_eliminado(): void
    {
        $this->assertSame(R::YA_APLICADO, R::decidir($this->actual(5, eliminado: true), 4, null, true));
        $this->assertSame(R::YA_APLICADO, R::decidir(null, 0, null, true));
    }

    public function test_eliminar_algo_que_otro_modifico_es_conflicto(): void
    {
        $this->assertSame(R::CONFLICTO, R::decidir($this->actual(5), 4, null, true));
    }

    public function test_el_orden_de_las_listas_si_importa(): void
    {
        $this->assertNotSame(R::canonico(['l' => [1, 2]]), R::canonico(['l' => [2, 1]]));
        $this->assertSame(R::canonico((object) ['b' => 1, 'a' => 2]), R::canonico(['a' => 2, 'b' => 1]));
    }
}
