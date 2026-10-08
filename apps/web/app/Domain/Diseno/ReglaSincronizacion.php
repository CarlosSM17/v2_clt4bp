<?php

namespace App\Domain\Diseno;

/**
 * Control optimista de versiones para un cambio que llega de la consola.
 * El cliente dice sobre qué versión trabajó (base); el servidor compara con la suya.
 */
final class ReglaSincronizacion
{
    public const APLICAR = 'aplicar';

    public const YA_APLICADO = 'ya_aplicado';

    public const CONFLICTO = 'conflicto';

    /**
     * @param  array{version: int, contenido: mixed, eliminado: bool}|null  $actual  estado en el servidor (null = no existe)
     * @param  mixed  $nuevo  contenido que envía la consola (null si elimina)
     */
    public static function decidir(?array $actual, int $baseVersion, mixed $nuevo, bool $eliminar): string
    {
        if ($actual === null) {
            return $eliminar ? self::YA_APLICADO : self::APLICAR;
        }
        if ($actual['version'] === $baseVersion) {
            return self::APLICAR;
        }
        // Versión distinta: puede ser un reintento de un cambio que sí llegó (se perdió la respuesta)
        // o que otro equipo hizo exactamente el mismo cambio. En ambos casos no hay nada que resolver.
        if ($eliminar && $actual['eliminado']) {
            return self::YA_APLICADO;
        }
        if (! $eliminar && ! $actual['eliminado'] && self::canonico($actual['contenido']) === self::canonico($nuevo)) {
            return self::YA_APLICADO;
        }

        return self::CONFLICTO;
    }

    /** JSON con las llaves ordenadas: PostgreSQL (jsonb) no conserva el orden original de las llaves. */
    public static function canonico(mixed $valor): string
    {
        return json_encode(self::ordenar(json_decode(json_encode($valor), true)), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function ordenar(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        if (! array_is_list($v)) {
            ksort($v);
        }

        return array_map(self::ordenar(...), $v);
    }
}
