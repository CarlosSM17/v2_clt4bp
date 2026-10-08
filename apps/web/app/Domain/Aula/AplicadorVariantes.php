<?php

namespace App\Domain\Aula;

/**
 * Contenido que ve un grupo: el elemento base con los cambios de su variante encima.
 * Es la misma regla que aplicarVariante (consola, TypeScript) y aplicar_variante (verificador, Python).
 */
final class AplicadorVariantes
{
    /** Campos que una variante nunca cambia: identificadores, posición, solución y casos (todos se evalúan igual). */
    public const CAMPOS_FIJOS = ['uid', 'clase_uid', 'tarea_uid', 'orden', 'solucion', 'casos_prueba'];

    private const LISTAS = ['objetivos', 'clases', 'tareas', 'soporte', 'procedimental', 'practica_parcial'];

    public static function aplicar(array $base, ?array $cambios): array
    {
        foreach ($cambios ?? [] as $campo => $valor) {
            if (in_array($campo, self::CAMPOS_FIJOS, true)) {
                continue;
            }
            $actual = $base[$campo] ?? null;
            // «diseno» se mezcla un nivel: una variante puede cambiar solo los efectos, por ejemplo
            $base[$campo] = $campo === 'diseno' && self::esObjeto($actual) && self::esObjeto($valor)
                ? [...$actual, ...$valor]
                : $valor;
        }

        return $base;
    }

    /**
     * El manifiesto de una publicación tal como lo verá un grupo (null = versión base).
     * Las variantes de otros grupos desaparecen del resultado.
     */
    public static function paraGrupo(array $manifiesto, ?string $grupo): array
    {
        $cambios = [];
        foreach ($manifiesto['variantes'] ?? [] as $v) {
            if ($grupo !== null && $v['grupo_clave'] === $grupo) {
                $cambios[$v['elemento_uid']] = $v['cambios'];
            }
        }
        foreach (self::LISTAS as $lista) {
            $manifiesto[$lista] = array_map(
                fn (array $e) => isset($cambios[$e['uid']]) ? self::aplicar($e, $cambios[$e['uid']]) : $e,
                $manifiesto[$lista] ?? [],
            );
        }
        unset($manifiesto['variantes']);

        return $grupo === null ? $manifiesto : self::soloSuRuta($manifiesto, $grupo);
    }

    /**
     * Las tareas de otras rutas no las ve el grupo (ni sus ayudas): «rutas» vacío o ausente = todos los grupos.
     * Sin grupo (antes del diagnóstico, o el instructor) se ve todo.
     */
    public static function soloSuRuta(array $manifiesto, string $grupo): array
    {
        $manifiesto['tareas'] = array_values(array_filter(
            $manifiesto['tareas'] ?? [],
            fn (array $t) => empty($t['rutas']) || in_array($grupo, $t['rutas'], true),
        ));
        $visibles = array_flip(array_column($manifiesto['tareas'], 'uid'));
        $manifiesto['procedimental'] = array_values(array_filter(
            $manifiesto['procedimental'] ?? [],
            fn (array $a) => empty($a['tarea_uid']) || isset($visibles[$a['tarea_uid']]),
        ));

        return $manifiesto;
    }

    private static function esObjeto(mixed $v): bool
    {
        return is_array($v) && ($v === [] || ! array_is_list($v));
    }
}
