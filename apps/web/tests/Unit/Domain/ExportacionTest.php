<?php

namespace Tests\Unit\Domain;

use App\Domain\Evaluacion\Celdas;
use App\Domain\Evaluacion\DiccionarioDatos;
use App\Domain\Evaluacion\LibroXlsx;
use App\Domain\Evaluacion\ProporcionEditada;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ExportacionTest extends TestCase
{
    public function test_valores_de_celda_e_inyeccion_de_formulas(): void
    {
        $this->assertSame('', Celdas::valor(null));
        $this->assertSame(1, Celdas::valor(true));
        $this->assertSame(-3.5, Celdas::valor(-3.5));
        $this->assertSame('-3.5', Celdas::valor('-3.5')); // número en texto: se deja
        $this->assertSame("'=HYPERLINK(\"x\")", Celdas::valor('=HYPERLINK("x")'));
        $this->assertSame('{"a":"ñ"}', Celdas::valor(['a' => 'ñ']));
        $this->assertSame('2026-10-20T10:00:00+00:00', Celdas::valor(new \DateTimeImmutable('2026-10-20 10:00', new \DateTimeZone('UTC'))));
    }

    public function test_csv_con_comillas_y_saltos_de_linea(): void
    {
        $f = fopen('php://memory', 'w+');
        Celdas::escribirCsv($f, ['E-ABC123', "printf(\"hola\");\nreturn 0;", 0.75]);
        rewind($f);
        $this->assertSame("E-ABC123,\"printf(\"\"hola\"\");\nreturn 0;\",0.75\r\n", stream_get_contents($f));
    }

    public function test_libro_xlsx_con_dos_hojas(): void
    {
        $libro = new LibroXlsx();
        $libro->agregarHoja('pruebas', ['seudonimo', 'porcentaje'], [['E-1', 80.5], ['E-2', null]]);
        $libro->agregarHoja('envios', ['codigo'], [["int x = 1; // <&>\x01"]]);
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        $libro->guardar($ruta);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($ruta) === true);
        $this->assertStringContainsString('<sheet name="envios" sheetId="2" r:id="rId2"/>', $zip->getFromName('xl/workbook.xml'));
        $hoja1 = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('<c t="n"><v>80.5</v></c>', $hoja1);
        $this->assertStringContainsString('int x = 1; // &lt;&amp;&gt;', $zip->getFromName('xl/worksheets/sheet2.xml'));
        $this->assertStringNotContainsString("\x01", $zip->getFromName('xl/worksheets/sheet2.xml'));
        $zip->close();
        unlink($ruta);
    }

    public function test_diccionario_sin_huecos(): void
    {
        foreach (DiccionarioDatos::TABLAS as $tabla => $t) {
            $this->assertNotEmpty($t['descripcion'], $tabla);
            if ($tabla !== 'agente') { // la única tabla sin estudiantes
                $this->assertSame('seudonimo', DiccionarioDatos::columnas($tabla)[0], $tabla);
            }
            foreach ($t['columnas'] as $columna => $descripcion) {
                $this->assertNotEmpty($descripcion, "{$tabla}.{$columna}");
            }
        }
        $this->assertCount(count(DiccionarioDatos::TABLAS) + array_sum(array_map(fn ($t) => count($t['columnas']), DiccionarioDatos::TABLAS)),
            iterator_to_array(DiccionarioDatos::filas(), false));
    }

    public function test_proporcion_editada(): void
    {
        $propuesto = ['uid' => 'j4-t1', 'titulo' => 'Promedio', 'enunciado_md' => 'Lee n datos', 'diseno' => ['paso_clt4bp' => 5]];
        $igual = ['uid' => 'j4-t1', 'titulo' => 'Promedio', 'enunciado_md' => 'Lee n datos', 'diseno' => ['paso_clt4bp' => 7]];

        $this->assertSame(0.0, ProporcionEditada::entre(ProporcionEditada::texto($propuesto), ProporcionEditada::texto($igual)));
        $this->assertSame(1.0, ProporcionEditada::entre('abc', 'xyz'));
        $this->assertEqualsWithDelta(0.25, ProporcionEditada::entre('abcd', 'abce'), 0.0001);
    }
}
