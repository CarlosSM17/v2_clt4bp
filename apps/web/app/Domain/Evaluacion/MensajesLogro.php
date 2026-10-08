<?php

namespace App\Domain\Evaluacion;

/**
 * Mensajes de logro del dashboard del estudiante, alineados con ARCS (confianza y satisfacción).
 * Solo hablan del propio avance: nunca comparan con compañeros.
 */
final class MensajesLogro
{
    private const SIN_GUIA = ['convencional', 'solucion_libre'];

    private const MEDIDAS = ['teorico' => 'la parte teórica', 'practico' => 'la parte práctica'];

    /**
     * @param  list<array{orden: int, titulo: string, tareas: list<array{nivel_apoyo: string, estado: string, fraccion: ?float, esfuerzo: ?int}>}>  $clases  en orden; las tareas de cada clase también en orden
     * @param  array<string, array{pre: ?float, post: ?float}>  $prePost  teorico y practico, de 0 a 100
     * @return list<array{arcs: 'confianza'|'satisfaccion', texto: string}>
     */
    public static function para(array $clases, array $prePost = []): array
    {
        $mensajes = [];

        foreach ($clases as $c) {
            $tareas = $c['tareas'];
            if (! $tareas) {
                continue;
            }
            $sinGuia = array_filter($tareas, fn ($t) => in_array($t['nivel_apoyo'], self::SIN_GUIA, true));
            $resueltas = array_filter($sinGuia, fn ($t) => ($t['fraccion'] ?? 0) >= 1.0);
            if ($sinGuia && count($resueltas) === count($sinGuia)) {
                $n = count($sinGuia);
                $mensajes[] = ['arcs' => 'confianza', 'texto' => $n === 1
                    ? "Resolviste la tarea sin guía de la clase {$c['orden']}."
                    : "Resolviste las {$n} tareas sin guía de la clase {$c['orden']}."];
            }
            if (count(array_filter($tareas, fn ($t) => $t['estado'] === 'completada')) === count($tareas)) {
                $mensajes[] = ['arcs' => 'satisfaccion', 'texto' => "Completaste la clase {$c['orden']}: «{$c['titulo']}»."];
            }
        }

        // Automatización: el esfuerzo baja mientras el desempeño se mantiene
        $valoradas = array_values(array_filter(array_merge([], ...array_column($clases, 'tareas')),
            fn ($t) => $t['esfuerzo'] !== null && $t['fraccion'] !== null));
        if (count($valoradas) >= 6) {
            $primeras = array_slice($valoradas, 0, 3);
            $ultimas = array_slice($valoradas, -3);
            $media = fn (array $ts, string $k) => array_sum(array_column($ts, $k)) / count($ts);
            if ($media($ultimas, 'esfuerzo') <= $media($primeras, 'esfuerzo') - 1
                && $media($ultimas, 'fraccion') >= $media($primeras, 'fraccion') - 0.05) {
                $mensajes[] = ['arcs' => 'confianza',
                    'texto' => 'Cada vez te cuesta menos esfuerzo resolver tareas del mismo nivel: estás automatizando lo que aprendiste.'];
            }
        }

        foreach (self::MEDIDAS as $clave => $nombre) {
            $pre = $prePost[$clave]['pre'] ?? null;
            $post = $prePost[$clave]['post'] ?? null;
            if ($pre !== null && $post !== null && $post - $pre >= 10) {
                $mensajes[] = ['arcs' => 'satisfaccion',
                    'texto' => 'Mejoraste '.round($post - $pre)." puntos en {$nombre} respecto a tu diagnóstico."];
            }
        }

        return $mensajes;
    }
}
