<?php

namespace App\Domain\Agente;

/**
 * Qué necesita cada plantilla del agente para trabajar. Se valida en Laravel antes de encolar:
 * así un error del instructor no gasta tokens.
 */
final class AlcancePlantilla
{
    /** Plantilla → paso de CLT4BP. Debe coincidir con app/plantillas del servicio del agente. */
    public const PASOS = [
        'objetivos' => 1, 'resumen_grupo' => 2, 'preseleccion' => 3, 'diferenciacion' => 4, 'ficha_tema' => 4,
        'clase_tareas' => 5, 'tareas_complementarias' => 5, 'info_soporte' => 6, 'ejemplo_resuelto_tema' => 6, 'mapa_glosario' => 6,
        'info_procedimental' => 7, 'guion_protocolo' => 7, 'plan_implementacion' => 8, 'items_evaluacion' => 9,
        'informe_revision' => 10,
    ];

    /** Las ayudas y el protocolo son del tema (clase_uid) o de una sola tarea (tarea_uid). */
    private const DE_CLASE_O_TAREA = ['info_procedimental', 'guion_protocolo'];

    /** Campo → tipo de referencia que debe existir en el diseño. */
    private const REQUERIDOS = [
        'clase_tareas' => ['objetivos' => 'objetivos'],
        'items_evaluacion' => ['objetivos' => 'objetivos'],
        'info_soporte' => ['clase_uid' => 'clase'],
        'tareas_complementarias' => ['clase_uid' => 'clase'],
        'ejemplo_resuelto_tema' => ['clase_uid' => 'clase'],
        'mapa_glosario' => ['clase_uid' => 'clase'],
        'ficha_tema' => ['clase_uid' => 'clase'],
        'preseleccion' => ['grupo_clave' => 'grupo'],
        'plan_implementacion' => ['inicio' => 'fecha', 'fin' => 'fecha'],
    ];

    /**
     * @param  array<string, mixed>  $alcance
     * @param  array{objetivo: list<string>, clase: list<string>, tarea: list<string>, grupo: list<string>}  $existentes
     *                                                                                                                    códigos de objetivo, uid de clases y tareas, claves de grupo
     * @return list<string> errores legibles; vacío si todo está bien
     */
    public static function validar(string $plantilla, array $alcance, array $existentes): array
    {
        if (! isset(self::PASOS[$plantilla])) {
            return ["La plantilla «{$plantilla}» no existe."];
        }
        $errores = [];
        $requeridos = self::REQUERIDOS[$plantilla] ?? [];
        if (in_array($plantilla, self::DE_CLASE_O_TAREA, true)) {
            $requeridos = isset($alcance['clase_uid']) ? ['clase_uid' => 'clase'] : ['tarea_uid' => 'tarea'];
        }
        // La evaluación del tema puede indicar su clase, además de sus objetivos
        if ($plantilla === 'items_evaluacion' && isset($alcance['clase_uid'])) {
            $requeridos['clase_uid'] = 'clase';
        }
        foreach ($requeridos as $campo => $tipo) {
            $valor = $alcance[$campo] ?? null;
            $error = match ($tipo) {
                'objetivos' => self::objetivos($valor, $existentes['objetivo']),
                'fecha' => is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) ? null : 'debe ser una fecha AAAA-MM-DD',
                default => is_string($valor) && in_array($valor, $existentes[$tipo], true) ? null : "no existe en el diseño ({$tipo})",
            };
            if ($error) {
                $errores[] = "{$campo}: {$error}.";
            }
        }
        if ($plantilla === 'plan_implementacion' && ! $errores && $alcance['inicio'] > $alcance['fin']) {
            $errores[] = 'inicio: debe ser anterior al fin.';
        }
        if ($plantilla === 'diferenciacion' && ! $existentes['grupo']) {
            $errores[] = 'El curso todavía no tiene grupos diferenciados (Etapa 2).';
        }

        return $errores;
    }

    private static function objetivos(mixed $valor, array $codigos): ?string
    {
        if (! is_array($valor) || ! $valor || ! array_is_list($valor)) {
            return 'indica al menos un objetivo';
        }
        $faltan = array_diff($valor, $codigos);

        return $faltan ? 'no existen '.implode(', ', $faltan) : null;
    }
}
