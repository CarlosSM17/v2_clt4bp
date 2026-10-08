<?php

namespace App\Domain\Evaluacion;

use RuntimeException;
use ZipArchive;

/**
 * Escritor mínimo de XLSX (Office Open XML) con una hoja por tabla. Escribe cada hoja en un archivo temporal
 * fila por fila, así que no carga la exportación completa en memoria. Solo usa ZipArchive (extensión zip de PHP).
 */
final class LibroXlsx
{
    public const MAX_FILAS = 1_048_576; // límite de filas por hoja de Excel, encabezado incluido

    /** @var list<array{nombre: string, ruta: string}> */
    private array $hojas = [];

    /**
     * @param  list<string>  $columnas
     * @param  iterable<array>  $filas
     */
    public function agregarHoja(string $nombre, array $columnas, iterable $filas): void
    {
        $nombre = mb_substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], '_', $nombre), 0, 31);

        $ruta = tempnam(sys_get_temp_dir(), 'hoja');
        $f = fopen($ruta, 'w');
        fwrite($f, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
        fwrite($f, $this->fila(1, $columnas));
        $n = 1;
        foreach ($filas as $fila) {
            if ($n >= self::MAX_FILAS) {
                break; // Excel no abriría la hoja: el resto está completo en la exportación CSV
            }
            fwrite($f, $this->fila(++$n, array_values($fila)));
        }
        fwrite($f, '</sheetData></worksheet>');
        fclose($f);
        $this->hojas[] = ['nombre' => $nombre, 'ruta' => $ruta];
    }

    /** Arma el .xlsx en $destino y borra los temporales. */
    public function guardar(string $destino): void
    {
        $zip = new ZipArchive();
        if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("No se pudo crear {$destino}");
        }
        $hojas = '';
        $relaciones = '';
        $tipos = '';
        foreach ($this->hojas as $i => $h) {
            $n = $i + 1;
            $zip->addFile($h['ruta'], "xl/worksheets/sheet{$n}.xml");
            $hojas .= '<sheet name="'.$this->xml($h['nombre']).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $relaciones .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $tipos .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $cabecera = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";
        $zip->addFromString('[Content_Types].xml', $cabecera
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$tipos.'</Types>');
        $zip->addFromString('_rels/.rels', $cabecera
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', $cabecera
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$hojas.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $cabecera
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relaciones.'</Relationships>');
        $zip->close();

        foreach ($this->hojas as $h) {
            @unlink($h['ruta']);
        }
        $this->hojas = [];
    }

    private function fila(int $numero, array $valores): string
    {
        $celdas = '';
        foreach ($valores as $v) {
            $v = Celdas::valor($v);
            $celdas .= is_int($v) || is_float($v)
                ? '<c t="n"><v>'.$v.'</v></c>'
                : '<c t="inlineStr"><is><t xml:space="preserve">'.$this->xml($v).'</t></is></c>';
        }

        return '<row r="'.$numero.'">'.$celdas.'</row>';
    }

    private function xml(string $texto): string
    {
        // Quita los caracteres de control que XML 1.0 no admite (p. ej., en código pegado)
        $limpio = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $texto) ?? '';

        return htmlspecialchars($limpio, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
