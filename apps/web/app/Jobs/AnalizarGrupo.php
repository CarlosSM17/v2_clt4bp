<?php

namespace App\Jobs;

use App\Domain\Perfil\AnalizadorGrupo;
use App\Domain\Perfil\ConfiguracionPerfil;
use App\Models\Course;
use App\Models\GroupAnalysis;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Recalcula la recomendación de homogeneidad con todos los perfiles vigentes del curso. */
class AnalizarGrupo implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $cursoId) {}

    public function handle(): void
    {
        $curso = Course::findOrFail($this->cursoId);

        $perfiles = $curso->enrollments()
            ->whereNotIn('estado', ['solicitud', 'rechazada', 'baja'])
            ->with('perfil')->get()
            ->pluck('perfil')->filter()
            ->map(fn ($p) => ['cp' => $p->cp_global, 'nivel' => $p->nivel])
            ->values()->all();

        $resultado = (new AnalizadorGrupo(ConfiguracionPerfil::desde($curso->configuracion ?? [])))->analizar($perfiles);

        GroupAnalysis::create([
            'course_id' => $curso->id,
            'resultado' => $resultado,
            'recomendacion' => $resultado['recomendacion'],
        ]);
    }
}
