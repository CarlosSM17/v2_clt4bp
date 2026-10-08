<?php

namespace App\Domain\Diseno;

use InvalidArgumentException;

/** Los tipos de elemento del diseño 4C/ID, su esquema en contracts y cómo se ubican en el árbol. */
final class TiposElemento
{
    public const ESQUEMAS = [
        'objetivo' => 'objetivo.schema.json',
        'clase' => 'clase-tareas.schema.json',
        'tarea' => 'tarea.schema.json',
        'soporte' => 'info-soporte.schema.json',
        'procedimental' => 'info-procedimental.schema.json',
        'practica_parcial' => 'practica-parcial.schema.json',
        'variante' => 'variante.schema.json',
        'medio' => 'medio.schema.json',
    ];

    /** Campo del contenido que apunta al elemento padre (null = cuelga del curso). */
    private const CAMPO_PADRE = [
        'tarea' => 'clase_uid',
        'soporte' => 'clase_uid',
        'procedimental' => 'tarea_uid',
        'variante' => 'elemento_uid',
    ];

    public static function esquema(string $tipo): string
    {
        return self::ESQUEMAS[$tipo] ?? throw new InvalidArgumentException("Tipo desconocido: {$tipo}");
    }

    /** El padre se deduce del contenido; nunca se confía en lo que diga el cliente aparte. */
    public static function padre(string $tipo, object $contenido): ?string
    {
        // Una ayuda es de una tarea o, si es del tema (tarjeta de sintaxis, errores frecuentes…), de su clase
        if ($tipo === 'procedimental' && empty($contenido->tarea_uid)) {
            return $contenido->clase_uid ?? null;
        }
        $campo = self::CAMPO_PADRE[$tipo] ?? null;

        return $campo ? ($contenido->{$campo} ?? null) : null;
    }

    public static function orden(object $contenido): int
    {
        return (int) ($contenido->orden ?? 1);
    }
}
