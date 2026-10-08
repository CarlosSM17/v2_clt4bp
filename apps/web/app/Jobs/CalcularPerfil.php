<?php

namespace App\Jobs;

use App\Domain\Perfil\CalculadoraPerfil;
use App\Domain\Perfil\ConfiguracionPerfil;
use App\Enums\EstadoInscripcion;
use App\Models\AssessmentAttempt;
use App\Models\Enrollment;
use App\Models\InstrumentResponse;
use App\Models\StudentProfile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class CalcularPerfil implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $inscripcionId) {}

    public function handle(): void
    {
        $inscripcion = Enrollment::with('course')->findOrFail($this->inscripcionId);

        $mslq = InstrumentResponse::query()
            ->where('enrollment_id', $inscripcion->id)
            ->whereNotNull('completado_at')
            ->whereHas('administration', fn ($q) => $q->where('momento', 'pre')
                ->whereHas('instrument', fn ($i) => $i->where('clave', 'mslq')))
            ->first()?->puntajes['subescalas'] ?? [];

        $intentoDe = fn (string $tipo) => AssessmentAttempt::query()
            ->where('enrollment_id', $inscripcion->id)->whereNotNull('calificado_at')
            ->whereHas('assessment', fn ($q) => $q->where('momento', 'pre')->where('tipo', $tipo))
            ->first();
        $teoria = $intentoDe('teorica');
        $practica = $intentoDe('practica');

        $calculadora = new CalculadoraPerfil(ConfiguracionPerfil::desde($inscripcion->course->configuracion ?? []));
        $perfil = $calculadora->calcular(
            $mslq,
            recall: (float) ($teoria?->subpuntajes['recall'] ?? 0),
            comprension: (float) ($teoria?->subpuntajes['comprension'] ?? 0),
            teorico: (float) ($teoria?->porcentaje ?? 0),
            practico: (float) ($practica?->porcentaje ?? 0),
        );

        DB::transaction(function () use ($inscripcion, $perfil) {
            $version = (int) StudentProfile::where('enrollment_id', $inscripcion->id)->max('version') + 1;
            StudentProfile::create([...$perfil, 'enrollment_id' => $inscripcion->id, 'version' => $version]);
            $inscripcion->update(['estado' => EstadoInscripcion::ConPerfil]);
        });

        AnalizarGrupo::dispatch($inscripcion->course_id);
    }
}
