<?php

namespace App\Console\Commands;

use App\Enums\EstadoInscripcion;
use App\Models\Activation;
use App\Models\Enrollment;
use App\Models\Release;
use App\Notifications\ClaseDisponible;
use Illuminate\Console\Command;

/** Cada cinco minutos: avisa a los estudiantes de las clases que acaban de abrir. */
class AvisarAperturas extends Command
{
    protected $signature = 'aula:avisar-aperturas';

    protected $description = 'Envía el aviso de las clases de tareas cuya fecha de apertura ya llegó';

    public function handle(): int
    {
        $pendientes = Activation::with('curso')->whereNull('avisado_at')->where('abre_at', '<=', now())->get();

        foreach ($pendientes as $a) {
            $clase = collect(Release::vigente($a->course_id)?->manifiesto['clases'] ?? [])->firstWhere('uid', $a->clase_uid);
            if ($clase) {
                // Una activación de grupo avisa a su grupo; la general, a quienes no tienen una propia
                $conPropia = Activation::where('course_id', $a->course_id)->where('clase_uid', $a->clase_uid)
                    ->whereNotNull('diff_group_id')->pluck('diff_group_id');
                $inscripciones = Enrollment::with('user', 'membresia')->where('course_id', $a->course_id)
                    ->whereIn('estado', [EstadoInscripcion::ConPerfil, EstadoInscripcion::Cursando])->get()
                    ->filter(fn (Enrollment $e) => $a->diff_group_id !== null
                        ? $e->membresia?->diff_group_id === $a->diff_group_id
                        : ! $conPropia->contains($e->membresia?->diff_group_id));

                foreach ($inscripciones as $e) {
                    $e->user->notify(new ClaseDisponible($a->course_id, $a->curso->titulo, $a->clase_uid, $clase['titulo'],
                        $a->cierra_at?->timezone(config('app.timezone'))->translatedFormat('j \d\e F, H:i')));
                }
                $this->info("{$clase['titulo']}: {$inscripciones->count()} avisos.");
            }
            $a->update(['avisado_at' => now()]);
        }

        return self::SUCCESS;
    }
}
