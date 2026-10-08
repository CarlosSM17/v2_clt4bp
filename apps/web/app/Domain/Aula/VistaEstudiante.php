<?php

namespace App\Domain\Aula;

/**
 * Lo que llega al navegador del estudiante. Nunca salen las soluciones de referencia,
 * los casos ocultos ni el bloque «diseno» (decisiones del instructor, material de investigación).
 */
final class VistaEstudiante
{
    public const ETIQUETA_APOYO = [
        'ejemplo_resuelto' => 'Ejemplo resuelto',
        'por_completar' => 'Por completar',
        'convencional' => 'Convencional',
        'solucion_libre' => 'Solución libre',
    ];

    public static function tarea(array $t): array
    {
        return [
            'uid' => $t['uid'],
            'clase_uid' => $t['clase_uid'],
            'orden' => $t['orden'],
            'titulo' => $t['titulo'],
            'nivel_apoyo' => $t['nivel_apoyo'],
            'etiqueta_apoyo' => self::ETIQUETA_APOYO[$t['nivel_apoyo']] ?? $t['nivel_apoyo'],
            'enunciado_md' => $t['enunciado_md'],
            'arcs' => ['relevancia' => $t['arcs']['relevancia'] ?? '', 'confianza' => $t['arcs']['confianza'] ?? ''],
            'lenguaje' => $t['lenguaje'],
            'codigo_inicial' => $t['codigo_inicial'],
            'ejemplos' => self::visibles($t['casos_prueba'] ?? []),
            'casos_ocultos' => count($t['casos_prueba'] ?? []) - count(self::visibles($t['casos_prueba'] ?? [])),
            'pide_autoexplicacion' => (bool) ($t['pide_autoexplicacion'] ?? false),
            'colaborativa' => (bool) ($t['colaborativa'] ?? false),
            'tiempo_estimado_min' => $t['diseno']['tiempo_estimado_min'] ?? null,
            'rutas' => array_values($t['rutas'] ?? []),
            'papel' => self::papel($t),
        ];
    }

    /** Qué hace el estudiante en la tarea, como las etiquetas del mapa de ruta (sin exponer el bloque «diseno»). */
    private static function papel(array $t): ?string
    {
        $codigoDado = ($t['pide_autoexplicacion'] ?? false) && $t['nivel_apoyo'] === 'ejemplo_resuelto' && ($t['orden'] ?? 1) > 1;

        return match (true) {
            (bool) ($t['colaborativa'] ?? false) => 'Reto colaborativo',
            // El contrato no distingue la imaginación (T7) de la autoexplicación (T6): su título lo dice
            $codigoDado && preg_match('/^\s*imaginaci[oó]n\b/iu', $t['titulo'] ?? '') === 1 => 'Imaginación',
            $codigoDado => 'Autoexplicación',
            // La solución libre ya la nombra la etiqueta de apoyo: repetirla como papel duplicaba el chip
            default => null,
        };
    }

    public static function ayuda(array $p): array
    {
        return ['uid' => $p['uid'], 'tipo' => $p['tipo'], 'titulo' => $p['titulo'], 'cuerpo_md' => $p['cuerpo_md']];
    }

    public static function soporte(array $s): array
    {
        return ['uid' => $s['uid'], 'tipo' => $s['tipo'], 'titulo' => $s['titulo'], 'cuerpo_md' => $s['cuerpo_md']];
    }

    public static function practica(array $p): array
    {
        return [
            'uid' => $p['uid'],
            'habilidad' => $p['habilidad'],
            'lenguaje' => $p['lenguaje'],
            'ejercicios' => array_map(fn (array $e) => [
                'enunciado_md' => $e['enunciado_md'],
                'ejemplos' => self::visibles($e['casos_prueba'] ?? []),
            ], $p['ejercicios'] ?? []),
        ];
    }

    /** @return list<array{entrada: string, salida_esperada: string}> */
    private static function visibles(array $casos): array
    {
        return array_values(array_map(
            fn (array $c) => ['entrada' => $c['entrada'], 'salida_esperada' => $c['salida_esperada']],
            array_filter($casos, fn (array $c) => ! ($c['oculto'] ?? false)),
        ));
    }
}
