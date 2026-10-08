<?php

namespace App\Jobs;

use App\Models\TaskProgress;
use App\Models\TaskSubmission;
use App\Services\Aula\Eventos;
use App\Services\Codigo\EvaluadorCasos;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Califica un envío contra todos los casos de la tarea, tal como estaban en la publicación que vio el estudiante. */
class CalificarEnvio implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public int $envioId) {}

    public function handle(EvaluadorCasos $evaluador): void
    {
        $envio = TaskSubmission::with('release')->findOrFail($this->envioId);
        if ($envio->estado !== 'en_cola') {
            return;
        }
        // Solución y casos no cambian entre variantes: se usa la tarea base de esa publicación
        $tarea = collect($envio->release->manifiesto['tareas'])->firstWhere('uid', $envio->tarea_uid);
        $r = $evaluador->evaluar($tarea['lenguaje'], $envio->codigo, $tarea['casos_prueba']);

        DB::transaction(function () use ($envio, $r) {
            $envio->update(['estado' => 'calificado', 'resultado' => $r, 'fraccion' => $r['fraccion']]);

            $p = TaskProgress::firstOrCreate(['enrollment_id' => $envio->enrollment_id, 'tarea_uid' => $envio->tarea_uid], ['estado' => 'en_progreso']);
            $completa = $r['fraccion'] >= 1.0;
            $p->update([
                'intentos' => $p->intentos + 1,
                'mejor_fraccion' => max($p->mejor_fraccion ?? 0, $r['fraccion']),
                'estado' => $completa ? 'completada' : ($p->estado ?? 'en_progreso'),
                'completado_at' => $completa ? ($p->completado_at ?? now()) : $p->completado_at,
            ]);
            Eventos::registrar($envio->enrollment_id, 'calificado', 'tarea', $envio->tarea_uid, [
                'envio_id' => $envio->id, 'aprobados' => $r['aprobados'], 'total' => $r['total'],
                'compilo' => $r['error_compilacion'] === null,
            ], $envio->release_id);
        });
    }

    public function failed(?Throwable $e): void
    {
        TaskSubmission::whereKey($this->envioId)->update(['estado' => 'error']);
    }
}
