<?php

namespace App\Services\Diagnostico;

use App\Enums\EstadoInscripcion;
use App\Jobs\CalcularPerfil;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Enrollment;
use App\Models\InstrumentAdministration;
use App\Models\InstrumentResponse;

/** Sabe qué pasos tiene el diagnóstico inicial (pre) o la evaluación final (post) de un curso y cuándo está completo. */
class ServicioDiagnostico
{
    /** @return list<array{tipo: string, id: int, titulo: string, estado: string}> */
    public function pasos(Enrollment $inscripcion, string $momento = 'pre'): array
    {
        $pasos = [];

        $aplicaciones = InstrumentAdministration::with('instrument')
            ->where('course_id', $inscripcion->course_id)->where('momento', $momento)->orderBy('id')->get();
        foreach ($aplicaciones as $a) {
            $respuesta = InstrumentResponse::where('instrument_administration_id', $a->id)
                ->where('enrollment_id', $inscripcion->id)->first();
            $pasos[] = [
                'tipo' => 'cuestionario',
                'id' => $a->id,
                'titulo' => $a->instrument->nombre,
                'estado' => match (true) {
                    $respuesta?->completado_at !== null => 'completo',
                    $respuesta !== null => 'en_progreso',
                    default => 'pendiente',
                },
            ];
        }

        $pruebas = Assessment::where('course_id', $inscripcion->course_id)->where('momento', $momento)
            ->orderByRaw("case tipo when 'teorica' then 0 else 1 end")->get();
        foreach ($pruebas as $p) {
            $intento = AssessmentAttempt::where('assessment_id', $p->id)->where('enrollment_id', $inscripcion->id)->first();
            $pasos[] = [
                'tipo' => 'prueba',
                'id' => $p->id,
                'titulo' => $p->nombre,
                'estado' => match (true) {
                    $intento?->calificado_at !== null => 'completo',
                    $intento?->enviado_at !== null => 'calificando',
                    $intento !== null => 'en_progreso',
                    default => 'pendiente',
                },
            ];
        }

        return $pasos;
    }

    /** Si todos los pasos están completos, encola el cálculo del perfil. */
    public function verificarCompleto(Enrollment $inscripcion): bool
    {
        $pasos = $this->pasos($inscripcion);
        $completo = $pasos !== [] && collect($pasos)->every(fn ($p) => $p['estado'] === 'completo');

        if ($completo && in_array($inscripcion->estado, [EstadoInscripcion::Inscrito, EstadoInscripcion::Diagnostico], true)) {
            CalcularPerfil::dispatch($inscripcion->id);
        }

        return $completo;
    }
}
