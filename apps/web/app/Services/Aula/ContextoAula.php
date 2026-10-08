<?php

namespace App\Services\Aula;

use App\Domain\Aula\Disponibilidad;
use App\Models\DiffGroup;
use App\Models\Enrollment;
use App\Models\Release;
use Illuminate\Support\Collection;

/** Todo lo que se necesita para responder una pantalla del aula, calculado una vez por petición. */
final class ContextoAula
{
    /**
     * @param  list<array>  $clases  ordenadas
     * @param  array<string, array{estado: string, activacion: ?array}>  $estados  por uid de clase
     * @param  Collection<string, \App\Models\TaskProgress>  $progreso  por uid de tarea
     */
    public function __construct(
        public readonly Enrollment $inscripcion,
        public readonly Release $release,
        public readonly ?DiffGroup $grupo,
        public readonly array $manifiesto,
        public readonly array $clases,
        public readonly array $estados,
        public readonly Collection $progreso,
    ) {}

    public function clase(string $uid): array
    {
        $c = collect($this->clases)->firstWhere('uid', $uid);
        abort_unless($c && Disponibilidad::puedeVer($this->estados[$uid]['estado']), 404);

        return $c;
    }

    /** La tarea, si su clase está disponible para este estudiante. */
    public function tarea(string $uid): array
    {
        $t = collect($this->manifiesto['tareas'])->firstWhere('uid', $uid);
        abort_unless($t, 404);
        $this->clase($t['clase_uid']);

        return $t;
    }

    public function tareasDe(string $claseUid): array
    {
        return collect($this->manifiesto['tareas'])->where('clase_uid', $claseUid)->sortBy('orden')->values()->all();
    }

    public function puedeEnviar(string $claseUid): bool
    {
        return ($this->estados[$claseUid]['estado'] ?? null) === Disponibilidad::ABIERTA;
    }

    /** Misma regla que «requiere_anterior» en ServicioAula: cada tarea tiene un envío o quedó completada. */
    public function claseCompleta(string $claseUid): bool
    {
        $tareas = $this->tareasDe($claseUid);

        return $tareas !== [] && collect($tareas)->every(fn ($t) => ($p = $this->progreso->get($t['uid']))
            && ($p->intentos > 0 || $p->estado === 'completada'));
    }
}
