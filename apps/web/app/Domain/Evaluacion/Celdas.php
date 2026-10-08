<?php

namespace App\Domain\Evaluacion;

/** Cómo se escribe cada valor en una exportación (CSV o XLSX), igual en los dos formatos. */
final class Celdas
{
    /**
     * null → vacío; bool → 1/0; arreglo → JSON; fechas → ISO 8601.
     * Un texto que empieza con = + - @ se antepone con ' para que Excel no lo ejecute como fórmula
     * (inyección de CSV); los números negativos no se tocan.
     */
    public static function valor(mixed $v): string|int|float
    {
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? 1 : 0,
            is_int($v), is_float($v) => $v,
            is_array($v) => json_encode($v, JSON_UNESCAPED_UNICODE),
            $v instanceof \DateTimeInterface => $v->format('Y-m-d\TH:i:sP'),
            $v instanceof \BackedEnum => $v->value,
            is_string($v) && $v !== '' && ! is_numeric($v) && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) => "'".$v,
            default => (string) $v,
        };
    }

    /** @param  resource  $archivo */
    public static function escribirCsv($archivo, array $fila): void
    {
        fputcsv($archivo, array_map(self::valor(...), array_values($fila)), ',', '"', '', "\r\n");
    }
}
