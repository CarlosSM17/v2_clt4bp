<?php

namespace App\Domain\Evaluacion;

/**
 * Cuánto cambió el instructor un elemento que propuso el agente (métrica de uso de la sección 12.2):
 * distancia de edición entre el texto propuesto y el actual, dividida entre la longitud mayor.
 */
final class ProporcionEditada
{
    private const IGNORAR = ['uid', 'clase_uid', 'tarea_uid', 'elemento_uid', 'orden', 'diseno'];

    private const LIMITE = 4000; // bytes por texto: la distancia de edición crece con el producto de longitudes

    /** 0 = aceptado tal cual; 1 = reescrito por completo. */
    public static function entre(string $propuesto, string $final): float
    {
        if ($propuesto === $final) {
            return 0.0;
        }
        $a = substr($propuesto, 0, self::LIMITE);
        $b = substr($final, 0, self::LIMITE);
        $mayor = max(strlen($a), strlen($b));

        return $mayor === 0 ? 0.0 : round(min(1.0, levenshtein($a, $b) / $mayor), 4);
    }

    /** Texto comparable de un elemento: sus valores de texto en orden, sin identificadores ni metadatos. */
    public static function texto(array $contenido): string
    {
        $partes = [];
        $contenido = array_diff_key($contenido, array_flip(self::IGNORAR)); // quita también el bloque «diseno» completo
        array_walk_recursive($contenido, function ($v, $k) use (&$partes) {
            if (! in_array($k, self::IGNORAR, true) && (is_string($v) || is_numeric($v) || is_bool($v))) {
                $partes[] = is_bool($v) ? ($v ? 'sí' : 'no') : (string) $v;
            }
        });

        return implode("\n", $partes);
    }
}
