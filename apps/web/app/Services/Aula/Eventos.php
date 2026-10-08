<?php

namespace App\Services\Aula;

use App\Models\LearningEvent;

/**
 * Registro de eventos de aprendizaje (estilo xAPI). Los verbos del cliente llegan en lotes desde el navegador;
 * los del servidor (envíos, calificaciones, valoraciones) se registran aquí, donde no se pueden falsificar.
 */
final class Eventos
{
    public const VERBOS_CLIENTE = [
        'abrio_mapa', 'abrio_clase', 'abrio_tarea', 'abrio_practica',
        'vio_soporte', 'consulto_ayuda', 'reprodujo_segmento', 'tiempo_visible',
    ];

    public const VERBOS_SERVIDOR = [
        'ejecuto', 'envio', 'calificado', 'completo_tarea',
        'valoro_esfuerzo', 'comento', 'comprobo_practica',
        'recibio_sugerencia', // Etapa 6: selección adaptativa (opcional)
    ];

    public const OBJETOS = ['curso', 'clase', 'tarea', 'soporte', 'ayuda', 'medio', 'practica'];

    public static function registrar(
        int $inscripcionId, string $verbo, ?string $objetoTipo = null, ?string $objetoUid = null,
        ?array $resultado = null, ?int $releaseId = null,
    ): void {
        LearningEvent::create([
            'enrollment_id' => $inscripcionId,
            'verbo' => $verbo,
            'objeto_tipo' => $objetoTipo,
            'objeto_uid' => $objetoUid,
            'release_id' => $releaseId,
            'resultado' => $resultado,
            'origen' => 'servidor',
            'ocurrido_at' => now(),
        ]);
    }
}
