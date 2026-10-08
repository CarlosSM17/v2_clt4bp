<?php

namespace Tests\Unit\Domain;

use App\Domain\Aula\ValidadorManifiesto;
use PHPUnit\Framework\TestCase;

class ValidadorManifiestoTest extends TestCase
{
    private function ejemplo(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../../../../packages/contracts/examples/diseno-curso/clase-recorridos.json'), true);
    }

    public function test_el_ejemplo_se_puede_publicar(): void
    {
        $this->assertSame([], ValidadorManifiesto::problemas($this->ejemplo(), []));
    }

    public function test_huerfanos_variantes_sueltas_y_medios_sin_archivo(): void
    {
        $m = $this->ejemplo();
        $m['tareas'][0]['enunciado_md'] .= "\n\n![Explicación](media:vid-1)";
        $m['procedimental'][0]['tarea_uid'] = 'tc9-t1';
        $m['variantes'][] = ['uid' => 'v', 'elemento_uid' => 'nada', 'grupo_clave' => 'G1', 'cambios' => [], 'diferenciacion' => []];

        $this->assertCount(3, ValidadorManifiesto::problemas($m, []));
        $this->assertSame(['vid-1'], ValidadorManifiesto::mediosCitados($m));
        $this->assertCount(2, ValidadorManifiesto::problemas($m, ['vid-1'])); // con el archivo subido, queda en 2
    }

    public function test_una_ayuda_es_de_una_tarea_o_del_tema_de_su_clase(): void
    {
        $m = $this->ejemplo();
        $tema = [...$m['procedimental'][0], 'uid' => 'tc1-p9', 'titulo' => 'Tarjeta de sintaxis', 'clase_uid' => 'tc1'];
        unset($tema['tarea_uid']);
        $m['procedimental'][] = $tema;
        $this->assertSame([], ValidadorManifiesto::problemas($m, []));

        $m['procedimental'][1]['clase_uid'] = 'tc9';
        $this->assertSame(['La ayuda «Tarjeta de sintaxis» pertenece a una clase que no se publica (tc9).'], ValidadorManifiesto::problemas($m, []));
        $m['procedimental'][1]['tarea_uid'] = 'tc1-t1';  // las dos a la vez tampoco
        $this->assertStringContainsString('exactamente a una', ValidadorManifiesto::problemas($m, [])[0]);
    }
}
